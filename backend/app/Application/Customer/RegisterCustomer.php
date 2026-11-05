<?php

namespace App\Application\Customer;

use App\Models\ChannelIdentity;
use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RegisterCustomer
{
    /** The caller must provide identity values obtained from a trusted channel integration. */
    public function handle(mixed $firstName, mixed $lastName, mixed $phone, mixed $channel, mixed $externalId): Customer|RegistrationOutcome
    {
        $data = Validator::make([
            'first_name' => is_string($firstName) ? trim(preg_replace('/\s+/u', ' ', $firstName) ?? $firstName) : $firstName,
            'last_name' => is_string($lastName) ? trim(preg_replace('/\s+/u', ' ', $lastName) ?? $lastName) : $lastName,
            'phone' => $phone,
            'channel' => $channel,
            'external_id' => $externalId,
        ], [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => [$phone === null ? 'nullable' : 'required', 'string', 'max:32', 'regex:/^\+?(?=(?:[ ()-]*[0-9]){7,15}[ ()-]*$)[0-9][0-9 ()-]*[0-9]$/D'],
            'channel' => ['bail', 'required', 'string', 'in:whatsapp'],
            'external_id' => ['required', 'string', 'max:255'],
        ])->validate();

        if (ChannelIdentity::query()->where('channel', $channel)->where('external_id', $externalId)->exists()) {
            return RegistrationOutcome::AlreadyRegistered;
        }

        try {
            return DB::transaction(function () use ($data): Customer {
                $customer = Customer::query()->create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone' => $data['phone'],
                ]);

                $customer->channelIdentities()->create([
                    'channel' => $data['channel'],
                    'external_id' => $data['external_id'],
                ]);

                return $customer;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->errorInfo[2] ?? '', '"channel_identities_channel_external_id_unique"')) {
                return RegistrationOutcome::AlreadyRegistered;
            }

            throw $exception;
        }
    }
}
