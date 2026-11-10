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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('channel');
            $table->string('sender_identity');
            $table->timestampTz('last_activity_at');
            $table->timestamps();
            $table->index(['business_id', 'channel', 'sender_identity', 'last_activity_at'], 'conversations_participant_activity_index');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
