<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ConversationRetryConfigurationTest extends TestCase
{
    /**
     * @param  list<int>  $expectedDelays
     */
    #[DataProvider('retryTimingCases')]
    public function test_retry_timings_are_validated(
        ?string $delays,
        ?string $finalization,
        array $expectedDelays,
        int $expectedFinalization,
    ): void {
        foreach ([
            'CONVERSATION_RETRY_DELAYS_SECONDS' => $delays,
            'CONVERSATION_FINALIZATION_RETRY_SECONDS' => $finalization,
        ] as $key => $value) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($value === null ? $key : $key.'='.$value);
        }

        $configuration = require __DIR__.'/../../config/conversation.php';

        $this->assertSame($expectedDelays, $configuration['processing']['retry_delays_seconds']);
        $this->assertSame($expectedFinalization, $configuration['processing']['finalization_retry_seconds']);
    }

    /**
     * @return array<string, array{?string, ?string, list<int>, int}>
     */
    public static function retryTimingCases(): array
    {
        return [
            'missing' => [null, null, [30, 120], 120],
            'custom with whitespace' => ['10, 45, 90', ' 75 ', [10, 45, 90], 75],
            'single delay' => ['1', '1', [1], 1],
            'empty' => ['', '', [30, 120], 120],
            'zero' => ['10,0', '0', [30, 120], 120],
            'negative' => ['10,-60', '-60', [30, 120], 120],
            'fractional' => ['1.5,60', '1.5', [30, 120], 120],
            'malformed token falls back for the entire list' => ['10,nope,60', '60', [30, 120], 60],
            'empty token' => ['10,,60', null, [30, 120], 120],
            'invalid finalization leaves valid delays intact' => ['10,60', '10,60', [10, 60], 120],
            'boolean' => ['true', 'true', [30, 120], 120],
            'overflow' => [PHP_INT_MAX.'0', PHP_INT_MAX.'0', [30, 120], 120],
        ];
    }
}
