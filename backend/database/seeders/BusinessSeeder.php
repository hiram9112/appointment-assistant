<?php

namespace Database\Seeders;

use App\Models\Business;
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
            ['Initial beauty consultation', 30, '0.00'],
            ['Deep facial cleansing', 60, '55.00'],
            ['Hydrating facial treatment', 60, '65.00'],
            ['Premium facial treatment', 90, '85.00'],
            ['Laser hair removal — underarms', 20, '25.00'],
            ['Laser hair removal — full legs', 45, '65.00'],
            ['Firming body treatment', 60, '70.00'],
            ['Wellness massage', 60, '50.00'],
        ];

        foreach ($services as [$name, $duration, $price]) {
            $business->services()->updateOrCreate(['name' => $name], [
                'description' => null,
                'duration_minutes' => $duration,
                'price_amount' => $price,
                'currency_code' => 'EUR',
                'active' => true,
            ])->id;
        }

        foreach ([
            ['Laura', 'Martín'],
            ['Marta', 'Ruiz'],
            ['Claudia', 'Pérez'],
            ['Sara', 'Gómez'],
        ] as [$firstName, $lastName]) {
            $business->professionals()->updateOrCreate([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ], [
                'display_name' => null,
            ]);
        }

    }
}
