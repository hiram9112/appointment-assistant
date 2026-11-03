<?php

namespace Database\Seeders;

use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class BusinessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $business = Business::updateOrCreate(['name' => 'Lúmina Estética Avanzada'], [
            'description' => "Lúmina Estética Avanzada is an independent beauty center offering facial treatments, body treatments, laser hair removal and wellness services.\n\nThe center operates from a single location with multiple Professionals and uses appointments as its primary service model.\n\nWhatsApp is its main conversational Customer channel.\n\nThe Business does not provide medical or clinical treatments.",
            'timezone' => 'Europe/Madrid',
            'address_line' => 'Calle Lúmina 18',
            'postal_code' => '50005',
            'city' => 'Zaragoza',
            'country_code' => 'ES',
            'phone' => '+34 600 000 000',
            'email' => 'hola@lumina-demo.test',
            'payment_methods' => ['card', 'cash'],
            'appointments_recommended' => true,
            'arrival_lead_minutes' => 5,
            'cancellation_notice_minutes' => 1440,
            'rescheduling_notice_minutes' => 1440,
        ]);

        $business->operatingHours()->delete();

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $weekday) {
            $business->operatingHours()->create([
                'weekday' => $weekday,
                'opens_at' => '09:00:00',
                'closes_at' => $weekday === 'saturday' ? '14:00:00' : '20:00:00',
            ]);
        }

        $services = [
            ['Initial beauty consultation', 30, '0.00', 0, 0],
            ['Deep facial cleansing', 60, '55.00', 5, 10],
            ['Hydrating facial treatment', 60, '65.00', 5, 10],
            ['Premium facial treatment', 90, '85.00', 10, 10],
            ['Laser hair removal — underarms', 20, '25.00', 5, 5],
            ['Laser hair removal — full legs', 45, '65.00', 5, 10],
            ['Firming body treatment', 60, '70.00', 5, 10],
            ['Wellness massage', 60, '50.00', 5, 10],
        ];

        $serviceIds = [];
        foreach ($services as [$name, $duration, $price, $bufferBefore, $bufferAfter]) {
            $serviceIds[$name] = $business->services()->updateOrCreate(['name' => $name], [
                'description' => null,
                'duration_minutes' => $duration,
                'buffer_before_minutes' => $bufferBefore,
                'buffer_after_minutes' => $bufferAfter,
                'price_amount' => $price,
                'currency_code' => 'EUR',
                'active' => true,
            ])->id;
        }

        $professionalIds = [];
        foreach ([
            ['Laura', 'Martín', ['Initial beauty consultation', 'Deep facial cleansing', 'Hydrating facial treatment', 'Premium facial treatment']],
            ['Marta', 'Ruiz', ['Initial beauty consultation', 'Laser hair removal — underarms', 'Laser hair removal — full legs']],
            ['Claudia', 'Pérez', ['Initial beauty consultation', 'Firming body treatment', 'Wellness massage']],
            ['Sara', 'Gómez', ['Initial beauty consultation', 'Deep facial cleansing', 'Hydrating facial treatment', 'Laser hair removal — underarms', 'Firming body treatment']],
        ] as [$firstName, $lastName, $eligibleServices]) {
            $professional = $business->professionals()->updateOrCreate([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ], [
                'display_name' => null,
            ]);
            $eligibleIds = array_map(fn (string $name): int => $serviceIds[$name], $eligibleServices);
            $professional->services()->detach(array_values(array_diff($serviceIds, $eligibleIds)));
            $professional->services()->syncWithoutDetaching($eligibleIds);
            $professionalIds[$firstName] = $professional->id;
        }

        $schedules = [
            'Laura' => [
                [['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], [['09:00', '13:30'], ['14:30', '17:00']]],
            ],
            'Marta' => [
                [['monday', 'tuesday', 'wednesday', 'thursday'], [['11:00', '15:00'], ['16:00', '20:00']]],
                [['friday'], [['09:00', '15:00'], ['16:00', '17:00']]],
            ],
            'Claudia' => [
                [['tuesday', 'wednesday', 'thursday', 'friday'], [['10:00', '14:00'], ['15:00', '19:00']]],
                [['saturday'], [['09:00', '14:00']]],
            ],
            'Sara' => [
                [['monday', 'tuesday', 'wednesday'], [['12:00', '14:00'], ['15:00', '20:00']]],
                [['thursday', 'friday'], [['09:00', '14:00'], ['15:00', '17:00']]],
            ],
        ];

        foreach ($schedules as $name => $schedule) {
            $professional = $business->professionals()->findOrFail($professionalIds[$name]);
            foreach ($schedule as [$weekdays, $intervals]) {
                foreach ($weekdays as $weekday) {
                    foreach ($intervals as [$startsAt, $endsAt]) {
                        $professional->availabilityIntervals()->firstOrCreate([
                            'weekday' => $weekday,
                            'starts_at' => $startsAt.':00',
                            'ends_at' => $endsAt.':00',
                        ]);
                    }
                }
            }
        }

        foreach ([
            ['Laura', '2026-11-10 09:00', '2026-11-10 12:00', 'Personal block.'],
            ['Marta', '2026-11-12 17:00', '2026-11-12 20:00', 'Professional unavailable.'],
            ['Claudia', '2026-11-13 00:00', '2026-11-14 00:00', null],
            [null, '2026-11-11 18:00', '2026-11-11 20:00', null],
        ] as [$name, $startsAt, $endsAt, $reason]) {
            $business->scheduleExceptions()->updateOrCreate([
                'professional_id' => $name === null ? null : $professionalIds[$name],
                'starts_at' => CarbonImmutable::parse($startsAt, $business->timezone)->utc()->format('Y-m-d H:i:s'),
                'ends_at' => CarbonImmutable::parse($endsAt, $business->timezone)->utc()->format('Y-m-d H:i:s'),
                'kind' => 'blocked',
            ], ['reason' => $reason]);
        }
    }
}
