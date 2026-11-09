<?php

namespace Tests\Feature;

use App\Application\Appointment\AppendAppointmentAudit;
use App\Application\Appointment\CancelAppointment;
use App\Application\Appointment\CancelAppointmentOutcome;
use App\Application\Appointment\RecordAppointmentAttendance;
use App\Application\Appointment\RecordAppointmentAttendanceOutcome as Outcome;
use App\Application\Appointment\RescheduleAppointment;
use App\Application\Appointment\RescheduleAppointmentOutcome;
use App\Application\Availability\PostgresAppointmentOccupancy;
use App\Application\Availability\TimeInterval;
use App\Models\Appointment;
use App\Models\AppointmentAudit;
use App\Models\Business;
use App\Models\ChannelIdentity;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RecordAppointmentAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    private Professional $professional;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new DateTimeImmutable('2026-11-09 10:00 UTC'));
        $this->business = Business::factory()->create();
        $this->customer = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        $service = Service::factory()->for($this->business)->create();
        $this->professional = Professional::factory()->for($this->business)->create();
        $this->appointment = Appointment::factory()->create(['customer_id' => $this->customer->id, 'service_id' => $service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2026-11-09 10:00 UTC', 'ends_at' => '2026-11-09 11:00 UTC', 'buffer_before_minutes' => 5, 'buffer_after_minutes' => 10, 'status' => 'confirmed']);
    }

    public function test_both_outcomes_preserve_history_release_occupancy_and_reject_customer_changes(): void
    {
        foreach (['completed', 'no_show'] as $outcome) {
            $this->appointment->refresh()->update(['status' => 'confirmed']);
            $created = app(AppendAppointmentAudit::class)->created($this->appointment, 'system', 'fixture', 'test');
            $historical = $created->fresh()->getAttributes();
            $original = $this->appointment->fresh()->getAttributes();
            $auditCount = $this->appointment->audits()->count();
            $period = new TimeInterval(new DateTimeImmutable('2026-11-09 09:00 UTC'), new DateTimeImmutable('2026-11-09 12:00 UTC'));
            $occupancy = app(PostgresAppointmentOccupancy::class);
            $this->assertCount(1, $occupancy->forProfessional($this->professional, $period));

            $result = $this->record($outcome);
            $this->assertInstanceOf(Appointment::class, $result);
            $this->assertSame($outcome, $result->status);
            foreach (['id', 'customer_id', 'service_id', 'professional_id', 'starts_at', 'ends_at', 'buffer_before_minutes', 'buffer_after_minutes', 'created_at'] as $field) {
                $this->assertSame($original[$field], $result->getAttributes()[$field], $field);
            }
            $this->assertSame($historical, $created->fresh()->getAttributes());
            $this->assertSame($auditCount + 1, $result->audits()->count());
            $audit = $result->audits()->reorder()->latest('id')->firstOrFail();
            $this->assertSame([$outcome, 'operator', '42', 'admin', false], [$audit->action, $audit->actor_type, $audit->actor_id, $audit->source, $audit->late_notice]);
            $this->assertSame('2026-11-09T10:00:00.000000Z', $audit->occurred_at->utc()->toJSON());
            $this->assertSame('confirmed', $audit->before_state['status']);
            $this->assertSame($outcome, $audit->after_state['status']);
            foreach (['id', 'customer_id', 'service_id', 'professional_id', 'starts_at', 'ends_at', 'buffer_before_minutes', 'buffer_after_minutes', 'created_at'] as $field) {
                $this->assertSame($audit->before_state[$field], $audit->after_state[$field], $field);
            }
            $this->assertSame('2026-11-09T10:00:00.000000Z', $audit->before_state['starts_at']);
            $this->assertCount(0, $occupancy->forProfessional($this->professional, $period));
            $this->assertSame(Outcome::NotEligible, $this->record($outcome));
            $this->assertSame(Outcome::NotEligible, $this->record($outcome === 'completed' ? 'no_show' : 'completed'));
            $this->travelTo(new DateTimeImmutable('2026-11-09 08:00 UTC'));
            $this->assertSame(CancelAppointmentOutcome::NotEligible, app(CancelAppointment::class)->handle($this->business, $this->customer->id, $result->id, 'customer', '42', 'test'));
            $this->assertSame(RescheduleAppointmentOutcome::NotEligible, app(RescheduleAppointment::class)->handle($this->business, $this->customer->id, $result->id, new DateTimeImmutable('2026-11-09 12:00 UTC'), null, 'customer', '42', 'test'));
            $this->assertSame($auditCount + 1, $result->audits()->count());
            $this->travelTo(new DateTimeImmutable('2026-11-09 10:00 UTC'));
        }
    }

    public function test_request_business_lifecycle_and_time_are_validated(): void
    {
        $action = app(RecordAppointmentAttendance::class);
        foreach (['', 'cancelled', 'Completed', 'no-show'] as $outcome) {
            $this->assertSame(Outcome::InvalidRequest, $this->record($outcome));
        }
        foreach ([[' ', '42', 'admin'], ['operator', ' ', 'admin'], ['operator', '42', ' ']] as [$type, $id, $source]) {
            $this->assertSame(Outcome::InvalidRequest, $action->handle($this->business, $this->appointment->id, 'completed', $type, $id, $source));
        }
        $this->assertSame(Outcome::InvalidRequest, $action->handle(new Business, $this->appointment->id, 'completed', 'operator', '42', 'admin'));
        $this->assertSame(Outcome::InvalidAppointment, $action->handle(Business::factory()->create(), $this->appointment->id, 'completed', 'operator', '42', 'admin'));
        foreach ([null, 99999999] as $id) {
            $this->assertSame(Outcome::InvalidAppointment, $action->handle($this->business, $id, 'completed', 'operator', '42', 'admin'));
        }
        foreach (['cancelled', 'completed', 'no_show'] as $status) {
            $this->appointment->update(['status' => $status]);
            $this->assertSame(Outcome::NotEligible, $this->record('completed'));
            $this->assertSame($status, $this->appointment->fresh()->status);
        }
        $this->appointment->update(['status' => 'confirmed']);
        $this->travelTo(new DateTimeImmutable('2026-11-09 09:59:59 UTC'));
        $this->assertSame(Outcome::NotEligible, $this->record('completed'));
        $this->assertSame('confirmed', $this->appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->travelTo(new DateTimeImmutable('2026-11-09 10:00 UTC'));
        $this->assertInstanceOf(Appointment::class, $this->record('completed'));
    }

    public function test_audit_and_persistence_failures_roll_back_and_remain_technical_failures(): void
    {
        app(AppendAppointmentAudit::class)->created($this->appointment, 'system', 'fixture', 'test');
        AppointmentAudit::creating(fn () => throw new RuntimeException('audit failed'));
        try {
            $this->record('completed');
            $this->fail('Expected audit failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            AppointmentAudit::flushEventListeners();
        }
        $this->assertSame('confirmed', $this->appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 1);
        try {
            app(RecordAppointmentAttendance::class)->handle($this->business, $this->appointment->id, 'no_show', str_repeat('x', 256), '42', 'admin');
            $this->fail('Expected database failure');
        } catch (QueryException $exception) {
            $this->assertSame('22001', $exception->errorInfo[0]);
        }
        $this->assertSame('confirmed', $this->appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    private function record(string $outcome): Appointment|Outcome
    {
        return app(RecordAppointmentAttendance::class)->handle($this->business, $this->appointment->id, $outcome, 'operator', '42', 'admin');
    }
}
