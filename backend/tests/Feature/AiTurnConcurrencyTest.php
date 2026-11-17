<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnState;
use App\Models\AiTurn;
use App\Models\Business;
use App\Models\ChannelIdentity;
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
        $this->assertSame(['kind' => 'outcome', 'name' => 'StaleAttempt'], $this->finish($stale));
        $this->assertDatabaseCount('customers', 0);
        $accepted = $this->start('complete', ['turn' => $new['id'], 'token' => $new['token'], 'inbound' => $old->inboundMessage->id]);
        $this->assertSame('message', $this->finish($accepted)['kind']);
        $this->assertSame(1, ConversationMessage::query()->where('role', 'assistant')->count());
    }

    public static function authorizedActions(): array
    {
        return ['response completion' => ['complete'], 'write callback' => ['write']];
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
        proc_terminate($this->workers[$interrupted]['process']);
        foreach ($this->workers[$interrupted]['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($this->workers[$interrupted]['process']);
        unset($this->workers[$interrupted]);
        $recovery = $this->start($processing, [], '', '2030-01-01 12:05:00 UTC');
        $this->assertSame('message', $this->finish($recovery)['kind']);
        $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public static function processingPaths(): array
    {
        return ['coordinator' => ['process'], 'SDK agent' => ['sdk_process']];
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
            if ($barrier === 'registration') {
                App\Models\Conversation::updating(fn () => $wait('locked'));
            }
            try {
                $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
                $business = App\Models\Business::findOrFail($data['business'] ?? $argv[1]);
                $sender = $data['sender'] ?? 'sender';
                $coordinator = app(App\Application\Conversation\AiTurnCoordinator::class);
                if ($argv[2] === 'sdk_process') {
                    config(['ai.default' => 'openai', 'ai.providers.openai.models.text.default' => 'test-model']);
                    $app->bind(Laravel\Ai\Contracts\ConversationStore::class, fn () => throw new RuntimeException('Unexpected SDK storage'));
                    App\Application\Conversation\ConversationAgent::fake(function () use ($barrier, $wait): string {
                        if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new RuntimeException('Open provider transaction'); }
                        if ($barrier === 'generate') { $wait('generating'); }
                        return 'answer';
                    })->preventStrayPrompts();
                }
                $result = match ($argv[2]) {
                    'inbound' => $coordinator->inbound($business, 'web', $sender, $data['message'], 'hello'),
                    'claim' => $coordinator->claimNext($business, 'web', $sender),
                    'claim_customer' => $coordinator->claimNext($business, 'whatsapp', $sender),
                    'register_customer' => (new App\Application\Conversation\RegisterCustomerTool(
                        $business, new App\Application\Conversation\AiTurnClaim(
                            $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                        ), $coordinator, new App\Application\Customer\ResolveCustomer,
                        new App\Application\Customer\RegisterCustomer, new App\Application\Conversation\ConversationHandler,
                    ))->handle(new Laravel\Ai\Tools\Request(['first_name' => 'Ana', 'last_name' => 'García'])),
                    'complete' => $coordinator->complete($business, new App\Application\Conversation\AiTurnClaim(
                        $data['turn'], $data['token'], App\Models\ConversationMessage::findOrFail($data['inbound'])
                    ), 'answer'),
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
