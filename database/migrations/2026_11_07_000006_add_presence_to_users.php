<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // What they chose (online / idle / dnd / invisible) and what others last heard.
            $table->string('status', 10)->default('online');
            $table->string('presence', 10)->default('offline')->index();
            // Null while no tab has the WebSocket open.
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('active_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['presence']);
            $table->dropColumn(['status', 'presence', 'connected_at', 'active_at']);
        });
    }
};
