<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnState;
use App\Models\AiTurn;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    public function test_concurrent_registration_and_processing_preserve_idempotency_and_fifo(bool $duplicate): void
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

        $processing = $this->start('process', [], 'generate');
        $this->assertSame("generating\n", $this->line($processing));
        $otherDelivery = $this->start('inbound', ['message' => '3']);
        $this->assertSame('turn', $this->finish($otherDelivery)['kind']);
        $competing = $this->start('process');
        $this->assertSame(['kind' => 'outcome', 'name' => 'Busy'], $this->finish($competing));
        $this->release($processing);
        $this->assertSame('message', $this->finish($processing)['kind']);
        $this->assertSame(AiTurnState::Completed, AiTurn::findOrFail($firstResult['id'])->state);

        $next = app(AiTurnCoordinator::class)->claimNext($this->business, 'web', 'sender');
        $expected = $duplicate ? AiTurn::query()->orderByDesc('inbound_message_id')->firstOrFail()->id : $secondResult['id'];
        $this->assertSame($expected, $next->turnId);
        $this->assertSame(1, ConversationMessage::query()->where('role', 'assistant')->count());
    }

    public static function deliveries(): array
    {
        return ['duplicate' => [true], 'distinct' => [false]];
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

    public function test_takeover_fences_an_old_completion_waiting_on_the_participant_lock(): void
    {
        $coordinator = app(AiTurnCoordinator::class);
        $coordinator->inbound($this->business, 'web', 'sender', '1', 'hello');
        $old = $coordinator->claimNext($this->business, 'web', 'sender', 60);
        $takeover = $this->start('claim', [], 'claim', '2030-01-01 12:01:00 UTC');
        $this->assertSame("locked\n", $this->line($takeover));
        $stale = $this->start('complete', $this->claimData($old), '', '2030-01-01 12:01:00 UTC');
        $this->assertBlocked($takeover, $stale);
        $this->release($takeover);
        $new = $this->finish($takeover);
        $this->assertNotSame($old->token, $new['token']);
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($stale));
        $accepted = $this->start('complete', ['turn' => $new['id'], 'token' => $new['token'], 'inbound' => $old->inboundMessage->id]);
        $this->assertSame('message', $this->finish($accepted)['kind']);
        $this->assertSame(1, ConversationMessage::query()->where('role', 'assistant')->count());
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

    public function test_killed_provider_execution_is_durably_recoverable(): void
    {
        $turn = app(AiTurnCoordinator::class)->inbound($this->business, 'web', 'sender', '1', 'hello');
        $interrupted = $this->start('process', [], 'generate');
        $this->assertSame("generating\n", $this->line($interrupted));
        $this->assertSame(AiTurnState::Processing, $turn->fresh()->state);
        proc_terminate($this->workers[$interrupted]['process']);
        foreach ($this->workers[$interrupted]['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($this->workers[$interrupted]['process']);
        unset($this->workers[$interrupted]);
        $recovery = $this->start('process', [], '', '2030-01-01 12:05:00 UTC');
        $this->assertSame('message', $this->finish($recovery)['kind']);
        $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
        $this->assertDatabaseCount('conversation_messages', 2);
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
            if ($barrier === 'claim') {
                App\Models\AiTurn::updating(fn () => $wait('locked'));
            }
            try {
                $business = App\Models\Business::findOrFail($argv[1]);
                $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
                $sender = $data['sender'] ?? 'sender';
                $coordinator = app(App\Application\Conversation\AiTurnCoordinator::class);
                $result = match ($argv[2]) {
                    'inbound' => $coordinator->inbound($business, 'web', $sender, $data['message'], 'hello'),
                    'claim' => $coordinator->claimNext($business, 'web', $sender),
                    'complete' => $coordinator->complete($business, new App\Application\Conversation\AiTurnClaim(
                        $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                    ), 'answer'),
                    'process' => $coordinator->processNext($business, 'web', $sender, function () use ($barrier, $wait): string {
                        if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new RuntimeException('Open provider transaction'); }
                        if ($barrier === 'generate') { $wait('generating'); }
                        return 'answer';
                    }),
                };
                $output = match (true) {
                    $result instanceof App\Application\Conversation\AiTurnOutcome => ['kind' => 'outcome', 'name' => $result->name],
                    $result instanceof App\Application\Conversation\AiTurnClaim => ['kind' => 'claim', 'id' => $result->turnId, 'token' => $result->token],
                    $result instanceof App\Models\AiTurn => ['kind' => 'turn', 'id' => $result->id],
                    $result instanceof App\Models\ConversationMessage => ['kind' => 'message', 'id' => $result->id],
                };
                fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR)."\n");
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
