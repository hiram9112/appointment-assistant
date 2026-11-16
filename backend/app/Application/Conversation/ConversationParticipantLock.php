<?php

namespace App\Application\Conversation;

use Illuminate\Support\Facades\DB;

final class ConversationParticipantLock
{
    /** Must be acquired inside a transaction, before any participant row locks. */
    public function acquire(int $businessId, string $channel, string $senderIdentity): void
    {
        $participant = json_encode([(string) $businessId, $channel, $senderIdentity], JSON_THROW_ON_ERROR);
        DB::select('select pg_advisory_xact_lock(?)', [unpack('J', substr(hash('sha256', $participant, true), 0, 8))[1]]);
    }
}
