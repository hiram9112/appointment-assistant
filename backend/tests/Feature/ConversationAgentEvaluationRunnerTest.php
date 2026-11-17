<?php

namespace Tests\Feature;

use App\Models\AiTurn;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Evaluations\ConversationAgent\ConversationAgentEvaluationRunner;
use Tests\TestCase;

class ConversationAgentEvaluationRunnerTest extends TestCase
{
    use DatabaseMigrations;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = storage_path('app/private/s11-smoke-test-'.Str::uuid().'.json');
        config([
            'ai.providers.openai.key' => 'synthetic-secret-must-not-appear',
            'ai.providers.openai.url' => 'https://api.openai.com/v1',
            'ai.providers.openai.models.text.default' => 'gpt-4.1-mini',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->output)) {
            unlink($this->output);
        }
        parent::tearDown();
    }

    public function test_standalone_cli_help_and_invalid_arguments_exit_before_bootstrap(): void
    {
        $script = base_path('tests/Evaluations/ConversationAgent/run-live.php');
        foreach ([
            [['--help'], 0], [[], 1], [['--live'], 1], [['--reset-smoke-database'], 1],
            [['--help', '--live'], 1], [['--unknown'], 1],
        ] as [$arguments, $expectedExit]) {
            $process = new Process([PHP_BINARY, '-d', 'open_basedir='.dirname($script), $script, ...$arguments]);
            $process->run();
            $this->assertSame($expectedExit, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('php backend/tests/Evaluations/ConversationAgent/run-live.php --live --reset-smoke-database', $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
        }
        $this->assertTrue(class_exists(ConversationAgentEvaluationRunner::class));
    }

    public function test_all_scenarios_run_through_real_sdk_and_s03_with_fake_http_and_private_reviewable_evidence(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenarios = ConversationAgentEvaluationRunner::scenarios($business);
        $number = $this->fakeScenarioResponses($scenarios);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        $this->assertSame('completed_needs_review', $report['status']);
        $this->assertSame('pending', $report['developer_review']);
        $this->assertCount(12, $report['scenarios']);
        $this->assertSame(array_fill(0, 12, 'pass'), array_column($report['scenarios'], 'contract_status'));
        $this->assertSame(array_fill(0, 12, 'unreviewed'), array_column($report['scenarios'], 'developer_status'));
        $this->assertSame(22, array_sum(array_map(fn (array $scenario): int => count($scenario['turns']), $report['scenarios'])));
        $this->assertSame($number, $report['usage']['http_requests']);
        Http::assertSentCount($number);
        $requests = Http::recorded();
        foreach ($requests as [$request]) {
            $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(512, $body['max_output_tokens']);
            $this->assertFalse($body['store']);
        }
        $this->assertStringContainsString($scenarios[3]['turns'][0]['prompt'], $requests[0][0]->body());
        $this->assertStringContainsString($scenarios[4]['turns'][0]['prompt'], $requests[1][0]->body());
        $this->assertSame('ok', $report['scenarios'][4]['turns'][0]['tools'][0]['result']['status']);
        $this->assertTrue($report['scenarios'][4]['turns'][0]['tools'][0]['matches_s03']);
        $this->assertCount(4, $report['scenarios'][2]['turns'][2]['history']);
        $this->assertSame('Alex', $report['scenarios'][2]['turns'][2]['tools'][0]['result']['requested_professional']['display_name']);
        $this->assertSame('55.00', collect($report['scenarios'][11]['turns'][0]['runtime_context']['services'])->firstWhere('name', 'Deep facial cleansing')['price_amount']);
        $this->assertSame('59.90', collect($report['scenarios'][11]['turns'][1]['runtime_context']['services'])->firstWhere('name', 'Deep facial cleansing')['price_amount']);
        $this->assertSame('hola@lumina-demo.test', $business->fresh()->email);
        $this->assertNull($business->professionals()->where('first_name', 'Laura')->sole()->display_name);
        $this->assertSame('55.00', $business->services()->where('name', 'Deep facial cleansing')->sole()->price_amount);
        $this->assertDatabaseCount('appointments', 8);
        $this->assertDatabaseCount('conversation_messages', 44);
        $this->assertDatabaseCount('ai_turns', 22);
        $contents = file_get_contents($this->output);
        $this->assertStringNotContainsString('synthetic-secret-must-not-appear', $contents);
        $this->assertStringContainsString('[REDACTED]', $contents);
        $this->assertSame(0600, fileperms($this->output) & 0777);
        $this->assertSame('completed_needs_review', json_decode($contents, true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    #[DataProvider('additionalScenarioOutcomes')]
    public function test_additional_scenarios_execute_after_existing_scenarios_and_control_completion(bool $truncate): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenarios = ConversationAgentEvaluationRunner::scenarios($business);
        $scenarios[13] = [
            'name' => 'Additional scenario', 'review' => 'Review the answer.',
            'turns' => [['prompt' => 'Additional question?', 'arguments' => null]],
        ];
        $number = $this->fakeScenarioResponses($scenarios, $truncate);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run($scenarios);

        $this->assertCount(13, $report['scenarios']);
        $this->assertSame($truncate ? 'fail' : 'pass', $report['scenarios'][13]['contract_status']);
        $this->assertSame($truncate ? 'contract_failures' : 'completed_needs_review', $report['status']);
        $this->assertSame('Additional question?', $report['scenarios'][13]['turns'][0]['prompt']);
        Http::assertSentCount($number);
        $requests = Http::recorded();
        $index = 0;
        foreach ([3, 4, ...array_diff(array_keys($scenarios), [3, 4])] as $id) {
            foreach ($scenarios[$id]['turns'] as $turn) {
                $this->assertStringContainsString($turn['prompt'], $requests[$index][0]->body());
                $index += $turn['arguments'] === null ? 1 : 2;
            }
        }
    }

    public static function additionalScenarioOutcomes(): array
    {
        return ['passing' => [false], 'truncated' => [true]];
    }

    public function test_scenario_without_turns_cannot_be_reported_as_passed(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $scenarios = ConversationAgentEvaluationRunner::scenarios($business);
        $scenarios[13] = ['name' => 'Empty scenario', 'review' => 'Review the answer.', 'turns' => []];
        $number = $this->fakeScenarioResponses($scenarios);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run($scenarios);

        Http::assertSentCount($number);
        $this->assertSame('fail', $report['scenarios'][13]['contract_status']);
        $this->assertSame('contract_failures', $report['status']);
    }

    public function test_luna_responses_use_bounded_reasoning_budget_and_replay_tool_results_without_sdk_storage(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        config(['ai.providers.openai.models.text.default' => 'gpt-6-luna']);
        $turn = ConversationAgentEvaluationRunner::scenarios($business)[4]['turns'][0];
        $descriptive = $this->response(1);
        $descriptive['model'] = 'gpt-6-luna';
        $toolCall = $this->response(2, $turn['arguments']);
        $toolCall['model'] = 'gpt-6-luna';
        array_unshift($toolCall['output'], [
            'id' => 'rs_synthetic', 'type' => 'reasoning', 'summary' => [],
            'encrypted_content' => 'synthetic-encrypted-reasoning',
        ]);
        $answer = $this->response(3);
        $answer['model'] = 'gpt-6-luna';
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push($descriptive)->push($toolCall)->push($answer)
            ->push(['error' => ['type' => 'rate_limit_error']], 429)]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        Http::assertSentCount(4);
        $this->assertSame('gpt-6-luna', $report['model']);
        $this->assertSame('pass', $report['scenarios'][4]['contract_status']);
        $this->assertTrue($report['scenarios'][4]['turns'][0]['tools'][0]['matches_s03']);
        $requests = Http::recorded();
        foreach ($requests as [$request]) {
            $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('gpt-6-luna', $body['model']);
            $this->assertSame(2048, $body['max_output_tokens']);
            $this->assertFalse($body['store']);
            $this->assertContains('reasoning.encrypted_content', $body['include']);
            $this->assertArrayNotHasKey('reasoning', $body);
        }
        $first = json_decode($requests[1][0]->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('CheckAvailabilityTool', $first['tools'][0]['name']);
        $continuation = json_decode($requests[2][0]->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('synthetic-encrypted-reasoning', array_column($continuation['input'], 'encrypted_content'));
        $this->assertContains('function_call', array_column($continuation['input'], 'type'));
        $this->assertContains('function_call_output', array_column($continuation['input'], 'type'));
        $this->assertArrayNotHasKey('previous_response_id', $continuation);
    }

    public function test_luna_output_limit_truncation_fails_the_initial_probe(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        config(['ai.providers.openai.models.text.default' => 'gpt-6-luna']);
        $response = $this->response(1);
        $response['model'] = 'gpt-6-luna';
        $response['status'] = 'incomplete';
        $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
        $response['usage']['output_tokens_details'] = ['reasoning_tokens' => 1900];
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($response)]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        Http::assertSentCount(1);
        $this->assertSame('stopped_at_integration_gate', $report['status']);
        $this->assertSame('fail', $report['scenarios'][3]['contract_status']);
        $this->assertSame('length', $report['scenarios'][3]['turns'][0]['steps'][0]['finish_reason']);
        $this->assertSame(1900, $report['scenarios'][3]['turns'][0]['steps'][0]['usage']['reasoning_tokens']);
        $this->assertFalse($report['scenarios'][3]['turns'][0]['checks']['not_truncated']);
        $this->assertSame('not_run', $report['scenarios'][4]['contract_status']);
    }

    public function test_failed_initial_descriptive_contract_stops_before_availability_or_later_scenarios(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()->push($this->response(1, [
            'service_id' => $business->services()->where('name', 'Deep facial cleansing')->sole()->id,
            'professional_id' => null, 'from' => '2026-11-09T12:30:00+01:00', 'to' => '2026-11-09T12:30:00+01:00',
        ]))->push($this->response(2))]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        $this->assertSame('stopped_at_integration_gate', $report['status']);
        $this->assertSame('fail', $report['scenarios'][3]['contract_status']);
        $this->assertSame('not_run', $report['scenarios'][4]['contract_status']);
        Http::assertSentCount(2);
    }

    public function test_provider_failure_never_retries_even_with_short_retry_after_and_keeps_partial_evidence(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'error' => ['message' => 'synthetic-secret-must-not-appear', 'type' => 'rate_limit_error'],
        ], 429, ['Retry-After' => '1'])]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertSame('stopped', $report['status']);
        $this->assertSame('technical_or_provider_failure', $report['failure']['category']);
        $this->assertSame(429, $report['failure']['http_status']);
        $this->assertSame('rate_limit_error', $report['failure']['provider_error']['type']);
        $this->assertSame('fail', $report['scenarios'][3]['contract_status']);
        $this->assertSame('not_run', $report['scenarios'][4]['contract_status']);
        $this->assertStringNotContainsString('synthetic-secret-must-not-appear', file_get_contents($this->output));
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertNull(AiTurn::query()->sole()->assistant_message_id);
        $this->assertSame(0, ConversationMessage::query()->where('role', 'assistant')->count());
    }

    public function test_missing_tool_in_initial_availability_probe_stops_the_remaining_scenarios(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()->push($this->response(1))->push($this->response(2))]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        Http::assertSentCount(2);
        $this->assertSame('stopped_at_integration_gate', $report['status']);
        $this->assertSame('pass', $report['scenarios'][3]['contract_status']);
        $this->assertSame('fail', $report['scenarios'][4]['contract_status']);
        $this->assertSame('not_run', $report['scenarios'][1]['contract_status']);
    }

    public function test_unapproved_endpoint_is_blocked_before_credentials_can_leave_the_process(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        config(['ai.providers.openai.url' => 'https://not-openai.example.test/v1']);
        Http::preventStrayRequests();
        Http::fake(['https://not-openai.example.test/v1/responses' => Http::response($this->response(1))]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        Http::assertNothingSent();
        $this->assertSame('smoke_endpoint_guard', $report['failure']['category']);
    }

    public function test_database_reset_guard_refuses_the_ordinary_test_environment_without_mutating_it(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('smoke_database_guard');

        ConversationAgentEvaluationRunner::configureIsolatedDatabase();
    }

    public function test_oversized_context_stops_before_any_paid_request(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $business->update(['description' => str_repeat('x', ConversationAgentEvaluationRunner::Limits['request_bytes'])]);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response(1))]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        Http::assertNothingSent();
        $this->assertSame('smoke_budget_exceeded', $report['failure']['category']);
        $this->assertSame(0, $report['usage']['http_requests']);
        $this->assertSame('not_run', $report['scenarios'][4]['contract_status']);
    }

    public function test_fixture_failure_is_distinguished_from_model_failure_without_any_paid_request(): void
    {
        $business = ConversationAgentEvaluationRunner::seed();
        $business->professionals()->where('first_name', 'Laura')->sole()->availabilityIntervals()->delete();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response(1))]);

        $report = (new ConversationAgentEvaluationRunner($business, $this->output))->run();

        Http::assertNothingSent();
        $this->assertSame('smoke_fixture_status_mismatch', $report['failure']['category']);
        $this->assertSame('not_run', $report['scenarios'][3]['contract_status']);
    }

    /** Minimal Responses API fixture; the real SDK parses it and invokes the real tool. */
    private function response(int $number, ?array $arguments = null): array
    {
        return [
            'id' => 'resp_'.$number, 'status' => 'completed', 'model' => 'gpt-4.1-mini',
            'output' => $arguments === null ? [[
                'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Synthetic response synthetic-secret-must-not-appear', 'annotations' => []]],
            ]] : [[
                'type' => 'function_call', 'id' => 'fc_'.$number, 'call_id' => 'call_'.$number,
                'name' => 'CheckAvailabilityTool', 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
            ]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }

    private function fakeScenarioResponses(array $scenarios, bool $truncateLast = false): int
    {
        $sequence = Http::sequence();
        $number = 0;
        foreach ([3, 4, ...array_diff(array_keys($scenarios), [3, 4])] as $id) {
            foreach ($scenarios[$id]['turns'] as $turn) {
                if ($turn['arguments'] !== null) {
                    $sequence->push($this->response(++$number, $turn['arguments']));
                }
                $response = $this->response(++$number);
                if ($truncateLast && $id === array_key_last($scenarios)) {
                    $response['status'] = 'incomplete';
                    $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
                }
                $sequence->push($response);
            }
        }
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => $sequence]);

        return $number;
    }
}
