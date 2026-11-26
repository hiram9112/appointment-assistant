<?php

namespace App\Application\Conversation;

final class AiTurnRetryPolicy
{
    public static function maxAttempts(): int
    {
        return max(1, (int) config('conversation.processing.max_attempts'));
    }

    public static function delay(int $attempt): int
    {
        $delays = self::delays();

        return $delays[min(max(0, $attempt - 1), count($delays) - 1)];
    }

    /** @return non-empty-list<int> Invalid configuration must not prevent recovery or failure recording. */
    public static function delays(): array
    {
        $delays = config('conversation.processing.retry_delays_seconds');
        if (! is_array($delays) || $delays === [] || ! array_is_list($delays)
            || array_filter($delays, fn (mixed $delay): bool => ! is_int($delay) || $delay < 1) !== []) {
            return [30, 120];
        }

        return $delays;
    }
}
