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
        Schema::create('professional_availability_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained();
            $table->string('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
        });

        DB::statement("ALTER TABLE professional_availability_intervals ADD CONSTRAINT professional_availability_weekday_valid CHECK (weekday IN ('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'))");
        DB::statement('ALTER TABLE professional_availability_intervals ADD CONSTRAINT professional_availability_interval_positive CHECK (starts_at < ends_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_availability_intervals');
    }
};
