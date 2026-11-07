<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function registration(array $overrides = []): array
    {
        return [
            'display_name' => 'Taylor',
            'email' => 'taylor@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            ...$overrides,
        ];
    }

    public function test_a_user_can_register_and_is_signed_in(): void
    {
        $this->fromSpa()->postJson('/api/auth/register', $this->registration())
            ->assertCreated()
            ->assertJson(['display_name' => 'Taylor', 'is_guest' => false, 'email' => 'taylor@example.com'])
            ->assertJsonMissingPath('password');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{8}$/', $user->public_id);
        $this->assertSame($user->public_id, $this->getJson('/api/auth/me')->json('id'));
    }

    public function test_display_names_do_not_have_to_be_unique(): void
    {
        User::factory()->create(['display_name' => 'Taylor']);

        $this->fromSpa()->postJson('/api/auth/register', $this->registration())->assertCreated();

        $this->assertSame(2, User::where('display_name', 'Taylor')->count());
        $this->assertSame(2, User::distinct()->count('public_id'));
    }

    public function test_an_email_can_only_register_once(): void
    {
        User::factory()->create(['email' => 'taylor@example.com']);

        $this->fromSpa()->postJson('/api/auth/register', $this->registration())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_display_name_needs_something_visible_and_no_control_characters(): void
    {
        foreach (['', '   ', "evil\u{202E}name", str_repeat('a', 33)] as $name) {
            $this->fromSpa()->postJson('/api/auth/register', $this->registration(['display_name' => $name]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('display_name');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_user_can_log_in_with_their_email_and_password(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $this->fromSpa()->postJson('/api/auth/login', ['email' => 'test@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJson(['id' => $user->public_id]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_logging_in_fails_with_the_wrong_password(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->fromSpa()->postJson('/api/auth/login', ['email' => 'test@example.com', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_signing_in_needs_a_stateful_browser_app(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'test@example.com', 'password' => 'password'])
            ->assertStatus(400);

        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/auth/guest', ['display_name' => 'Sneaky'])
            ->assertStatus(400);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_anyone_can_join_as_a_guest_with_just_a_name(): void
    {
        $this->fromSpa()->postJson('/api/auth/guest', ['display_name' => 'Taylor'])
            ->assertCreated()
            ->assertJson(['display_name' => 'Taylor', 'is_guest' => true, 'email' => null]);

        $guest = User::sole();
        $this->assertTrue($guest->is_guest);
        $this->assertNull($guest->password);
        $this->assertAuthenticatedAs($guest);
    }

    public function test_you_cannot_sign_in_again_while_signed_in(): void
    {
        $this->fromSpa()->postJson('/api/auth/guest', ['display_name' => 'First'])->assertCreated();

        $this->postJson('/api/auth/guest', ['display_name' => 'Second'])->assertStatus(409);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_guest_creation_is_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->fromSpa()->postJson('/api/auth/guest', ['display_name' => "Guest {$i}"])->assertCreated();
            $this->postJson('/api/auth/logout')->assertNoContent();
        }

        $this->postJson('/api/auth/guest', ['display_name' => 'One too many'])->assertTooManyRequests();
    }

    public function test_logging_out_keeps_a_user_but_deletes_a_guest(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->fromSpa()->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $guest = User::factory()->guest()->create();
        $this->actingAs($guest)->fromSpa()->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $guest->id]);
    }

    public function test_signed_out_requests_get_a_json_401_even_without_asking_for_json(): void
    {
        $this->get('/api/friends')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_bearer_token_logout_revokes_only_that_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('script')->plainTextToken;
        $user->createToken('other');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertSame(['other'], $user->tokens()->pluck('name')->all());
    }

    public function test_me_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_user_can_change_their_display_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/auth/me', ['display_name' => 'Night Owl'])
            ->assertOk()
            ->assertJson(['display_name' => 'Night Owl']);

        $this->assertSame('Night Owl', $user->fresh()->display_name);
    }

    public function test_a_user_can_change_their_password_with_the_current_one(): void
    {
        $user = User::factory()->create();
        $change = fn (string $current) => $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => $current,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $change('not-the-password')->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $change('password')->assertNoContent();
        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_guests_have_no_password_to_change(): void
    {
        $this->actingAs(User::factory()->guest()->create())
            ->putJson('/api/auth/password', [
                'current_password' => '',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertForbidden();
    }

    public function test_deleting_a_user_takes_their_tokens_with_them(): void
    {
        $user = User::factory()->create();
        $user->createToken('script');

        $user->delete();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
