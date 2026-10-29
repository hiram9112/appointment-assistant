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
        ]);

        $business->operatingHours()->delete();

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $weekday) {
            $business->operatingHours()->create([
                'weekday' => $weekday,
                'opens_at' => '09:00:00',
                'closes_at' => $weekday === 'saturday' ? '14:00:00' : '20:00:00',
            ]);
        }
    }
}
