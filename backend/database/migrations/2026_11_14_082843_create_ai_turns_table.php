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
        Schema::create('ai_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_message_id')->unique()->constrained('conversation_messages');
            $table->foreignId('assistant_message_id')->nullable()->unique()->constrained('conversation_messages');
            $table->string('state')->default('pending');
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claimed_until')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE ai_turns ADD CONSTRAINT ai_turns_state_valid CHECK (state IN ('pending', 'processing', 'completed', 'failed', 'superseded'))");
        DB::statement("ALTER TABLE ai_turns ADD CONSTRAINT ai_turns_ownership_valid CHECK ((state = 'processing' AND claim_token IS NOT NULL AND claimed_until IS NOT NULL) OR (state <> 'processing' AND claim_token IS NULL AND claimed_until IS NULL))");
        DB::statement("ALTER TABLE ai_turns ADD CONSTRAINT ai_turns_completion_valid CHECK ((state = 'completed' AND assistant_message_id IS NOT NULL AND completed_at IS NOT NULL) OR (state <> 'completed' AND assistant_message_id IS NULL AND completed_at IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_turns');
    }
};
