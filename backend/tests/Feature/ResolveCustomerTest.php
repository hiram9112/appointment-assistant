<?php

namespace Tests\Feature;

use App\Application\Customer\ResolveCustomer;
use App\Models\ChannelIdentity;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolveCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_identity_resolves_the_same_customer_repeatedly_without_modifying_persistence(): void
    {
        $identity = ChannelIdentity::factory()->create(['external_id' => '  Opaque:ÁbC/+001  ']);
        $customersBefore = DB::table('customers')->orderBy('id')->get()->toArray();
        $identitiesBefore = DB::table('channel_identities')->orderBy('id')->get()->toArray();
        $resolver = new ResolveCustomer;

        $first = $resolver->handle('whatsapp', '  Opaque:ÁbC/+001  ');
        $second = $resolver->handle('whatsapp', '  Opaque:ÁbC/+001  ');
        $unknown = $resolver->handle('whatsapp', 'not-registered');

        $this->assertTrue($identity->customer->is($first));
        $this->assertTrue($identity->customer->is($second));
        $this->assertNull($unknown);
        $this->assertEquals($customersBefore, DB::table('customers')->orderBy('id')->get()->toArray());
        $this->assertEquals($identitiesBefore, DB::table('channel_identities')->orderBy('id')->get()->toArray());
    }

    public function test_unknown_identity_does_not_match_a_different_channel_or_transformed_identifier(): void
    {
        ChannelIdentity::factory()->create(['channel' => 'other', 'external_id' => 'same-id']);
        ChannelIdentity::factory()->create(['external_id' => '  Opaque-ID  ']);
        $resolver = new ResolveCustomer;

        $this->assertNull($resolver->handle('whatsapp', 'same-id'));
        $this->assertNull($resolver->handle('whatsapp', 'Opaque-ID'));
        $this->assertNull($resolver->handle('whatsapp', '  opaque-id  '));
    }

    public function test_customers_with_matching_names_and_phone_remain_distinguishable(): void
    {
        $attributes = ['first_name' => 'Ana', 'last_name' => 'García', 'phone' => '+34912345678'];
        $first = Customer::factory()->create($attributes);
        $second = Customer::factory()->create($attributes);
        ChannelIdentity::factory()->for($first)->create(['external_id' => 'first']);
        ChannelIdentity::factory()->for($second)->create(['external_id' => 'second']);
        $resolver = new ResolveCustomer;

        $this->assertTrue($first->is($resolver->handle('whatsapp', 'first')));
        $this->assertTrue($second->is($resolver->handle('whatsapp', 'second')));
        $this->assertNull($resolver->handle('whatsapp', '+34912345678'));
    }

    #[DataProvider('invalidIdentityInputs')]
    public function test_invalid_identity_input_is_rejected(mixed $channel, mixed $externalId, string $field): void
    {
        try {
            (new ResolveCustomer)->handle($channel, $externalId);
            $this->fail('Invalid identity input must not resolve a customer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidIdentityInputs(): array
    {
        return [
            'missing channel' => [null, 'id', 'channel'],
            'empty channel' => ['', 'id', 'channel'],
            'non-string channel' => [123, 'id', 'channel'],
            'unsupported channel' => ['telegram', 'id', 'channel'],
            'missing external identifier' => ['whatsapp', null, 'external_id'],
            'empty external identifier' => ['whatsapp', '', 'external_id'],
            'whitespace external identifier' => ['whatsapp', '   ', 'external_id'],
            'non-string external identifier' => ['whatsapp', 123, 'external_id'],
            'array external identifier' => ['whatsapp', ['id'], 'external_id'],
        ];
    }
}
