<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->jsonb('payment_methods')->nullable();
            $table->boolean('appointments_recommended')->nullable();
            $table->integer('arrival_lead_minutes')->nullable();
            $table->integer('cancellation_notice_minutes')->nullable();
            $table->integer('rescheduling_notice_minutes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'payment_methods',
                'appointments_recommended',
                'arrival_lead_minutes',
                'cancellation_notice_minutes',
                'rescheduling_notice_minutes',
            ]);
        });
    }
};
