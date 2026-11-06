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
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_valid');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('confirmed', 'cancelled', 'completed', 'no_show'))");

        Schema::table('appointment_audits', function (Blueprint $table) {
            $table->boolean('late_notice')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_valid');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('confirmed', 'cancelled', 'completed'))");

        Schema::table('appointment_audits', function (Blueprint $table) {
            $table->dropColumn('late_notice');
        });
    }
};
