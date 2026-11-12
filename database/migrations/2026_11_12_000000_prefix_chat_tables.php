<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chat tables get a chat_ prefix, as the music ones have music_. SQLite rewrites the foreign
// keys that point at a renamed table; the indexes keep their old names unless renamed too.
return new class extends Migration
{
    private const TABLES = [
        'conversations' => 'chat_conversations',
        'conversation_members' => 'chat_conversation_members',
        'conversation_bans' => 'chat_conversation_bans',
        'friendships' => 'chat_friendships',
        'blocks' => 'chat_blocks',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $from => $to) {
            $this->rename($from, $to);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $from => $to) {
            $this->rename($to, $from);
        }
    }

    private function rename(string $from, string $to): void
    {
        Schema::rename($from, $to);

        $indexes = collect(Schema::getIndexes($to))
            ->reject(fn (array $index) => $index['primary'])
            ->pluck('name')
            ->filter(fn (string $name) => str_starts_with($name, $from.'_'));

        Schema::table($to, function (Blueprint $table) use ($indexes, $from, $to) {
            foreach ($indexes as $name) {
                $table->renameIndex($name, $to.substr($name, strlen($from)));
            }
        });
    }
};
