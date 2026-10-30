<?php

namespace Tests\Feature;

use App\Application\Business\BusinessInformation;
use App\Application\Business\BusinessOperatingInterval;
use App\Application\Business\GetBusinessInformation;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetBusinessInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_complete_configured_information_and_only_the_business_intervals(): void
    {
        $business = Business::factory()->create([
            'name' => 'Example Business', 'description' => 'A description', 'timezone' => 'Europe/Paris',
            'address_line' => '12 Example Street', 'postal_code' => '75001', 'city' => 'Paris',
            'country_code' => 'FR', 'phone' => '+33 1 23 45 67 89', 'email' => 'hello@example.test',
            'payment_methods' => ['card', 'cash'], 'appointments_recommended' => true,
            'arrival_lead_minutes' => 5, 'cancellation_notice_minutes' => 1440, 'rescheduling_notice_minutes' => 720,
        ]);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '14:00:00', 'closes_at' => '18:00:00']);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '09:00:00', 'closes_at' => '12:00:00']);
        BusinessOperatingHour::factory()->create(['weekday' => 'sunday']);

        $result = (new GetBusinessInformation)->handle($business->fresh());

        $this->assertEquals(new BusinessInformation(
            name: 'Example Business', description: 'A description', timezone: 'Europe/Paris',
            addressLine: '12 Example Street', postalCode: '75001', city: 'Paris', countryCode: 'FR',
            phone: '+33 1 23 45 67 89', email: 'hello@example.test',
            operatingHours: [
                new BusinessOperatingInterval('monday', '09:00:00', '12:00:00'),
                new BusinessOperatingInterval('monday', '14:00:00', '18:00:00'),
            ],
            paymentMethods: ['card', 'cash'], appointmentsRecommended: true, arrivalLeadMinutes: 5,
            cancellationNoticeMinutes: 1440, reschedulingNoticeMinutes: 720,
        ), $result);
    }

    public function test_intervals_are_ordered_by_weekday_then_opening_and_closing_time(): void
    {
        $business = Business::factory()->create();
        foreach (['sunday', 'saturday', 'friday', 'thursday', 'wednesday', 'tuesday', 'monday'] as $weekday) {
            BusinessOperatingHour::factory()->for($business)->create([
                'weekday' => $weekday, 'opens_at' => '09:00:00', 'closes_at' => '12:00:00',
            ]);
        }
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '14:00:00', 'closes_at' => '18:00:00']);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '09:00:00', 'closes_at' => '10:00:00']);

        $result = (new GetBusinessInformation)->handle($business);

        $this->assertEquals([
            new BusinessOperatingInterval('monday', '09:00:00', '10:00:00'),
            new BusinessOperatingInterval('monday', '09:00:00', '12:00:00'),
            new BusinessOperatingInterval('monday', '14:00:00', '18:00:00'),
            new BusinessOperatingInterval('tuesday', '09:00:00', '12:00:00'),
            new BusinessOperatingInterval('wednesday', '09:00:00', '12:00:00'),
            new BusinessOperatingInterval('thursday', '09:00:00', '12:00:00'),
            new BusinessOperatingInterval('friday', '09:00:00', '12:00:00'),
            new BusinessOperatingInterval('saturday', '09:00:00', '12:00:00'),
            new BusinessOperatingInterval('sunday', '09:00:00', '12:00:00'),
        ], $result->operatingHours);
    }

    public function test_unavailable_information_is_not_fabricated(): void
    {
        $business = Business::factory()->create([
            'name' => 'Minimal', 'timezone' => 'UTC', 'description' => null, 'address_line' => null,
            'postal_code' => null, 'city' => null, 'country_code' => null, 'phone' => null, 'email' => null,
            'payment_methods' => null, 'appointments_recommended' => null, 'arrival_lead_minutes' => null,
            'cancellation_notice_minutes' => null, 'rescheduling_notice_minutes' => null,
        ]);

        $result = (new GetBusinessInformation)->handle($business->fresh());

        $this->assertEquals(new BusinessInformation(
            'Minimal', null, 'UTC', null, null, null, null, null, null, [], null, null, null, null, null,
        ), $result);
    }

    public function test_false_zero_and_empty_configuration_remain_distinct_from_unavailable(): void
    {
        $business = Business::factory()->create([
            'payment_methods' => [], 'appointments_recommended' => false, 'arrival_lead_minutes' => 0,
            'cancellation_notice_minutes' => 0, 'rescheduling_notice_minutes' => 0,
        ]);

        $result = (new GetBusinessInformation)->handle($business->fresh());

        $this->assertSame([], $result->paymentMethods);
        $this->assertFalse($result->appointmentsRecommended);
        $this->assertSame(0, $result->arrivalLeadMinutes);
        $this->assertSame(0, $result->cancellationNoticeMinutes);
        $this->assertSame(0, $result->reschedulingNoticeMinutes);
    }

    public function test_reference_business_exercises_the_capability(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();

        $result = (new GetBusinessInformation)->handle($business);

        $this->assertSame('Lúmina Estética Avanzada', $result->name);
        $this->assertSame("Lúmina Estética Avanzada is an independent beauty center offering facial treatments, body treatments, laser hair removal and wellness services.\n\nThe center operates from a single location with multiple Professionals and uses appointments as its primary service model.\n\nWhatsApp is its main conversational Customer channel.\n\nThe Business does not provide medical or clinical treatments.", $result->description);
        $this->assertSame('Europe/Madrid', $result->timezone);
        $this->assertSame('Calle Lúmina 18', $result->addressLine);
        $this->assertSame('50005', $result->postalCode);
        $this->assertSame('Zaragoza', $result->city);
        $this->assertSame('ES', $result->countryCode);
        $this->assertSame('+34 600 000 000', $result->phone);
        $this->assertSame('hola@lumina-demo.test', $result->email);
        $this->assertSame(['card', 'cash'], $result->paymentMethods);
        $this->assertTrue($result->appointmentsRecommended);
        $this->assertSame(5, $result->arrivalLeadMinutes);
        $this->assertSame(1440, $result->cancellationNoticeMinutes);
        $this->assertSame(1440, $result->reschedulingNoticeMinutes);
        $this->assertCount(6, $result->operatingHours);
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $weekday) {
            $this->assertContainsEquals(new BusinessOperatingInterval($weekday, '09:00:00', '20:00:00'), $result->operatingHours);
        }
        $this->assertContainsEquals(new BusinessOperatingInterval('saturday', '09:00:00', '14:00:00'), $result->operatingHours);
        $this->assertNotContains('sunday', array_map(fn (BusinessOperatingInterval $interval): string => $interval->weekday, $result->operatingHours));
    }
}
