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
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained();
            $table->string('role');
            $table->text('content');
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at', 'id'], 'conversation_messages_history_index');
        });

        DB::statement("ALTER TABLE conversation_messages ADD CONSTRAINT conversation_messages_role_valid CHECK (role IN ('user', 'assistant'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
