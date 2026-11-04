<?php

namespace App\Application\Customer;

use App\Models\ChannelIdentity;
use App\Models\Customer;
use Illuminate\Support\Facades\Validator;

final class ResolveCustomer
{
    /** The caller must provide identity values obtained from a trusted channel integration. */
    public function handle(mixed $channel, mixed $externalId): ?Customer
    {
        Validator::make(['channel' => $channel, 'external_id' => $externalId], [
            'channel' => ['bail', 'required', 'string', 'in:whatsapp'],
            'external_id' => ['required', 'string'],
        ])->validate();

        $identity = ChannelIdentity::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first();

        return $identity?->customer;
    }
}
