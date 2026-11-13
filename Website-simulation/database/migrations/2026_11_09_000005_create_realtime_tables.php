<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Short-lived credentials used to authenticate a TCP / WebSocket connection.
        Schema::create('chat_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 of the plaintext token
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Outbox: web requests write here, the gateway process reads and pushes to sockets.
        Schema::create('realtime_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_events');
        Schema::dropIfExists('chat_tokens');
    }
};
