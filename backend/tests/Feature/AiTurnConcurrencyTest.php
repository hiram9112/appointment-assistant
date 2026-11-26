<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnFailure;
use App\Application\Conversation\AiTurnFailureReason;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalOperation;
use App\Application\Conversation\AppointmentProposalPayload;
use App\Application\Conversation\AppointmentProposalPresentation;
use App\Application\Conversation\AppointmentProposals;
use App\Application\Conversation\AppointmentProposalState;
use App\Application\Conversation\ConfirmAppointmentProposal;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Application\Conversation\PrepareAppointmentProposal;
use App\Jobs\ProcessConversationTurn;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiTurnConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    /** @var array<int, array{process: resource, pipes: array<int, resource>, pid: int}> */
    private array $workers = [];

    private int $nextWorker = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $this->business = Business::factory()->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            proc_terminate($worker['process']);
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker['process']);
        }
        parent::tearDown();
    }

    #[DataProvider('deliveries')]
    public function test_concurrent_registration_and_processing_preserve_idempotency_and_fifo(bool $duplicate, string $processing): void
    {
        $first = $this->start('inbound', ['message' => '1'], 'insert');
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start('inbound', ['message' => $duplicate ? '1' : '2']);
        $this->assertBlocked($first, $second);
        $this->release($first);
        $firstResult = $this->finish($first);
        $secondResult = $this->finish($second);
        $this->assertSame($duplicate, $firstResult['id'] === $secondResult['id']);
        $this->assertDatabaseCount('ai_turns', $duplicate ? 1 : 2);

        $worker = $this->start($processing, [], 'generate');
        $this->assertSame("generating\n", $this->line($worker));
        $otherDelivery = $this->start('inbound', ['message' => '3']);
        $this->assertSame('turn', $this->finish($otherDelivery)['kind']);
        $competing = $this->start($processing);
        $this->assertSame(['kind' => 'outcome', 'name' => 'Busy'], $this->finish($competing));
        $this->release($worker);
        $this->assertSame('message', $this->finish($worker)['kind']);
        $this->assertSame(AiTurnState::Completed, AiTurn::findOrFail($firstResult['id'])->state);

        $next = app(AiTurnCoordinator::class)->claimNext($this->business, 'web', 'sender');
        $expected = $duplicate ? AiTurn::query()->orderByDesc('inbound_message_id')->firstOrFail()->id : $secondResult['id'];
        $this->assertSame($expected, $next->turnId);
        $this->assertSame(1, ConversationMessage::query()->where('role', 'assistant')->count());
    }

    public static function deliveries(): array
    {
        return [
            'duplicate through coordinator' => [true, 'process'],
            'distinct through coordinator' => [false, 'process'],
            'duplicate through SDK agent' => [true, 'sdk_process'],
            'distinct through SDK agent' => [false, 'sdk_process'],
        ];
    }

    public function test_independent_participants_can_claim_while_another_claim_transaction_is_open(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $other = $coordinator->inbound($this->business, 'web', 'other', '1', 'hello');
        $first = $this->start('claim', [], 'claim');
        $this->assertSame("locked\n", $this->line($first));
        $independent = $this->start('process', ['sender' => 'other']);
        $this->assertSame('message', $this->finish($independent)['kind']);
        $this->assertSame(AiTurnState::Completed, $other->fresh()->state);
        $this->release($first);
        $this->assertSame('claim', $this->finish($first)['kind']);
    }

    #[DataProvider('registrationScopes')]
    public function test_concurrent_customer_registration_reuses_one_identity_and_associates_each_current_conversation(bool $differentBusiness): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'whatsapp', 'sender', '1', 'Register me');
        $claim = $coordinator->claimNext($this->business, 'whatsapp', 'sender');
        $otherBusiness = $differentBusiness ? Business::factory()->create() : $this->business;
        $otherClaim = $claim;
        if ($differentBusiness) {
            $coordinator->inbound($otherBusiness, 'whatsapp', 'sender', '1', 'Register me too');
            $otherClaim = $coordinator->claimNext($otherBusiness, 'whatsapp', 'sender');
        }

        $first = $this->start('register_customer', $this->claimData($claim), 'registration');
        $this->assertSame("locked\n", $this->line($first));
        $this->assertDatabaseCount('customers', 0);
        $second = $this->start('register_customer', [...$this->claimData($otherClaim), 'business' => $otherBusiness->id]);
        $this->assertBlocked($first, $second);
        $this->release($first);
        $firstResult = $this->finish($first);
        $secondResult = $this->finish($second);

        $this->assertSame('registered', $firstResult['status']);
        $this->assertSame('already_registered', $secondResult['status']);
        $this->assertSame($firstResult['customer'], $secondResult['customer']);
        $customerId = $firstResult['customer']['id'];
        $this->assertSame($customerId, ChannelIdentity::sole()->customer_id);
        $this->assertSame($customerId, Conversation::findOrFail($claim->inboundMessage->conversation_id)->customer_id);
        $this->assertSame($customerId, Conversation::findOrFail($otherClaim->inboundMessage->conversation_id)->customer_id);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
        $this->assertDatabaseCount('conversations', $differentBusiness ? 2 : 1);
        $this->assertSame(0, ConversationMessage::query()->where('role', 'assistant')->count());
        $this->assertInstanceOf(ConversationMessage::class, $coordinator->complete($this->business, $claim, 'Welcome'));
        if ($differentBusiness) {
            $this->assertInstanceOf(ConversationMessage::class, $coordinator->complete($otherBusiness, $otherClaim, 'Welcome'));
        }
    }

    public static function registrationScopes(): array
    {
        return [
            'same participant serialized by ownership boundary' => [false],
            'same identity across Businesses resolved after PostgreSQL unique conflict' => [true],
        ];
    }

    public function test_stale_attempt_cannot_register_an_unidentified_contact(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'whatsapp', 'sender', '1', 'Register me');
        $claim = $coordinator->claimNext($this->business, 'whatsapp', 'sender', 60);
        $takeover = $this->start('claim_customer', [], 'claim', '2030-01-01 12:01:00 UTC');
        $this->assertSame("locked\n", $this->line($takeover));
        $stale = $this->start('register_customer', $this->claimData($claim));
        $this->assertBlocked($takeover, $stale);
        $this->release($takeover);
        $new = $this->finish($takeover);
        $this->assertSame(['status' => 'not_authorized', 'reason' => 'StaleAttempt'], $this->finish($stale));
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('channel_identities', 0);
        $this->assertNull(Conversation::sole()->customer_id);
        $recovered = $this->start('register_customer', [
            'turn' => $new['id'], 'token' => $new['token'], 'inbound' => $claim->inboundMessage->id,
        ]);
        $this->assertSame('registered', $this->finish($recovered)['status']);
        $this->assertDatabaseCount('customers', 1);
    }

    #[DataProvider('authorizedActions')]
    public function test_takeover_fences_an_old_attempt_waiting_on_the_participant_lock(string $action): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $old = $coordinator->claimNext($this->business, 'web', 'sender', 60);
        $takeover = $this->start('claim', [], 'claim', '2030-01-01 12:01:00 UTC');
        $this->assertSame("locked\n", $this->line($takeover));
        $stale = $this->start($action, $this->claimData($old), '', '2030-01-01 12:01:00 UTC');
        $this->assertBlocked($takeover, $stale);
        $this->release($takeover);
        $new = $this->finish($takeover);
        $this->assertNotSame($old->token, $new['token']);
        $this->assertSame(2, AiTurn::findOrFail($old->turnId)->attempt_count);
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($stale));
        $this->assertDatabaseCount('customers', 0);
        $accepted = $this->start('complete', ['turn' => $new['id'], 'token' => $new['token'], 'inbound' => $old->inboundMessage->id]);
        $this->assertSame('message', $this->finish($accepted)['kind']);
        $this->assertSame(1, ConversationMessage::query()->where('role', 'assistant')->count());
    }

    public static function authorizedActions(): array
    {
        return ['response completion' => ['complete'], 'write callback' => ['write'], 'failure recording' => ['fail']];
    }

    public function test_write_callbacks_serialize_for_one_participant_without_blocking_another(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $coordinator->inbound($this->business, 'web', 'other', '1', 'hello');
        $otherClaim = $coordinator->claimNext($this->business, 'web', 'other');

        $first = $this->start('write', $this->claimData($claim), 'write');
        $this->assertSame("locked\n", $this->line($first));
        $this->assertDatabaseCount('customers', 0);
        $sameParticipant = $this->start('write', $this->claimData($claim));
        $this->assertBlocked($first, $sameParticipant);
        $independent = $this->start('write', $this->claimData($otherClaim));
        $otherResult = $this->finish($independent);
        $this->assertSame('write', $otherResult['kind']);
        $this->assertSame($otherClaim->inboundMessage->conversation_id, $otherResult['conversation']);
        $this->assertNull($otherResult['previous_customer']);
        $this->assertDatabaseCount('customers', 1);
        $this->release($first);
        $firstResult = $this->finish($first);
        $secondResult = $this->finish($sameParticipant);
        $this->assertSame('write', $firstResult['kind']);
        $this->assertSame('write', $secondResult['kind']);
        $this->assertNull($firstResult['previous_customer']);
        $this->assertSame($firstResult['customer'], $secondResult['previous_customer']);
        $this->assertSame($secondResult['customer'], Conversation::findOrFail($claim->inboundMessage->conversation_id)->customer_id);
        $this->assertDatabaseCount('customers', 3);
        $this->assertSame(2, AiTurn::query()->where('state', AiTurnState::Processing)->count());
        $this->assertSame(0, ConversationMessage::query()->where('role', 'assistant')->count());
    }

    public function test_claim_takeover_waits_for_an_already_authorized_write_to_commit(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender', 60);
        $write = $this->start('write', $this->claimData($claim), 'write');
        $this->assertSame("locked\n", $this->line($write));
        $takeover = $this->start('claim', [], '', '2030-01-01 12:01:00 UTC');
        $this->assertBlocked($write, $takeover);
        $this->release($write);
        $this->assertSame('write', $this->finish($write)['kind']);
        $new = $this->finish($takeover);
        $this->assertSame('claim', $new['kind']);
        $this->assertNotSame($claim->token, $new['token']);
        $stale = $this->start('write', $this->claimData($claim));
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($stale));
        $this->assertDatabaseCount('customers', 1);
    }

    #[DataProvider('replacementOrder')]
    public function test_write_and_conversation_replacement_use_the_same_participant_lock(bool $replacementFirst): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $turn = $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $time = '2030-01-02 00:00:00 UTC';
        $first = $this->start($replacementFirst ? 'inbound' : 'write', $replacementFirst ? ['message' => '2'] : $this->claimData($claim), $replacementFirst ? 'insert' : 'write', $time);
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start($replacementFirst ? 'write' : 'inbound', $replacementFirst ? $this->claimData($claim) : ['message' => '2'], '', $time);
        $this->assertBlocked($first, $second);
        $this->release($first);
        $firstResult = $this->finish($first);
        $secondResult = $this->finish($second);

        if ($replacementFirst) {
            $this->assertSame('turn', $firstResult['kind']);
            $this->assertSame(['kind' => 'outcome', 'name' => 'Superseded'], $secondResult);
            $this->assertSame(AiTurnState::Superseded, $turn->fresh()->state);
            $this->assertDatabaseCount('customers', 0);
        } else {
            $this->assertSame('write', $firstResult['kind']);
            $this->assertSame('turn', $secondResult['kind']);
            $this->assertDatabaseCount('customers', 1);
            $lateWrite = $this->start('write', $this->claimData($claim), '', $time);
            $this->assertSame(['kind' => 'outcome', 'name' => 'Superseded'], $this->finish($lateWrite));
            $this->assertDatabaseCount('customers', 1);
        }
        $this->assertDatabaseCount('conversations', 2);
        $this->assertSame('2030-01-01 12:00:00', Conversation::findOrFail($claim->inboundMessage->conversation_id)->last_activity_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, ConversationMessage::query()->where('role', 'assistant')->count());
    }

    public function test_concurrent_completions_accept_only_one_assistant_message(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $first = $this->start('complete', $this->claimData($claim), 'insert');
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start('complete', $this->claimData($claim));
        $this->assertBlocked($first, $second);
        $this->release($first);
        $this->assertSame('message', $this->finish($first)['kind']);
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($second));
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    #[DataProvider('responseOrder')]
    public function test_late_normal_response_and_fallback_race_to_accept_only_one_reply(bool $fallbackFirst): void
    {
        config(['conversation.processing.max_attempts' => 1]);
        $coordinator = app(AiTurnCoordinator::class);
        $turn = $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $time = '2030-01-01 12:05:00 UTC';
        $normal = $this->claimData($claim);
        $fallback = ['conversation' => $turn->inboundMessage->conversation_id, 'max_attempts' => 1];
        $first = $this->start($fallbackFirst ? 'queued_process' : 'complete', $fallbackFirst ? $fallback : $normal, 'insert', $time);
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start($fallbackFirst ? 'complete' : 'queued_process', $fallbackFirst ? $normal : $fallback, time: $time);
        $this->assertBlocked($first, $second);
        $this->release($first);
        $firstResult = $this->finish($first);
        $secondResult = $this->finish($second);

        $this->assertSame('job', ($fallbackFirst ? $firstResult : $secondResult)['kind']);
        if ($fallbackFirst) {
            $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $secondResult);
            $this->assertStringContainsString('No pudimos completar tu solicitud', $turn->fresh()->assistantMessage->content);
        } else {
            $this->assertSame('message', $firstResult['kind']);
            $this->assertSame('answer', $turn->fresh()->assistantMessage->content);
        }
        $this->assertSame(AiTurnState::Completed, $turn->refresh()->state);
        $this->assertSame($fallbackFirst, $turn->fallback_used);
        $this->assertSame(1, $turn->attempt_count);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public static function responseOrder(): array
    {
        return ['normal response first' => [false], 'fallback first' => [true]];
    }

    #[DataProvider('replacementOrder')]
    public function test_completion_and_replacement_share_s10_lifecycle_synchronization(bool $replacementFirst): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $turn = $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $completionData = $this->claimData($claim);
        $first = $this->start($replacementFirst ? 'inbound' : 'complete', $replacementFirst ? ['message' => '2'] : $completionData, 'insert', '2030-01-02 00:00:00 UTC');
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start($replacementFirst ? 'complete' : 'inbound', $replacementFirst ? $completionData : ['message' => '2'], '', '2030-01-02 00:00:00 UTC');
        $this->assertBlocked($first, $second);
        $this->release($first);
        $firstResult = $this->finish($first);
        $secondResult = $this->finish($second);
        if ($replacementFirst) {
            $this->assertSame(['kind' => 'outcome', 'name' => 'Superseded'], $secondResult);
            $this->assertSame(AiTurnState::Superseded, $turn->fresh()->state);
            $this->assertSame('2030-01-01 12:00:00', Conversation::findOrFail($claim->inboundMessage->conversation_id)->last_activity_at->format('Y-m-d H:i:s'));
            $this->assertDatabaseCount('conversations', 2);
            $this->assertSame(0, ConversationMessage::query()->where('role', 'assistant')->count());
        } else {
            $this->assertSame('message', $firstResult['kind']);
            $this->assertSame('turn', $secondResult['kind']);
            $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
            $this->assertDatabaseCount('conversations', 1);
            $this->assertSame(1, ConversationMessage::query()->where('role', 'assistant')->count());
        }
    }

    public static function replacementOrder(): array
    {
        return ['replacement first' => [true], 'completion first' => [false]];
    }

    #[DataProvider('processingPaths')]
    public function test_killed_provider_execution_is_durably_recoverable(string $processing): void
    {
        $turn = app(AiTurnCoordinator::class)->inbound($this->business, 'web', 'sender', '1', 'hello');
        $interrupted = $this->start($processing, [], 'generate');
        $this->assertSame("generating\n", $this->line($interrupted));
        $this->assertSame(AiTurnState::Processing, $turn->fresh()->state);
        $this->assertSame(1, $turn->fresh()->attempt_count);
        proc_terminate($this->workers[$interrupted]['process']);
        foreach ($this->workers[$interrupted]['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($this->workers[$interrupted]['process']);
        unset($this->workers[$interrupted]);
        $recovery = $this->start($processing, [], '', '2030-01-01 12:05:00 UTC');
        $this->assertSame('message', $this->finish($recovery)['kind']);
        $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(2, $turn->fresh()->attempt_count);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_concurrent_last_attempt_takeovers_and_finalization_claims_cannot_exceed_the_budget(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $turn = $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $coordinator->claimNext($this->business, 'web', 'sender');
        $this->travel(300)->seconds();
        $coordinator->claimNext($this->business, 'web', 'sender');
        $first = $this->start('claim', [], 'claim', '2030-01-01 12:10:00 UTC');
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start('claim', [], '', '2030-01-01 12:10:00 UTC');
        $this->assertBlocked($first, $second);
        $this->release($first);
        $this->assertSame('claim', $this->finish($first)['kind']);
        $this->assertSame(['kind' => 'outcome', 'name' => 'Busy'], $this->finish($second));
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->travelTo(Carbon::parse('2030-01-01 12:15:00 UTC'));
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $coordinator->claimNext($this->business, 'web', 'sender'));
        $first = $this->start('claim_finalization', [], 'claim', '2030-01-01 12:15:00 UTC');
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start('claim_finalization', [], '', '2030-01-01 12:15:00 UTC');
        $this->assertBlocked($first, $second);
        $this->release($first);
        $this->assertSame('claim', $this->finish($first)['kind']);
        $this->assertSame(['kind' => 'outcome', 'name' => 'Busy'], $this->finish($second));
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->assertSame(1, $turn->fresh()->diagnostics['finalization']['claim_count']);
        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public function test_killed_finalizer_is_recovered_without_another_model_attempt_or_duplicate_terminal_disposition(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $turn = $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $claim = $coordinator->claimNext($this->business, 'web', 'sender');
            $failed = $coordinator->fail($this->business, $claim);
            if ($failed->next_attempt_at !== null) {
                $this->travelTo($failed->next_attempt_at);
            }
        }
        $time = now()->toAtomString();
        $interrupted = $this->start('finalize', [], 'finalize', $time);
        $this->assertSame("resolving_failure\n", $this->line($interrupted));
        $old = $turn->fresh()->claim_token;
        $this->assertSame(AiTurnState::ResolvingFailure, $turn->fresh()->state);
        proc_terminate($this->workers[$interrupted]['process']);
        foreach ($this->workers[$interrupted]['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($this->workers[$interrupted]['process']);
        unset($this->workers[$interrupted]);
        $this->travel(300)->seconds();
        $current = $coordinator->claimFinalizationNext($this->business, 'web', 'sender');
        $this->assertNotSame($old, $current->token);
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $coordinator->complete($this->business,
            new AiTurnClaim($turn->id, $old, $turn->inboundMessage), 'obsolete'));
        $first = $this->start('terminate', $this->claimData($current), 'claim', now()->toAtomString());
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start('terminate', $this->claimData($current), '', now()->toAtomString());
        $this->assertBlocked($first, $second);
        $this->release($first);
        $this->assertSame('turn', $this->finish($first)['kind']);
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($second));
        $this->assertSame(AiTurnState::ClosedWithoutResponse, $turn->fresh()->state);
        $this->assertNotNull($turn->fresh()->terminal_at);
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public static function processingPaths(): array
    {
        return ['coordinator' => ['process'], 'SDK agent' => ['sdk_process']];
    }

    #[DataProvider('proposalRetries')]
    public function test_concurrent_proposal_preparation_is_idempotent_and_cannot_change_the_same_turn_payload(bool $different): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'Book');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $data = [...$this->claimData($claim), ...$this->proposalData()];
        $first = $this->start('prepare_proposal', $data, 'proposal');
        $this->assertSame("locked\n", $this->line($first));
        $this->assertDatabaseCount('appointment_proposals', 0);
        $retry = $data;
        if ($different) {
            $retry['payload']['starts_at'] = '2030-02-01T11:00:00+00:00';
            $retry['presentation']['starts_at'] = '2030-02-01T11:00:00+00:00';
            $retry['presentation']['ends_at'] = '2030-02-01T12:00:00+00:00';
        }
        $second = $this->start('prepare_proposal', $retry);
        $this->assertBlocked($first, $second);
        $this->release($first);
        $result = $this->finish($first);
        $this->assertSame('proposal', $result['kind']);
        $this->assertSame($different ? ['kind' => 'proposal_outcome', 'name' => 'ParametersConflict'] : $result, $this->finish($second));
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertSame($data['payload'], AppointmentProposal::sole()->canonicalPayload()->toArray());
    }

    public static function proposalRetries(): array
    {
        return ['same parameters' => [false], 'different parameters' => [true]];
    }

    public function test_confirmation_request_and_promotion_commit_together_under_overlapping_completions(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'Book');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $data = $this->proposalData();
        $proposal = app(AppointmentProposals::class)->prepare($this->business, $claim,
            AppointmentProposalPayload::fromArray(AppointmentProposalOperation::Create, $data['payload']),
            AppointmentProposalPresentation::fromArray($data['presentation']));
        $generated = 'La consulta cuesta 45 €. ¿Confirmas la cita con Laura para el 1 de febrero a las once?';
        $first = $this->start('complete', [...$this->claimData($claim), 'content' => $generated], 'promotion');
        $this->assertSame("locked\n", $this->line($first));
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->confirmation_request_message_id);
        $this->assertSame(AiTurnState::Processing, AiTurn::findOrFail($claim->turnId)->state);
        $this->assertDatabaseCount('conversation_messages', 1);
        $second = $this->start('complete', [...$this->claimData($claim), 'content' => '¿Quieres reservar esta otra propuesta?']);
        $this->assertBlocked($first, $second);
        $this->release($first);
        $result = $this->finish($first);
        $this->assertSame('message', $result['kind']);
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($second));
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertSame($result['id'], $proposal->fresh()->confirmation_request_message_id);
        $this->assertSame($result['id'], AiTurn::findOrFail($claim->turnId)->assistant_message_id);
        $this->assertSame($generated, ConversationMessage::findOrFail($result['id'])->content);
        $this->assertDatabaseCount('conversation_messages', 2);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_claim_takeover_prevents_stale_generator_from_promoting_prepared_proposal(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'Book');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $data = $this->proposalData();
        $proposal = app(AppointmentProposals::class)->prepare($this->business, $claim,
            AppointmentProposalPayload::fromArray(AppointmentProposalOperation::Create, $data['payload']),
            AppointmentProposalPresentation::fromArray($data['presentation']));
        $takeover = $this->start('claim', [], 'claim', '2030-01-01 12:05:00 UTC');
        $this->assertSame("locked\n", $this->line($takeover));
        $stale = $this->start('complete', $this->claimData($claim));
        $this->assertBlocked($takeover, $stale);
        $this->release($takeover);
        $newClaim = $this->finish($takeover);
        $this->assertSame('claim', $newClaim['kind']);
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($stale));
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->confirmation_request_message_id);
        $this->assertDatabaseCount('conversation_messages', 1);
        $current = $this->start('complete', ['turn' => $newClaim['id'], 'token' => $newClaim['token'], 'inbound' => $claim->inboundMessage->id]);
        $this->assertSame('message', $this->finish($current)['kind']);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    public function test_replacement_blocks_authorized_lookup_until_only_the_new_proposal_is_active(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'Book');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $data = $this->proposalData();
        $proposals = app(AppointmentProposals::class);
        $old = $proposals->prepare($this->business, $claim,
            AppointmentProposalPayload::fromArray(AppointmentProposalOperation::Create, $data['payload']),
            AppointmentProposalPresentation::fromArray($data['presentation']));
        $prompt = $coordinator->complete($this->business, $claim, 'Confirm?');
        $old->update(['state' => 'pending', 'confirmation_request_message_id' => $prompt->id]);
        $coordinator->inbound($this->business, 'web', 'sender', '2', 'Change the Appointment');
        $claim = $coordinator->claimNext($this->business, 'web', 'sender');
        $data = [...$data, ...$this->claimData($claim)];
        $replacement = $this->start('prepare_proposal', $data, 'proposal');
        $this->assertSame("locked\n", $this->line($replacement));
        $this->assertSame(AppointmentProposalState::Pending, $old->fresh()->state);
        $lookup = $this->start('active_proposal', $data);
        $this->assertBlocked($replacement, $lookup);
        $this->release($replacement);
        $new = $this->finish($replacement);
        $this->assertSame($new, $this->finish($lookup));
        $this->assertNotSame($old->id, $new['id']);
        $this->assertSame(AppointmentProposalState::Rejected, $old->fresh()->state);
        $this->assertDatabaseCount('appointment_proposals', 2);
        $this->assertSame(1, AppointmentProposal::query()->whereIn('state', ['prepared', 'pending'])->count());
    }

    #[DataProvider('proposalRetries')]
    public function test_postgresql_uniqueness_fences_concurrent_inserts_without_application_locking(bool $differentTurn): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $firstTurn = $coordinator->inbound($this->business, 'web', 'sender', '1', 'Book');
        $secondTurn = $coordinator->inbound($this->business, 'web', 'sender', '2', 'Another choice');
        $data = [...$this->proposalData(), 'conversation' => $firstTurn->inboundMessage->conversation_id, 'turn' => $firstTurn->id];
        $first = $this->start('insert_proposal', $data, 'proposal');
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start('insert_proposal', [...$data, 'turn' => $differentTurn ? $secondTurn->id : $firstTurn->id]);
        $this->assertBlocked($first, $second);
        $this->release($first);
        $this->assertSame('proposal', $this->finish($first)['kind']);
        $this->assertSame(['kind' => 'sqlstate', 'code' => '23505'], $this->finish($second));
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    /** @return array{payload: array, presentation: array} */
    private function proposalData(): array
    {
        $service = Service::factory()->for($this->business)->create();
        $professional = Professional::factory()->for($this->business)->create(['display_name' => 'Dra. Laura']);
        $start = CarbonImmutable::parse('2030-02-01 10:00:00 UTC');

        return [
            'payload' => AppointmentProposalPayload::create($service->id, $professional->id, $start)->toArray(),
            'presentation' => (new AppointmentProposalPresentation($service->name, $professional->display_name, $start, $start->addHour(), $this->business->timezone))->toArray(),
        ];
    }

    public function test_concurrent_confirmations_of_the_same_claim_return_one_committed_result_and_audit(): void
    {
        [$claim, $proposal, $appointment] = $this->confirmation();
        $first = $this->start('confirm_proposal', $this->claimData($claim), 'execution');
        $this->assertSame("locked\n", $this->line($first));
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertNull($proposal->fresh()->result);
        $second = $this->start('confirm_proposal', $this->claimData($claim));
        $this->assertBlocked($first, $second);
        $this->release($first);
        $result = $this->finish($first);
        $this->assertSame($result, $this->finish($second));
        $this->assertSame('cancelled', $result['outcome']);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_identity_reassignment_cannot_transfer_ownership_through_registration_waiting_on_execution(): void
    {
        [$claim, $proposal, $appointment] = $this->confirmation();
        $customerId = $appointment->customer_id;
        $first = $this->start('confirm_proposal', $this->claimData($claim), 'execution');
        $this->assertSame("locked\n", $this->line($first));
        $other = Customer::factory()->create();
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $other->id]);
        $registration = $this->start('register_customer', $this->claimData($claim));
        $this->assertBlocked($first, $registration);
        $this->release($first);

        $result = $this->finish($first);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($customerId, $result['customer_id']);
        $this->assertSame(['status' => 'identification_failed'], $this->finish($registration));
        $this->assertSame($customerId, $proposal->conversation->fresh()->customer_id);
        $this->assertSame($customerId, $appointment->fresh()->customer_id);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_claim_takeover_fences_a_confirmation_waiting_on_participant_ownership(): void
    {
        [$claim, $proposal] = $this->confirmation();
        $takeover = $this->start('claim_customer', [], 'claim', '2030-01-01 12:05:00 UTC');
        $this->assertSame("locked\n", $this->line($takeover));
        $stale = $this->start('confirm_proposal', $this->claimData($claim));
        $this->assertBlocked($takeover, $stale);
        $this->release($takeover);
        $new = $this->finish($takeover);
        $this->assertSame(['status' => 'not_authorized', 'reason' => 'StaleAttempt'], $this->finish($stale));
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertDatabaseCount('appointment_audits', 0);
        $current = $this->start('confirm_proposal', [
            'turn' => $new['id'], 'token' => $new['token'], 'inbound' => $claim->inboundMessage->id,
        ]);
        $this->assertSame('cancelled', $this->finish($current)['outcome']);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_already_authorized_confirmation_commits_before_takeover_and_is_recovered_without_reexecution(): void
    {
        [$claim, $proposal] = $this->confirmation();
        $first = $this->start('confirm_proposal', $this->claimData($claim), 'execution');
        $this->assertSame("locked\n", $this->line($first));
        $takeover = $this->start('claim_customer', [], '', '2030-01-01 12:05:00 UTC');
        $this->assertBlocked($first, $takeover);
        $this->release($first);
        $result = $this->finish($first);
        $new = $this->finish($takeover);
        $retry = $this->start('confirm_proposal', [
            'turn' => $new['id'], 'token' => $new['token'], 'inbound' => $claim->inboundMessage->id,
        ]);
        $this->assertSame($result, $this->finish($retry));
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_scheduler_recovers_a_terminated_worker_without_repeating_its_committed_cancellation(): void
    {
        [$claim, $proposal, $appointment] = $this->confirmation();
        $turns = app(AiTurnCoordinator::class);
        $failed = $turns->fail($this->business, $claim);
        $this->travelTo($failed->next_attempt_at);
        $worker = $this->start('queued_process', [
            'conversation' => $claim->inboundMessage->conversation_id,
            'cancel_turn' => $claim->turnId,
        ], 'generate', now()->toAtomString());
        $this->assertSame("generating\n", $this->line($worker));
        $stored = $proposal->fresh()->result;
        $this->assertSame('cancelled', $stored['outcome']);

        ['process' => $process, 'pipes' => $pipes] = $this->workers[$worker];
        $this->assertTrue(proc_terminate($process, 9));
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        unset($this->workers[$worker]);
        $interrupted = AiTurn::findOrFail($claim->turnId);
        $this->assertSame(AiTurnState::Processing, $interrupted->state);
        $this->assertSame(2, $interrupted->attempt_count);
        $this->assertNull($interrupted->assistant_message_id);

        Queue::fake([ProcessConversationTurn::class]);
        $this->travelTo($interrupted->claimed_until->copy()->subSecond());
        $this->artisan('schedule:run')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->travel(1)->second();
        $this->artisan('schedule:run')->assertSuccessful();
        $this->travel(1)->minute();
        $this->artisan('schedule:run')->assertSuccessful();
        Queue::assertPushedTimes(ProcessConversationTurn::class, 2);
        [$first, $duplicate] = Queue::pushed(ProcessConversationTurn::class)->all();
        $processor = app(ConversationTurnProcessor::class);
        ConversationAgent::fake(function () use ($interrupted, $stored, $duplicate): string {
            $current = $interrupted->fresh();
            $competitor = $this->start('queued_process', ['conversation' => $duplicate->conversationId], time: now()->toAtomString());
            $this->assertSame(['kind' => 'job'], $this->finish($competitor));
            $this->assertSame($current->claim_token, $interrupted->fresh()->claim_token);
            $retry = new AiTurnClaim($current->id, $current->claim_token, $current->inboundMessage);
            $this->assertSame($stored, app(ConfirmAppointmentProposal::class)->handle($this->business, $retry, true));

            return 'Tu cita fue cancelada.';
        })->preventStrayPrompts();

        $first->handle($processor);
        $duplicate->handle($processor);

        $this->assertSame(AiTurnState::Completed, $interrupted->refresh()->state);
        $this->assertSame(3, $interrupted->attempt_count);
        $this->assertSame('Tu cita fue cancelada.', $interrupted->assistantMessage->content);
        $this->assertDatabaseCount('conversation_messages', 4);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 1);
        $this->assertSame($stored, $proposal->fresh()->result);
        ConversationAgent::assertPromptedTimes(1);
    }

    public function test_scheduler_recovers_a_worker_killed_during_atomic_fallback_acceptance(): void
    {
        [$claim, $proposal, $appointment] = $this->confirmation();
        $stored = app(ConfirmAppointmentProposal::class)->handle($this->business, $claim, true);
        $this->assertSame('cancelled', $stored['outcome']);
        $turn = app(AiTurnCoordinator::class)->fail($this->business, $claim, AiTurnFailure::NonRecoverable, AiTurnFailureReason::GenerationFailed);
        $worker = $this->start('queued_process', ['conversation' => $claim->inboundMessage->conversation_id], 'after_insert', now()->toAtomString());
        $this->assertSame("locked\n", $this->line($worker));
        $this->assertSame(AiTurnState::ResolvingFailure, $turn->refresh()->state);
        $this->assertDatabaseCount('conversation_messages', 3);

        ['process' => $process, 'pipes' => $pipes] = $this->workers[$worker];
        $this->assertTrue(proc_terminate($process, 9));
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        unset($this->workers[$worker]);

        Queue::fake([ProcessConversationTurn::class]);
        ConversationAgent::fake([])->preventStrayPrompts();
        $this->travelTo($turn->claimed_until);
        $this->artisan('schedule:run')->assertSuccessful();
        $job = Queue::pushed(ProcessConversationTurn::class)->sole();
        $processor = app(ConversationTurnProcessor::class);
        $job->handle($processor);
        $job->handle($processor);

        $this->assertSame(AiTurnState::Completed, $turn->refresh()->state);
        $this->assertTrue($turn->fallback_used);
        $this->assertSame(1, $turn->attempt_count);
        $this->assertSame(2, $turn->diagnostics['finalization']['claim_count']);
        $this->assertSame(AiTurnFailureReason::GenerationFailed, $turn->failure_reason);
        $this->assertSame(AiTurnFailureReason::ClaimExpired->value, $turn->diagnostics['finalization']['last_failure']['reason']);
        $this->assertStringContainsString('Tu cita ha sido cancelada.', $turn->assistantMessage->content);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame($stored, $proposal->fresh()->result);
        $this->assertDatabaseCount('conversation_messages', 4);
        $this->assertDatabaseCount('appointment_audits', 1);
        ConversationAgent::assertNeverPrompted();
    }

    public function test_conversation_replacement_fences_a_waiting_confirmation(): void
    {
        [$claim, $proposal, $appointment] = $this->confirmation();
        $replacement = $this->start('inbound_customer', ['message' => 'new'], 'insert', '2030-01-02 12:00:00 UTC');
        $this->assertSame("locked\n", $this->line($replacement));
        $old = $this->start('confirm_proposal', $this->claimData($claim));
        $this->assertBlocked($replacement, $old);
        $this->release($replacement);
        $this->finish($replacement);
        $this->assertSame(['status' => 'not_authorized', 'reason' => 'Superseded'], $this->finish($old));
        $this->assertNull($proposal->fresh()->result);
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_independent_participant_can_execute_while_another_confirmation_is_uncommitted(): void
    {
        [$claim] = $this->confirmation();
        [$other] = $this->confirmation('other');
        $first = $this->start('confirm_proposal', $this->claimData($claim), 'execution');
        $this->assertSame("locked\n", $this->line($first));
        $independent = $this->start('confirm_proposal', $this->claimData($other));
        $this->assertSame('completed', $this->finish($independent)['status']);
        $this->assertDatabaseCount('appointment_audits', 1);
        $this->release($first);
        $this->assertSame('completed', $this->finish($first)['status']);
        $this->assertDatabaseCount('appointment_audits', 2);
    }

    public function test_real_postgresql_deadlock_rolls_back_one_entire_execution_and_allows_turn_retry(): void
    {
        [$claim, $firstProposal] = $this->confirmation();
        [$other, $otherProposal] = $this->confirmation('other');
        $first = $this->start('confirm_proposal', [...$this->claimData($claim), 'locks' => [912001, 912002]], 'deadlock');
        $this->assertSame("locked\n", $this->line($first));
        $second = $this->start('confirm_proposal', [...$this->claimData($other), 'locks' => [912002, 912001]], 'deadlock');
        $this->assertSame("locked\n", $this->line($second));
        $this->release($first);
        $this->release($second);
        $results = [$this->finish($first), $this->finish($second)];
        $failedIndex = isset($results[0]['technical_error']) ? 0 : 1;
        $this->assertSame(['technical_error' => '40P01'], $results[$failedIndex]);
        $this->assertSame('completed', $results[1 - $failedIndex]['status']);
        $proposal = [$firstProposal, $otherProposal][$failedIndex];
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->result);
        $this->assertDatabaseCount('appointment_audits', 1);
        $retry = $this->start('confirm_proposal', $this->claimData([$claim, $other][$failedIndex]));
        $this->assertSame('completed', $this->finish($retry)['status']);
        $this->assertDatabaseCount('appointment_audits', 2);
    }

    public function test_real_serializable_snapshot_conflict_leaves_confirmation_retryable_without_a_result(): void
    {
        [$claim, $proposal, $appointment] = $this->confirmation();
        $change = $this->start('change_appointment', [...$this->claimData($claim), 'appointment' => $appointment->id], 'write');
        $this->assertSame("locked\n", $this->line($change));
        $confirmation = $this->start('confirm_proposal', [...$this->claimData($claim), 'serializable' => true]);
        $this->assertBlocked($change, $confirmation);
        $this->release($change);
        $this->assertSame(['kind' => 'changed'], $this->finish($change));
        $this->assertSame(['technical_error' => '40001'], $this->finish($confirmation));
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->result);
        $this->assertDatabaseCount('appointment_audits', 0);
        $retry = $this->start('confirm_proposal', $this->claimData($claim));
        $this->assertSame('not_eligible', $this->finish($retry)['outcome']);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    /** @return array{AiTurnClaim, AppointmentProposal, Appointment} */
    private function confirmation(string $sender = 'sender'): array
    {
        $customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($customer)->create(['external_id' => $sender]);
        $service = Service::factory()->for($this->business)->create();
        $professional = Professional::factory()->for($this->business)->create();
        $appointment = Appointment::factory()->for($customer)->for($service)->for($professional)->create([
            'starts_at' => '2030-02-01 10:00 UTC', 'ends_at' => '2030-02-01 11:00 UTC', 'status' => 'confirmed',
        ]);
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'whatsapp', $sender, '1', 'Cancelar mi cita');
        $claim = $coordinator->claimNext($this->business, 'whatsapp', $sender);
        $result = app(PrepareAppointmentProposal::class)->handle($this->business, $claim, AppointmentProposalOperation::Cancel, ['appointment_id' => $appointment->id]);
        $this->assertSame('prepared', $result['status']);
        $coordinator->complete($this->business, $claim, '¿Confirmas la cancelación?');
        $coordinator->inbound($this->business, 'whatsapp', $sender, '2', 'Sí');

        return [
            $coordinator->claimNext($this->business, 'whatsapp', $sender),
            AppointmentProposal::findOrFail($result['proposal_id']), $appointment,
        ];
    }

    /** @return array{turn: int, token: string, inbound: int} */
    private function claimData(AiTurnClaim $claim): array
    {
        return ['turn' => $claim->turnId, 'token' => $claim->token, 'inbound' => $claim->inboundMessage->id];
    }

    private function start(string $action, array $data = [], string $barrier = '', string $time = '2030-01-01 12:00:00 UTC'): int
    {
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Illuminate\Support\Carbon::setTestNow($argv[5]);
            $pid = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid;
            fwrite(STDOUT, "pid:$pid\n");
            $wait = function (string $label): void {
                fwrite(STDOUT, "$label\n");
                if (trim(fgets(STDIN)) !== 'continue') { throw new RuntimeException('Barrier failed'); }
            };
            $barrier = $argv[4];
            if ($barrier === 'insert') {
                App\Models\ConversationMessage::creating(fn () => $wait('locked'));
            }
            if ($barrier === 'after_insert') {
                App\Models\ConversationMessage::created(fn () => $wait('locked'));
            }
            if ($barrier === 'claim') {
                App\Models\AiTurn::updating(fn () => $wait('locked'));
            }
            if ($barrier === 'registration') {
                App\Models\Conversation::updating(fn () => $wait('locked'));
            }
             if ($barrier === 'proposal') {
                 App\Models\AppointmentProposal::created(fn () => $wait('locked'));
             }
             if ($barrier === 'promotion') {
                  App\Models\AppointmentProposal::updated(fn () => $wait('locked'));
              }
            if ($barrier === 'execution') {
                App\Models\AppointmentAudit::created(fn () => $wait('locked'));
            }
            try {
                $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
                $business = App\Models\Business::findOrFail($data['business'] ?? $argv[1]);
                $sender = $data['sender'] ?? 'sender';
                $coordinator = app(App\Application\Conversation\AiTurnCoordinator::class);
                if (isset($data['max_attempts'])) {
                    config(['conversation.processing.max_attempts' => $data['max_attempts']]);
                }
                if ($data['serializable'] ?? false) {
                    Illuminate\Support\Facades\DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                }
                if ($barrier === 'deadlock') {
                    App\Models\AppointmentAudit::creating(function () use ($data, $wait): void {
                        Illuminate\Support\Facades\DB::select('select pg_advisory_xact_lock(?)', [$data['locks'][0]]);
                        $wait('locked');
                        Illuminate\Support\Facades\DB::select('select pg_advisory_xact_lock(?)', [$data['locks'][1]]);
                    });
                }
                if (in_array($argv[2], ['sdk_process', 'queued_process'], true)) {
                    config(['ai.default' => 'openai', 'ai.providers.openai.models.text.default' => 'test-model']);
                    $app->bind(Laravel\Ai\Contracts\ConversationStore::class, fn () => throw new RuntimeException('Unexpected SDK storage'));
                    App\Application\Conversation\ConversationAgent::fake(function () use ($barrier, $wait, $business, $data): string {
                        if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new RuntimeException('Open provider transaction'); }
                        if (isset($data['cancel_turn'])) {
                            $turn = App\Models\AiTurn::findOrFail($data['cancel_turn']);
                            $claim = new App\Application\Conversation\AiTurnClaim($turn->id, $turn->claim_token, $turn->inboundMessage);
                            $result = app(App\Application\Conversation\ConfirmAppointmentProposal::class)->handle($business, $claim, true);
                            if ($result['outcome'] !== 'cancelled') { throw new RuntimeException('Cancellation did not commit'); }
                        }
                        if ($barrier === 'generate') { $wait('generating'); }
                        return 'answer';
                    })->preventStrayPrompts();
                }
                $result = match ($argv[2]) {
                    'confirm_proposal' => app(App\Application\Conversation\ConfirmAppointmentProposal::class)->handle(
                        $business, new App\Application\Conversation\AiTurnClaim(
                            $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                        ), true,
                    ),
                    'change_appointment' => $coordinator->withOwnership($business, new App\Application\Conversation\AiTurnClaim(
                        $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                    ), function () use ($data, $wait): array {
                        App\Models\Appointment::findOrFail($data['appointment'])->update(['status' => 'cancelled']);
                        $wait('locked');
                        return ['kind' => 'changed'];
                    }),
                    'prepare_proposal' => app(App\Application\Conversation\AppointmentProposals::class)->prepare(
                        $business, new App\Application\Conversation\AiTurnClaim(
                            $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                        ), App\Application\Conversation\AppointmentProposalPayload::fromArray(
                            App\Application\Conversation\AppointmentProposalOperation::Create, $data['payload']
                        ), App\Application\Conversation\AppointmentProposalPresentation::fromArray($data['presentation']),
                    ),
                    'active_proposal' => app(App\Application\Conversation\AppointmentProposals::class)->active(
                        $business, new App\Application\Conversation\AiTurnClaim(
                            $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                        ),
                    ),
                    'insert_proposal' => (function () use ($data) {
                        try {
                            return Illuminate\Support\Facades\DB::transaction(fn () => App\Models\AppointmentProposal::query()->create([
                                'conversation_id' => $data['conversation'], 'originating_turn_id' => $data['turn'],
                                'operation' => 'create', 'payload' => $data['payload'], 'presentation' => $data['presentation'],
                            ]));
                        } catch (Illuminate\Database\QueryException $exception) {
                            return ['kind' => 'sqlstate', 'code' => $exception->errorInfo[0]];
                        }
                    })(),
                    'inbound' => $coordinator->inbound($business, 'web', $sender, $data['message'], 'hello'),
                    'inbound_customer' => $coordinator->inbound($business, 'whatsapp', $sender, $data['message'], 'hello'),
                    'claim' => $coordinator->claimNext($business, 'web', $sender),
                    'claim_finalization' => $coordinator->claimFinalizationNext($business, 'web', $sender),
                    'finalize' => (function () use ($coordinator, $business, $sender, $wait) {
                        $claim = $coordinator->claimFinalizationNext($business, 'web', $sender);
                        $wait('resolving_failure');
                        return $coordinator->terminate($business, $claim);
                    })(),
                    'claim_customer' => $coordinator->claimNext($business, 'whatsapp', $sender),
                    'register_customer' => (new App\Application\Conversation\RegisterCustomerTool(
                        $business, new App\Application\Conversation\AiTurnClaim(
                            $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                        ), $coordinator, new App\Application\Customer\ResolveCustomer,
                        new App\Application\Customer\RegisterCustomer, new App\Application\Conversation\ConversationHandler,
                    ))->handle(new Laravel\Ai\Tools\Request(['first_name' => 'Ana', 'last_name' => 'García'])),
                    'complete' => $coordinator->complete($business, new App\Application\Conversation\AiTurnClaim(
                        $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                      ), $data['content'] ?? 'answer'),
                    'fail' => $coordinator->fail($business, new App\Application\Conversation\AiTurnClaim(
                        $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                    )),
                    'terminate' => $coordinator->terminate($business, new App\Application\Conversation\AiTurnClaim(
                        $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                    )),
                    'write' => $coordinator->withOwnership($business, new App\Application\Conversation\AiTurnClaim(
                        $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                    ), function (App\Models\AiTurn $turn, App\Models\Conversation $conversation) use ($barrier, $wait): array {
                        if (Illuminate\Support\Facades\DB::transactionLevel() !== 1) { throw new RuntimeException('Write outside bounded transaction'); }
                        $previousCustomer = $conversation->customer_id;
                        $customer = App\Models\Customer::factory()->create();
                        $conversation->update(['customer_id' => $customer->id]);
                        if ($barrier === 'write') { $wait('locked'); }
                        return ['kind' => 'write', 'turn' => $turn->id, 'conversation' => $conversation->id, 'customer' => $customer->id, 'previous_customer' => $previousCustomer];
                    }),
                    'process' => $coordinator->processNext($business, 'web', $sender, function () use ($barrier, $wait): string {
                        if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new RuntimeException('Open provider transaction'); }
                        if ($barrier === 'generate') { $wait('generating'); }
                        return 'answer';
                    }),
                    'sdk_process' => app(App\Application\Conversation\ConversationTurnProcessor::class)->processNext($business, 'web', $sender),
                    'queued_process' => (function () use ($data): array {
                        (new App\Jobs\ProcessConversationTurn($data['conversation']))->handle(
                            app(App\Application\Conversation\ConversationTurnProcessor::class),
                        );
                        App\Application\Conversation\ConversationAgent::assertNeverPrompted();
                        return ['kind' => 'job'];
                    })(),
                };
                if ($argv[2] === 'sdk_process') {
                    if ($result instanceof App\Models\ConversationMessage) {
                        App\Application\Conversation\ConversationAgent::assertPromptedTimes(1);
                        App\Application\Conversation\ConversationAgent::assertPrompted('hello');
                    } else {
                        App\Application\Conversation\ConversationAgent::assertNeverPrompted();
                    }
                }
                $output = match (true) {
                    is_array($result) => $result,
                    is_string($result) => json_decode($result, true, flags: JSON_THROW_ON_ERROR),
                    $result instanceof App\Application\Conversation\AiTurnOutcome => ['kind' => 'outcome', 'name' => $result->name],
                    $result instanceof App\Application\Conversation\AppointmentProposalOutcome => ['kind' => 'proposal_outcome', 'name' => $result->name],
                    $result instanceof App\Models\AppointmentProposal => ['kind' => 'proposal', 'id' => $result->id],
                    $result instanceof App\Application\Conversation\AiTurnClaim => ['kind' => 'claim', 'id' => $result->turnId, 'token' => $result->token],
                    $result instanceof App\Models\AiTurn => ['kind' => 'turn', 'id' => $result->id],
                    $result instanceof App\Models\ConversationMessage => ['kind' => 'message', 'id' => $result->id],
                };
                fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR)."\n");
            } catch (Illuminate\Database\DeadlockException $exception) {
                if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new RuntimeException('Concurrency rollback left an open transaction'); }
                fwrite(STDOUT, json_encode(['technical_error' => $exception->getPrevious()->errorInfo[0]], JSON_THROW_ON_ERROR)."\n");
            } catch (Illuminate\Database\QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '40001' || Illuminate\Support\Facades\DB::transactionLevel() !== 0) {
                    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
                    exit(1);
                }
                fwrite(STDOUT, json_encode(['technical_error' => $exception->errorInfo[0]], JSON_THROW_ON_ERROR)."\n");
            } catch (Throwable $exception) {
                fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
                exit(1);
            }
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, (string) $this->business->id, $action, json_encode($data, JSON_THROW_ON_ERROR), $barrier, $time], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']));
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        $pidLine = fgets($pipes[1]);
        $this->assertMatchesRegularExpression('/^pid:\d+\n$/', $pidLine ?: '');
        $id = $this->nextWorker++;
        $this->workers[$id] = ['process' => $process, 'pipes' => $pipes, 'pid' => (int) substr($pidLine, 4)];

        return $id;
    }

    private function line(int $worker): string
    {
        return fgets($this->workers[$worker]['pipes'][1]) ?: '';
    }

    private function release(int $worker): void
    {
        fwrite($this->workers[$worker]['pipes'][0], "continue\n");
    }

    private function finish(int $worker): array
    {
        $line = $this->line($worker);
        ['process' => $process, 'pipes' => $pipes] = $this->workers[$worker];
        fclose($pipes[0]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        unset($this->workers[$worker]);
        $this->assertSame(0, $exit, $error);
        $this->assertNotSame('', $line, 'Worker did not return before the barrier was released.');

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertBlocked(int $owner, int $waiter): void
    {
        $deadline = microtime(true) + 10;
        do {
            $blocked = (int) DB::selectOne('select case when ? = any(pg_blocking_pids(?)) then 1 else 0 end as blocked', [$this->workers[$owner]['pid'], $this->workers[$waiter]['pid']])->blocked === 1;
            if (! $blocked) {
                usleep(10000);
            }
        } while (! $blocked && microtime(true) < $deadline);

        $this->assertTrue($blocked, 'Expected participant-scoped lock contention.');
    }
}
