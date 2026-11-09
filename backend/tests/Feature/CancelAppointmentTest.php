<?php

namespace Tests\Feature;

use App\Application\Appointment\AppendAppointmentAudit;
use App\Application\Appointment\CancelAppointment;
use App\Application\Appointment\CancelAppointmentOutcome as Outcome;
use App\Application\Appointment\CreateAppointment;
use App\Application\Availability\PostgresAppointmentOccupancy;
use App\Application\Availability\TimeInterval;
use App\Models\Appointment;
use App\Models\AppointmentAudit;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\ChannelIdentity;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CancelAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    private Service $service;

    private Professional $professional;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new DateTimeImmutable('2026-11-09 08:00 UTC'));
        $this->business = Business::factory()->create(['timezone' => 'UTC', 'cancellation_notice_minutes' => 120, 'rescheduling_notice_minutes' => 0]);
        $this->customer = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        $this->service = Service::factory()->for($this->business)->create(['active' => true, 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $this->professional = Professional::factory()->for($this->business)->create();
        $this->professional->services()->attach($this->service);
        BusinessOperatingHour::factory()->for($this->business)->create(['weekday' => 'monday', 'opens_at' => '08:00', 'closes_at' => '18:00']);
        ProfessionalAvailabilityInterval::factory()->for($this->professional)->create(['weekday' => 'monday', 'starts_at' => '08:00', 'ends_at' => '18:00']);
        $this->appointment = Appointment::factory()->create(['customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2026-11-09 10:00 UTC', 'ends_at' => '2026-11-09 11:00 UTC', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'status' => 'confirmed']);
    }

    public function test_cancellation_preserves_information_and_history_and_records_audit(): void
    {
        app(AppendAppointmentAudit::class)->created($this->appointment, 'system', 'fixture', 'test');
        $original = $this->appointment->fresh()->getAttributes();
        $this->service->update(['active' => false]);
        $this->professional->services()->detach($this->service);
        $result = $this->cancel();
        $this->assertInstanceOf(Appointment::class, $result);
        foreach (['id', 'customer_id', 'service_id', 'professional_id', 'starts_at', 'ends_at', 'buffer_before_minutes', 'buffer_after_minutes', 'created_at'] as $field) {
            $this->assertSame($original[$field], $result->getAttributes()[$field], $field);
        }
        $this->assertSame('cancelled', $result->status);
        $this->assertDatabaseCount('appointments', 1);
        $audits = $result->audits()->get();
        $this->assertCount(2, $audits);
        $this->assertSame('created', $audits[0]->action);
        $audit = $audits[1];
        $this->assertSame(['cancelled', 'customer', '123', 'test', false], [$audit->action, $audit->actor_type, $audit->actor_id, $audit->source, $audit->late_notice]);
        $this->assertSame('2026-11-09T08:00:00.000000Z', $audit->occurred_at->utc()->toJSON());
        $this->assertSame('confirmed', $audit->before_state['status']);
        $this->assertSame('cancelled', $audit->after_state['status']);
        foreach (['id', 'customer_id', 'service_id', 'professional_id', 'starts_at', 'ends_at', 'buffer_before_minutes', 'buffer_after_minutes', 'created_at'] as $field) {
            $this->assertSame($audit->before_state[$field], $audit->after_state[$field], $field);
        }
        $this->assertSame('2026-11-09T10:00:00.000000Z', $audit->before_state['starts_at']);
        $this->assertSame(Outcome::NotEligible, $this->cancel());
        $this->assertCount(2, $result->audits()->get());
    }

    public function test_identity_ownership_context_and_lifecycle_are_enforced(): void
    {
        $action = app(CancelAppointment::class);
        foreach ([null, 99999999, Customer::factory()->create()->id] as $id) {
            $this->assertSame(Outcome::IdentificationFailed, $action->handle($this->business, $id, $this->appointment->id, 'customer', '123', 'test'));
        }
        $other = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        foreach ([[$other->id, $this->appointment->id], [$this->customer->id, null], [$this->customer->id, 99999999]] as [$customerId, $appointmentId]) {
            $this->assertSame(Outcome::InvalidAppointment, $action->handle($this->business, $customerId, $appointmentId, 'customer', '123', 'test'));
        }
        $this->assertSame(Outcome::InvalidAppointment, $action->handle(Business::factory()->create(), $this->customer->id, $this->appointment->id, 'customer', '123', 'test'));
        $this->assertSame(Outcome::InvalidRequest, $action->handle(new Business, $this->customer->id, $this->appointment->id, 'customer', '123', 'test'));
        foreach ([[' ', '123', 'test'], ['customer', ' ', 'test'], ['customer', '123', ' ']] as [$type, $id, $source]) {
            $this->assertSame(Outcome::InvalidRequest, $action->handle($this->business, $this->customer->id, $this->appointment->id, $type, $id, $source));
        }
        $this->assertSame('confirmed', $this->appointment->fresh()->status);
        foreach (['cancelled', 'completed', 'no_show'] as $status) {
            $this->appointment->update(['status' => $status]);
            $this->assertSame(Outcome::NotEligible, $this->cancel());
            $this->assertSame($status, $this->appointment->fresh()->status);
        }
        foreach (['2026-11-09 07:00 UTC', '2026-11-09 08:00 UTC'] as $start) {
            $this->appointment->update(['status' => 'confirmed', 'starts_at' => $start, 'ends_at' => (new DateTimeImmutable($start))->modify('+1 hour')]);
            $this->assertSame(Outcome::NotEligible, $this->cancel());
        }
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_notice_is_advisory_and_independent_of_rescheduling(): void
    {
        foreach ([['2026-11-09 07:59:59 UTC', 120, 0, false], ['2026-11-09 08:00 UTC', 120, 0, false], ['2026-11-09 08:00:01 UTC', 120, 0, true], ['2026-11-09 09:59 UTC', null, 1000, false], ['2026-11-09 09:00 UTC', 0, 1000, false]] as [$now, $notice, $rescheduling, $late]) {
            $this->appointment->refresh()->update(['status' => 'confirmed']);
            $this->business->update(['cancellation_notice_minutes' => $notice, 'rescheduling_notice_minutes' => $rescheduling]);
            $this->travelTo(new DateTimeImmutable($now));
            $result = $this->cancel();
            $this->assertInstanceOf(Appointment::class, $result, $now.' '.($result instanceof Outcome ? $result->name : ''));
            $audit = $result->audits()->reorder()->latest('id')->firstOrFail();
            $this->assertSame($late, $audit->late_notice, $now);
        }
    }

    public function test_released_occupancy_allows_new_booking(): void
    {
        $period = new TimeInterval(new DateTimeImmutable('2026-11-09 09:00 UTC'), new DateTimeImmutable('2026-11-09 12:00 UTC'));
        $occupancy = app(PostgresAppointmentOccupancy::class);
        $this->assertCount(1, $occupancy->forProfessional($this->professional, $period));
        $this->assertInstanceOf(Appointment::class, $this->cancel());
        $this->assertCount(0, $occupancy->forProfessional($this->professional, $period));
        $this->assertInstanceOf(Appointment::class, app(CreateAppointment::class)->handle($this->business, $this->customer->id, $this->service->id, $this->professional->id, new DateTimeImmutable('2026-11-09 10:00 UTC'), 'customer', '123', 'test'));
    }

    public function test_audit_failure_rolls_back_and_remains_a_technical_failure(): void
    {
        app(AppendAppointmentAudit::class)->created($this->appointment, 'system', 'fixture', 'test');
        AppointmentAudit::creating(fn () => throw new RuntimeException('audit failed'));
        try {
            $this->cancel();
            $this->fail('Expected audit failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            AppointmentAudit::flushEventListeners();
        }
        $this->assertSame('confirmed', $this->appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 1);
        try {
            app(CancelAppointment::class)->handle($this->business, $this->customer->id, $this->appointment->id, str_repeat('x', 256), '123', 'test');
            $this->fail('Expected database failure');
        } catch (QueryException $exception) {
            $this->assertSame('22001', $exception->errorInfo[0]);
        }
        $this->assertSame('confirmed', $this->appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    private function cancel(): Appointment|Outcome
    {
        return app(CancelAppointment::class)->handle($this->business, $this->customer->id, $this->appointment->id, 'customer', '123', 'test');
    }
}
