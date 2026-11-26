<?php

namespace Tests\Feature;

use App\Models\AppointmentProposal;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Evaluations\ConversationAgent\AppointmentOperationScenarios;
use Tests\Evaluations\ConversationAgent\AppointmentOperationsEvaluationRunner;
use Tests\Evaluations\ConversationAgent\ConversationAgentEvaluationRunner;
use Tests\Evaluations\ConversationAgent\EvaluationProviderGuard;
use Tests\TestCase;

class AppointmentOperationsEvaluationRunnerTest extends TestCase
{
    use DatabaseMigrations;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = storage_path('app/private/appointment-evaluation-test-'.Str::uuid().'.json');
        config([
            'ai.providers.openai.key' => 'synthetic-secret',
            'ai.providers.openai.url' => 'https://api.openai.com/v1',
            'ai.providers.openai.models.text.default' => 'gpt-4.1-mini',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->output, $this->output.'.tmp'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    #[DataProvider('scenarios')]
    public function test_scenario_contracts_use_real_sdk_tools_and_postgresql_with_fake_http(string $name): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, $name, 'synthetic-evaluation-sender');
        $script = $this->fakeScenario($scenario);
        $this->travelTo('2028-01-01 12:00:00 UTC');

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('completed_needs_review', $report['status'], json_encode($report['failure'] ?? array_map(fn (array $turn): array => $turn['checks'] ?? [], $report['turns'])));
        $this->assertSame('pending', $report['developer_review']);
        $this->assertCount(count($scenario['turns']), $report['turns']);
        foreach ($report['turns'] as $turn) {
            $this->assertSame('pass', $turn['contract_status']);
            $this->assertSame('unreviewed', $turn['developer_status']);
            $this->assertNotContains(false, $turn['checks'], json_encode($turn['checks']));
        }
        Http::assertSentCount($script);
        $this->assertSame($script, $report['usage']['http_requests']);
        $this->assertSame('2028-01-01 12:00:00', now()->utc()->format('Y-m-d H:i:s'));
        $contents = file_get_contents($this->output);
        $this->assertStringNotContainsString('synthetic-secret', $contents);
        $this->assertStringContainsString('[REDACTED]', $contents);
        $this->assertSame(0600, fileperms($this->output) & 0777);
        $this->assertSame('completed_needs_review', json_decode($contents, true, flags: JSON_THROW_ON_ERROR)['status']);
        foreach (Http::recorded() as [$request]) {
            $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1024, $body['max_output_tokens']);
            $this->assertFalse($body['store']);
            $this->assertArrayNotHasKey('previous_response_id', $body);
        }
    }

    public static function scenarios(): array
    {
        return array_combine(array_keys(AppointmentOperationScenarios::catalog()), array_map(fn (string $name): array => [$name], array_keys(AppointmentOperationScenarios::catalog())));
    }

    #[DataProvider('unchangedRequestResponses')]
    public function test_unchanged_request_contract_requires_verification_not_a_manufactured_reschedule(string $response, string $status): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'unchanged-reschedule', 'synthetic-sender');
        $this->assertCount(1, $scenario['turns']);
        $this->assertSame('none', $scenario['turns'][0]['effect']);
        $this->assertSame([['name' => 'GetCustomerAppointmentsTool', 'arguments' => []]], $scenario['turns'][0]['calls']);
        $scripted = $scenario;
        if ($response === 'unverified') {
            $scripted['turns'][0]['calls'] = [];
        } elseif ($response === 'prepare') {
            $scripted['turns'][0]['calls'][] = ['name' => 'PrepareRescheduleAppointmentTool', 'arguments' => [
                'appointment_id' => $scenario['fixture']['target_appointment_ids'][0],
                'starts_at' => '2026-11-11T12:00:00+01:00',
            ]];
        }
        $count = $this->fakeScenario($scripted);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame($status, $report['status']);
        $turn = $report['turns'][0];
        $this->assertTrue($turn['checks']['no_domain_mutation']);
        $this->assertSame($turn['before']['appointments'], $turn['after']['appointments']);
        $this->assertSame($response !== 'unverified', $turn['checks']['required_reads']);
        $this->assertSame($response !== 'prepare', $turn['checks']['expected_write_contract']);
        $this->assertSame($response !== 'prepare', $turn['checks']['no_proposal_change']);
        $this->assertDatabaseCount('appointment_proposals', $response === 'prepare' ? 1 : 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertSame('pending', $report['developer_review']);
        Http::assertSentCount($count);
    }

    public static function unchangedRequestResponses(): array
    {
        return [
            'verified acknowledgement' => ['read', 'completed_needs_review'],
            'unverified acknowledgement' => ['unverified', 'contract_failures'],
            'unnecessary proposal' => ['prepare', 'contract_failures'],
        ];
    }

    public function test_missing_preparation_stops_dependent_turns_without_claiming_semantic_acceptance(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'mixed-intent', 'synthetic-sender');
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response(1))]);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('contract_failures', $report['status']);
        $this->assertCount(1, $report['turns']);
        $this->assertFalse($report['turns'][0]['checks']['canonical_proposal']);
        $this->assertSame('pending', $report['developer_review']);
        $this->assertDatabaseCount('appointments', 8);
        $this->assertDatabaseCount('appointment_audits', 0);
        Http::assertSentCount(1);
    }

    public function test_fixture_failure_precedes_provider_calls_and_keeps_private_partial_evidence(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'mixed-intent', 'synthetic-sender');
        $business->professionals()->where('first_name', 'Laura')->sole()->availabilityIntervals()->delete();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response(1))]);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('stopped', $report['status']);
        $this->assertSame('evaluation_fixture_mismatch', $report['failure']['category']);
        $this->assertArrayNotHasKey('http_status', $report['failure']);
        $this->assertArrayNotHasKey('provider_error', $report['failure']);
        $this->assertSame([], $report['turns']);
        $this->assertFileExists($this->output);
        Http::assertNothingSent();
    }

    public function test_denied_premature_and_repeated_confirmations_cannot_create_duplicate_writes(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'premature-reply', 'synthetic-sender');
        $scenario['turns'][] = ['prompt' => 'Sí, otra vez.', 'calls' => [], 'effect' => 'none'];
        $scripted = $scenario;
        foreach ([0, 1, 3] as $index) {
            $scripted['turns'][$index]['calls'][] = ['name' => 'ConfirmAppointmentProposalTool', 'arguments' => ['decision' => 'confirm']];
        }
        $count = $this->fakeScenario($scripted);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('completed_needs_review', $report['status']);
        $this->assertSame('confirmation_required', $report['turns'][0]['tools'][1]['result']['status']);
        $this->assertSame('confirmation_required', $report['turns'][1]['tools'][0]['result']['status']);
        $this->assertSame('no_pending_proposal', $report['turns'][3]['tools'][0]['result']['status']);
        $this->assertDatabaseCount('appointments', 9);
        $this->assertDatabaseCount('appointment_audits', 1);
        Http::assertSentCount($count);
    }

    public function test_explicit_existing_professional_is_equivalent_to_retaining_the_current_professional(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'registered-lifecycle', 'synthetic-sender');
        $scripted = $scenario;
        $scripted['turns'][4]['calls'][0]['arguments']['professional_id'] = $business->professionals()->where('first_name', 'Laura')->sole()->id;
        $count = $this->fakeScenario($scripted);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('completed_needs_review', $report['status']);
        $this->assertTrue($report['turns'][4]['checks']['expected_write_contract']);
        $this->assertSame('Laura Martín', $report['turns'][4]['tools'][0]['result']['presentation']['professional_name']);
        $this->assertDatabaseCount('appointment_audits', 4);
        Http::assertSentCount($count);
    }

    public function test_real_provider_errors_are_not_mistaken_for_injected_recovery_faults_or_retried(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'prepared-provider-recovery', 'synthetic-sender');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => [
            'type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'synthetic-secret',
        ]], 429, ['Retry-After' => '1'])]);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('stopped', $report['status']);
        $this->assertSame('technical_or_provider_failure', $report['failure']['category']);
        $this->assertSame(429, $report['failure']['http_status']);
        $this->assertSame(['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded'], $report['failure']['provider_error']);
        $this->assertCount(1, $report['turns']);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertStringNotContainsString('synthetic-secret', file_get_contents($this->output));
        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
    }

    #[DataProvider('providerErrors')]
    public function test_first_request_failure_records_only_safe_provider_diagnostics(array|string $body, array $expected): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'mixed-intent', 'synthetic-sender');
        if (is_array($body)) {
            $body['error']['message'] = 'private-provider-message synthetic-secret';
            $body['error']['param'] = 'private-provider-param';
            $body['debug'] = 'private-response-debug';
        }
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($body, 400, ['X-Private-Diagnostic' => 'private-response-header'])]);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('stopped', $report['status']);
        $failure = ['exception_class' => RequestException::class, 'category' => 'technical_or_provider_failure', 'http_status' => 400];
        if ($expected !== []) {
            $failure['provider_error'] = $expected;
        }
        $this->assertSame($failure, $report['failure']);
        $this->assertSame(1, $report['usage']['http_requests']);
        $this->assertCount(1, $report['turns']);
        $this->assertSame([], $report['turns'][0]['tools']);
        $this->assertSame([], $report['turns'][0]['steps']);
        $this->assertSame('fail', $report['turns'][0]['contract_status']);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertDatabaseCount('appointments', 8);
        $contents = file_get_contents($this->output);
        $this->assertSame($failure, json_decode($contents, true, flags: JSON_THROW_ON_ERROR)['failure']);
        foreach (['synthetic-secret', 'private-', 'X-Private-Diagnostic', 'Authorization', 'Bearer'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $contents);
            $this->assertStringNotContainsString($sensitive, json_encode($report, JSON_THROW_ON_ERROR));
        }
        Http::assertSentCount(1);
    }

    public static function providerErrors(): array
    {
        return [
            'structured machine identifiers' => [['error' => ['type' => 'invalid_request_error', 'code' => 'unsupported_parameter']], ['type' => 'invalid_request_error', 'code' => 'unsupported_parameter']],
            'missing code' => [['error' => ['type' => 'invalid_request_error']], ['type' => 'invalid_request_error']],
            'null type' => [['error' => ['type' => null, 'code' => 'invalid_value']], ['code' => 'invalid_value']],
            'null code' => [['error' => ['type' => 'invalid_request_error', 'code' => null]], ['type' => 'invalid_request_error']],
            'non-string fields' => [['error' => ['type' => ['private-type'], 'code' => 123]], []],
            'unsafe characters' => [['error' => ['type' => "private-type\n", 'code' => 'Bearer private-token']], []],
            'oversized identifiers' => [['error' => ['type' => str_repeat('x', 81), 'code' => str_repeat('y', 81)]], []],
            'credential in identifier' => [['error' => ['type' => 'synthetic-secret', 'code' => 'prefix-synthetic-secret-suffix']], []],
            'non-JSON response' => ['<html>private-response-body</html>', []],
            'malformed JSON response' => ['{"error": private-response-body', []],
        ];
    }

    public function test_wrapped_http_failures_keep_status_and_safe_codes_without_exception_messages(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenario = AppointmentOperationScenarios::make($business, 'mixed-intent', 'synthetic-sender');
        $attempts = 0;
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => function () use (&$attempts): never {
            $attempts++;
            throw new \RuntimeException('private-wrapper synthetic-secret', previous: Http::failedRequest([
                'error' => ['type' => 'authentication_error', 'code' => 'invalid_api_key', 'message' => 'private-provider-message'],
            ], 401));
        }]);

        $report = (new AppointmentOperationsEvaluationRunner($business, $this->output))->run($scenario);

        $this->assertSame('stopped', $report['status']);
        $this->assertSame([
            'exception_class' => \RuntimeException::class, 'category' => 'technical_or_provider_failure',
            'http_status' => 401, 'provider_error' => ['type' => 'authentication_error', 'code' => 'invalid_api_key'],
        ], $report['failure']);
        $this->assertSame(1, $attempts);
        $this->assertSame(1, $report['usage']['http_requests']);
        $this->assertSame([], $report['turns'][0]['tools']);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertStringNotContainsString('private-', file_get_contents($this->output));
        $this->assertStringNotContainsString('synthetic-secret', file_get_contents($this->output));
        Http::assertNothingSent();
    }

    public function test_cli_help_and_incomplete_opt_in_never_bootstrap_or_reset_a_database(): void
    {
        $script = base_path('tests/Evaluations/ConversationAgent/run-appointment-operations.php');
        foreach ([
            [['--help'], 0], [[], 1], [['--live'], 1], [['--reset-evaluation-database'], 1],
            [['--live', '--reset-evaluation-database'], 1],
            [['--live', '--reset-smoke-database', '--scenario=mixed-intent'], 1],
            [['--live', '--reset-evaluation-database', '--scenario=../bad'], 1],
        ] as [$arguments, $exit]) {
            $process = new Process([PHP_BINARY, '-d', 'open_basedir='.dirname($script), $script, ...$arguments]);
            $process->run();
            $this->assertSame($exit, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('Developer-only', $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
        }
    }

    public function test_cli_unknown_scenario_is_rejected_before_bootstrap_with_no_live_execution(): void
    {
        $process = new Process([PHP_BINARY, base_path('tests/Evaluations/ConversationAgent/run-appointment-operations.php'),
            '--live', '--reset-evaluation-database', '--scenario=not-a-scenario']);

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('No bootstrap, database reset or provider call', $process->getErrorOutput());
    }

    #[DataProvider('unsafeDatabases')]
    public function test_reset_guard_rejects_unsafe_targets_before_changing_the_connection(string $database, string $environment, string $host): void
    {
        $this->app->instance('env', $environment);
        config(['database.connections.pgsql.host' => $host]);
        $before = config('database.default');

        try {
            ConversationAgentEvaluationRunner::configureIsolatedDatabase($database);
            $this->fail('Unsafe reset configuration must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('smoke_database_guard', $exception->getMessage());
            $this->assertSame($before, config('database.default'));
        }
    }

    public static function unsafeDatabases(): array
    {
        return [
            'ordinary database' => ['appointment_assistant', 'local', '127.0.0.1'],
            'testing environment' => [AppointmentOperationsEvaluationRunner::Database, 'testing', '127.0.0.1'],
            'remote host' => [AppointmentOperationsEvaluationRunner::Database, 'local', 'remote.example.test'],
        ];
    }

    #[DataProvider('budgets')]
    public function test_shared_guard_blocks_retries_and_each_usage_ceiling_before_an_extra_request(string $budget, array $limits, int $tokens, bool $identical): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['ok' => true])]);
        $guard = new EvaluationProviderGuard([...AppointmentOperationsEvaluationRunner::Limits, ...$limits]);
        $guard->install();
        Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-4.1-mini', 'input' => 'one']);
        $guard->recordTokens($tokens);

        try {
            Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-4.1-mini', 'input' => $identical ? 'one' : 'two']);
            $this->fail('The next request must be blocked by '.$budget);
        } catch (\RuntimeException $exception) {
            $this->assertSame($identical ? 'smoke_retry_blocked' : 'smoke_budget_exceeded', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame(1, $guard->usage()['http_requests']);
    }

    public static function budgets(): array
    {
        return [
            'total requests' => ['total requests', ['http_requests' => 1], 0, false],
            'requests per attempt' => ['per attempt', ['requests_per_turn' => 1], 0, false],
            'total bytes' => ['total bytes', ['total_request_bytes' => 150], 0, false],
            'reported tokens' => ['reported tokens', ['reported_tokens' => 10], 10, false],
            'identical retry' => ['identical retry', [], 0, true],
        ];
    }

    public function test_explicit_recovery_resets_only_attempt_allowance_not_cumulative_budget(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['ok' => true])]);
        $guard = new EvaluationProviderGuard([...AppointmentOperationsEvaluationRunner::Limits, 'http_requests' => 2, 'requests_per_turn' => 1]);
        $guard->install();
        Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-6-luna', 'input' => 'same request']);
        $guard->recordTokens(20);
        $guard->beginAttempt();
        Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-6-luna', 'input' => 'same request']);
        $guard->beginAttempt();

        try {
            Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-6-luna', 'input' => 'third attempt']);
            $this->fail('Recovery must not reset the cumulative budget.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('smoke_budget_exceeded', $exception->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertSame(2, $guard->usage()['http_requests']);
        $this->assertSame(20, $guard->usage()['reported_tokens']);
        foreach (Http::recorded() as [$request]) {
            $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(4096, $body['max_output_tokens']);
            $this->assertFalse($body['store']);
        }
    }

    /** Return scripted provider responses while leaving SDK orchestration and all domain writes real. */
    private function fakeScenario(array $scenario): int
    {
        $script = [];
        foreach ($scenario['turns'] as $turn) {
            foreach ($turn['calls'] as $call) {
                $script[] = $call;
            }
            if (! in_array($turn['interrupt'] ?? null, ['after_preparation', 'after_execution'], true)) {
                $script[] = null;
            }
        }
        $count = count($script);
        $number = 0;
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => function (Request $request) use (&$script, &$number) {
            $this->assertNotSame([], $script, 'Unexpected additional provider request.');
            $call = array_shift($script);
            if (($call['arguments']['appointment_id'] ?? null) === '@created') {
                $call['arguments']['appointment_id'] = AppointmentProposal::query()->where('operation', 'create')->where('state', 'completed')->latest('id')->firstOrFail()->result['appointment']['id'];
            }

            return Http::response($this->response(++$number, $call));
        }]);

        return $count;
    }

    private function response(int $number, ?array $call = null): array
    {
        return [
            'id' => 'resp_'.$number, 'status' => 'completed', 'model' => 'gpt-4.1-mini',
            'output' => $call === null ? [[
                'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Unmodified synthetic model response synthetic-secret', 'annotations' => []]],
            ]] : [[
                'type' => 'function_call', 'id' => 'fc_'.$number, 'call_id' => 'call_'.$number,
                'name' => $call['name'], 'arguments' => json_encode($call['arguments'], JSON_THROW_ON_ERROR),
            ]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }
}
