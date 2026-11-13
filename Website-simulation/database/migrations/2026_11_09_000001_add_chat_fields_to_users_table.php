<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable at DB level (SQLite can't add NOT NULL columns without default);
            // registration always requires it.
            $table->string('username', 30)->nullable()->unique()->after('name');
            $table->string('bio', 160)->nullable()->after('email');
            $table->boolean('is_admin')->default(false)->after('bio');
            $table->timestamp('last_seen_at')->nullable()->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'bio', 'is_admin', 'last_seen_at']);
        });
    }
};
