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
        Schema::table('ai_turns', function (Blueprint $table) {
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('failure_classification')->nullable();
            $table->string('failure_reason')->nullable();
            $table->jsonb('diagnostics')->default('{}');
            $table->timestampTz('terminal_at')->nullable();
            $table->string('terminal_reason')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->index(['state', 'next_attempt_at']);
        });

        /** Earlier attempt totals are unknowable; retain the one evidenced by legacy state. */
        DB::table('ai_turns')->whereIn('state', ['processing', 'failed', 'completed'])->update(['attempt_count' => 1]);
        DB::statement('ALTER TABLE ai_turns DROP CONSTRAINT ai_turns_state_valid, DROP CONSTRAINT ai_turns_ownership_valid');
        DB::statement(<<<'SQL'
            ALTER TABLE ai_turns
            ADD CONSTRAINT ai_turns_state_valid CHECK (
                state IN ('pending', 'processing', 'completed', 'failed', 'superseded', 'failure_resolution_pending', 'resolving_failure', 'closed_without_response')
            ),
            ADD CONSTRAINT ai_turns_ownership_valid CHECK (
                (state IN ('processing', 'resolving_failure') AND claim_token IS NOT NULL AND claimed_until IS NOT NULL)
                OR (state NOT IN ('processing', 'resolving_failure') AND claim_token IS NULL AND claimed_until IS NULL)
            ),
            ADD CONSTRAINT ai_turns_retry_valid CHECK (attempt_count >= 0 AND (next_attempt_at IS NULL OR state IN ('failed', 'failure_resolution_pending'))),
            ADD CONSTRAINT ai_turns_terminal_valid CHECK (
                (state = 'closed_without_response' AND terminal_at IS NOT NULL AND terminal_reason IS NOT NULL)
                OR (state <> 'closed_without_response' AND terminal_at IS NULL AND terminal_reason IS NULL)
            ),
            ADD CONSTRAINT ai_turns_failure_valid CHECK (
                (failure_classification IS NULL OR failure_classification IN ('recoverable', 'non_recoverable', 'persistence_unavailable'))
                AND (failure_reason IS NULL OR failure_reason IN ('processing_failed', 'generation_failed', 'response_persistence_failed', 'claim_expired', 'finalization_failed', 'authorization_failed', 'unresolved_failure'))
                AND (terminal_reason IS NULL OR terminal_reason IN ('processing_failed', 'generation_failed', 'response_persistence_failed', 'claim_expired', 'finalization_failed', 'authorization_failed', 'unresolved_failure'))
                AND jsonb_typeof(diagnostics) = 'object'
                AND (NOT fallback_used OR state = 'completed')
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE ai_turns DROP CONSTRAINT ai_turns_state_valid, DROP CONSTRAINT ai_turns_ownership_valid, DROP CONSTRAINT ai_turns_retry_valid, DROP CONSTRAINT ai_turns_terminal_valid, DROP CONSTRAINT ai_turns_failure_valid');
        DB::table('ai_turns')->whereIn('state', ['failure_resolution_pending', 'resolving_failure'])
            ->update(['state' => 'failed', 'claim_token' => null, 'claimed_until' => null]);
        DB::table('ai_turns')->where('state', 'closed_without_response')->update(['state' => 'superseded']);
        DB::statement("ALTER TABLE ai_turns ADD CONSTRAINT ai_turns_state_valid CHECK (state IN ('pending', 'processing', 'completed', 'failed', 'superseded'))");
        DB::statement("ALTER TABLE ai_turns ADD CONSTRAINT ai_turns_ownership_valid CHECK ((state = 'processing' AND claim_token IS NOT NULL AND claimed_until IS NOT NULL) OR (state <> 'processing' AND claim_token IS NULL AND claimed_until IS NULL))");
        Schema::table('ai_turns', function (Blueprint $table) {
            $table->dropIndex(['state', 'next_attempt_at']);
            $table->dropColumn(['attempt_count', 'next_attempt_at', 'failure_classification', 'failure_reason', 'diagnostics', 'terminal_at', 'terminal_reason', 'fallback_used']);
        });
    }
};
