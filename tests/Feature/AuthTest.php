<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_is_not_part_of_the_api(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        foreach (['/api/auth/login', '/api/auth/register', '/api/auth/guest'] as $path) {
            $this->fromSpa()->postJson($path, ['email' => 'test@example.com', 'password' => 'password', 'display_name' => 'Taylor'])
                ->assertNotFound();
        }

        $this->assertGuest();
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
