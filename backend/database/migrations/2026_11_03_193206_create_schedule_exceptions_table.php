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
        Schema::table('professionals', function (Blueprint $table) {
            $table->unique(['business_id', 'id']);
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('professional_id')->nullable()->constrained();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('kind');
            $table->text('reason')->nullable();
            $table->foreign(['business_id', 'professional_id'])->references(['business_id', 'id'])->on('professionals');
        });

        DB::statement("ALTER TABLE schedule_exceptions ADD CONSTRAINT schedule_exceptions_kind_valid CHECK (kind IN ('available', 'blocked'))");
        DB::statement('ALTER TABLE schedule_exceptions ADD CONSTRAINT schedule_exceptions_interval_positive CHECK (starts_at < ends_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'id']);
        });
    }
};
