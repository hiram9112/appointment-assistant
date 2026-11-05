<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained();
            $table->string('action');
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('source');
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state');
            $table->timestampTz('occurred_at');
            $table->index(['appointment_id', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_audits');
    }
};
