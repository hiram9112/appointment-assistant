<?php

use App\Application\Conversation\ReconcileConversationTurns;
use Illuminate\Support\Facades\Schedule;

Schedule::call(ReconcileConversationTurns::class)
    ->name('conversation:reconcile')
    ->everyMinute()
    ->withoutOverlapping(5);
