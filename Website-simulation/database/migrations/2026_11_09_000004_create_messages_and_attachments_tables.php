<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = system message
            $table->string('type', 16)->default('text'); // text | file | system
            $table->text('body')->nullable();

            // Reserved for the AI spam/scam detection module (see docs/ROADMAP.md).
            $table->string('spam_label', 16)->nullable();   // ham | spam | scam
            $table->float('spam_score')->nullable();        // 0..1
            $table->timestamp('scanned_at')->nullable();

            $table->timestamp('deleted_at')->nullable();    // "recalled" messages
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->boolean('is_image')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('messages');
    }
};
