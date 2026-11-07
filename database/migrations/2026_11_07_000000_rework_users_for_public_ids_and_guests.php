<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People are now told apart by a random public ID, so the name they show is free
     * text anyone can share. Guests have a name and nothing else.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('username', 'display_name');
            $table->string('public_id', 8)->nullable()->after('id');
            $table->boolean('is_guest')->default(false)->after('password');
        });

        foreach (DB::table('users')->whereNull('public_id')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['public_id' => User::newPublicId()]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('public_id', 8)->nullable(false)->change();
            $table->unique('public_id');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->index('is_guest');
        });
    }

    /**
     * Reverse the migrations. Guests have no email or password, so they cannot survive it.
     */
    public function down(): void
    {
        DB::table('users')->where('is_guest', true)->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropIndex(['is_guest']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'is_guest']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
            $table->renameColumn('display_name', 'username');
        });

        // Names were free text in between, so make them unique again the same way as before.
        $taken = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'username']) as $user) {
            $username = $user->username;

            for ($suffix = 2; isset($taken[$username]); $suffix++) {
                $username = $user->username.'-'.$suffix;
            }

            $taken[$username] = true;

            if ($username !== $user->username) {
                DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }
};
