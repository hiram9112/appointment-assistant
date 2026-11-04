<?php

namespace Tests\Feature;

use App\Application\Availability\AppointmentAvailability;
use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\AppointmentStartRange;
use App\Application\Availability\CalculateAvailability;
use App\Application\Availability\GetAppointmentAvailability;
use App\Application\Availability\ProfessionalAvailability;
use App\Application\Availability\TimeInterval;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AppointmentAvailabilityCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_choices_are_neutrally_ordered_and_contain_calculated_ranges(): void
    {
        [$business, $service] = $this->schedule();
        $zoe = $this->professional($business, $service, 'Zoe');
        $ana = $this->professional($business, $service, 'Ana');
        $ana2 = $this->professional($business, $service, 'Ana');
        $this->professional($business, $service, 'Bea', false);
        $other = Professional::factory()->create();
        $service->professionals()->attach($other);

        $result = $this->availability($business, $service->id);

        $this->assertNull($result->requestedProfessional);
        $this->assertSame([$ana->id, $ana2->id, $zoe->id], array_map(fn (ProfessionalAvailability $p) => $p->id, $result->availableProfessionals));
        $this->assertTrue($result->availableProfessionals[0]->eligible);
        $this->assertInstanceOf(AppointmentStartRange::class, $result->availableProfessionals[0]->ranges[0]);
        $this->assertSame('2026-11-09 09:10', $result->availableProfessionals[0]->ranges[0]->earliest->format('Y-m-d H:i'));
        $this->assertSame('2026-11-09 15:40', $result->availableProfessionals[0]->ranges[0]->latest->format('Y-m-d H:i'));
    }

    public function test_one_eligible_professional_and_no_valid_availability(): void
    {
        [$business, $service] = $this->schedule();
        $professional = $this->professional($business, $service, 'Ana');
        $this->assertSame($professional->id, $this->availability($business, $service->id)->availableProfessionals[0]->id);
        $professional->availabilityIntervals()->delete();
        $this->assertSame([], $this->availability($business, $service->id)->availableProfessionals);
    }

    public function test_invalid_inactive_and_other_business_services_and_invalid_professionals_fail_safely(): void
    {
        [$business, $service] = $this->schedule();
        $inactive = Service::factory()->for($business)->create(['active' => false]);
        $otherService = Service::factory()->create();
        $otherProfessional = Professional::factory()->create();
        foreach ([9999999, $inactive->id, $otherService->id] as $id) {
            $this->assertNull($this->availability($business, $id));
        }
        $this->assertNull($this->availability($business, $service->id, $otherProfessional->id));
        $this->assertNull($this->availability($business, $service->id, 9999999));
    }

    public function test_requested_professional_is_preserved_when_available_or_unavailable(): void
    {
        [$business, $service] = $this->schedule();
        $requested = $this->professional($business, $service, 'Zoe');
        $alternative = $this->professional($business, $service, 'Ana');

        $result = $this->availability($business, $service->id, $requested->id);
        $this->assertSame($requested->id, $result->requestedProfessional->id);
        $this->assertTrue($result->requestedProfessional->eligible);
        $this->assertNotEmpty($result->requestedProfessional->ranges);
        $this->assertSame([], $result->availableProfessionals);

        $requested->availabilityIntervals()->delete();
        $result = $this->availability($business, $service->id, $requested->id);
        $this->assertSame($requested->id, $result->requestedProfessional->id);
        $this->assertTrue($result->requestedProfessional->eligible);
        $this->assertSame([], $result->requestedProfessional->ranges);
        $this->assertSame([$alternative->id], array_map(fn ($p) => $p->id, $result->availableProfessionals));
    }

    public function test_ineligible_requested_professional_is_distinguished_from_alternatives(): void
    {
        [$business, $service] = $this->schedule();
        $requested = Professional::factory()->for($business)->create();
        $alternative = $this->professional($business, $service, 'Ana');

        $result = $this->availability($business, $service->id, $requested->id);
        $this->assertSame($requested->id, $result->requestedProfessional->id);
        $this->assertFalse($result->requestedProfessional->eligible);
        $this->assertSame([], $result->requestedProfessional->ranges);
        $this->assertSame($alternative->id, $result->availableProfessionals[0]->id);
    }

    public function test_reversed_range_is_rejected_even_without_eligible_professionals(): void
    {
        [$business, $service] = $this->schedule();
        $this->expectException(InvalidArgumentException::class);
        $this->capability()->handle($business, $service->id, new DateTimeImmutable('2026-11-10 UTC'), new DateTimeImmutable('2026-11-09 UTC'));
    }

    public function test_occupancy_is_applied_to_the_selected_professionals_ranges(): void
    {
        [$business, $service] = $this->schedule();
        $professional = $this->professional($business, $service, 'Ana');
        $occupancy = new class implements AppointmentOccupancy
        {
            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                return [new TimeInterval(new DateTimeImmutable('2026-11-09 11:00 UTC'), new DateTimeImmutable('2026-11-09 12:00 UTC'))];
            }
        };
        $capability = new GetAppointmentAvailability(new CalculateAvailability($occupancy));
        $result = $capability->handle($business, $service->id, new DateTimeImmutable('2026-11-09 08:00 UTC'), new DateTimeImmutable('2026-11-09 19:00 UTC'), $professional->id);

        $this->assertSame([['09:10', '09:40'], ['12:10', '15:40']], array_map(
            fn (AppointmentStartRange $range): array => [$range->earliest->format('H:i'), $range->latest->format('H:i')],
            $result->requestedProfessional->ranges,
        ));
    }

    public function test_reference_business_preference_and_alternatives_use_seeded_exceptions(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $service = $business->services()->where('name', 'Initial beauty consultation')->firstOrFail();
        $laura = $business->professionals()->where('first_name', 'Laura')->firstOrFail();

        $result = $this->capability()->handle($business, $service->id, new DateTimeImmutable('2026-11-10 09:00 Europe/Madrid'), new DateTimeImmutable('2026-11-10 11:00 Europe/Madrid'), $laura->id);

        $this->assertSame($laura->id, $result->requestedProfessional->id);
        $this->assertTrue($result->requestedProfessional->eligible);
        $this->assertSame([], $result->requestedProfessional->ranges);
        $this->assertSame(['Claudia Pérez', 'Marta Ruiz'], array_map(fn ($p) => $p->displayName, $result->availableProfessionals));
        $this->assertSame('2026-11-10 09:00', $result->availableProfessionals[0]->ranges[0]->earliest->format('Y-m-d H:i'));
    }

    private function schedule(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['active' => true, 'duration_minutes' => 60, 'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20]);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '09:00', 'closes_at' => '17:00']);

        return [$business, $service];
    }

    private function professional(Business $business, Service $service, string $name, bool $available = true): Professional
    {
        $professional = Professional::factory()->for($business)->create(['first_name' => $name, 'last_name' => 'Smith']);
        $professional->services()->attach($service);
        if ($available) {
            ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'monday', 'starts_at' => '09:00', 'ends_at' => '17:00']);
        }

        return $professional;
    }

    private function capability(): GetAppointmentAvailability
    {
        $occupancy = new class implements AppointmentOccupancy
        {
            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                return [];
            }
        };

        return new GetAppointmentAvailability(new CalculateAvailability($occupancy));
    }

    private function availability(Business $business, int $serviceId, ?int $professionalId = null): ?AppointmentAvailability
    {
        return $this->capability()->handle($business, $serviceId, new DateTimeImmutable('2026-11-09 08:00 UTC'), new DateTimeImmutable('2026-11-09 19:00 UTC'), $professionalId);
    }
}
