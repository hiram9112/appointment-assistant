<?php

namespace Tests\Feature;

use App\Application\Conversation\ConversationHandler;
use App\Application\Conversation\ConversationOutcome;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ConversationHandlerTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('boundaries')]
    public function test_inactivity_and_history_are_preserved(int $seconds, bool $new): void
    {
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $business = Business::factory()->create();
        $handler = new ConversationHandler;
        $first = $handler->inbound($business, 'web', 'sender', '1', 'hello');
        Carbon::setTestNow(now()->addSeconds($seconds));
        $second = $handler->inbound($business, 'web', 'sender', '2', 'hello');
        $this->assertSame($new, $first->conversation_id !== $second->conversation_id);
        $this->assertDatabaseCount('conversations', $new ? 2 : 1);
        $this->assertCount($new ? 1 : 2, $handler->history($business, $first->conversation_id));
    }

    public static function boundaries(): array
    {
        return ['before' => [43199, false], 'at' => [43200, true], 'after' => [43201, true]];
    }

    public function test_threshold_is_configurable_and_duplicates_never_renew_activity(): void
    {
        config(['conversation.inactivity_seconds' => 60]);
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $business = Business::factory()->create();
        $handler = new ConversationHandler;
        $first = $handler->inbound($business, 'web', 'sender', '1', 'hello');
        Carbon::setTestNow(now()->addSeconds(30));
        $this->assertSame($first->id, $handler->inbound($business, 'web', 'sender', '1', 'hello')->id);
        $this->assertSame(ConversationOutcome::ConflictingMessage, $handler->inbound($business, 'web', 'sender', '1', 'different'));
        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertSame($first->id, $handler->inbound($business, 'web', 'sender', '1', 'hello')->id);
        $this->assertDatabaseCount('conversations', 1);
        $second = $handler->inbound($business, 'web', 'sender', '2', 'hello');
        $this->assertNotSame($first->conversation_id, $second->conversation_id);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_business_participant_history_and_customer_isolation(): void
    {
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $customer = Customer::factory()->create();
        $handler = new ConversationHandler;
        $first = $handler->inbound($business, 'web', 'sender', '1', 'hello');
        $second = $handler->inbound($business, 'web', 'sender', '2', 'hello');
        Carbon::setTestNow(now()->addHour());
        $assistant = $handler->assistant($business, $first->conversation_id, 'answer');
        $this->assertSame('2030-01-01 13:00:00', Conversation::findOrFail($first->conversation_id)->last_activity_at->format('Y-m-d H:i:s'));
        $this->assertSame([$first->id, $second->id, $assistant->id], $handler->history($business, $first->conversation_id)->modelKeys());
        $this->assertNull($assistant->idempotency_key);
        $this->assertSame(ConversationOutcome::NotFound, $handler->history($other, $first->conversation_id));
        $this->assertSame(ConversationOutcome::NotFound, $handler->assistant($other, $first->conversation_id, 'hidden'));
        $this->assertSame(ConversationOutcome::NotFound, $handler->associateCustomer($other, $first->conversation_id, $customer));
        $associated = $handler->associateCustomer($business, $first->conversation_id, $customer);
        $this->assertSame($customer->id, $associated->customer_id);
        $this->assertCount(3, $handler->history($business, $first->conversation_id));
        foreach ([[$other, 'web', 'sender'], [$business, 'web', 'other'], [$business, 'other', 'sender']] as [$scope, $channel, $sender]) {
            $message = $handler->inbound($scope, $channel, $sender, '1', 'hello');
            $this->assertNotSame($first->conversation_id, $message->conversation_id);
        }
    }

    public function test_failed_message_insert_rolls_back_conversation_and_activity(): void
    {
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $business = Business::factory()->create();
        $handler = new ConversationHandler;
        ConversationMessage::creating(fn () => throw new RuntimeException('failure'));
        try {
            $handler->inbound($business, 'web', 'sender', '1', 'hello');
            $this->fail('Message insertion should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('failure', $e->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $this->assertDatabaseCount('conversations', 0);
        $message = $handler->inbound($business, 'web', 'sender', '1', 'hello');
        Carbon::setTestNow(now()->addHour());
        ConversationMessage::creating(fn () => throw new RuntimeException('failure'));
        try {
            $handler->assistant($business, $message->conversation_id, 'answer');
            $this->fail('Message insertion should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('failure', $e->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $this->assertSame('2030-01-01 12:00:00', Conversation::findOrFail($message->conversation_id)->last_activity_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public function test_invalid_input_is_a_validation_failure(): void
    {
        $business = Business::factory()->create();
        $this->expectException(ValidationException::class);
        (new ConversationHandler)->inbound($business, 'web', 'sender', '', 'hello');
    }
}
