<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('professional_id')->constrained();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
            $table->string('status');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('confirmed', 'cancelled', 'completed'))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_interval_positive CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_buffer_before_nonnegative CHECK (buffer_before_minutes >= 0)');
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_buffer_after_nonnegative CHECK (buffer_after_minutes >= 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE appointments ADD CONSTRAINT appointments_confirmed_occupancy_exclusion
            EXCLUDE USING gist (
                professional_id WITH =,
                tsrange(
                    (starts_at AT TIME ZONE 'UTC') - buffer_before_minutes * interval '1 minute',
                    (ends_at AT TIME ZONE 'UTC') + buffer_after_minutes * interval '1 minute',
                    '[)'
                ) WITH &&
            ) WHERE (status = 'confirmed')
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
