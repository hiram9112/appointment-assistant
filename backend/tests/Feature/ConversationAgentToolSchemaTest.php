<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Models\Business;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Evaluations\ConversationAgent\AppointmentOperationsEvaluationRunner;
use Tests\Evaluations\ConversationAgent\EvaluationProviderGuard;
use Tests\TestCase;

class ConversationAgentToolSchemaTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('transports')]
    public function test_real_sdk_tool_schemas_remain_valid_at_the_http_boundary(bool $guarded, string $model): void
    {
        $this->travelTo('2026-11-09 00:00:00 UTC');
        config([
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'synthetic-schema-test-key',
            'ai.providers.openai.url' => 'https://api.openai.com/v1',
            'ai.providers.openai.store' => false,
            'ai.providers.openai.models.text.default' => $model,
            'logging.default' => 'null',
        ]);
        $business = Business::factory()->create();
        $sdkTools = [];
        Http::preventStrayRequests();
        Http::globalMiddleware(function (callable $handler) use (&$sdkTools): callable {
            return function (RequestInterface $request, array $options) use ($handler, &$sdkTools) {
                $sdkTools = json_decode((string) $request->getBody(), flags: JSON_THROW_ON_ERROR)->tools;

                return $handler($request, $options);
            };
        });
        if ($guarded) {
            (new EvaluationProviderGuard(AppointmentOperationsEvaluationRunner::Limits))->install();
        }
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_schema', 'status' => 'completed', 'model' => $model,
            'output' => [[
                'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Respuesta sintética.', 'annotations' => []]],
            ]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ])]);
        app(AiTurnCoordinator::class)->inbound($business, 'whatsapp', 'synthetic-sender', 'schema-turn', 'Información, por favor.');

        app(ConversationTurnProcessor::class)->processNext($business, 'whatsapp', 'synthetic-sender');

        Http::assertSentCount(1);
        $payload = json_decode(Http::recorded()[0][0]->body(), flags: JSON_THROW_ON_ERROR);
        $this->assertToolSchemas($sdkTools);
        $this->assertToolSchemas($payload->tools);
        $this->assertSame(json_encode($sdkTools, JSON_THROW_ON_ERROR), json_encode($payload->tools, JSON_THROW_ON_ERROR));
        $this->assertFalse($payload->store);
        if ($guarded) {
            $this->assertSame($model === 'gpt-6-luna' ? 4096 : 1024, $payload->max_output_tokens);
        }
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function transports(): array
    {
        return [
            'SDK without evaluation middleware' => [false, 'gpt-4.1-mini'],
            'evaluation middleware' => [true, 'gpt-4.1-mini'],
            'evaluation middleware with reasoning model' => [true, 'gpt-6-luna'],
        ];
    }

    /** @param list<object> $tools */
    private function assertToolSchemas(array $tools): void
    {
        $expected = [
            'CheckAvailabilityTool' => [['service_id', 'professional_id', 'from', 'to'], ['service_id', 'from', 'to']],
            'RegisterCustomerTool' => [['first_name', 'last_name', 'phone'], ['first_name', 'last_name']],
            'GetCustomerAppointmentsTool' => [[], []],
            'PrepareCreateAppointmentTool' => [['service_id', 'professional_id', 'starts_at'], ['service_id', 'professional_id', 'starts_at']],
            'PrepareRescheduleAppointmentTool' => [['appointment_id', 'professional_id', 'starts_at'], ['appointment_id', 'starts_at']],
            'PrepareCancelAppointmentTool' => [['appointment_id'], ['appointment_id']],
            'ConfirmAppointmentProposalTool' => [['decision'], ['decision']],
        ];
        $this->assertSame(array_keys($expected), array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertSame('function', $tool->type, $tool->name);
            $this->assertFalse($tool->strict, $tool->name);
            $parameters = $tool->parameters;
            $this->assertSame('object', $parameters->type, $tool->name);
            $this->assertIsObject($parameters->properties, $tool->name.'.parameters.properties must be a JSON object, including when empty.');
            $this->assertSame($expected[$tool->name][0], array_keys(get_object_vars($parameters->properties)), $tool->name);
            $this->assertSame($expected[$tool->name][1], $parameters->required, $tool->name);
            $this->assertFalse($parameters->additionalProperties, $tool->name);
            foreach ($parameters->properties as $name => $property) {
                $this->assertIsObject($property, $tool->name.'.'.$name);
                if (str_ends_with($name, '_id')) {
                    $this->assertSame(1, $property->minimum, $tool->name.'.'.$name);
                    $this->assertSame(PHP_INT_MAX, $property->maximum, $tool->name.'.'.$name);
                }
                if (in_array($name, ['from', 'to', 'starts_at'], true)) {
                    $this->assertSame('date-time', $property->format);
                    $this->assertSame(25, $property->maxLength);
                }
            }
        }
        $byName = collect($tools)->keyBy('name');
        $this->assertSame('{}', json_encode($byName['GetCustomerAppointmentsTool']->parameters->properties, JSON_THROW_ON_ERROR));
        $this->assertSame([], $byName['GetCustomerAppointmentsTool']->parameters->required);
        $this->assertSame(['confirm', 'decline'], $byName['ConfirmAppointmentProposalTool']->parameters->properties->decision->enum);
        $this->assertSame(['string', 'null'], $byName['RegisterCustomerTool']->parameters->properties->phone->type);
        foreach (['CheckAvailabilityTool', 'PrepareRescheduleAppointmentTool'] as $name) {
            $this->assertSame(['integer', 'null'], $byName[$name]->parameters->properties->professional_id->type);
        }
    }
}
