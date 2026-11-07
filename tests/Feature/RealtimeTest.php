<?php

namespace Tests\Feature;

use App\Events\Ping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_is_a_json_health_check_without_a_session(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_the_realtime_key_is_public(): void
    {
        config(['broadcasting.connections.reverb.key' => 'public-key']);

        $this->getJson('/api/realtime')->assertExactJson(['key' => 'public-key']);
    }

    public function test_a_ping_is_broadcast_on_the_senders_own_channel(): void
    {
        Event::fake([Ping::class]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/ping')->assertAccepted();

        Event::assertDispatched(Ping::class, fn (Ping $ping) => $ping->broadcastOn()->name === 'private-user.'.$user->public_id);
    }

    public function test_pinging_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/ping')->assertUnauthorized();
    }

    public function test_only_you_can_listen_on_your_channel(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        // Channels were registered on the null driver at boot; register them on Reverb too.
        require base_path('routes/channels.php');

        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($me)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$me->public_id])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($me)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$other->public_id])
            ->assertForbidden();
    }
}
