<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\CheckAvailabilityTool;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\ConversationMessage;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;
use Tests\TestCase;

class ConversationReadAcceptanceTest extends TestCase
{
    use DatabaseMigrations;

    /** @var list<AgentPrompted> */
    private array $executions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new DateTimeImmutable('2026-11-08 23:30:00 UTC'));
        config(['ai.default' => 'openai', 'ai.providers.openai.models.text.default' => 'test-model']);
        Http::preventStrayRequests();
        $this->app->bind(ConversationStore::class, fn () => throw new RuntimeException('SDK conversation storage is forbidden.'));
        Event::listen(AgentPrompted::class, function (AgentPrompted $event): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->executions[] = $event;
        });
    }

    public function test_descriptive_enquiries_and_ambiguous_followups_receive_current_facts_and_completed_history(): void
    {
        $business = $this->business();
        $first = Service::factory()->for($business)->create([
            'name' => 'A consultation', 'description' => 'Discuss your options',
            'duration_minutes' => 30, 'price_amount' => '0.00', 'currency_code' => 'EUR',
        ]);
        $second = Service::factory()->for($business)->create([
            'name' => 'B treatment', 'description' => 'A longer treatment',
            'duration_minutes' => 60, 'price_amount' => '45.50', 'currency_code' => 'EUR',
        ]);
        Service::factory()->for($business)->create(['name' => 'Hidden service', 'active' => false]);
        $ana = $this->professional($business, [$first, $second], 'Ana');
        $otherAna = $this->professional($business, [$second], 'Ana');
        $dialogue = [
            ['Where are you, how can I pay, and what is the cancellation policy?', 'We are at 12 Example Street, accept card and cash, and have no configured cancellation notice.'],
            ['What services, prices, durations and professionals do you have?', '1. A consultation: 30 minutes, free, with Ana. 2. B treatment: 60 minutes, EUR 45.50, with two professionals named Ana.'],
            ['The second service, with Ana today at ten.', 'Two professionals are named Ana. Which one do you mean?'],
            ['The Ana who also offers consultations.', 'That Ana is available for B treatment today at 10:00.'],
        ];
        ConversationAgent::fake([
            $dialogue[0][1], $dialogue[1][1], $dialogue[2][1],
            $this->availabilityCall($second, $ana, '2026-11-09T10:00:00+01:00'),
            $dialogue[3][1],
            'Which service and professional would you like to check?',
        ])->preventStrayPrompts();
        $history = [];

        foreach ($dialogue as $index => [$question, $answer]) {
            $execution = $this->exchange($business, (string) $index, $question, $answer, $history);
            $this->assertCount($index === 3 ? 1 : 0, $execution->response->toolResults);
            $history[] = ['user', $question];
            $history[] = ['assistant', $answer];
        }

        $context = $this->context($this->executions[1]->prompt);
        $this->assertSame('2026-11-09T00:30:00+01:00', $context['operation_time']);
        $this->assertSame('12 Example Street', $context['business']['address_line']);
        $this->assertSame(['card', 'cash'], $context['business']['payment_methods']);
        $this->assertNull($context['business']['cancellation_notice_minutes']);
        $this->assertSame([['weekday' => 'monday', 'opens_at' => '09:00:00', 'closes_at' => '17:00:00']], $context['business']['operating_hours']);
        $this->assertSame([
            ['id' => $first->id, 'name' => 'A consultation', 'description' => 'Discuss your options', 'duration_minutes' => 30, 'price_amount' => '0.00', 'currency_code' => 'EUR', 'eligible_professional_ids' => [$ana->id]],
            ['id' => $second->id, 'name' => 'B treatment', 'description' => 'A longer treatment', 'duration_minutes' => 60, 'price_amount' => '45.50', 'currency_code' => 'EUR', 'eligible_professional_ids' => [$ana->id, $otherAna->id]],
        ], $context['services']);
        $this->assertSame([
            ['id' => $ana->id, 'display_name' => 'Ana'],
            ['id' => $otherAna->id, 'display_name' => 'Ana'],
        ], $context['professionals']);
        $result = $this->toolResult($execution);
        $this->assertSame('ok', $result['status']);
        $this->assertSame($second->id, $result['service_id']);
        $this->assertSame($ana->id, $result['requested_professional']['id']);
        $this->assertSame([['earliest' => '2026-11-09T10:00:00+01:00', 'latest' => '2026-11-09T10:00:00+01:00']], $result['requested_professional']['ranges']);

        $second->update(['active' => false]);
        $business->update(['payment_methods' => null]);
        $this->travel((int) config('conversation.inactivity_seconds'))->seconds();
        $fresh = $this->exchange($business, 'fresh', 'Same again?', 'Which service and professional would you like to check?', []);

        $freshContext = $this->context($fresh->prompt);
        $this->assertSame([$first->id], array_column($freshContext['services'], 'id'));
        $this->assertNull($freshContext['business']['payment_methods']);
        $this->assertCount(0, $fresh->response->toolResults);
        $this->assertDatabaseCount('conversations', 2);
        $this->assertDatabaseCount('conversation_messages', 10);
        $this->assertDatabaseCount('appointments', 0);
        ConversationAgent::assertPromptedTimes(5);
    }

    public function test_changed_professional_service_date_and_topic_keep_authoritative_availability_separate_from_preferences(): void
    {
        $business = $this->business();
        $treatment = Service::factory()->for($business)->create(['name' => 'Treatment', 'duration_minutes' => 60]);
        $consultation = Service::factory()->for($business)->create(['name' => 'Consultation', 'duration_minutes' => 30]);
        $ana = $this->professional($business, [$treatment, $consultation], 'Ana');
        $bea = $this->professional($business, [$treatment], 'Bea');
        $appointment = Appointment::factory()->for($treatment, 'service')->for($ana, 'professional')->create([
            'starts_at' => '2026-11-09 10:00:00+01:00', 'ends_at' => '2026-11-09 11:00:00+01:00',
            'buffer_before_minutes' => 15, 'buffer_after_minutes' => 15,
        ]);
        $originalAppointment = $appointment->fresh()->getAttributes();
        $dialogue = [
            ['Treatment with Ana today at 11?', 'Ana is unavailable then; Bea is an available alternative.'],
            ['Then with Bea at the same time.', 'Bea is available at 11:00.'],
            ['Actually a consultation, still with Bea, next Monday at ten.', 'Bea does not offer consultations; Ana is an available alternative.'],
            ['Back to treatment, anyone that Monday at ten.', 'Ana and Bea are both available for treatment then.'],
            ['What about the next day at ten?', 'No professional is available at that time.'],
            ['Never mind. How can I contact you?', 'Our phone number is +34 900 000 000.'],
        ];
        ConversationAgent::fake([
            $this->availabilityCall($treatment, $ana, '2026-11-09T11:00:00+01:00'), $dialogue[0][1],
            $this->availabilityCall($treatment, $bea, '2026-11-09T11:00:00+01:00'), $dialogue[1][1],
            $this->availabilityCall($consultation, $bea, '2026-11-16T10:00:00+01:00'), $dialogue[2][1],
            $this->availabilityCall($treatment, null, '2026-11-16T10:00:00+01:00'), $dialogue[3][1],
            $this->availabilityCall($treatment, null, '2026-11-17T10:00:00+01:00'), $dialogue[4][1],
            $dialogue[5][1],
        ])->preventStrayPrompts();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(?:insert into|update|delete from)\s+"?(\w+)/i', $query->sql, $matches)) {
                $writes[] = $matches[1];
            }
        });
        $history = [];

        foreach ($dialogue as $index => [$question, $answer]) {
            $this->exchange($business, (string) $index, $question, $answer, $history);
            $history[] = ['user', $question];
            $history[] = ['assistant', $answer];
        }

        $unavailable = $this->toolResult($this->executions[0]);
        $this->assertSame('requested_professional_unavailable', $unavailable['status']);
        $this->assertSame(['id' => $ana->id, 'display_name' => 'Ana', 'eligible' => true, 'ranges' => []], $unavailable['requested_professional']);
        $this->assertSame([$bea->id], array_column($unavailable['available_professionals'], 'id'));
        $this->assertSame([['earliest' => '2026-11-09T11:00:00+01:00', 'latest' => '2026-11-09T11:00:00+01:00']], $unavailable['available_professionals'][0]['ranges']);
        $changedProfessional = $this->toolResult($this->executions[1]);
        $this->assertSame('ok', $changedProfessional['status']);
        $this->assertSame($bea->id, $changedProfessional['requested_professional']['id']);
        $this->assertSame([], $changedProfessional['available_professionals']);
        $changedService = $this->toolResult($this->executions[2]);
        $this->assertSame('requested_professional_ineligible', $changedService['status']);
        $this->assertSame($consultation->id, $changedService['service_id']);
        $this->assertSame(['id' => $bea->id, 'display_name' => 'Bea', 'eligible' => false, 'ranges' => []], $changedService['requested_professional']);
        $this->assertSame([$ana->id], array_column($changedService['available_professionals'], 'id'));
        $noPreference = $this->toolResult($this->executions[3]);
        $this->assertSame('ok', $noPreference['status']);
        $this->assertSame($treatment->id, $noPreference['service_id']);
        $this->assertNull($noPreference['requested_professional']);
        $this->assertSame([$ana->id, $bea->id], array_column($noPreference['available_professionals'], 'id'));
        $this->assertSame([['earliest' => '2026-11-16T10:00:00+01:00', 'latest' => '2026-11-16T10:00:00+01:00']], $noPreference['available_professionals'][0]['ranges']);
        $closed = $this->toolResult($this->executions[4]);
        $this->assertSame('unavailable', $closed['status']);
        $this->assertNull($closed['requested_professional']);
        $this->assertSame([], $closed['available_professionals']);
        $this->assertCount(0, $this->executions[5]->response->toolResults);
        $this->assertSame('+34 900 000 000', $this->context($this->executions[5]->prompt)['business']['phone']);
        $this->assertNotEmpty($writes);
        $this->assertSame([], array_values(array_diff(array_unique($writes), ['ai_turns', 'conversations', 'conversation_messages'])));
        $this->assertSame($originalAppointment, $appointment->fresh()->getAttributes());
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversation_messages', 12);
        ConversationAgent::assertPromptedTimes(6);
    }

    public function test_identical_senders_in_different_businesses_cannot_use_each_others_professionals_or_history(): void
    {
        $business = $this->business();
        $other = $this->business();
        $service = Service::factory()->for($business)->create(['name' => 'Local service']);
        $otherService = Service::factory()->for($other)->create(['name' => 'Other service']);
        $ana = $this->professional($business, [$service], 'Ana');
        $otherAna = $this->professional($other, [$otherService], 'Ana');
        ConversationAgent::fake([
            $this->availabilityCall($service, $otherAna, '2026-11-09T10:00:00+01:00'), 'That professional is not valid for this business.',
            $this->availabilityCall($otherService, $ana, '2026-11-09T10:00:00+01:00'), 'Please choose a professional from this business.',
            $this->availabilityCall($service, $ana, '2026-11-09T10:00:00+01:00'), 'The local Ana is available.',
        ])->preventStrayPrompts();

        $first = $this->exchange($business, '1', 'Check with Ana.', 'That professional is not valid for this business.', []);
        $second = $this->exchange($other, '1', 'Check with Ana.', 'Please choose a professional from this business.', []);
        $corrected = $this->exchange($business, '2', 'Use the local Ana.', 'The local Ana is available.', [
            ['user', 'Check with Ana.'], ['assistant', 'That professional is not valid for this business.'],
        ]);

        foreach ([$first, $second] as $execution) {
            $result = $this->toolResult($execution);
            $this->assertSame('invalid_reference', $result['status']);
            $this->assertArrayHasKey('professional_id', $result['errors']);
        }
        $this->assertSame([$service->id], array_column($this->context($first->prompt)['services'], 'id'));
        $this->assertSame([$otherService->id], array_column($this->context($second->prompt)['services'], 'id'));
        $this->assertSame('ok', $this->toolResult($corrected)['status']);
        $this->assertSame($ana->id, $this->toolResult($corrected)['requested_professional']['id']);
        $this->assertDatabaseCount('conversations', 2);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('customers', 0);
        ConversationAgent::assertPromptedTimes(3);
    }

    private function business(): Business
    {
        $business = Business::factory()->create([
            'timezone' => 'Europe/Madrid', 'address_line' => '12 Example Street',
            'phone' => '+34 900 000 000', 'payment_methods' => ['card', 'cash'],
            'cancellation_notice_minutes' => null,
        ]);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '09:00', 'closes_at' => '17:00']);

        return $business;
    }

    /** @param list<Service> $services */
    private function professional(Business $business, array $services, string $name): Professional
    {
        $professional = Professional::factory()->for($business)->create(['first_name' => $name, 'last_name' => 'Example', 'display_name' => $name]);
        $professional->services()->attach(array_map(fn (Service $service): int => $service->id, $services));
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'monday']);

        return $professional;
    }

    private function availabilityCall(Service $service, ?Professional $professional, string $start): ToolCall
    {
        return new ToolCall('availability-'.$service->id.'-'.$start, 'CheckAvailabilityTool', [
            'service_id' => $service->id, 'professional_id' => $professional?->id, 'from' => $start, 'to' => $start,
        ]);
    }

    /** @param list<array{string, string}> $history */
    private function exchange(Business $business, string $id, string $question, string $answer, array $history): AgentPrompted
    {
        $turn = app(AiTurnCoordinator::class)->inbound($business, 'web', 'sender', $id, $question);
        $response = app(ConversationTurnProcessor::class)->processNext($business, 'web', 'sender');

        $this->assertInstanceOf(ConversationMessage::class, $response);
        $this->assertSame($answer, $response->content);
        $this->assertSame($turn->inboundMessage->conversation_id, $response->conversation_id);
        $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
        $this->assertSame($response->id, $turn->fresh()->assistant_message_id);
        $execution = $this->executions[array_key_last($this->executions)];
        $this->assertSame($question, $execution->prompt->prompt);
        $this->assertSame($history, array_map(fn (Message $message): array => [$message->role->value, $message->content], $execution->prompt->messages));
        $this->assertSame([CheckAvailabilityTool::class], array_map(fn ($tool): string => $tool::class, [...$execution->prompt->agent->tools()]));
        $this->assertDatabaseHas('conversation_messages', ['id' => $response->id, 'role' => 'assistant', 'content' => $answer]);

        return $execution;
    }

    /** @return array<string, mixed> */
    private function context(AgentPrompt $prompt): array
    {
        $this->assertSame(1, preg_match('/<runtime_context>\n(.*)\n<\/runtime_context>$/s', $prompt->agent->instructions(), $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function toolResult(AgentPrompted $execution): array
    {
        $this->assertCount(1, $execution->response->toolResults);

        return json_decode($execution->response->toolResults[0]->result, true, flags: JSON_THROW_ON_ERROR);
    }
}
