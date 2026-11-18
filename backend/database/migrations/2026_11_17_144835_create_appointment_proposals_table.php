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
        Schema::create('appointment_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained();
            $table->foreignId('originating_turn_id')->unique()->constrained('ai_turns');
            $table->string('operation');
            $table->jsonb('payload');
            $table->jsonb('presentation');
            $table->string('state')->default('prepared');
            $table->foreignId('confirmation_request_message_id')->nullable()->unique()->constrained('conversation_messages');
            $table->foreignId('confirming_turn_id')->nullable()->unique()->constrained('ai_turns');
            $table->jsonb('result')->nullable();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX appointment_proposals_one_active ON appointment_proposals (conversation_id) WHERE state IN ('prepared', 'pending')");
        DB::statement(<<<'SQL'
            ALTER TABLE appointment_proposals
            ADD CONSTRAINT appointment_proposals_operation_valid CHECK (operation IN ('create', 'reschedule', 'cancel')),
            ADD CONSTRAINT appointment_proposals_documents_valid CHECK (
                jsonb_typeof(payload) = 'object' AND jsonb_typeof(presentation) = 'object'
                AND (result IS NULL OR jsonb_typeof(result) = 'object')
            ),
            ADD CONSTRAINT appointment_proposals_state_valid CHECK (
                (state = 'prepared' AND confirmation_request_message_id IS NULL AND confirming_turn_id IS NULL AND result IS NULL)
                OR (state = 'pending' AND confirmation_request_message_id IS NOT NULL AND confirming_turn_id IS NULL AND result IS NULL)
                OR (state = 'completed' AND confirmation_request_message_id IS NOT NULL AND confirming_turn_id IS NOT NULL AND result IS NOT NULL)
                OR (state = 'rejected' AND (
                    (confirming_turn_id IS NULL AND result IS NULL)
                    OR (confirmation_request_message_id IS NOT NULL AND confirming_turn_id IS NOT NULL AND result IS NOT NULL)
                ))
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_appointment_proposal() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Proposal history must be retained' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'INSERT' AND NEW.state <> 'prepared' THEN
                    RAISE EXCEPTION 'A proposal must start prepared' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF ROW(NEW.id, NEW.conversation_id, NEW.originating_turn_id, NEW.operation, NEW.payload, NEW.presentation, NEW.created_at)
                        IS DISTINCT FROM ROW(OLD.id, OLD.conversation_id, OLD.originating_turn_id, OLD.operation, OLD.payload, OLD.presentation, OLD.created_at) THEN
                        RAISE EXCEPTION 'Canonical proposal parameters are immutable' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.state IN ('completed', 'rejected') AND NEW IS DISTINCT FROM OLD THEN
                        RAISE EXCEPTION 'A terminal proposal is immutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.state <> OLD.state AND NOT (
                        (OLD.state = 'prepared' AND NEW.state IN ('pending', 'rejected'))
                        OR (OLD.state = 'pending' AND NEW.state IN ('completed', 'rejected'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid proposal transition' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.confirmation_request_message_id IS NOT NULL
                        AND NEW.confirmation_request_message_id IS DISTINCT FROM OLD.confirmation_request_message_id THEN
                        RAISE EXCEPTION 'An accepted confirmation request is immutable' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.confirmation_request_message_id IS NULL AND NEW.confirmation_request_message_id IS NOT NULL
                        AND NOT (OLD.state = 'prepared' AND NEW.state = 'pending') THEN
                        RAISE EXCEPTION 'Only presentation can attach a confirmation request' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM ai_turns t JOIN conversation_messages m ON m.id = t.inbound_message_id
                    WHERE t.id = NEW.originating_turn_id AND m.conversation_id = NEW.conversation_id AND m.role = 'user'
                ) THEN
                    RAISE EXCEPTION 'Originating turn must belong to the proposal Conversation' USING ERRCODE = '23514';
                END IF;
                IF NEW.confirmation_request_message_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM conversation_messages m
                    WHERE m.id = NEW.confirmation_request_message_id AND m.conversation_id = NEW.conversation_id AND m.role = 'assistant'
                ) THEN
                    RAISE EXCEPTION 'Confirmation request must be an assistant message in the same Conversation' USING ERRCODE = '23514';
                END IF;
                IF NEW.confirming_turn_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM ai_turns t JOIN conversation_messages m ON m.id = t.inbound_message_id
                    WHERE t.id = NEW.confirming_turn_id AND m.conversation_id = NEW.conversation_id
                        AND m.role = 'user' AND m.id > NEW.confirmation_request_message_id
                ) THEN
                    RAISE EXCEPTION 'Confirming turn must follow the request in the same Conversation' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER appointment_proposals_guard BEFORE INSERT OR UPDATE OR DELETE ON appointment_proposals
                FOR EACH ROW EXECUTE FUNCTION guard_appointment_proposal();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_proposals');
        DB::statement('DROP FUNCTION IF EXISTS guard_appointment_proposal()');
    }
};
