<?php

namespace App\Providers;

use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\PostgresAppointmentOccupancy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AppointmentOccupancy::class, PostgresAppointmentOccupancy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
