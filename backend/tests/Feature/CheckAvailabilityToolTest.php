<?php

namespace Tests\Feature;

use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\GetAppointmentAvailability;
use App\Application\Availability\TimeInterval;
use App\Application\Conversation\CheckAvailabilityTool;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckAvailabilityToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(new DateTimeImmutable('2026-11-01 00:00 UTC'));
    }

    public function test_sdk_contract_has_simple_canonical_id_and_bounded_temporal_schemas(): void
    {
        [$business] = $this->schedule();
        $tool = $this->tool($business);
        $factory = new JsonSchemaTypeFactory;
        $schema = $factory->object($tool->schema($factory))->toArray();

        $this->assertInstanceOf(Tool::class, $tool);
        $this->assertNotEmpty($tool->description());
        $this->assertSame(['service_id', 'professional_id', 'from', 'to'], array_keys($schema['properties']));
        $this->assertSame(['service_id', 'from', 'to'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['service_id']['type']);
        $this->assertSame(['integer', 'null'], $schema['properties']['professional_id']['type']);
        $this->assertSame('date-time', $schema['properties']['from']['format']);
        $this->assertSame(25, $schema['properties']['to']['maxLength']);
        foreach ($schema['properties'] as $property) {
            $this->assertArrayNotHasKey('enum', $property);
        }
    }

    public function test_results_delegate_to_s03_and_preserve_start_ranges_buffers_and_real_occupancy(): void
    {
        [$business, $service, $professional] = $this->schedule();
        Appointment::factory()->for($service)->for($professional)->create([
            'starts_at' => '2026-11-09 11:00:00+00:00', 'ends_at' => '2026-11-09 12:00:00+00:00',
            'buffer_before_minutes' => 5, 'buffer_after_minutes' => 10,
        ]);

        $result = $this->check($business, $service);
        $authoritative = app(GetAppointmentAvailability::class)->handle(
            $business, $service->id, new DateTimeImmutable('2026-11-09 08:00 UTC'), new DateTimeImmutable('2026-11-09 19:00 UTC'),
        );

        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['requested_professional']);
        $this->assertSame($service->id, $result['service_id']);
        $this->assertSame('UTC', $result['timezone']);
        $this->assertSame('2026-11-09T08:00:00+00:00', $result['from']);
        $this->assertSame([[
            'id' => $professional->id, 'display_name' => 'Ana Smith', 'eligible' => true,
            'ranges' => [
                ['earliest' => '2026-11-09T09:10:00+00:00', 'latest' => '2026-11-09T09:35:00+00:00'],
                ['earliest' => '2026-11-09T12:20:00+00:00', 'latest' => '2026-11-09T15:40:00+00:00'],
            ],
        ]], $result['available_professionals']);
        $this->assertSame(array_map(fn ($range): array => [
            'earliest' => $range->earliest->format(DATE_ATOM), 'latest' => $range->latest->format(DATE_ATOM),
        ], $authoritative->availableProfessionals[0]->ranges), $result['available_professionals'][0]['ranges']);
    }

    #[DataProvider('wholeSecondBoundaries')]
    public function test_ranges_are_normalized_inward_before_availability_is_reported(string $earliest, string $latest, array $expectedRanges): void
    {
        [$business, $service, $professional] = $this->schedule();
        $service->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $this->occupancy([
            $professional->id => [
                new TimeInterval(new DateTimeImmutable('2026-11-09 09:00 UTC'), new DateTimeImmutable($earliest)),
                new TimeInterval(new DateTimeImmutable($latest), new DateTimeImmutable('2026-11-09 17:00 UTC')),
            ],
        ]);

        $result = $this->check($business, $service);

        $this->assertSame($expectedRanges === [] ? 'unavailable' : 'ok', $result['status']);
        $this->assertNull($result['requested_professional']);
        $this->assertSame($expectedRanges === [] ? [] : [[
            'id' => $professional->id, 'display_name' => 'Ana Smith', 'eligible' => true, 'ranges' => $expectedRanges,
        ]], $result['available_professionals']);
    }

    public static function wholeSecondBoundaries(): array
    {
        return [
            'fractional boundaries round inward' => [
                '2026-11-09 10:00:00.250000 UTC', '2026-11-09 11:00:02.750000 UTC',
                [['earliest' => '2026-11-09T10:00:01+00:00', 'latest' => '2026-11-09T10:00:02+00:00']],
            ],
            'exact earliest is unchanged' => [
                '2026-11-09 10:00:00 UTC', '2026-11-09 11:00:02.000001 UTC',
                [['earliest' => '2026-11-09T10:00:00+00:00', 'latest' => '2026-11-09T10:00:02+00:00']],
            ],
            'exact latest is unchanged' => [
                '2026-11-09 10:00:00.999999 UTC', '2026-11-09 11:00:02 UTC',
                [['earliest' => '2026-11-09T10:00:01+00:00', 'latest' => '2026-11-09T10:00:02+00:00']],
            ],
            'exact second boundaries are unchanged' => [
                '2026-11-09 10:00:00 UTC', '2026-11-09 11:00:02 UTC',
                [['earliest' => '2026-11-09T10:00:00+00:00', 'latest' => '2026-11-09T10:00:02+00:00']],
            ],
            'exact second point remains available' => [
                '2026-11-09 10:00:00 UTC', '2026-11-09 11:00:00 UTC',
                [['earliest' => '2026-11-09T10:00:00+00:00', 'latest' => '2026-11-09T10:00:00+00:00']],
            ],
            'only interior whole second remains' => [
                '2026-11-09 09:59:59.999999 UTC', '2026-11-09 11:00:00.000001 UTC',
                [['earliest' => '2026-11-09T10:00:00+00:00', 'latest' => '2026-11-09T10:00:00+00:00']],
            ],
            'fractional interval contains no whole second' => [
                '2026-11-09 10:00:00.100000 UTC', '2026-11-09 11:00:00.900000 UTC', [],
            ],
            'fractional point contains no whole second' => [
                '2026-11-09 10:00:00.500000 UTC', '2026-11-09 11:00:00.500000 UTC', [],
            ],
        ];
    }

    public function test_requested_fractional_only_ranges_remain_unavailable_and_valid_alternatives_are_preserved(): void
    {
        [$business, $service, $requested] = $this->schedule();
        $service->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $fractionalAlternative = $this->professional($business, $service);
        $validAlternative = $this->professional($business, $service);
        $blocks = [
            new TimeInterval(new DateTimeImmutable('2026-11-09 09:00 UTC'), new DateTimeImmutable('2026-11-09 10:00:00.1 UTC')),
            new TimeInterval(new DateTimeImmutable('2026-11-09 11:00:00.9 UTC'), new DateTimeImmutable('2026-11-09 17:00 UTC')),
        ];
        $this->occupancy([$requested->id => $blocks, $fractionalAlternative->id => $blocks]);

        $result = $this->check($business, $service, ['professional_id' => $requested->id]);

        $this->assertSame('requested_professional_unavailable', $result['status']);
        $this->assertSame(['id' => $requested->id, 'display_name' => 'Ana Smith', 'eligible' => true, 'ranges' => []], $result['requested_professional']);
        $this->assertSame([$validAlternative->id], array_column($result['available_professionals'], 'id'));
        $this->assertSame([['earliest' => '2026-11-09T09:00:00+00:00', 'latest' => '2026-11-09T16:00:00+00:00']], $result['available_professionals'][0]['ranges']);
    }

    public function test_requested_fractional_only_ranges_without_valid_alternatives_do_not_report_availability(): void
    {
        [$business, $service, $requested] = $this->schedule();
        $service->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $alternative = $this->professional($business, $service);
        $blocks = [
            new TimeInterval(new DateTimeImmutable('2026-11-09 09:00 UTC'), new DateTimeImmutable('2026-11-09 10:00:00.1 UTC')),
            new TimeInterval(new DateTimeImmutable('2026-11-09 11:00:00.9 UTC'), new DateTimeImmutable('2026-11-09 17:00 UTC')),
        ];
        $this->occupancy([$requested->id => $blocks, $alternative->id => $blocks]);

        $result = $this->check($business, $service, ['professional_id' => $requested->id]);

        $this->assertSame('requested_professional_unavailable', $result['status']);
        $this->assertSame($requested->id, $result['requested_professional']['id']);
        $this->assertTrue($result['requested_professional']['eligible']);
        $this->assertSame([], $result['requested_professional']['ranges']);
        $this->assertSame([], $result['available_professionals']);
    }

    public function test_requested_whole_second_range_is_preserved_without_offering_alternatives(): void
    {
        [$business, $service, $requested] = $this->schedule();
        $service->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $this->professional($business, $service);
        $this->occupancy([$requested->id => [
            new TimeInterval(new DateTimeImmutable('2026-11-09 09:00 UTC'), new DateTimeImmutable('2026-11-09 10:00:00.1 UTC')),
            new TimeInterval(new DateTimeImmutable('2026-11-09 11:00:01.9 UTC'), new DateTimeImmutable('2026-11-09 17:00 UTC')),
        ]]);

        $result = $this->check($business, $service, ['professional_id' => $requested->id]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($requested->id, $result['requested_professional']['id']);
        $this->assertSame([['earliest' => '2026-11-09T10:00:01+00:00', 'latest' => '2026-11-09T10:00:01+00:00']], $result['requested_professional']['ranges']);
        $this->assertSame([], $result['available_professionals']);
    }

    #[DataProvider('dstRoundingBoundaries')]
    public function test_fractional_boundaries_are_rounded_as_instants_across_dst(string $date, string $earliest, string $latest, string $from, string $to, array $expectedRanges): void
    {
        [$business, $service, $professional] = $this->schedule('Europe/Madrid', 'sunday');
        $service->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $business->operatingHours()->update(['opens_at' => '00:00', 'closes_at' => '05:00']);
        $professional->availabilityIntervals()->update(['starts_at' => '00:00', 'ends_at' => '05:00']);
        $this->occupancy([$professional->id => [
            new TimeInterval(new DateTimeImmutable($date.' 00:00 Europe/Madrid'), new DateTimeImmutable($earliest)),
            new TimeInterval(new DateTimeImmutable($latest), new DateTimeImmutable($date.' 05:00 Europe/Madrid')),
        ]]);

        $result = $this->check($business, $service, ['from' => $from, 'to' => $to]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($expectedRanges, $result['available_professionals'][0]['ranges']);
    }

    public static function dstRoundingBoundaries(): array
    {
        return [
            'spring rounding crosses the missing hour' => [
                '2027-03-28', '2027-03-28 00:59:59.5 UTC', '2027-03-28 02:00:01.5 UTC',
                '2027-03-28T00:00:00+01:00', '2027-03-28T05:00:00+02:00',
                [['earliest' => '2027-03-28T03:00:00+02:00', 'latest' => '2027-03-28T03:00:01+02:00']],
            ],
            'fall rounding enters the repeated hour' => [
                '2027-10-31', '2027-10-31 00:59:59.5 UTC', '2027-10-31 02:00:01.5 UTC',
                '2027-10-31T00:00:00+02:00', '2027-10-31T05:00:00+01:00',
                [['earliest' => '2027-10-31T02:00:00+01:00', 'latest' => '2027-10-31T02:00:01+01:00']],
            ],
            'fall range spans different offsets' => [
                '2027-10-31', '2027-10-31 00:59:58.5 UTC', '2027-10-31 02:00:01.5 UTC',
                '2027-10-31T00:00:00+02:00', '2027-10-31T05:00:00+01:00',
                [['earliest' => '2027-10-31T02:59:59+02:00', 'latest' => '2027-10-31T02:00:01+01:00']],
            ],
        ];
    }

    public function test_available_requested_professional_is_not_replaced_or_duplicated_among_alternatives(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->professional($business, $service);

        $result = $this->check($business, $service, ['professional_id' => $professional->id]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($professional->id, $result['requested_professional']['id']);
        $this->assertTrue($result['requested_professional']['eligible']);
        $this->assertNotEmpty($result['requested_professional']['ranges']);
        $this->assertSame([], $result['available_professionals']);
    }

    public function test_requested_unavailability_and_ineligibility_take_precedence_with_and_without_alternatives(): void
    {
        [$business, $service, $requested] = $this->schedule();
        $requested->availabilityIntervals()->delete();
        $alternative = $this->professional($business, $service);
        $tool = $this->tool($business);

        foreach ([true, false] as $hasAlternatives) {
            if (! $hasAlternatives) {
                $alternative->availabilityIntervals()->delete();
            }
            foreach ([true, false] as $eligible) {
                $requested->services()->sync($eligible ? [$service->id] : []);
                $result = $this->check($business, $service, ['professional_id' => $requested->id], $tool);

                $this->assertSame($eligible ? 'requested_professional_unavailable' : 'requested_professional_ineligible', $result['status']);
                $this->assertSame($requested->id, $result['requested_professional']['id']);
                $this->assertSame($eligible, $result['requested_professional']['eligible']);
                $this->assertSame([], $result['requested_professional']['ranges']);
                $this->assertSame($hasAlternatives ? [$alternative->id] : [], array_column($result['available_professionals'], 'id'));
            }
        }
    }

    public function test_no_preference_without_available_professionals_returns_unavailable(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $professional->availabilityIntervals()->delete();
        $result = $this->check($business, $service, ['professional_id' => null]);

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['requested_professional']);
        $this->assertSame([], $result['available_professionals']);
    }

    public function test_unknown_inactive_cross_business_and_malformed_references_fail_before_scheduling(): void
    {
        [$business, $service] = $this->schedule();
        $inactive = Service::factory()->for($business)->create(['active' => false]);
        $foreignService = Service::factory()->create(['active' => true]);
        $foreignProfessional = Professional::factory()->create();
        $this->mock(AppointmentOccupancy::class)->shouldNotReceive('forProfessional');

        foreach ([9999999, $inactive->id, $foreignService->id, null, 0, -1, 1.5, true, 'not an ID', (string) $service->id, [], '9223372036854775808'] as $id) {
            $result = $this->check($business, $service, ['service_id' => $id]);
            $this->assertSame('invalid_reference', $result['status']);
            $this->assertArrayHasKey('service_id', $result['errors']);
        }
        foreach ([9999999, $foreignProfessional->id, 0, -1, true, 'Ana Smith', []] as $id) {
            $result = $this->check($business, $service, ['professional_id' => $id]);
            $this->assertSame('invalid_reference', $result['status']);
            $this->assertArrayHasKey('professional_id', $result['errors']);
        }
        $missing = json_decode($this->tool($business)->handle(new Request(['from' => '2026-11-09T09:00:00Z', 'to' => '2026-11-09T10:00:00Z'])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_reference', $missing['status']);
    }

    public function test_business_is_trusted_and_current_reference_and_timezone_changes_are_revalidated(): void
    {
        [$business, $service] = $this->schedule();
        $other = Business::factory()->create();
        $tool = $this->tool($business);

        $this->assertSame('ok', $this->check($business, $service, ['business_id' => $other->id], $tool)['status']);
        Business::whereKey($business->id)->update(['timezone' => 'Europe/Madrid']);
        $this->assertSame('invalid_temporal_arguments', $this->check($business, $service, tool: $tool)['status']);
        $result = $this->check($business, $service, ['from' => '2026-11-09T08:00:00+01:00', 'to' => '2026-11-09T19:00:00+01:00'], $tool);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('Europe/Madrid', $result['timezone']);

        $service->update(['active' => false]);
        $this->assertSame('invalid_reference', $this->check($business, $service, tool: $tool)['status']);
    }

    #[DataProvider('invalidTemporalArguments')]
    public function test_invalid_temporal_arguments_fail_safely_without_scheduling(array $arguments, string $field): void
    {
        [$business, $service] = $this->schedule();
        $this->mock(AppointmentOccupancy::class)->shouldNotReceive('forProfessional');

        $result = $this->check($business, $service, $arguments);

        $this->assertSame('invalid_temporal_arguments', $result['status']);
        $this->assertArrayHasKey($field, $result['errors']);
    }

    public static function invalidTemporalArguments(): array
    {
        return [
            'null from' => [['from' => null], 'from'],
            'null to' => [['to' => null], 'to'],
            'array' => [['from' => []], 'from'],
            'relative date' => [['from' => 'next monday'], 'from'],
            'missing offset' => [['from' => '2026-11-09T09:00:00'], 'from'],
            'date only' => [['from' => '2026-11-09'], 'from'],
            'impossible date' => [['from' => '2026-02-30T09:00:00Z'], 'from'],
            'impossible time' => [['to' => '2026-11-09T25:00:00Z'], 'to'],
            'wrong timezone offset' => [['from' => '2026-11-09T09:00:00+01:00'], 'from'],
            'null byte' => [['from' => "2026-11-09T09:00:00Z\0"], 'from'],
            'fractional seconds' => [['from' => '2026-11-09T09:00:00.1Z'], 'from'],
            'reversed' => [['to' => '2026-11-09T07:59:59Z'], 'to'],
            'over bounded range' => [['to' => '2026-12-10T08:00:01Z'], 'to'],
        ];
    }

    public function test_exact_start_is_inclusive_and_s03_enforces_the_current_operation_time_cutoff(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->travelTo(new DateTimeImmutable('2026-11-09 09:58:00.000001 UTC'));

        $pastCutoff = $this->check($business, $service, ['from' => '2026-11-09T10:00:00Z', 'to' => '2026-11-09T10:00:00Z']);
        $this->assertSame('unavailable', $pastCutoff['status']);
        $atCutoff = $this->check($business, $service, ['from' => '2026-11-09T10:01:00Z', 'to' => '2026-11-09T10:01:00Z', 'professional_id' => $professional->id]);
        $this->assertSame('ok', $atCutoff['status']);
        $this->assertSame([['earliest' => '2026-11-09T10:01:00+00:00', 'latest' => '2026-11-09T10:01:00+00:00']], $atCutoff['requested_professional']['ranges']);
    }

    public function test_dst_spring_gap_is_rejected_and_valid_transition_ranges_use_business_local_offsets(): void
    {
        [$business, $service, $professional] = $this->schedule('Europe/Madrid', 'sunday');
        $service->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $business->operatingHours()->update(['opens_at' => '01:00', 'closes_at' => '05:00']);
        $professional->availabilityIntervals()->update(['starts_at' => '01:00', 'ends_at' => '05:00']);

        foreach (['2027-03-28T02:30:00+01:00', '2027-03-28T02:30:00+02:00'] as $gap) {
            $this->assertSame('invalid_temporal_arguments', $this->check($business, $service, ['from' => $gap, 'to' => $gap])['status']);
        }
        $result = $this->check($business, $service, ['from' => '2027-03-28T01:00:00+01:00', 'to' => '2027-03-28T05:00:00+02:00']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([['earliest' => '2027-03-28T01:00:00+01:00', 'latest' => '2027-03-28T04:00:00+02:00']], $result['available_professionals'][0]['ranges']);
    }

    public function test_both_occurrences_of_a_dst_fold_are_distinct_valid_exact_starts(): void
    {
        [$business, $service, $professional] = $this->schedule('Europe/Madrid', 'sunday');
        $service->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $business->operatingHours()->update(['opens_at' => '01:00', 'closes_at' => '05:00']);
        $professional->availabilityIntervals()->update(['starts_at' => '01:00', 'ends_at' => '05:00']);

        foreach (['2027-10-31T02:30:00+02:00', '2027-10-31T02:30:00+01:00'] as $start) {
            $result = $this->check($business, $service, ['from' => $start, 'to' => $start]);
            $this->assertSame('ok', $result['status']);
            $this->assertSame([['earliest' => $start, 'latest' => $start]], $result['available_professionals'][0]['ranges']);
        }
    }

    public function test_range_bound_is_inclusive_and_measured_in_local_calendar_days_across_dst(): void
    {
        [$business, $service] = $this->schedule('Europe/Madrid');
        $arguments = ['from' => '2027-10-10T00:00:00+02:00', 'to' => '2027-11-10T00:00:00+01:00'];
        $this->assertSame('ok', $this->check($business, $service, $arguments)['status']);
        $arguments['to'] = '2027-11-10T00:00:01+01:00';
        $this->assertSame('invalid_temporal_arguments', $this->check($business, $service, $arguments)['status']);
    }

    public function test_tool_execution_performs_no_writes_or_private_data_disclosure(): void
    {
        [$business, $service] = $this->schedule();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = $this->check($business, $service);

        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $query);
        }
        $this->assertSame(['status', 'service_id', 'timezone', 'from', 'to', 'requested_professional', 'available_professionals'], array_keys($result));
        $this->assertSame(['id', 'display_name', 'eligible', 'ranges'], array_keys($result['available_professionals'][0]));
    }

    /** @return array{Business, Service, Professional} */
    private function schedule(string $timezone = 'UTC', string $weekday = 'monday'): array
    {
        $business = Business::factory()->create(['timezone' => $timezone]);
        $service = Service::factory()->for($business)->create([
            'active' => true, 'duration_minutes' => 60, 'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20,
        ]);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => $weekday, 'opens_at' => '09:00', 'closes_at' => '17:00']);

        return [$business, $service, $this->professional($business, $service, $weekday)];
    }

    private function professional(Business $business, Service $service, string $weekday = 'monday'): Professional
    {
        $professional = Professional::factory()->for($business)->create(['first_name' => 'Ana', 'last_name' => 'Smith', 'display_name' => null]);
        $professional->services()->attach($service);
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '17:00']);

        return $professional;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function check(Business $business, Service $service, array $arguments = [], ?CheckAvailabilityTool $tool = null): array
    {
        return json_decode(($tool ?? $this->tool($business))->handle(new Request(array_replace([
            'service_id' => $service->id, 'from' => '2026-11-09T08:00:00Z', 'to' => '2026-11-09T19:00:00Z',
        ], $arguments))), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tool(Business $business): CheckAvailabilityTool
    {
        return new CheckAvailabilityTool($business, app(GetAppointmentAvailability::class));
    }

    /** @param array<int, list<TimeInterval>> $occupied */
    private function occupancy(array $occupied): void
    {
        $this->app->instance(AppointmentOccupancy::class, new class($occupied) implements AppointmentOccupancy
        {
            /** @param array<int, list<TimeInterval>> $occupied */
            public function __construct(private array $occupied) {}

            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                return $this->occupied[$professional->id] ?? [];
            }
        });
    }
}
