<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestPruneTest extends TestCase
{
    use RefreshDatabase;

    private function sessionFor(User $user, int $minutesAgo): void
    {
        DB::table('sessions')->insert([
            'id' => fake()->uuid(),
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    public function test_only_guests_without_a_live_session_are_pruned(): void
    {
        $lifetime = config('session.lifetime');
        $old = ['created_at' => now()->subDays(60)];

        $active = User::factory()->guest()->create($old);
        $this->sessionFor($active, 5);

        $expired = User::factory()->guest()->create($old);
        $this->sessionFor($expired, $lifetime + 5);

        $noSession = User::factory()->guest()->create($old);
        $brandNew = User::factory()->guest()->create();
        $user = User::factory()->create($old);

        $this->artisan('guests:prune')->expectsOutput('Pruned 2 abandoned guest(s).')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $active->id]);
        $this->assertDatabaseMissing('users', ['id' => $expired->id]);
        $this->assertDatabaseMissing('users', ['id' => $noSession->id]);
        $this->assertDatabaseHas('users', ['id' => $brandNew->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
