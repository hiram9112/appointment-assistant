<?php

namespace Tests\Feature;

use App\Application\Appointment\GetCustomerAppointments;
use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\ConversationHandler;
use App\Application\Conversation\GetCustomerAppointmentsTool;
use App\Application\Conversation\RegisterCustomerTool;
use App\Application\Customer\RegisterCustomer;
use App\Application\Customer\ResolveCustomer;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerConversationToolsTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    private AiTurnCoordinator $turns;

    private AiTurnClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $this->business = Business::factory()->create(['timezone' => 'Europe/Madrid']);
        $this->turns = app(AiTurnCoordinator::class);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '1', 'I would like an Appointment');
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
    }

    public function test_sdk_schemas_expose_registration_data_but_never_trusted_scope(): void
    {
        $factory = new JsonSchemaTypeFactory;
        $registration = $this->registration();
        $read = $this->reader();
        $schema = $factory->object($registration->schema($factory))->toArray();

        $this->assertInstanceOf(Tool::class, $registration);
        $this->assertInstanceOf(Tool::class, $read);
        $this->assertNotEmpty($registration->description());
        $this->assertNotEmpty($read->description());
        $this->assertSame(['first_name', 'last_name', 'phone'], array_keys($schema['properties']));
        $this->assertSame(['first_name', 'last_name'], $schema['required']);
        $this->assertSame(['string', 'null'], $schema['properties']['phone']['type']);
        $this->assertSame(255, $schema['properties']['last_name']['maxLength']);
        $this->assertSame([], $read->schema($factory));
    }

    #[DataProvider('optionalPhones')]
    public function test_registration_uses_trusted_identity_and_associates_without_restarting_or_completing_the_turn(array $phone): void
    {
        $conversation = $this->conversation();
        $originalActivity = $conversation->last_activity_at;
        $other = ChannelIdentity::factory()->create();
        $this->claim->inboundMessage->setRelation('conversation', new Conversation([
            'channel' => 'telegram', 'sender_identity' => $other->external_id, 'customer_id' => $other->customer_id,
        ]));
        Carbon::setTestNow(now()->addHours(13));
        $result = $this->invokeTool($this->registration(), [
            'first_name' => '  José  María ', 'last_name' => " García\tde León ",
            'channel' => 'telegram', 'external_id' => $other->external_id, 'sender_identity' => $other->external_id,
            'customer_id' => $other->customer_id, 'business_id' => 99999999, 'conversation_id' => 99999999,
            ...$phone,
        ]);

        $this->assertSame('registered', $result['status']);
        $customer = (new ResolveCustomer)->handle('whatsapp', 'sender');
        $this->assertSame(['id' => $customer->id, 'first_name' => 'José María', 'last_name' => 'García de León'], $result['customer']);
        $this->assertNotSame($other->customer_id, $customer->id);
        $this->assertSame($phone['phone'] ?? null, $customer->phone);
        $this->assertSame($customer->id, $conversation->fresh()->customer_id);
        $this->assertTrue($originalActivity->equalTo($conversation->fresh()->last_activity_at));
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame(AiTurnState::Processing, AiTurn::findOrFail($this->claim->turnId)->state);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function optionalPhones(): array
    {
        return ['omitted' => [[]], 'declined' => [['phone' => null]], 'provided' => [['phone' => '+34 912 345 678']]];
    }

    public function test_same_tool_instances_resolve_fresh_identity_after_registration_and_preserve_history(): void
    {
        $reader = $this->reader();
        $register = $this->registration();
        $this->assertSame(['status' => 'identification_failed'], $this->invokeTool($reader));
        $result = $this->invokeTool($register, ['first_name' => 'Ana', 'last_name' => 'García']);
        $this->assertSame(['status' => 'no_appointments', 'appointments' => []], $this->invokeTool($reader));
        $appointment = $this->appointment(Customer::findOrFail($result['customer']['id']), $this->business);
        $this->assertSame([$appointment->id], array_column($this->invokeTool($reader)['appointments'], 'id'));

        $retry = $this->invokeTool($register, ['first_name' => 'Different', 'last_name' => 'Person', 'phone' => 'not a phone']);
        $this->assertSame('already_registered', $retry['status']);
        $this->assertSame($result['customer'], $retry['customer']);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
        $this->turns->complete($this->business, $this->claim, 'Registration complete; continuing your booking.');
        $turn = $this->turns->inbound($this->business, 'whatsapp', 'sender', '2', 'Thank you');
        $history = $this->turns->completedBefore($this->business, $turn->inbound_message_id);
        $this->assertCount(1, $history);
        $this->assertSame('I would like an Appointment', $history[0]->inboundMessage->content);
        $this->assertSame('Registration complete; continuing your booking.', $history[0]->assistantMessage->content);
    }

    public function test_identity_is_reused_and_associated_in_a_new_conversation_without_registration_data(): void
    {
        $result = $this->invokeTool($this->registration(), ['first_name' => 'Ana', 'last_name' => 'García']);
        $old = $this->conversation();
        $this->turns->complete($this->business, $this->claim, 'Welcome');
        Carbon::setTestNow(now()->addHours(12));
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '2', 'Hello again');
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');

        $this->assertNotSame($old->id, $this->conversation()->id);
        $this->assertSame('no_appointments', $this->invokeTool($this->reader())['status']);
        $reused = $this->invokeTool($this->registration());
        $this->assertSame('already_registered', $reused['status']);
        $this->assertSame($result['customer'], $reused['customer']);
        $this->assertSame($result['customer']['id'], $this->conversation()->customer_id);
        $this->assertCount(0, $this->turns->completedBefore($this->business, $this->claim->inboundMessage->id));
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversations', 2);
    }

    #[DataProvider('invalidRegistration')]
    public function test_invalid_registration_is_structured_and_has_no_writes(array $arguments, string $field): void
    {
        $result = $this->invokeTool($this->registration(), array_replace(['first_name' => 'Ana', 'last_name' => 'García'], $arguments));
        $this->assertSame('invalid_arguments', $result['status']);
        $this->assertArrayHasKey($field, $result['errors']);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('channel_identities', 0);
        $this->assertNull($this->conversation()->customer_id);
    }

    public static function invalidRegistration(): array
    {
        return [
            'missing first name' => [['first_name' => null], 'first_name'],
            'blank first name' => [['first_name' => '  '], 'first_name'],
            'non-string first name' => [['first_name' => []], 'first_name'],
            'long first name' => [['first_name' => str_repeat('a', 256)], 'first_name'],
            'missing surname' => [['last_name' => null], 'last_name'],
            'blank surname' => [['last_name' => "\t "], 'last_name'],
            'long surname' => [['last_name' => str_repeat('a', 256)], 'last_name'],
            'invalid phone' => [['phone' => 'no thanks'], 'phone'],
            'blank phone' => [['phone' => ''], 'phone'],
            'short phone' => [['phone' => '123456'], 'phone'],
            'long phone' => [['phone' => '1234567890123456'], 'phone'],
        ];
    }

    public function test_unsupported_channel_cannot_register_or_identify_even_with_a_customer_association(): void
    {
        $identity = ChannelIdentity::factory()->create();
        $this->appointment($identity->customer, $this->business);
        $turn = $this->turns->inbound($this->business, 'telegram', $identity->external_id, '1', 'Hello');
        $this->claim = $this->turns->claimNext($this->business, 'telegram', $identity->external_id);
        Conversation::findOrFail($turn->inboundMessage->conversation_id)->update(['customer_id' => $identity->customer_id]);

        $result = $this->invokeTool($this->registration(), ['first_name' => 'Ana', 'last_name' => 'García', 'channel' => 'whatsapp']);
        $this->assertSame('invalid_arguments', $result['status']);
        $this->assertArrayHasKey('channel', $result['errors']);
        $this->assertSame(['status' => 'identification_failed'], $this->invokeTool($this->reader()));
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
    }

    public function test_matching_names_and_phone_are_not_identity_proof(): void
    {
        $other = ChannelIdentity::factory()->create();
        $this->appointment($other->customer, $this->business);
        $arguments = [
            'first_name' => $other->customer->first_name, 'last_name' => $other->customer->last_name,
            'phone' => '+34912345678', 'customer_id' => $other->customer_id, 'sender_identity' => $other->external_id,
        ];
        $other->customer->update(['phone' => $arguments['phone']]);
        $this->conversation()->update(['customer_id' => $other->customer_id]);

        $this->assertSame(['status' => 'identification_failed'], $this->invokeTool($this->reader(), $arguments));
        $registered = $this->invokeTool($this->registration(), $arguments);
        $this->assertSame('registered', $registered['status']);
        $this->assertNotSame($other->customer_id, $registered['customer']['id']);
        $this->assertSame($registered['customer']['id'], $this->conversation()->customer_id);
        $this->assertSame(['status' => 'no_appointments', 'appointments' => []], $this->invokeTool($this->reader()));
        $this->assertDatabaseCount('customers', 2);
    }

    #[DataProvider('unauthorizedContexts')]
    public function test_unauthorized_attempts_cannot_register_associate_or_read(string $scenario, string $reason): void
    {
        $identity = ChannelIdentity::factory()->create(['external_id' => 'sender']);
        $this->appointment($identity->customer, $this->business);
        $business = $this->business;
        switch ($scenario) {
            case 'completed':
                $this->turns->complete($business, $this->claim, 'Done');
                break;
            case 'failed':
                $this->turns->fail($business, $this->claim);
                break;
            case 'takeover':
                Carbon::setTestNow(now()->addMinutes(5));
                $this->turns->claimNext($business, 'whatsapp', 'sender');
                break;
            case 'replacement':
                Carbon::setTestNow(now()->addHours(12));
                $this->turns->inbound($business, 'whatsapp', 'sender', '2', 'New Conversation');
                break;
            case 'wrong business':
                $business = Business::factory()->create();
                break;
            case 'wrong token':
                $this->claim = new AiTurnClaim($this->claim->turnId, 'wrong', $this->claim->inboundMessage);
                break;
            case 'wrong message':
                $other = $this->turns->inbound($business, 'whatsapp', 'other', '1', 'Other');
                $this->claim = new AiTurnClaim($this->claim->turnId, $this->claim->token, $other->inboundMessage);
                break;
        }
        $this->assertSame(['status' => 'not_authorized', 'reason' => $reason], $this->invokeTool($this->registration($business)));
        $read = $this->invokeTool($this->reader($business));
        $this->assertSame('not_authorized', $read['status']);
        $this->assertArrayNotHasKey('appointments', $read);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
        $this->assertSame(0, Conversation::query()->whereNotNull('customer_id')->count());
    }

    public static function unauthorizedContexts(): array
    {
        return [
            'completed' => ['completed', 'StaleAttempt'],
            'failed' => ['failed', 'StaleAttempt'],
            'taken over' => ['takeover', 'StaleAttempt'],
            'replaced' => ['replacement', 'Superseded'],
            'wrong Business' => ['wrong business', 'NotFound'],
            'wrong token' => ['wrong token', 'StaleAttempt'],
            'wrong inbound message' => ['wrong message', 'StaleAttempt'],
        ];
    }

    #[DataProvider('writeFailures')]
    public function test_registration_and_association_roll_back_together_on_technical_failure(string $failure): void
    {
        $original = $this->conversation()->getAttributes();
        if ($failure === 'identity') {
            ChannelIdentity::creating(fn () => throw new RuntimeException('Write failed'));
        } else {
            Conversation::updating(function (Conversation $conversation): void {
                $this->assertSame(1, DB::transactionLevel());
                $this->assertDatabaseCount('customers', 1);
                $this->assertDatabaseCount('channel_identities', 1);
                $this->assertNotNull($conversation->customer_id);
                throw new RuntimeException('Write failed');
            });
        }

        try {
            $this->invokeTool($this->registration(), ['first_name' => 'Ana', 'last_name' => 'García']);
            $this->fail('Technical failures must propagate, not claim registration succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Write failed', $exception->getMessage());
        } finally {
            ChannelIdentity::flushEventListeners();
            Conversation::flushEventListeners();
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('channel_identities', 0);
        $this->assertSame($original, $this->conversation()->getAttributes());
        $this->assertSame(AiTurnState::Processing, AiTurn::findOrFail($this->claim->turnId)->state);
        $this->assertSame('registered', $this->invokeTool($this->registration(), ['first_name' => 'Ana', 'last_name' => 'García'])['status']);
    }

    public static function writeFailures(): array
    {
        return ['identity persistence' => ['identity'], 'Conversation association' => ['association']];
    }

    #[DataProvider('responseFailures')]
    public function test_committed_registration_recovers_after_provider_or_response_persistence_failure(string $failure): void
    {
        $this->turns->fail($this->business, $this->claim);
        $this->travelTo(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        if ($failure === 'assistant') {
            ConversationMessage::creating(function (ConversationMessage $message): void {
                if ($message->role === 'assistant') {
                    throw new RuntimeException('Response failed');
                }
            });
        }
        $customerId = null;
        try {
            $this->turns->processNext($this->business, 'whatsapp', 'sender', function (AiTurnClaim $claim) use ($failure, &$customerId): string {
                $this->claim = $claim;
                $result = $this->invokeTool($this->registration(), ['first_name' => 'Ana', 'last_name' => 'García']);
                $customerId = $result['customer']['id'];
                $this->assertSame(0, DB::transactionLevel());
                if ($failure === 'provider') {
                    throw new RuntimeException('Response failed');
                }

                return 'Registered';
            });
            $this->fail('The interrupted response must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Response failed', $exception->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $this->assertSame(AiTurnState::Failed, AiTurn::findOrFail($this->claim->turnId)->state);
        $this->travelTo(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $this->assertSame($customerId, $this->conversation()->customer_id);
        $this->assertDatabaseCount('conversation_messages', 1);
        $response = $this->turns->processNext($this->business, 'whatsapp', 'sender', function (AiTurnClaim $claim) use ($customerId): string {
            $this->claim = $claim;
            $result = $this->invokeTool($this->registration());
            $this->assertSame('already_registered', $result['status']);
            $this->assertSame($customerId, $result['customer']['id']);
            $this->assertSame('no_appointments', $this->invokeTool($this->reader())['status']);

            return 'Registration recovered; continue booking';
        });
        $this->assertSame('Registration recovered; continue booking', $response->content);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public static function responseFailures(): array
    {
        return ['provider interrupted after commit' => ['provider'], 'assistant persistence failed' => ['assistant']];
    }

    public function test_reads_use_resolved_customer_and_business_in_sql_preserving_dto_history_order_and_timezone(): void
    {
        $identity = ChannelIdentity::factory()->create(['external_id' => 'sender']);
        $other = ChannelIdentity::factory()->create();
        $otherBusiness = Business::factory()->create();
        $past = $this->appointment($identity->customer, $this->business, [
            'starts_at' => '2020-01-01 10:00+00', 'ends_at' => '2020-01-01 11:00+00', 'status' => 'completed',
        ]);
        $future = $this->appointment($identity->customer, $this->business, ['status' => 'cancelled']);
        $tie = $this->appointment($identity->customer, $this->business);
        $foreign = $this->appointment($identity->customer, $otherBusiness);
        $this->appointment($other->customer, $this->business);
        $this->conversation()->update(['customer_id' => $other->customer_id]);
        $this->claim->inboundMessage->setRelation('conversation', new Conversation(['sender_identity' => $other->external_id]));

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $this->invokeTool($this->reader(), [
                'customer_id' => $other->customer_id, 'business_id' => $otherBusiness->id, 'appointment_id' => $foreign->id,
                'channel' => 'whatsapp', 'sender_identity' => $other->external_id,
            ]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('ok', $result['status']);
        $this->assertSame([$tie->id, $future->id, $past->id], array_column($result['appointments'], 'id'));
        $this->assertSame(['confirmed', 'cancelled', 'completed'], array_column($result['appointments'], 'status'));
        $this->assertSame([
            'id' => $tie->id, 'service_name' => 'Facial', 'professional_name' => 'Dra. Laura',
            'starts_at' => '2030-01-01T11:00:00+01:00', 'ends_at' => '2030-01-01T12:00:00+01:00', 'status' => 'confirmed',
        ], $result['appointments'][0]);
        $query = collect($queries)->first(fn (array $query) => str_contains($query['query'], 'from "appointments"'));
        $this->assertStringContainsString('"customer_id" = ?', $query['query']);
        $this->assertStringContainsString('"business_id" = ?', $query['query']);
        $this->assertSame([$identity->customer_id, $this->business->id], $query['bindings']);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame($other->customer_id, $this->conversation()->customer_id);
    }

    public function test_read_failure_propagates_instead_of_fabricating_no_appointments(): void
    {
        ChannelIdentity::factory()->create(['external_id' => 'sender']);
        $throw = true;
        DB::listen(function (QueryExecuted $query) use (&$throw): void {
            if ($throw && str_contains($query->sql, 'from "appointments"')) {
                $throw = false;
                throw new RuntimeException('Read failed');
            }
        });
        try {
            $this->invokeTool($this->reader());
            $this->fail('Technical failure must not become an empty result.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Read failed', $exception->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(AiTurnState::Processing, AiTurn::findOrFail($this->claim->turnId)->state);
        $this->assertSame(['status' => 'no_appointments', 'appointments' => []], $this->invokeTool($this->reader()));
    }

    private function conversation(): Conversation
    {
        return Conversation::findOrFail($this->claim->inboundMessage->conversation_id);
    }

    private function registration(?Business $business = null): RegisterCustomerTool
    {
        return new RegisterCustomerTool(
            $business ?? $this->business, $this->claim, $this->turns,
            new ResolveCustomer, new RegisterCustomer, new ConversationHandler,
        );
    }

    private function reader(?Business $business = null): GetCustomerAppointmentsTool
    {
        return new GetCustomerAppointmentsTool(
            $business ?? $this->business, $this->claim, $this->turns, new ResolveCustomer, new GetCustomerAppointments,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function invokeTool(Tool $tool, array $arguments = []): array
    {
        return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $attributes */
    private function appointment(Customer $customer, Business $business, array $attributes = []): Appointment
    {
        $service = Service::factory()->for($business)->create(['name' => 'Facial', 'active' => false]);
        $professional = Professional::factory()->for($business)->create(['display_name' => 'Dra. Laura']);

        return Appointment::factory()->for($customer)->for($service)->for($professional)->create($attributes);
    }
}
