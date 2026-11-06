<?php

namespace Tests\Feature;

use App\Application\Appointment\CustomerAppointment;
use App\Application\Appointment\GetCustomerAppointments;
use App\Application\Appointment\GetCustomerAppointmentsOutcome;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\ChannelIdentity;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Database\Seeders\BusinessSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class GetCustomerAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_six_fields_with_configured_name_and_business_timezone(): void
    {
        $customer = $this->registeredCustomer();
        $business = Business::factory()->create(['timezone' => 'Europe/Madrid']);
        $service = Service::factory()->for($business)->create(['name' => 'Facial', 'active' => false]);
        $professional = Professional::factory()->for($business)->create(['display_name' => 'Dra. Laura']);
        $appointment = Appointment::factory()->for($customer)->for($service)->for($professional)->create([
            'starts_at' => '2026-01-15 10:00:00+00', 'ends_at' => '2026-01-15 11:00:00+00',
        ]);
        $before = $appointment->fresh()->getAttributes();

        $results = app(GetCustomerAppointments::class)->handle($customer->id);

        $this->assertCount(1, $results);
        $item = $results[0];
        $this->assertInstanceOf(CustomerAppointment::class, $item);
        $this->assertSame(['id', 'service_name', 'professional_name', 'starts_at', 'ends_at', 'status'], array_keys(get_object_vars($item)));
        $this->assertSame([$appointment->id, 'Facial', 'Dra. Laura', 'confirmed'], [$item->id, $item->service_name, $item->professional_name, $item->status]);
        $this->assertSame('2026-01-15T11:00:00+01:00', $item->starts_at->format('c'));
        $this->assertSame('2026-01-15T12:00:00+01:00', $item->ends_at->format('c'));
        $this->assertSame('2026-01-15T10:00:00+00:00', $item->starts_at->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2026-01-15T11:00:00+00:00', $item->ends_at->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_returns_all_owned_statuses_and_dates_in_deterministic_order(): void
    {
        $customer = $this->registeredCustomer();
        $other = $this->registeredCustomer();
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create();
        $professional = Professional::factory()->for($business)->create(['display_name' => null, 'first_name' => 'Laura', 'last_name' => 'Martín']);
        $past = Appointment::factory()->for($customer)->for($service)->for($professional)->create(['starts_at' => '2020-01-01 10:00+00', 'ends_at' => '2020-01-01 11:00+00', 'status' => 'confirmed']);
        $future = Appointment::factory()->for($customer)->for($service)->for($professional)->create(['starts_at' => '2030-07-01 10:00+00', 'ends_at' => '2030-07-01 11:00+00', 'status' => 'cancelled']);
        $tie = Appointment::factory()->for($customer)->for($service)->for($professional)->create(['starts_at' => '2030-07-01 10:00+00', 'ends_at' => '2030-07-01 11:00+00', 'status' => 'completed']);
        Appointment::factory()->for($other)->for($service)->for($professional)->create(['status' => 'cancelled']);

        $results = app(GetCustomerAppointments::class)->handle($customer->id);

        $this->assertSame([$tie->id, $future->id, $past->id], array_column($results, 'id'));
        $this->assertSame(['completed', 'cancelled', 'confirmed'], array_column($results, 'status'));
        $this->assertSame('Laura Martín', $results[0]->professional_name);
        $this->assertSame('2030-07-01T12:00:00+02:00', $results[0]->starts_at->format('c'));
        $this->assertSame('2030-07-01T13:00:00+02:00', $results[0]->ends_at->format('c'));
        $this->assertSame('2030-07-01T10:00:00+00:00', $results[0]->starts_at->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2030-07-01T11:00:00+00:00', $results[0]->ends_at->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function test_empty_and_invalid_identity_outcomes_are_distinct(): void
    {
        $customer = $this->registeredCustomer();
        $unregistered = Customer::factory()->create();
        $action = app(GetCustomerAppointments::class);

        $this->assertSame([], $action->handle($customer->id));
        $this->assertSame(GetCustomerAppointmentsOutcome::IdentificationFailed, $action->handle(null));
        $this->assertSame(GetCustomerAppointmentsOutcome::IdentificationFailed, $action->handle(99999999));
        $this->assertSame(GetCustomerAppointmentsOutcome::IdentificationFailed, $action->handle($unregistered->id));
    }

    public function test_multiple_appointments_use_bounded_read_queries_without_writes(): void
    {
        $customer = $this->registeredCustomer();
        for ($i = 0; $i < 12; $i++) {
            Appointment::factory()->for($customer)->create(['status' => 'cancelled']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();

        $results = app(GetCustomerAppointments::class)->handle($customer->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(12, $results);
        $this->assertCount(5, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
    }

    public function test_database_failure_is_not_converted_to_an_empty_result(): void
    {
        $customer = $this->registeredCustomer();
        $throw = true;
        DB::listen(function (QueryExecuted $query) use (&$throw): void {
            if ($throw && str_contains($query->sql, 'appointments')) {
                $throw = false;
                throw new RuntimeException('Persistence failure');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Persistence failure');
        app(GetCustomerAppointments::class)->handle($customer->id);
    }

    public function test_lumina_configuration_is_used_for_appointment_information(): void
    {
        $this->seed(BusinessSeeder::class);
        $business = Business::query()->where('name', 'Lúmina Estética Avanzada')->sole();
        $customer = $this->registeredCustomer();
        $service = $business->services()->where('name', 'Deep facial cleansing')->sole();
        $professional = $business->professionals()->where('first_name', 'Laura')->sole();
        Appointment::factory()->for($customer)->for($service)->for($professional)->create(['starts_at' => '2026-11-09 09:00+00', 'ends_at' => '2026-11-09 10:00+00']);

        $results = app(GetCustomerAppointments::class)->handle($customer->id);

        $this->assertSame('Deep facial cleansing', $results[0]->service_name);
        $this->assertSame('Laura Martín', $results[0]->professional_name);
        $this->assertSame('2026-11-09T10:00:00+01:00', $results[0]->starts_at->format('c'));
    }

    private function registeredCustomer(): Customer
    {
        $customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($customer)->create();

        return $customer;
    }
}
