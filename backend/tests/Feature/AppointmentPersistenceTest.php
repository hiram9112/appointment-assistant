<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_persists_instants_buffers_and_relationships(): void
    {
        $appointment = Appointment::factory()->create([
            'starts_at' => Carbon::parse('2030-06-01 12:00:00+02:00'),
            'ends_at' => Carbon::parse('2030-06-01 13:00:00+02:00'),
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 20,
        ])->fresh();

        $this->assertSame('2030-06-01 10:00:00', $appointment->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2030-06-01 11:00:00', $appointment->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(15, $appointment->buffer_before_minutes);
        $this->assertSame(20, $appointment->buffer_after_minutes);
        $this->assertSame('confirmed', $appointment->status);
        $this->assertTrue($appointment->customer->appointments->contains($appointment));
        $this->assertTrue($appointment->service->appointments->contains($appointment));
        $this->assertTrue($appointment->professional->appointments->contains($appointment));
        $this->assertNotNull($appointment->created_at);
        $this->assertNotNull($appointment->updated_at);
    }

    #[DataProvider('invalidReferences')]
    public function test_references_are_required_and_must_exist(string $field, ?int $value, string $sqlState): void
    {
        $professional = Professional::factory()->create();

        try {
            Appointment::factory()->create(array_merge(['professional_id' => $professional->id], [$field => $value]));
            $this->fail('Invalid reference was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }

    public static function invalidReferences(): array
    {
        return [
            'missing customer' => ['customer_id', null, '23502'],
            'missing service' => ['service_id', null, '23502'],
            'missing professional' => ['professional_id', null, '23502'],
            'unknown customer' => ['customer_id', 99999999, '23503'],
            'unknown service' => ['service_id', 99999999, '23503'],
            'unknown professional' => ['professional_id', 99999999, '23503'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_database_rejects_invalid_values(array $attributes): void
    {
        try {
            Appointment::factory()->create($attributes);
            $this->fail('Invalid appointment was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public static function invalidValues(): array
    {
        return [
            'status' => [['status' => 'pending']],
            'zero length' => [['ends_at' => '2030-01-01 10:00:00+00:00']],
            'negative length' => [['ends_at' => '2030-01-01 09:00:00+00:00']],
            'negative before buffer' => [['buffer_before_minutes' => -1]],
            'negative after buffer' => [['buffer_after_minutes' => -1]],
        ];
    }

    #[DataProvider('conflictingOccupancies')]
    public function test_confirmed_occupancy_cannot_overlap(array $first, array $second): void
    {
        $appointment = Appointment::factory()->create($first);

        try {
            Appointment::factory()->create(array_merge($second, ['professional_id' => $appointment->professional_id]));
            $this->fail('Overlapping occupancy was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
            $this->assertStringContainsString('appointments_confirmed_occupancy_exclusion', $exception->getMessage());
        }
    }

    public static function conflictingOccupancies(): array
    {
        return [
            'service intervals' => [[], ['starts_at' => '2030-01-01 10:30:00+00', 'ends_at' => '2030-01-01 11:30:00+00']],
            'before buffer' => [[], ['starts_at' => '2030-01-01 11:10:00+00', 'ends_at' => '2030-01-01 12:00:00+00', 'buffer_before_minutes' => 15]],
            'after buffer' => [['buffer_after_minutes' => 15], ['starts_at' => '2030-01-01 11:10:00+00', 'ends_at' => '2030-01-01 12:00:00+00']],
        ];
    }

    public function test_adjacent_buffered_occupancy_is_allowed(): void
    {
        $first = Appointment::factory()->create(['buffer_after_minutes' => 10]);
        $second = Appointment::factory()->create([
            'professional_id' => $first->professional_id,
            'starts_at' => '2030-01-01 11:15:00+00',
            'ends_at' => '2030-01-01 12:00:00+00',
            'buffer_before_minutes' => 5,
        ]);

        $this->assertTrue($second->exists);
    }

    public function test_different_professionals_can_have_overlapping_appointments(): void
    {
        $first = Appointment::factory()->create();
        $second = Appointment::factory()->for(Professional::factory())->create();

        $this->assertNotSame($first->professional_id, $second->professional_id);
    }

    public function test_offsets_and_session_timezone_do_not_change_occupancy(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $first = Appointment::factory()->create([
            'starts_at' => Carbon::parse('2030-06-01 12:00:00+02:00'),
            'ends_at' => Carbon::parse('2030-06-01 13:00:00+02:00'),
        ]);
        $this->assertSame('2030-06-01 10:00:00', $first->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));

        try {
            Appointment::factory()->create([
                'professional_id' => $first->professional_id,
                'starts_at' => Carbon::parse('2030-06-01 10:30:00+00:00'),
                'ends_at' => Carbon::parse('2030-06-01 11:30:00+00:00'),
            ]);
            $this->fail('Overlapping instants were accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
    }

    public function test_updating_a_historical_appointment_to_confirmed_cannot_create_a_conflict(): void
    {
        $first = Appointment::factory()->create();
        $historical = Appointment::factory()->create(['professional_id' => $first->professional_id, 'status' => 'cancelled']);

        try {
            $historical->update(['status' => 'confirmed']);
            $this->fail('Conflicting status update was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
    }

    #[DataProvider('nonConfirmedStatuses')]
    public function test_non_confirmed_appointments_do_not_block_occupancy(string $status): void
    {
        $historical = Appointment::factory()->create(['status' => $status]);
        $confirmed = Appointment::factory()->create(['professional_id' => $historical->professional_id]);
        $anotherHistorical = Appointment::factory()->create(['professional_id' => $historical->professional_id, 'status' => $status]);

        $this->assertSame($status, $historical->fresh()->status);
        $this->assertTrue($confirmed->exists);
        $this->assertTrue($anotherHistorical->exists);
    }

    public static function nonConfirmedStatuses(): array
    {
        return [['cancelled'], ['completed']];
    }
}
