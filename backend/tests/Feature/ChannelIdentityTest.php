<?php

namespace Tests\Feature;

use App\Models\ChannelIdentity;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_is_persisted_with_its_opaque_external_identifier(): void
    {
        $customer = Customer::factory()->create();
        $externalId = '  Opaque:ÁbC/+001@Example  ';

        $identity = ChannelIdentity::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'external_id' => $externalId,
        ]);

        $retrieved = ChannelIdentity::findOrFail($identity->id);

        $this->assertSame($customer->id, $retrieved->customer_id);
        $this->assertSame('whatsapp', $retrieved->channel);
        $this->assertSame($externalId, $retrieved->external_id);
        $this->assertNotNull($retrieved->created_at);
        $this->assertNotNull($retrieved->updated_at);
    }

    public function test_customer_has_multiple_identities_and_each_identity_belongs_to_the_customer(): void
    {
        $customer = Customer::factory()->create();
        $identities = ChannelIdentity::factory()->count(2)->create(['customer_id' => $customer->id]);
        $other = ChannelIdentity::factory()->create();

        $this->assertEqualsCanonicalizing($identities->modelKeys(), $customer->channelIdentities->modelKeys());
        $this->assertTrue($identities[0]->customer->is($customer));
        $this->assertFalse($identities[0]->customer->is($other->customer));
    }

    public function test_customer_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        ChannelIdentity::factory()->create(['customer_id' => null]);
    }

    public function test_channel_is_required(): void
    {
        $this->expectException(QueryException::class);

        ChannelIdentity::factory()->create(['channel' => null]);
    }

    public function test_external_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        ChannelIdentity::factory()->create(['external_id' => null]);
    }

    public function test_channel_and_external_id_combination_must_be_unique(): void
    {
        ChannelIdentity::factory()->create(['channel' => 'whatsapp', 'external_id' => 'same-id']);

        $this->expectException(QueryException::class);

        ChannelIdentity::factory()->create(['channel' => 'whatsapp', 'external_id' => 'same-id']);
    }

    public function test_external_id_can_be_reused_on_another_channel(): void
    {
        ChannelIdentity::factory()->create(['channel' => 'whatsapp', 'external_id' => 'same-id']);
        ChannelIdentity::factory()->create(['channel' => 'another-channel', 'external_id' => 'same-id']);

        $this->assertSame(2, ChannelIdentity::where('external_id', 'same-id')->count());
    }

    public function test_identity_must_reference_an_existing_customer(): void
    {
        $this->expectException(QueryException::class);

        ChannelIdentity::factory()->create(['customer_id' => 999999999]);
    }
}
