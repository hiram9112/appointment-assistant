<?php

namespace App\Application\Conversation;

use App\Jobs\ProcessConversationTurn;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ReconcileConversationTurns
{
    private const CycleKey = 'conversation:reconcile:scan-cycle';

    /** Discover work without taking a claim. The worker rechecks eligibility under the participant lock. */
    public function __invoke(): void
    {
        $now = now()->toAtomString();
        $unfinished = AiTurnState::unfinished();
        $batchSize = max(1, (int) config('conversation.processing.reconciliation_batch_size'));
        $cycle = Cache::get(self::CycleKey);
        $afterId = $cycle['after'] ?? 0;
        $throughId = $cycle['through'] ?? (int) DB::table('ai_turns')->max('inbound_message_id');
        $batch = DB::table('ai_turns')
            ->join('conversation_messages as inbound', 'inbound.id', '=', 'ai_turns.inbound_message_id')
            ->join('conversations', 'conversations.id', '=', 'inbound.conversation_id')
            ->where('inbound.id', '>', $afterId)
            ->where('inbound.id', '<=', $throughId)
            ->whereIn('ai_turns.state', $unfinished)
            ->whereNotExists(fn (Builder $query) => $query->from('conversations as newer')
                ->whereColumn('newer.business_id', 'conversations.business_id')
                ->whereColumn('newer.channel', 'conversations.channel')
                ->whereColumn('newer.sender_identity', 'conversations.sender_identity')
                ->whereRaw('(newer.last_activity_at, newer.id) > (conversations.last_activity_at, conversations.id)'))
            ->whereNotExists(fn (Builder $query) => $query->from('conversation_messages as earlier')
                ->join('ai_turns as earlier_turn', 'earlier_turn.inbound_message_id', '=', 'earlier.id')
                ->whereColumn('earlier.conversation_id', 'inbound.conversation_id')
                ->whereColumn('earlier.id', '<', 'inbound.id')
                ->whereIn('earlier_turn.state', $unfinished))
            ->where(fn (Builder $query) => $query->whereNull('ai_turns.next_attempt_at')->orWhere('ai_turns.next_attempt_at', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('ai_turns.claimed_until')->orWhere('ai_turns.claimed_until', '<=', $now))
            ->where(fn (Builder $query) => $this->applyInterruptedAttemptBackoff($query, $now))
            ->orderBy('inbound.id')
            ->limit($batchSize)
            ->get(['inbound.id', 'inbound.conversation_id']);

        foreach ($batch as $turn) {
            ProcessConversationTurn::enqueue($turn->conversation_id);
        }

        // New arrivals cannot extend a cycle, and a long cycle must not expire before reaching its tail.
        if ($batch->count() === $batchSize && $batch->last()->id < $throughId) {
            Cache::forever(self::CycleKey, ['after' => $batch->last()->id, 'through' => $throughId]);
        } elseif ($cycle !== null) {
            Cache::forget(self::CycleKey);
        }
    }

    /** Match the claim boundary: interrupted attempts also need spacing unless the normal budget is exhausted. */
    private function applyInterruptedAttemptBackoff(Builder $query, string $now): void
    {
        $delays = AiTurnRetryPolicy::delays();
        $placeholders = implode(', ', array_fill(0, count($delays), '?'));
        $query->where('ai_turns.state', '!=', AiTurnState::Processing)
            ->orWhere('ai_turns.attempt_count', '>=', AiTurnRetryPolicy::maxAttempts())
            ->orWhereRaw(<<<SQL
                COALESCE(
                    (ai_turns.diagnostics->'attempts'->(ai_turns.attempt_count::text)->>'claimed_at')::timestamptz,
                    ai_turns.updated_at AT TIME ZONE 'UTC'
                ) + make_interval(secs => (ARRAY[$placeholders]::double precision[])[LEAST(GREATEST(ai_turns.attempt_count, 1), ?)]) <= ?
                SQL, [...$delays, count($delays), $now]);
    }
}
