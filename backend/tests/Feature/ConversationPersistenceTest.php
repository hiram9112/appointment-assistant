<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversations_can_be_associated_with_a_customer_after_creation(): void
    {
        $business = Business::factory()->create();
        $customer = Customer::factory()->create();
        $conversation = Conversation::factory()->for($business)->create([
            'last_activity_at' => Carbon::parse('2030-06-01 12:00:00+02:00'),
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create();

        $this->assertNull($conversation->customer);
        $this->assertTrue($conversation->business->is($business));
        $this->assertTrue($business->conversations->contains($conversation));
        $this->assertSame('2030-06-01 10:00:00', $conversation->fresh()->last_activity_at->utc()->format('Y-m-d H:i:s'));

        $conversation->customer()->associate($customer);
        $conversation->save();

        $this->assertTrue($conversation->fresh()->customer->is($customer));
        $this->assertTrue($customer->conversations->contains($conversation));
        $this->assertTrue($conversation->messages->contains($message));
        $this->assertTrue($message->conversation->is($conversation));
    }

    public function test_multiple_conversations_can_share_a_participant(): void
    {
        $first = Conversation::factory()->create();
        $second = Conversation::factory()->create([
            'business_id' => $first->business_id,
            'channel' => $first->channel,
            'sender_identity' => $first->sender_identity,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertCount(2, $first->business->conversations);
    }

    public function test_messages_persist_roles_content_and_deterministic_chronological_order(): void
    {
        $conversation = Conversation::factory()->create();
        $first = ConversationMessage::factory()->for($conversation)->create([
            'role' => 'user', 'content' => 'Hello', 'created_at' => '2030-01-01 10:00:00',
        ]);
        $second = ConversationMessage::factory()->for($conversation)->create([
            'role' => 'assistant', 'content' => 'How can I help?', 'idempotency_key' => null, 'created_at' => '2030-01-01 10:00:00',
        ]);
        $earlier = ConversationMessage::factory()->for($conversation)->create([
            'role' => 'assistant', 'content' => 'Welcome', 'idempotency_key' => null, 'created_at' => '2030-01-01 09:00:00',
        ]);

        $this->assertSame([$earlier->id, $first->id, $second->id], $conversation->messages->modelKeys());
        $this->assertSame(['assistant', 'user', 'assistant'], $conversation->messages->pluck('role')->all());
        $this->assertSame(['Welcome', 'Hello', 'How can I help?'], $conversation->messages->pluck('content')->all());
        $this->assertNull($second->fresh()->idempotency_key);
        $this->assertNull($earlier->fresh()->idempotency_key);
    }

    #[DataProvider('invalidReferences')]
    public function test_database_enforces_foreign_keys(string $model, string $field, ?int $value, string $sqlState): void
    {
        $factory = $model === 'conversation' ? Conversation::factory() : ConversationMessage::factory();

        try {
            $factory->create([$field => $value]);
            $this->fail('Invalid reference was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }

    public static function invalidReferences(): array
    {
        return [
            'missing business' => ['conversation', 'business_id', null, '23502'],
            'unknown business' => ['conversation', 'business_id', 99999999, '23503'],
            'unknown customer' => ['conversation', 'customer_id', 99999999, '23503'],
            'missing conversation' => ['message', 'conversation_id', null, '23502'],
            'unknown conversation' => ['message', 'conversation_id', 99999999, '23503'],
        ];
    }

    public function test_idempotency_keys_are_unique_across_conversations(): void
    {
        $message = ConversationMessage::factory()->create(['idempotency_key' => 'same-key']);
        $otherConversation = Conversation::factory()->create();
        $this->assertNotSame($message->conversation_id, $otherConversation->id);

        try {
            ConversationMessage::factory()->for($otherConversation)->create(['idempotency_key' => 'same-key']);
            $this->fail('Duplicate key was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
    }

    public function test_database_rejects_unsupported_message_roles(): void
    {
        try {
            ConversationMessage::factory()->create(['role' => 'system']);
            $this->fail('Unsupported role was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }
}
