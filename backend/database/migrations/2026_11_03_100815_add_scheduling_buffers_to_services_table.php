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
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
        });

        DB::statement('ALTER TABLE services ADD CONSTRAINT services_buffer_before_nonnegative CHECK (buffer_before_minutes >= 0)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_buffer_after_nonnegative CHECK (buffer_after_minutes >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['buffer_before_minutes', 'buffer_after_minutes']);
        });
    }
};
