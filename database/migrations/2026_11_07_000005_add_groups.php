<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Group fields stay null on DMs. Roles live in conversation_members.role.
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('name', 50)->nullable();
            $table->string('description', 200)->nullable();
            $table->string('visibility', 10)->nullable()->index();
            $table->string('invite_code', 16)->nullable()->unique();
        });

        // System messages ("Bob joined") have no author; meta holds who did what.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('type', 10)->default('text');
            $table->json('meta')->nullable();
        });

        Schema::create('conversation_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_bans');

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['type', 'meta']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropUnique(['invite_code']);
            $table->dropColumn(['name', 'description', 'visibility', 'invite_code']);
        });
    }
};
