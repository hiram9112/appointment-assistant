<?php

namespace Tests\Feature;

use App\Application\Customer\RegisterCustomer;
use App\Application\Customer\RegistrationOutcome;
use App\Application\Customer\ResolveCustomer;
use App\Models\ChannelIdentity;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RegisterCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_persists_normalized_names_and_opaque_identity_and_can_be_resolved(): void
    {
        $customer = (new RegisterCustomer)->handle('  José  María ', " García\tde  León ", '+34 912 345 678', 'whatsapp', '  Id:Á/+1  ');

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'first_name' => 'José María', 'last_name' => 'García de León', 'phone' => '+34 912 345 678']);
        $this->assertDatabaseHas('channel_identities', ['customer_id' => $customer->id, 'channel' => 'whatsapp', 'external_id' => '  Id:Á/+1  ']);
        $this->assertTrue($customer->is((new ResolveCustomer)->handle('whatsapp', '  Id:Á/+1  ')));
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
    }

    public function test_registration_without_phone_allows_distinct_identities_with_matching_personal_data(): void
    {
        $register = new RegisterCustomer;
        $first = $register->handle('Ana', 'García', null, 'whatsapp', 'first');
        $second = $register->handle('Ana', 'García', null, 'whatsapp', 'second');

        $this->assertInstanceOf(Customer::class, $first);
        $this->assertInstanceOf(Customer::class, $second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($first->phone);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('channel_identities', 2);
    }

    public function test_matching_phone_numbers_do_not_merge_customers(): void
    {
        $register = new RegisterCustomer;
        $first = $register->handle('Ana', 'García', '+34912345678', 'whatsapp', 'first');
        $second = $register->handle('Ana', 'García', '+34912345678', 'whatsapp', 'second');

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('customers', 2);
    }

    #[DataProvider('validPhoneNumbers')]
    public function test_valid_optional_phone_is_persisted(?string $phone): void
    {
        $customer = (new RegisterCustomer)->handle('Ana', 'García', $phone, 'whatsapp', 'id');

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'phone' => $phone]);
    }

    public static function validPhoneNumbers(): array
    {
        return [
            'no phone' => [null],
            'seven digits' => ['1234567'],
            'fifteen digits' => ['123456789012345'],
            'international with spaces' => ['+34 912 345 678'],
            'international with parentheses and hyphens' => ['+1 (212) 555-0123'],
            'fifteen digits with separators' => ['+12 (345) 678-9012 345'],
        ];
    }

    public function test_existing_identity_returns_already_registered_without_changing_customer(): void
    {
        $identity = ChannelIdentity::factory()->create(['external_id' => 'existing']);
        $before = $identity->customer->getAttributes();

        $result = (new RegisterCustomer)->handle('New', 'Name', null, 'whatsapp', 'existing');

        $this->assertSame(RegistrationOutcome::AlreadyRegistered, $result);
        $this->assertSame($before, $identity->customer->fresh()->getAttributes());
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_without_persistence(array $overrides, string $field): void
    {
        $input = array_replace(['firstName' => 'Ana', 'lastName' => 'García', 'phone' => null, 'channel' => 'whatsapp', 'externalId' => 'id'], $overrides);

        try {
            (new RegisterCustomer)->handle(...$input);
            $this->fail('Invalid registration must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('channel_identities', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'missing first name' => [['firstName' => null], 'first_name'],
            'blank first name' => [['firstName' => '  '], 'first_name'],
            'invalid first name' => [['firstName' => []], 'first_name'],
            'long first name' => [['firstName' => str_repeat('a', 256)], 'first_name'],
            'missing surname' => [['lastName' => null], 'last_name'],
            'blank surname' => [['lastName' => '  '], 'last_name'],
            'long surname' => [['lastName' => str_repeat('a', 256)], 'last_name'],
            'invalid phone' => [['phone' => 'not-a-phone'], 'phone'],
            'blank phone' => [['phone' => ''], 'phone'],
            'short phone' => [['phone' => '123'], 'phone'],
            'phone with only two digits and separators' => [['phone' => '1-----2'], 'phone'],
            'six digit phone' => [['phone' => '123456'], 'phone'],
            'six digit phone with separators' => [['phone' => '+1 (234) 56'], 'phone'],
            'sixteen digit phone' => [['phone' => '1234567890123456'], 'phone'],
            'sixteen digit phone with separators' => [['phone' => '+12 (345) 678-9012 3456'], 'phone'],
            'phone with invalid character' => [['phone' => '+34 912.345.678'], 'phone'],
            'unsupported channel' => [['channel' => 'telegram'], 'channel'],
            'missing channel' => [['channel' => null], 'channel'],
            'missing identity' => [['externalId' => null], 'external_id'],
            'blank identity' => [['externalId' => '  '], 'external_id'],
            'invalid identity' => [['externalId' => []], 'external_id'],
            'long identity' => [['externalId' => str_repeat('a', 256)], 'external_id'],
        ];
    }

    public function test_identity_unique_conflict_rolls_back_customer_and_returns_already_registered(): void
    {
        ChannelIdentity::creating(function (ChannelIdentity $identity): void {
            DB::table('channel_identities')->insert([
                'customer_id' => $identity->customer_id,
                'channel' => $identity->channel,
                'external_id' => $identity->external_id,
            ]);
        });

        try {
            $result = (new RegisterCustomer)->handle('Ana', 'García', null, 'whatsapp', 'race');
        } finally {
            ChannelIdentity::flushEventListeners();
        }

        $this->assertSame(RegistrationOutcome::AlreadyRegistered, $result);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('channel_identities', 0);
    }

    public function test_unrelated_failure_rolls_back_customer_and_remains_a_technical_failure(): void
    {
        ChannelIdentity::creating(fn () => throw new RuntimeException('persistence failure'));

        try {
            (new RegisterCustomer)->handle('Ana', 'García', null, 'whatsapp', 'id');
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('persistence failure', $exception->getMessage());
        } finally {
            ChannelIdentity::flushEventListeners();
        }

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('channel_identities', 0);
    }

    public function test_unrelated_database_constraint_failure_is_not_reported_as_already_registered(): void
    {
        ChannelIdentity::creating(function (): void {
            DB::table('customers')->insert(['first_name' => null, 'last_name' => 'Test']);
        });

        try {
            (new RegisterCustomer)->handle('Ana', 'García', null, 'whatsapp', 'id');
            $this->fail('The database failure must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame('23502', $exception->errorInfo[0]);
        } finally {
            ChannelIdentity::flushEventListeners();
        }

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('channel_identities', 0);
    }

    public function test_unrelated_unique_constraint_failure_is_not_reported_as_already_registered(): void
    {
        $existing = Customer::factory()->create();
        ChannelIdentity::creating(function () use ($existing): void {
            DB::table('customers')->insert(['id' => $existing->id, 'first_name' => 'Other', 'last_name' => 'Person']);
        });

        try {
            (new RegisterCustomer)->handle('Ana', 'García', null, 'whatsapp', 'id');
            $this->fail('The database failure must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        } finally {
            ChannelIdentity::flushEventListeners();
        }

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 0);
    }
}
