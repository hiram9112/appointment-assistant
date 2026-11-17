<?php

namespace App\Application\Conversation;

use App\Application\Customer\RegisterCustomer;
use App\Application\Customer\RegistrationOutcome;
use App\Application\Customer\ResolveCustomer;
use App\Models\AiTurn;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;

final class RegisterCustomerTool implements Tool
{
    public function __construct(
        private Business $business,
        private AiTurnClaim $claim,
        private AiTurnCoordinator $turns,
        private ResolveCustomer $resolve,
        private RegisterCustomer $register,
        private ConversationHandler $conversations,
    ) {}

    public function description(): string
    {
        return 'Register the current Contact using their first name, at least one surname, and optional contact phone. '
            .'Omit phone or pass null if declined; phone is not identity proof. '
            .'Trusted channel identity is supplied by Laravel. Existing Customers are reused without changing their details. '
            .'Registration needs no Appointment confirmation and does not create an Appointment; continue the current Conversation afterward.';
    }

    public function handle(Request $request): string
    {
        try {
            $result = $this->turns->withOwnership($this->business, $this->claim, function (AiTurn $turn, Conversation $conversation) use ($request): array {
                $customer = $this->resolve->handle($conversation->channel, $conversation->sender_identity);
                $status = 'already_registered';
                if ($customer === null) {
                    $registered = $this->register->handle(
                        $request['first_name'] ?? null, $request['last_name'] ?? null, $request['phone'] ?? null,
                        $conversation->channel, $conversation->sender_identity,
                    );
                    $customer = $registered instanceof Customer
                        ? $registered
                        : $this->resolve->handle($conversation->channel, $conversation->sender_identity);
                    if ($customer === null) {
                        throw new LogicException('A registered channel identity could not be resolved.');
                    }
                    $status = $registered === RegistrationOutcome::AlreadyRegistered ? 'already_registered' : 'registered';
                }

                $associated = $this->conversations->associateCustomer($this->business, $conversation->id, $customer);
                if (! $associated instanceof Conversation) {
                    throw new LogicException('The authorized Customer could not be associated with the current Conversation.');
                }

                return [
                    'status' => $status,
                    'customer' => ['id' => $customer->id, 'first_name' => $customer->first_name, 'last_name' => $customer->last_name],
                ];
            });
        } catch (ValidationException $exception) {
            return json_encode(['status' => 'invalid_arguments', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return json_encode(
            $result instanceof AiTurnOutcome ? ['status' => 'not_authorized', 'reason' => $result->name] : $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'first_name' => $schema->string()->max(255)->description("The Contact's first name.")->required(),
            'last_name' => $schema->string()->max(255)->description('At least one surname.')->required(),
            'phone' => $schema->string()->max(32)->nullable()->description('Optional contact phone with 7–15 digits; omit or use null when not provided.'),
        ];
    }
}
