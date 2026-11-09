<?php

namespace Tests\Feature;

use App\Application\Appointment\CreateAppointment;
use App\Application\Appointment\CreateAppointmentOutcome;
use App\Application\Appointment\RescheduleAppointment;
use App\Application\Appointment\RescheduleAppointmentOutcome as Outcome;
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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RescheduleAppointmentTest extends TestCase
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
        $this->business = Business::factory()->create(['timezone' => 'UTC', 'rescheduling_notice_minutes' => 120]);
        $this->customer = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        $this->service = Service::factory()->for($this->business)->create(['active' => true, 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $this->professional = Professional::factory()->for($this->business)->create();
        $this->professional->services()->attach($this->service);
        BusinessOperatingHour::factory()->for($this->business)->create(['weekday' => 'monday', 'opens_at' => '08:00', 'closes_at' => '18:00']);
        ProfessionalAvailabilityInterval::factory()->for($this->professional)->create(['weekday' => 'monday', 'starts_at' => '08:00', 'ends_at' => '18:00']);
        $this->appointment = Appointment::factory()->create(['customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2026-11-09 10:00 UTC', 'ends_at' => '2026-11-09 11:00 UTC', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'status' => 'confirmed']);
    }

    public function test_reschedules_same_row_using_current_service_configuration_and_self_exclusion(): void
    {
        $this->service->update(['duration_minutes' => 45, 'buffer_before_minutes' => 5, 'buffer_after_minutes' => 10]);
        $result = $this->reschedule('2026-11-09 10:30 UTC');
        $this->assertInstanceOf(Appointment::class, $result);
        $this->assertSame([$this->appointment->id, $this->customer->id, $this->service->id, $this->professional->id, 'confirmed'], [$result->id, $result->customer_id, $result->service_id, $result->professional_id, $result->status]);
        $this->assertSame('10:30', $result->starts_at->format('H:i'));
        $this->assertSame('11:15', $result->ends_at->format('H:i'));
        $this->assertSame([5, 10], [$result->buffer_before_minutes, $result->buffer_after_minutes]);
        $this->assertDatabaseCount('appointments', 1);
        $audit = $result->audits()->sole();
        $this->assertSame(['rescheduled', 'customer', '123', 'test', false], [$audit->action, $audit->actor_type, $audit->actor_id, $audit->source, $audit->late_notice]);
        $this->assertSame('2026-11-09T10:00:00.000000Z', $audit->before_state['starts_at']);
        $this->assertSame('2026-11-09T10:30:00.000000Z', $audit->after_state['starts_at']);
    }

    public function test_selected_professional_is_used_and_recorded_in_same_audit(): void
    {
        $other = Professional::factory()->for($this->business)->create();
        $other->services()->attach($this->service);
        ProfessionalAvailabilityInterval::factory()->for($other)->create(['weekday' => 'monday', 'starts_at' => '08:00', 'ends_at' => '18:00']);
        $result = $this->reschedule('2026-11-09 12:00 UTC', $other->id);
        $this->assertInstanceOf(Appointment::class, $result);
        $audit = $result->audits()->sole();
        $this->assertSame($this->professional->id, $audit->before_state['professional_id']);
        $this->assertSame($other->id, $audit->after_state['professional_id']);
    }

    public function test_success_releases_original_occupancy_and_establishes_replacement_occupancy(): void
    {
        $this->assertInstanceOf(Appointment::class, $this->reschedule());
        $create = app(CreateAppointment::class);
        $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $create->handle($this->business, $this->customer->id, $this->service->id, $this->professional->id, new DateTimeImmutable('2026-11-09 12:00 UTC'), 'customer', '123', 'test'));
        $this->assertInstanceOf(Appointment::class, $create->handle($this->business, $this->customer->id, $this->service->id, $this->professional->id, new DateTimeImmutable('2026-11-09 10:00 UTC'), 'customer', '123', 'test'));
    }

    public function test_identity_ownership_and_lifecycle_are_enforced(): void
    {
        $action = app(RescheduleAppointment::class);
        foreach ([null, 99999999, Customer::factory()->create()->id] as $customerId) {
            $this->assertSame(Outcome::IdentificationFailed, $action->handle($this->business, $customerId, $this->appointment->id, new DateTimeImmutable('2026-11-09 12:00 UTC'), null, 'customer', '123', 'test'));
        }
        $other = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        $this->assertSame(Outcome::InvalidAppointment, $action->handle($this->business, $other->id, $this->appointment->id, new DateTimeImmutable('2026-11-09 12:00 UTC'), null, 'customer', '123', 'test'));
        $this->assertSame(Outcome::InvalidAppointment, $action->handle($this->business, $this->customer->id, null, new DateTimeImmutable('2026-11-09 12:00 UTC'), null, 'customer', '123', 'test'));
        $this->assertSame(Outcome::InvalidAppointment, $action->handle(Business::factory()->create(), $this->customer->id, $this->appointment->id, new DateTimeImmutable('2026-11-09 12:00 UTC'), null, 'customer', '123', 'test'));
        foreach (['cancelled', 'completed', 'no_show'] as $status) {
            $this->appointment->update(['status' => $status]);
            $this->assertSame(Outcome::NotEligible, $this->reschedule());
        }
        $this->appointment->update(['status' => 'confirmed', 'starts_at' => '2026-11-09 07:00 UTC', 'ends_at' => '2026-11-09 08:00 UTC']);
        $this->assertSame(Outcome::NotEligible, $this->reschedule());
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_invalid_professionals_unavailable_slots_and_safety_threshold_are_rejected(): void
    {
        $other = Professional::factory()->create();
        $ineligible = Professional::factory()->for($this->business)->create();
        $this->assertSame(Outcome::InvalidProfessional, $this->reschedule(professionalId: $other->id));
        $this->assertSame(Outcome::InvalidProfessional, $this->reschedule(professionalId: $ineligible->id));
        $this->assertSame(Outcome::InvalidStart, $this->reschedule('2026-11-09 07:59 UTC'));
        $this->assertSame(Outcome::SlotUnavailable, $this->reschedule('2026-11-09 08:01:59 UTC'));
        Appointment::factory()->create(['customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2026-11-09 12:00 UTC', 'ends_at' => '2026-11-09 13:00 UTC', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'status' => 'confirmed']);
        $this->assertSame(Outcome::SlotUnavailable, $this->reschedule());
        $this->assertSame('10:00', $this->appointment->fresh()->starts_at->format('H:i'));
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertInstanceOf(Appointment::class, $this->reschedule('2026-11-09 08:02 UTC'));
    }

    public function test_notice_boundary_late_and_no_recommendation(): void
    {
        $this->assertInstanceOf(Appointment::class, $this->reschedule('2026-11-09 12:00 UTC'));
        $this->assertFalse($this->appointment->audits()->reorder()->latest('id')->first()->late_notice);
        $this->travelTo(new DateTimeImmutable('2026-11-09 10:01 UTC'));
        $this->assertInstanceOf(Appointment::class, $this->reschedule('2026-11-09 15:00 UTC'));
        $this->assertTrue($this->appointment->audits()->reorder()->latest('id')->first()->late_notice);
        $this->business->update(['rescheduling_notice_minutes' => null]);
        $this->travelTo(new DateTimeImmutable('2026-11-09 14:30 UTC'));
        $this->assertInstanceOf(Appointment::class, $this->reschedule('2026-11-09 16:00 UTC'));
        $this->assertFalse($this->appointment->audits()->reorder()->latest('id')->first()->late_notice);
    }

    public function test_rescheduling_before_notice_boundary_is_not_late(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-11-09 07:59:59 UTC'));

        $result = $this->reschedule('2026-11-09 12:00 UTC');

        $this->assertInstanceOf(Appointment::class, $result);
        $audit = $result->audits()->sole();
        $this->assertSame('rescheduled', $audit->action);
        $this->assertFalse($audit->late_notice);
    }

    public function test_inactive_original_service_is_ineligible_not_an_unavailable_slot(): void
    {
        $this->service->update(['active' => false]);

        $this->assertSame(Outcome::NotEligible, $this->reschedule());
        $appointment = $this->appointment->fresh();
        $this->assertSame('10:00', $appointment->starts_at->format('H:i'));
        $this->assertSame('11:00', $appointment->ends_at->format('H:i'));
        $this->assertSame('confirmed', $appointment->status);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_unchanged_operation_does_not_create_audit(): void
    {
        $this->assertInstanceOf(Appointment::class, $this->reschedule('2026-11-09 10:00 UTC'));
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_unchanged_operation_during_late_notice_does_not_create_audit(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-11-09 09:30 UTC'));

        $result = $this->reschedule('2026-11-09 10:00 UTC');

        $this->assertInstanceOf(Appointment::class, $result);
        $this->assertSame($this->appointment->id, $result->id);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_audit_failure_rolls_back_update_and_remains_a_technical_failure(): void
    {
        AppointmentAudit::creating(fn () => throw new RuntimeException('audit failed'));
        try {
            $this->reschedule();
            $this->fail('Expected audit failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            AppointmentAudit::flushEventListeners();
        }
        $this->assertSame('10:00', $this->appointment->fresh()->starts_at->format('H:i'));
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_exclusion_conflict_during_update_is_translated_and_rolled_back(): void
    {
        Appointment::updating(function (): void {
            DB::table('appointments')->insert(['customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2026-11-09 12:00 UTC', 'ends_at' => '2026-11-09 13:00 UTC', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'status' => 'confirmed']);
        });
        try {
            $this->assertSame(Outcome::SlotUnavailable, $this->reschedule());
        } finally {
            Appointment::flushEventListeners();
        }
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertSame('10:00', $this->appointment->fresh()->starts_at->format('H:i'));
    }

    public function test_unrelated_database_failure_is_not_a_slot_conflict(): void
    {
        try {
            app(RescheduleAppointment::class)->handle($this->business, $this->customer->id, $this->appointment->id, new DateTimeImmutable('2026-11-09 12:00 UTC'), null, str_repeat('x', 256), '123', 'test');
            $this->fail('Expected database failure');
        } catch (QueryException $exception) {
            $this->assertSame('22001', $exception->errorInfo[0]);
        }
        $this->assertSame('10:00', $this->appointment->fresh()->starts_at->format('H:i'));
    }

    public function test_reference_business_rescheduling_uses_seeded_scheduling_configuration(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $service = $business->services()->where('name', 'Initial beauty consultation')->firstOrFail();
        $professional = $business->professionals()->where('first_name', 'Laura')->firstOrFail();
        $created = app(CreateAppointment::class)->handle($business, $this->customer->id, $service->id, $professional->id, new DateTimeImmutable('2026-11-10 12:00 Europe/Madrid'), 'customer', '123', 'test');
        $this->assertInstanceOf(Appointment::class, $created);
        $result = app(RescheduleAppointment::class)->handle($business, $this->customer->id, $created->id, new DateTimeImmutable('2026-11-10 13:00 Europe/Madrid'), null, 'customer', '123', 'test');
        $this->assertInstanceOf(Appointment::class, $result);
        $this->assertSame($created->id, $result->id);
        $this->assertSame('2026-11-10 12:00', $result->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(2, $result->audits()->count());
    }

    private function reschedule(string $start = '2026-11-09 12:00 UTC', ?int $professionalId = null): Appointment|Outcome
    {
        return app(RescheduleAppointment::class)->handle($this->business, $this->customer->id, $this->appointment->id, new DateTimeImmutable($start), $professionalId, 'customer', '123', 'test');
    }
}
