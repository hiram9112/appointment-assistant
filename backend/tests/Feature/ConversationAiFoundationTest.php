<?php

namespace Tests\Feature;

use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationHandler;
use App\Application\Conversation\ConversationHistoryAdapter;
use App\Application\Conversation\ConversationOutcome;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class ConversationAiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_receives_ordered_external_history_and_the_current_message_once(): void
    {
        $business = Business::factory()->create();
        $handler = new ConversationHandler;
        $first = $handler->inbound($business, 'web', 'sender', '1', 'First question');
        $handler->assistant($business, $first->conversation_id, 'First answer');
        $current = $handler->inbound($business, 'web', 'sender', '2', 'Current question');
        $handler->inbound($business, 'web', 'sender', '3', 'Later question');
        $history = (new ConversationHistoryAdapter($handler))->beforeInbound($business, $current->conversation_id, $current->id);

        config(['ai.default' => 'openai', 'ai.providers.openai.models.text.default' => 'test-model']);
        $this->app->bind(ConversationStore::class, fn () => throw new RuntimeException('SDK conversation store must not be used.'));
        ConversationAgent::fake(['A fake answer']);

        $response = ConversationAgent::make()->withMessages($history)->prompt($current->content);

        $this->assertSame('A fake answer', $response->text);
        ConversationAgent::assertPromptedTimes(1);
        ConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Current question'
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'test-model'
            && array_map(fn (Message $message): array => [$message->role->value, $message->content], $prompt->messages) === [
                ['user', 'First question'],
                ['assistant', 'First answer'],
            ]
        );
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_messages', 4);
    }

    public function test_history_requires_a_user_message_in_the_business_scoped_conversation(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $handler = new ConversationHandler;
        $first = $handler->inbound($business, 'web', 'sender', '1', 'Question');
        $assistant = $handler->assistant($business, $first->conversation_id, 'Answer');
        $otherMessage = $handler->inbound($other, 'web', 'sender', '1', 'Private');
        $adapter = new ConversationHistoryAdapter($handler);

        $this->assertSame([], $adapter->beforeInbound($business, $first->conversation_id, $first->id));
        $this->assertSame(ConversationOutcome::NotFound, $adapter->beforeInbound($other, $first->conversation_id, $first->id));
        $this->assertSame(ConversationOutcome::NotFound, $adapter->beforeInbound($business, $first->conversation_id, $otherMessage->id));
        $this->assertSame(ConversationOutcome::NotFound, $adapter->beforeInbound($business, $first->conversation_id, $assistant->id));
        $this->assertSame(ConversationOutcome::NotFound, $adapter->beforeInbound($business, $first->conversation_id, 999999));
    }

    public function test_history_does_not_include_an_expired_previous_conversation(): void
    {
        $this->travelTo(now()->startOfSecond());
        $business = Business::factory()->create();
        $handler = new ConversationHandler;
        $first = $handler->inbound($business, 'web', 'sender', '1', 'Old question');
        $this->travel(12)->hours();
        $current = $handler->inbound($business, 'web', 'sender', '2', 'New question');

        $this->assertNotSame($first->conversation_id, $current->conversation_id);
        $this->assertSame([], (new ConversationHistoryAdapter($handler))->beforeInbound($business, $current->conversation_id, $current->id));
    }
}
