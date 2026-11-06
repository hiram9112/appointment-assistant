<?php

namespace Tests\Feature;

use App\Application\Appointment\AppendAppointmentAudit;
use App\Application\Appointment\CreateAppointment;
use App\Application\Appointment\CreateAppointmentOutcome;
use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\CalculateAvailability;
use App\Application\Availability\GetAppointmentAvailability;
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
use App\Models\ScheduleException;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CreateAppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new DateTimeImmutable('2026-11-09 08:00:00 UTC'));
    }

    public function test_creates_confirmed_appointment_with_current_configuration_and_audit(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        $service->update(['duration_minutes' => 45, 'buffer_before_minutes' => 5, 'buffer_after_minutes' => 10]);
        $result = $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 10:07:15 UTC');

        $this->assertInstanceOf(Appointment::class, $result);
        $appointment = $result->fresh();
        $this->assertSame([$customer->id, $service->id, $professional->id, 'confirmed'], [$appointment->customer_id, $appointment->service_id, $appointment->professional_id, $appointment->status]);
        $this->assertSame('2026-11-09 10:07:15.000000', $appointment->starts_at->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-11-09 10:52:15.000000', $appointment->ends_at->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame([5, 10], [$appointment->buffer_before_minutes, $appointment->buffer_after_minutes]);
        $audit = $appointment->audits()->sole();
        $this->assertSame(['created', 'system', 'scheduler', 'internal'], [$audit->action, $audit->actor_type, $audit->actor_id, $audit->source]);
        $this->assertNull($audit->before_state);
        $this->assertSame($appointment->id, $audit->after_state['id']);
        $this->assertSame('2026-11-09T10:07:15.000000Z', $audit->after_state['starts_at']);
        $this->assertSame('2026-11-09T10:52:15.000000Z', $audit->after_state['ends_at']);
        $this->assertSame('confirmed', $audit->after_state['status']);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_invalid_references_and_ineligible_selection_are_rejected(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        $unregistered = Customer::factory()->create();
        $inactive = Service::factory()->for($business)->create(['active' => false]);
        $otherService = Service::factory()->create();
        $otherProfessional = Professional::factory()->create();
        $ineligible = Professional::factory()->for($business)->create();

        foreach ([
            [99999999, $service->id, $professional->id],
            [$unregistered->id, $service->id, $professional->id],
            [$customer->id, 99999999, $professional->id],
            [$customer->id, $inactive->id, $professional->id],
            [$customer->id, $otherService->id, $professional->id],
            [$customer->id, $service->id, $otherProfessional->id],
            [$customer->id, $service->id, 99999999],
            [$customer->id, $service->id, $ineligible->id],
            [$customer->id, $service->id, null],
        ] as [$customerId, $serviceId, $professionalId]) {
            $this->assertSame(CreateAppointmentOutcome::InvalidRequest, $this->create($business, $customerId, $serviceId, $professionalId));
        }
        $this->assertSame(CreateAppointmentOutcome::InvalidRequest, $this->create(Business::factory()->make(), $customer->id, $service->id, $professional->id));
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('temporalBoundaries')]
    public function test_creation_uses_shared_safety_threshold(string $now, string $start, bool $available): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        $this->travelTo(new DateTimeImmutable($now));
        $result = $this->create($business, $customer->id, $service->id, $professional->id, $start);

        if ($available) {
            $this->assertInstanceOf(Appointment::class, $result);
        } else {
            $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $result);
            $this->assertDatabaseCount('appointments', 0);
        }
    }

    public static function temporalBoundaries(): array
    {
        return [
            'past' => ['2026-11-09 10:00:00 UTC', '2026-11-09 09:59:59 UTC', false],
            'now' => ['2026-11-09 10:00:00 UTC', '2026-11-09 10:00:00 UTC', false],
            'just before threshold' => ['2026-11-09 10:00:00 UTC', '2026-11-09 10:01:59.999999 UTC', false],
            'exact threshold' => ['2026-11-09 10:00:00 UTC', '2026-11-09 10:02:00 UTC', true],
            'rounded seconds' => ['2026-11-09 10:00:35 UTC', '2026-11-09 10:02:59 UTC', false],
            'rounded threshold' => ['2026-11-09 10:00:59 UTC', '2026-11-09 10:03:00 UTC', true],
            'rounded microseconds' => ['2026-11-09 10:00:00.000001 UTC', '2026-11-09 10:02:59 UTC', false],
            'off grid' => ['2026-11-09 10:00:35 UTC', '2026-11-09 10:03:15 UTC', true],
            'off grid whole minute' => ['2026-11-09 10:00:35 UTC', '2026-11-09 10:07:00 UTC', true],
            'fraction at threshold' => ['2026-11-09 10:00:00 UTC', '2026-11-09 10:02:00.999999 UTC', true],
        ];
    }

    public function test_fractional_start_is_truncated_before_availability_persistence_and_auditing(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();

        $result = $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 16:00:00.999999 UTC');

        $this->assertInstanceOf(Appointment::class, $result);
        $appointment = $result->fresh();
        $this->assertSame('2026-11-09T16:00:00.000000Z', $appointment->starts_at->utc()->toJSON());
        $this->assertSame('2026-11-09T17:00:00.000000Z', $appointment->ends_at->utc()->toJSON());
        $audit = $appointment->audits()->sole();
        $this->assertSame('2026-11-09T16:00:00.000000Z', $audit->after_state['starts_at']);
        $this->assertSame('2026-11-09T17:00:00.000000Z', $audit->after_state['ends_at']);
    }

    public function test_fractional_start_before_existing_occupancy_ends_remains_unavailable(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        $existing = $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 10:00 UTC');
        $this->assertInstanceOf(Appointment::class, $existing);

        $result = $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 10:59:59.999999 UTC');

        $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $result);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_scheduling_boundaries_breaks_exceptions_and_previous_occupancy_are_revalidated(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        $service->update(['buffer_before_minutes' => 10, 'buffer_after_minutes' => 10]);
        $professional->availabilityIntervals()->delete();
        foreach ([['09:00', '12:00'], ['13:00', '17:00']] as [$start, $end]) {
            ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'monday', 'starts_at' => $start, 'ends_at' => $end]);
        }
        $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 09:05 UTC'));
        $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 11:30 UTC'));
        $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 16:00 UTC'));
        foreach ([null, $professional->id] as $professionalId) {
            $exception = ScheduleException::factory()->for($business)->create(['professional_id' => $professionalId, 'kind' => 'blocked', 'starts_at' => '2026-11-09 14:00 UTC', 'ends_at' => '2026-11-09 15:00 UTC']);
            $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 14:15 UTC'));
            $exception->delete();
        }

        $start = new DateTimeImmutable('2026-11-09 14:00 UTC');
        $offered = app(GetAppointmentAvailability::class)->handle($business, $service->id, $start, $start, $professional->id);
        $this->assertNotEmpty($offered->requestedProfessional->ranges);
        $first = $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 14:00 UTC');
        $this->assertInstanceOf(Appointment::class, $first);
        $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 14:00 UTC'));
        $this->assertInstanceOf(Appointment::class, $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-09 15:20 UTC'));
    }

    public function test_audit_failure_rolls_back_the_appointment_and_remains_a_technical_failure(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        AppointmentAudit::creating(fn () => throw new RuntimeException('audit failed'));
        try {
            $this->create($business, $customer->id, $service->id, $professional->id);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            AppointmentAudit::flushEventListeners();
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_only_identified_occupancy_exclusion_errors_are_translated(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        Appointment::creating(function () use ($customer, $service, $professional): void {
            DB::table('appointments')->insert(['customer_id' => $customer->id, 'service_id' => $service->id, 'professional_id' => $professional->id, 'starts_at' => '2026-11-09 10:00 UTC', 'ends_at' => '2026-11-09 11:00 UTC', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'status' => 'confirmed']);
        });
        try {
            $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $this->create($business, $customer->id, $service->id, $professional->id));
        } finally {
            Appointment::flushEventListeners();
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_unrelated_database_failure_is_not_reported_as_an_unavailable_slot(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        try {
            app(CreateAppointment::class)->handle($business, $customer->id, $service->id, $professional->id, new DateTimeImmutable('2026-11-09 10:00 UTC'), str_repeat('x', 256), 'scheduler', 'internal');
            $this->fail('Expected database failure.');
        } catch (QueryException $exception) {
            $this->assertSame('22001', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_unrelated_appointment_insert_failure_is_not_a_slot_conflict(): void
    {
        [$business, $customer, $service, $professional] = $this->schedule();
        Appointment::creating(function (Appointment $appointment): void {
            $appointment->customer_id = 99999999;
        });
        try {
            $this->create($business, $customer->id, $service->id, $professional->id);
            $this->fail('Expected foreign key failure.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
        } finally {
            Appointment::flushEventListeners();
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_deadlock_after_audit_insert_retries_and_commits_once(): void
    {
        $this->withCommittedSchedule(function (Business $business, Customer $customer, Service $service, Professional $professional): void {
            $auditAttempts = 0;
            AppointmentAudit::created(function () use (&$auditAttempts): void {
                if (++$auditAttempts === 1) {
                    DB::statement("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END $$");
                }
            });

            $result = $this->create($business, $customer->id, $service->id, $professional->id);

            $this->assertInstanceOf(Appointment::class, $result);
            $this->assertSame(2, $auditAttempts);
            $this->assertSame(1, Appointment::where('professional_id', $professional->id)->count());
            $this->assertSame(1, $result->audits()->count());
        });
    }

    public function test_retry_revalidates_occupancy_after_competing_booking_commits(): void
    {
        $this->withCommittedSchedule(function (Business $business, Customer $customer, Service $service, Professional $professional): void {
            $auditAttempts = 0;
            AppointmentAudit::created(function () use (&$auditAttempts): void {
                if (++$auditAttempts === 1) {
                    DB::statement("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END $$");
                }
            });
            $competitorResult = null;
            $occupancy = new class(function () use ($business, $customer, $service, $professional, &$competitorResult): void {
                DB::setDefaultConnection('booking_competitor');
                try {
                    $action = new CreateAppointment(new CalculateAvailability(new PostgresAppointmentOccupancy), new AppendAppointmentAudit);
                    $competitorResult = $action->handle($business, $customer->id, $service->id, $professional->id, new DateTimeImmutable('2026-11-09 10:00 UTC'), 'system', 'competitor', 'internal');
                } finally {
                    DB::setDefaultConnection('booking_retry');
                }
            }) implements AppointmentOccupancy
            {

                public int $calls = 0;

                public function __construct(private \Closure $onRetry) {}

                public function forProfessional(Professional $professional, TimeInterval $period): array
                {
                    if (++$this->calls === 2) {
                        ($this->onRetry)();
                    }

                    return (new PostgresAppointmentOccupancy)->forProfessional($professional, $period);
                }
            };
            $action = new CreateAppointment(new CalculateAvailability($occupancy), new AppendAppointmentAudit);

            $result = $action->handle($business, $customer->id, $service->id, $professional->id, new DateTimeImmutable('2026-11-09 10:00 UTC'), 'system', 'scheduler', 'internal');

            $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $result);
            $this->assertInstanceOf(Appointment::class, $competitorResult);
            $this->assertSame(2, $occupancy->calls);
            $this->assertSame(2, $auditAttempts);
            $this->assertSame(1, Appointment::where('professional_id', $professional->id)->count());
            $this->assertSame(1, $competitorResult->audits()->count());
            $this->assertSame('competitor', $competitorResult->audits()->sole()->actor_id);
        });
    }

    public function test_deadlocks_exhausting_three_attempts_remain_technical_failures(): void
    {
        $this->withCommittedSchedule(function (Business $business, Customer $customer, Service $service, Professional $professional): void {
            $attempts = 0;
            AppointmentAudit::created(function () use (&$attempts): void {
                $attempts++;
                DB::statement("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END $$");
            });
            try {
                $this->create($business, $customer->id, $service->id, $professional->id);
                $this->fail('Expected a deadlock failure.');
            } catch (QueryException $exception) {
                $this->assertSame('40P01', $exception->errorInfo[0]);
            }
            $this->assertSame(3, $attempts);
            $this->assertSame(0, Appointment::where('professional_id', $professional->id)->count());
            $this->assertSame(0, AppointmentAudit::whereHas('appointment', fn ($query) => $query->where('professional_id', $professional->id))->count());
        });
    }

    public function test_retry_revalidates_service_after_a_deadlock(): void
    {
        $this->withCommittedSchedule(function (Business $business, Customer $customer, Service $service, Professional $professional): void {
            $attempts = 0;
            AppointmentAudit::created(function () use ($service, &$attempts): void {
                $attempts++;
                DB::connection('booking_competitor')->table('services')->where('id', $service->id)->update(['active' => false]);
                DB::statement("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END $$");
            });

            $result = $this->create($business, $customer->id, $service->id, $professional->id);

            $this->assertSame(CreateAppointmentOutcome::InvalidRequest, $result);
            $this->assertSame(1, $attempts);
            $this->assertSame(0, Appointment::where('professional_id', $professional->id)->count());
            $this->assertSame(0, AppointmentAudit::whereHas('appointment', fn ($query) => $query->where('professional_id', $professional->id))->count());
        });
    }

    public function test_retry_does_not_misclassify_an_unrelated_exclusion_error(): void
    {
        $this->withCommittedSchedule(function (Business $business, Customer $customer, Service $service, Professional $professional): void {
            Appointment::creating(function (): void {
                DB::statement("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END $$");
            });
            $occupancy = new class implements AppointmentOccupancy
            {
                public int $calls = 0;

                public function forProfessional(Professional $professional, TimeInterval $period): array
                {
                    if (++$this->calls === 2) {
                        DB::statement(<<<'SQL'
                            DO $$ BEGIN RAISE EXCEPTION 'conflicting key violates exclusion constraint "appointments_confirmed_occupancy_exclusion"' USING ERRCODE = '23P01'; END $$
                            SQL);
                    }

                    return (new PostgresAppointmentOccupancy)->forProfessional($professional, $period);
                }
            };
            $action = new CreateAppointment(new CalculateAvailability($occupancy), new AppendAppointmentAudit);
            try {
                $action->handle($business, $customer->id, $service->id, $professional->id, new DateTimeImmutable('2026-11-09 10:00 UTC'), 'system', 'scheduler', 'internal');
                $this->fail('Expected unrelated exclusion failure.');
            } catch (QueryException $exception) {
                $this->assertSame('23P01', $exception->errorInfo[0]);
            }
            $this->assertSame(2, $occupancy->calls);
            $this->assertSame(0, Appointment::where('professional_id', $professional->id)->count());
        });
    }

    public function test_reference_business_booking_uses_selected_professional_and_seeded_schedule(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $service = $business->services()->where('name', 'Initial beauty consultation')->firstOrFail();
        $professional = $business->professionals()->where('first_name', 'Laura')->firstOrFail();
        $customer = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        $this->assertSame(CreateAppointmentOutcome::SlotUnavailable, $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-10 10:00 Europe/Madrid'));
        $result = $this->create($business, $customer->id, $service->id, $professional->id, '2026-11-10 12:00 Europe/Madrid');
        $this->assertInstanceOf(Appointment::class, $result);
        $this->assertSame($professional->id, $result->professional_id);
        $this->assertSame('2026-11-10 11:00', $result->starts_at->utc()->format('Y-m-d H:i'));
    }

    public function test_independent_conflicting_bookings_cannot_both_commit(): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.booking_race' => config("database.connections.$default")]);
        DB::setDefaultConnection('booking_race');
        [$business, $customer, $service, $professional] = $this->schedule();
        DB::setDefaultConnection($default);
        $workers = [];

        $workerCode = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Illuminate\Support\Carbon::setTestNow('2026-11-09 08:00:00 UTC');
            if ($argv[5] === 'first') {
                App\Models\AppointmentAudit::creating(function (): void {
                    static $calls = 0;
                    if (++$calls === 1) {
                        fwrite(STDOUT, "inserted\n");
                        if (trim(fgets(STDIN)) !== 'commit') { throw new RuntimeException('Commit barrier failed'); }
                    }
                });
            }
            $occupancy = new class implements App\Application\Availability\AppointmentOccupancy {
                private int $calls = 0;
                public function forProfessional(App\Models\Professional $professional, App\Application\Availability\TimeInterval $period): array {
                    $intervals = (new App\Application\Availability\PostgresAppointmentOccupancy)->forProfessional($professional, $period);
                    if (++$this->calls === 1) {
                        fwrite(STDOUT, "ready\n");
                        if (trim(fgets(STDIN)) !== 'go') { throw new RuntimeException('Barrier failed'); }
                    }
                    return $intervals;
                }
            };
            try {
                $action = new App\Application\Appointment\CreateAppointment(new App\Application\Availability\CalculateAvailability($occupancy), new App\Application\Appointment\AppendAppointmentAudit);
                $result = $action->handle(App\Models\Business::findOrFail($argv[1]), (int) $argv[2], (int) $argv[3], (int) $argv[4], new DateTimeImmutable('2026-11-09 10:00 UTC'), 'system', 'race', 'test');
                fwrite(STDOUT, ($result instanceof App\Models\Appointment ? 'created' : $result->name)."\n");
            } catch (Throwable $e) {
                fwrite(STDERR, get_class($e).': '.$e->getMessage());
                exit(1);
            }
            PHP;

        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, '-r', $workerCode, (string) $business->id, (string) $customer->id, (string) $service->id, (string) $professional->id, $i === 0 ? 'first' : 'second'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']));
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [, $pipes]) {
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            fwrite($workers[0][1][0], "go\n");
            $this->assertSame("inserted\n", fgets($workers[0][1][1]));
            fwrite($workers[1][1][0], "go\n");
            fwrite($workers[0][1][0], "commit\n");
            $results = [];
            foreach ($workers as $index => [$process, $pipes]) {
                $results[] = trim(fgets($pipes[1]));
                fclose($pipes[0]);
                fclose($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                unset($workers[$index]);
                $this->assertSame(0, $exitCode, $error);
            }
            $workers = [];
            sort($results);
            $this->assertSame(['SlotUnavailable', 'created'], $results);
            $this->assertSame(1, DB::connection('booking_race')->table('appointments')->where('professional_id', $professional->id)->count());
            $this->assertSame(1, DB::connection('booking_race')->table('appointment_audits')->whereIn('appointment_id', DB::connection('booking_race')->table('appointments')->select('id')->where('professional_id', $professional->id))->count());
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
            $db = DB::connection('booking_race');
            $appointmentIds = $db->table('appointments')->where('professional_id', $professional->id)->pluck('id');
            $db->table('appointment_audits')->whereIn('appointment_id', $appointmentIds)->delete();
            $db->table('appointments')->whereIn('id', $appointmentIds)->delete();
            $db->table('professional_service')->where('professional_id', $professional->id)->delete();
            $db->table('professional_availability_intervals')->where('professional_id', $professional->id)->delete();
            $db->table('business_operating_hours')->where('business_id', $business->id)->delete();
            $db->table('channel_identities')->where('customer_id', $customer->id)->delete();
            $db->table('customers')->where('id', $customer->id)->delete();
            $db->table('services')->where('id', $service->id)->delete();
            $db->table('professionals')->where('id', $professional->id)->delete();
            $db->table('businesses')->where('id', $business->id)->delete();
            DB::purge('booking_race');
        }
    }

    private function schedule(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $customer = Customer::factory()->has(ChannelIdentity::factory(), 'channelIdentities')->create();
        $service = Service::factory()->for($business)->create(['active' => true, 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $professional = Professional::factory()->for($business)->create();
        $professional->services()->attach($service);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '09:00', 'closes_at' => '17:00']);
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'monday', 'starts_at' => '09:00', 'ends_at' => '17:00']);

        return [$business, $customer, $service, $professional];
    }

    private function withCommittedSchedule(callable $test): void
    {
        $default = DB::getDefaultConnection();
        $connection = config("database.connections.$default");
        config(['database.connections.booking_retry' => $connection, 'database.connections.booking_competitor' => $connection]);
        DB::setDefaultConnection('booking_retry');
        [$business, $customer, $service, $professional] = $this->schedule();

        try {
            $test($business, $customer, $service, $professional);
        } finally {
            Appointment::flushEventListeners();
            AppointmentAudit::flushEventListeners();
            $db = DB::connection('booking_retry');
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
            DB::setDefaultConnection($default);
            DB::purge('booking_retry');
            DB::purge('booking_competitor');
        }
    }

    private function create(Business $business, int $customerId, int $serviceId, ?int $professionalId, string $start = '2026-11-09 10:00 UTC'): Appointment|CreateAppointmentOutcome
    {
        return app(CreateAppointment::class)->handle($business, $customerId, $serviceId, $professionalId, new DateTimeImmutable($start), 'system', 'scheduler', 'internal');
    }
}
