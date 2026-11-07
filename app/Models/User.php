<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['display_name', 'email', 'password', 'is_guest'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // No 0/o or 1/i/l, so an ID read out loud or copied by hand survives.
    private const PUBLIC_ID_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const PUBLIC_ID_LENGTH = 8;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->public_id ??= static::newPublicId();
        });

        // Tokens hang off a morph relation, which has no foreign key to cascade.
        static::deleting(function (User $user) {
            $user->tokens()->delete();
        });
    }

    public static function newPublicId(): string
    {
        do {
            $id = '';

            for ($i = 0; $i < self::PUBLIC_ID_LENGTH; $i++) {
                $id .= self::PUBLIC_ID_ALPHABET[random_int(0, strlen(self::PUBLIC_ID_ALPHABET) - 1)];
            }
        } while (DB::table('users')->where('public_id', $id)->exists());

        return $id;
    }

    /**
     * Guests that can no longer come back: their session expired or was never saved.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAbandonedGuests(Builder $query): void
    {
        $since = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        $query->where('is_guest', true)
            // Grace period, so a guest made a moment ago is not swept before its session is written.
            ->where('created_at', '<', now()->subMinutes(10))
            ->whereNotExists(fn ($sessions) => $sessions
                ->from(config('session.table'))
                ->whereColumn('user_id', 'users.id')
                ->where('last_activity', '>=', $since));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_guest' => 'boolean',
        ];
    }
}
