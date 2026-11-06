<?php

namespace Tests\Feature;

use App\Application\Appointment\AppendAppointmentAudit;
use App\Models\Appointment;
use App\Models\AppointmentAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_audit_persists_complete_snapshot_and_trusted_context(): void
    {
        $this->travelTo(Carbon::parse('2029-12-01 12:00:00+00:00'));
        $appointment = Appointment::factory()->create(['buffer_before_minutes' => 5, 'buffer_after_minutes' => 10]);
        $appointment->status = 'cancelled';

        $audit = (new AppendAppointmentAudit)->created($appointment, 'system', 'booking-service', 'internal')->fresh();

        $this->assertModelExists($audit);
        $this->assertTrue($audit->appointment->is($appointment));
        $this->assertSame('created', $audit->action);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('booking-service', $audit->actor_id);
        $this->assertSame('internal', $audit->source);
        $this->assertNull($audit->before_state);
        $this->assertFalse($audit->late_notice);
        $this->assertSame('2029-12-01T12:00:00.000000Z', $audit->occurred_at->utc()->toJSON());
        $expected = [
            'id' => $appointment->id,
            'status' => 'confirmed',
            'ends_at' => '2030-01-01T11:00:00.000000Z',
            'starts_at' => '2030-01-01T10:00:00.000000Z',
            'created_at' => '2029-12-01T12:00:00.000000Z',
            'updated_at' => '2029-12-01T12:00:00.000000Z',
            'customer_id' => $appointment->customer_id,
            'service_id' => $appointment->service_id,
            'professional_id' => $appointment->professional_id,
            'buffer_after_minutes' => 10,
            'buffer_before_minutes' => 5,
        ];
        $actual = $audit->after_state;
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_appending_preserves_order_and_historical_snapshots(): void
    {
        $this->travelTo(Carbon::parse('2029-12-01 12:00:00+00:00'));
        $appointment = Appointment::factory()->create();
        $writer = new AppendAppointmentAudit;
        $created = $writer->created($appointment, 'system', 'scheduler', 'internal');
        $appointment->update(['status' => 'cancelled']);
        $next = $writer->handle($appointment, 'example_action', 'operator', '42', 'console', ['status' => 'confirmed'], ['status' => 'cancelled', 'details' => ['reason' => 'example']]);

        $this->assertSame([$created->id, $next->id], $appointment->audits()->pluck('id')->all());
        $this->assertSame('confirmed', $created->fresh()->after_state['status']);
        $this->assertSame(['status' => 'confirmed'], $next->fresh()->before_state);
        $this->assertSame(['status' => 'cancelled', 'details' => ['reason' => 'example']], $next->fresh()->after_state);
        $this->assertSame('operator', $next->actor_type);
        $this->assertSame('42', $next->actor_id);
        $this->assertSame('console', $next->source);
        $this->assertFalse($next->fresh()->late_notice);
        $this->assertDatabaseCount('appointment_audits', 2);
    }

    #[DataProvider('lateNoticeValues')]
    public function test_late_notice_persists_as_a_boolean(bool $lateNotice): void
    {
        $audit = AppointmentAudit::factory()->create(['late_notice' => $lateNotice])->fresh();

        $this->assertSame($lateNotice, $audit->late_notice);
    }

    public static function lateNoticeValues(): array
    {
        return [[true], [false]];
    }

    public function test_existing_audit_receives_default_when_migration_is_applied(): void
    {
        $migration = require database_path('migrations/2026_11_06_160250_align_appointment_status_and_audit_late_notice.php');
        $migration->down();

        $audit = AppointmentAudit::factory()->create(['before_state' => ['status' => 'confirmed'], 'after_state' => ['status' => 'cancelled']]);
        $migration->up();

        $audit->refresh();
        $this->assertFalse($audit->late_notice);
        $this->assertSame(['status' => 'confirmed'], $audit->before_state);
        $this->assertSame(['status' => 'cancelled'], $audit->after_state);
        $this->assertTrue($audit->appointment->audits->contains($audit));
    }

    public function test_audit_history_is_ordered_by_action_time_before_id(): void
    {
        $appointment = Appointment::factory()->create();
        $later = AppointmentAudit::factory()->for($appointment)->create(['occurred_at' => '2030-01-02 12:00:00+00']);
        $earlier = AppointmentAudit::factory()->for($appointment)->create(['occurred_at' => '2030-01-01 12:00:00+00']);

        $this->assertSame([$earlier->id, $later->id], $appointment->audits()->pluck('id')->all());
    }

    public function test_appointment_with_audit_history_cannot_be_deleted(): void
    {
        $audit = AppointmentAudit::factory()->create();

        try {
            $audit->appointment->delete();
            $this->fail('Appointment with audit history was deleted.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
        }
    }

    #[DataProvider('invalidAuditFields')]
    public function test_required_audit_fields_and_reference_are_enforced(string $field, mixed $value, string $sqlState): void
    {
        try {
            AppointmentAudit::factory()->create([$field => $value]);
            $this->fail('Invalid audit was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }

    public static function invalidAuditFields(): array
    {
        return [
            'missing appointment' => ['appointment_id', null, '23502'],
            'unknown appointment' => ['appointment_id', 99999999, '23503'],
            'missing action' => ['action', null, '23502'],
            'missing actor type' => ['actor_type', null, '23502'],
            'missing actor identifier' => ['actor_id', null, '23502'],
            'missing source' => ['source', null, '23502'],
            'missing after state' => ['after_state', null, '23502'],
            'missing timestamp' => ['occurred_at', null, '23502'],
            'missing late notice' => ['late_notice', null, '23502'],
        ];
    }
}
