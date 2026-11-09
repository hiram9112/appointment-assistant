<?php

namespace Tests\Feature;

use App\Application\Appointment\AppendAppointmentAudit;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\ChannelIdentity;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RescheduleAppointmentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_and_rescheduling_cannot_commit_overlapping_occupancy(): void
    {
        $this->withCommittedSchedule(function ($business, $customer, $service, $professional, $first): void {
            $this->runRace($business, $customer, $service, $professional, [
                ['create', 0, '2026-11-09 12:00 UTC', '2026-11-09 08:00 UTC'],
                ['reschedule', $first->id, '2026-11-09 12:30 UTC', '2026-11-09 08:00 UTC'],
            ], true, function ($db) use ($first, $professional): void {
                $appointments = $db->table('appointments')->where('professional_id', $professional->id)->orderBy('starts_at')->get();
                $this->assertCount(3, $appointments);
                $this->assertSame($first->id, $appointments[0]->id);
                $this->assertSame('10:00', substr($appointments[0]->starts_at, 11, 5));
                $this->assertSame('11:00', substr($appointments[0]->ends_at, 11, 5));
                $this->assertSame('confirmed', $appointments[0]->status);
                $this->assertSame('12:00', substr($appointments[1]->starts_at, 11, 5));
                $this->assertSame('13:00', substr($appointments[1]->ends_at, 11, 5));
                $this->assertSame('14:00', substr($appointments[2]->starts_at, 11, 5));
                $this->assertSame('15:00', substr($appointments[2]->ends_at, 11, 5));
                $audits = $db->table('appointment_audits')->whereIn('appointment_id', $appointments->pluck('id'))->get();
                $this->assertCount(3, $audits);
                $this->assertCount(1, $audits->where('appointment_id', $first->id));
                $this->assertCount(1, $audits->where('appointment_id', $appointments[1]->id)->where('action', 'created'));
                $this->assertCount(0, $audits->where('action', 'rescheduled'));
            });
        });
    }

    public function test_concurrent_reschedulings_cannot_commit_overlapping_occupancy(): void
    {
        $this->withCommittedSchedule(function ($business, $customer, $service, $professional, $first, $second): void {
            $this->runRace($business, $customer, $service, $professional, [
                ['reschedule', $first->id, '2026-11-09 12:00 UTC', '2026-11-09 08:00 UTC'],
                ['reschedule', $second->id, '2026-11-09 12:30 UTC', '2026-11-09 08:00 UTC'],
            ], true, function ($db) use ($first, $second, $professional): void {
                $appointments = $db->table('appointments')->where('professional_id', $professional->id)->orderBy('id')->get()->keyBy('id');
                $this->assertCount(2, $appointments);
                $this->assertSame('12:00', substr($appointments[$first->id]->starts_at, 11, 5));
                $this->assertSame('13:00', substr($appointments[$first->id]->ends_at, 11, 5));
                $this->assertSame('14:00', substr($appointments[$second->id]->starts_at, 11, 5));
                $this->assertSame('15:00', substr($appointments[$second->id]->ends_at, 11, 5));
                $this->assertSame('confirmed', $appointments[$second->id]->status);
                $audits = $db->table('appointment_audits')->whereIn('appointment_id', [$first->id, $second->id])->get();
                $this->assertCount(3, $audits);
                $this->assertCount(1, $audits->where('appointment_id', $second->id)->where('action', 'created'));
                $this->assertCount(2, $audits->where('appointment_id', $first->id));
                $changed = $audits->where('action', 'rescheduled')->sole();
                $this->assertSame($first->id, $changed->appointment_id);
                $this->assertSame('2026-11-09T10:00:00.000000Z', json_decode($changed->before_state, true)['starts_at']);
                $this->assertSame('2026-11-09T12:00:00.000000Z', json_decode($changed->after_state, true)['starts_at']);
            });
        });
    }

    public function test_same_appointment_is_locked_and_eligibility_revalidated_after_concurrent_update(): void
    {
        $this->withCommittedSchedule(function ($business, $customer, $service, $professional, $first): void {
            $this->runRace($business, $customer, $service, $professional, [
                ['reschedule', $first->id, '2026-11-09 09:00 UTC', '2026-11-09 08:00 UTC'],
                ['reschedule', $first->id, '2026-11-09 16:00 UTC', '2026-11-09 09:30 UTC'],
            ], false, function ($db) use ($first): void {
                $appointment = $db->table('appointments')->where('id', $first->id)->first();
                $this->assertSame('09:00', substr($appointment->starts_at, 11, 5));
                $this->assertSame('10:00', substr($appointment->ends_at, 11, 5));
                $this->assertSame('confirmed', $appointment->status);
                $audits = $db->table('appointment_audits')->where('appointment_id', $first->id)->get();
                $this->assertCount(2, $audits);
                $this->assertCount(1, $audits->where('action', 'created'));
                $changed = $audits->where('action', 'rescheduled')->sole();
                $this->assertSame('2026-11-09T10:00:00.000000Z', json_decode($changed->before_state, true)['starts_at']);
                $this->assertSame('2026-11-09T09:00:00.000000Z', json_decode($changed->after_state, true)['starts_at']);
            });
        });
    }

    private function withCommittedSchedule(callable $test): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.reschedule_race' => config("database.connections.$default")]);
        DB::setDefaultConnection('reschedule_race');
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $customer = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        $service = Service::factory()->for($business)->create(['active' => true, 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $professional = Professional::factory()->for($business)->create();
        $professional->services()->attach($service);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '08:00', 'closes_at' => '18:00']);
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'monday', 'starts_at' => '08:00', 'ends_at' => '18:00']);
        $attributes = ['customer_id' => $customer->id, 'service_id' => $service->id, 'professional_id' => $professional->id, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'status' => 'confirmed'];
        $first = Appointment::factory()->create([...$attributes, 'starts_at' => '2026-11-09 10:00 UTC', 'ends_at' => '2026-11-09 11:00 UTC']);
        $second = Appointment::factory()->create([...$attributes, 'starts_at' => '2026-11-09 14:00 UTC', 'ends_at' => '2026-11-09 15:00 UTC']);
        $audit = new AppendAppointmentAudit;
        $audit->created($first, 'system', 'fixture', 'test');
        $audit->created($second, 'system', 'fixture', 'test');
        DB::setDefaultConnection($default);

        try {
            $test($business, $customer, $service, $professional, $first, $second);
        } finally {
            $db = DB::connection('reschedule_race');
            $ids = $db->table('appointments')->where('professional_id', $professional->id)->pluck('id');
            $db->table('appointment_audits')->whereIn('appointment_id', $ids)->delete();
            $db->table('appointments')->whereIn('id', $ids)->delete();
            $db->table('professional_service')->where('professional_id', $professional->id)->delete();
            $db->table('professional_availability_intervals')->where('professional_id', $professional->id)->delete();
            $db->table('business_operating_hours')->where('business_id', $business->id)->delete();
            $db->table('channel_identities')->where('customer_id', $customer->id)->delete();
            $db->table('customers')->where('id', $customer->id)->delete();
            $db->table('services')->where('id', $service->id)->delete();
            $db->table('professionals')->where('id', $professional->id)->delete();
            $db->table('businesses')->where('id', $business->id)->delete();
            DB::purge('reschedule_race');
        }
    }

    private function runRace(Business $business, Customer $customer, Service $service, Professional $professional, array $operations, bool $availabilityBarrier, callable $assertState): void
    {
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Illuminate\Support\Carbon::setTestNow($argv[9]);
            $pid = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid;
            fwrite(STDOUT, "pid:$pid\n");
            if ($argv[10] === 'first') {
                App\Models\AppointmentAudit::creating(function (): void {
                    fwrite(STDOUT, "updated\n");
                    if (trim(fgets(STDIN)) !== 'commit') { throw new RuntimeException('Commit barrier failed'); }
                });
            }
            $occupancy = new class($argv[11] === 'barrier') implements App\Application\Availability\ReschedulingAppointmentOccupancy {
                public function __construct(private bool $barrier) {}
                private function sync(array $intervals): array {
                    if ($this->barrier) {
                        $this->barrier = false;
                        fwrite(STDOUT, "ready\n");
                        if (trim(fgets(STDIN)) !== 'go') { throw new RuntimeException('Availability barrier failed'); }
                    }
                    return $intervals;
                }
                public function forProfessional(App\Models\Professional $professional, App\Application\Availability\TimeInterval $period): array {
                    return $this->sync((new App\Application\Availability\PostgresAppointmentOccupancy)->forProfessional($professional, $period));
                }
                public function forProfessionalExcludingAppointment(App\Models\Professional $professional, App\Application\Availability\TimeInterval $period, int $id): array {
                    return $this->sync((new App\Application\Availability\PostgresAppointmentOccupancy)->forProfessionalExcludingAppointment($professional, $period, $id));
                }
            };
            try {
                $availability = new App\Application\Availability\CalculateAvailability($occupancy);
                $audit = new App\Application\Appointment\AppendAppointmentAudit;
                $business = App\Models\Business::findOrFail($argv[1]);
                if ($argv[5] === 'create') {
                    $result = (new App\Application\Appointment\CreateAppointment($availability, $audit))->handle($business, (int) $argv[2], (int) $argv[3], (int) $argv[4], new DateTimeImmutable($argv[7]), 'system', 'race', 'test');
                } else {
                    $result = (new App\Application\Appointment\RescheduleAppointment($availability, $audit))->handle($business, (int) $argv[2], (int) $argv[6], new DateTimeImmutable($argv[7]), null, 'system', 'race', 'test');
                }
                fwrite(STDOUT, ($result instanceof App\Models\Appointment ? 'success' : $result->name)."\n");
            } catch (Throwable $e) {
                fwrite(STDERR, get_class($e).': '.$e->getMessage());
                exit(1);
            }
            PHP;

        $workers = [];
        $pids = [];
        try {
            foreach ($operations as $i => [$operation, $appointmentId, $start, $now]) {
                $process = proc_open([PHP_BINARY, '-r', $code, (string) $business->id, (string) $customer->id, (string) $service->id, (string) $professional->id, $operation, (string) $appointmentId, $start, 'unused', $now, $i === 0 ? 'first' : 'second', $availabilityBarrier ? 'barrier' : 'none'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']));
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
                if (! $availabilityBarrier && $i === 0) {
                    $line = fgets($pipes[1]);
                    $this->assertMatchesRegularExpression('/^pid:\d+\n$/', $line ?: '');
                    $pids[] = (int) substr($line, 4);
                    $this->assertSame("updated\n", fgets($pipes[1]));
                }
            }
            foreach ($workers as $index => [, $pipes]) {
                if (! $availabilityBarrier && $index === 0) {
                    continue;
                }
                $line = fgets($pipes[1]);
                $this->assertMatchesRegularExpression('/^pid:\d+\n$/', $line ?: '');
                $pids[] = (int) substr($line, 4);
            }
            if ($availabilityBarrier) {
                foreach ($workers as [, $pipes]) {
                    $this->assertSame("ready\n", fgets($pipes[1]));
                }
                fwrite($workers[0][1][0], "go\n");
            }
            if ($availabilityBarrier) {
                $this->assertSame("updated\n", fgets($workers[0][1][1]));
                fwrite($workers[1][1][0], "go\n");
            }
            $blocked = false;
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) DB::connection('reschedule_race')->selectOne('select case when ? = any(pg_blocking_pids(?)) then 1 else 0 end as blocked', [$pids[0], $pids[1]])->blocked === 1;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'The second transaction did not block on the first PostgreSQL transaction.');
            fwrite($workers[0][1][0], "commit\n");
            foreach ($workers as $index => [$process, $pipes]) {
                $result = trim((string) fgets($pipes[1]));
                fclose($pipes[0]);
                fclose($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                unset($workers[$index]);
                $this->assertSame(0, $exit, $error);
                $this->assertSame($index === 0 ? 'success' : ($availabilityBarrier ? 'SlotUnavailable' : 'NotEligible'), $result);
            }
            $assertState(DB::connection('reschedule_race'));
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
    }
}
