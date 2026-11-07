<?php

use App\Models\ChatMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Messages deleted before deletes wiped their text still hold it.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chat_messages')->whereNotNull('deleted_at')->update(['body' => ChatMessage::DELETED_BODY]);
    }

    public function down(): void
    {
        // The original text is gone for good.
    }
};
