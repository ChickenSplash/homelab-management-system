<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone')->default('Europe/London');
            $table->timestamps();
        });
        Schema::create('daily_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('prompt', 300)->default('');
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'position']);
        });
        Schema::create('daily_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('daily_journals')->cascadeOnDelete();
            $table->date('entry_date');
            $table->text('body');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['journal_id', 'entry_date']);
        });
        Schema::create('daily_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // Immutable: changing units would make old quantities meaningless.
            $table->string('unit', 20);
            $table->date('archived_on')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'archived_on']);
        });
        Schema::create('daily_schedule_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('daily_activities')->cascadeOnDelete();
            // ISO weekdays, Monday = 1. Versioned effective ranges are inclusive.
            $table->json('weekdays');
            $table->decimal('target', 12, 2)->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['activity_id', 'starts_on', 'ends_on']);
        });
        Schema::create('daily_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('daily_activities')->cascadeOnDelete();
            $table->date('activity_date');
            $table->string('name', 100);
            $table->string('unit', 20);
            $table->decimal('target', 12, 2)->nullable();
            $table->decimal('actual', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->boolean('is_one_off')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['activity_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        foreach (['daily_activity_days', 'daily_schedule_rules', 'daily_activities', 'daily_entries', 'daily_journals', 'daily_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
