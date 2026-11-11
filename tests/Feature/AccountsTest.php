<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The sign-in site. phpunit.xml puts it on https://accounts.test, with Chat and Music on chat.test and music.test. */
class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://accounts.test';

    private const BACK_TO_CHAT = 'https://chat.test/dm/abc?x=1#latest';

    /** @return array<string, string> */
    private function registration(array $overrides = []): array
    {
        return [
            'display_name' => 'Taylor',
            'email' => 'taylor@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            'terms' => '1',
            ...$overrides,
        ];
    }

    public function test_the_sign_in_page_carries_the_return_address_through_every_tab(): void
    {
        $response = $this->get(self::SITE.'/login?return='.urlencode(self::BACK_TO_CHAT))
            ->assertOk()
            ->assertSee('Log in')
            ->assertSee('name="return" value="'.e(self::BACK_TO_CHAT).'"', escape: false);

        foreach (['/login', '/register', '/guest'] as $tab) {
            $response->assertSee('href="'.$tab.'?return='.e(urlencode(self::BACK_TO_CHAT)).'"', escape: false);
        }

        $this->get(self::SITE.'/register')->assertOk()->assertSee('Create account')->assertDontSee('name="return"', escape: false);
        $this->get(self::SITE.'/guest')->assertOk()->assertSee('Join');
    }

    public function test_a_user_logs_in_and_goes_back_to_the_app_they_came_from(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $this->get(self::SITE.'/login');
        $before = session()->getId();

        $this->post(self::SITE.'/login', ['email' => 'test@example.com', 'password' => 'password', 'return' => self::BACK_TO_CHAT])
            ->assertRedirect(self::BACK_TO_CHAT);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'A planted session ID must not survive signing in.');
    }

    public function test_a_wrong_password_goes_back_to_the_form_with_an_error(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->from(self::SITE.'/login')
            ->post(self::SITE.'/login', ['email' => 'test@example.com', 'password' => 'wrong'])
            ->assertRedirect(self::SITE.'/login')
            ->assertSessionHasErrors('email')
            ->assertSessionHasInput('email', 'test@example.com')
            ->assertSessionMissing('_old_input.password');

        $this->assertGuest();
    }

    /** @return array<string, array{string}> */
    public static function unsafeReturns(): array
    {
        return [
            'another site' => ['https://evil.example/'],
            'plain http' => ['http://chat.test/'],
            'a lookalike host' => ['https://chat.test.evil.example/'],
            'userinfo' => ['https://chat.test@evil.example/'],
            'another port' => ['https://chat.test:8443/'],
            'protocol relative' => ['//evil.example/'],
            'a backslash' => ['https://chat.test\\@evil.example/'],
            'a header break' => ["https://chat.test/\r\nLocation: https://evil.example/"],
            'javascript' => ['javascript:alert(1)'],
            'the sign-in site itself' => [self::SITE.'/'],
            'a relative path' => ['/somewhere'],
        ];
    }

    #[DataProvider('unsafeReturns')]
    public function test_only_the_apps_own_origins_are_returned_to(string $return): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->get(self::SITE.'/login?return='.urlencode($return))->assertDontSee('name="return"', escape: false);

        $this->post(self::SITE.'/login', ['email' => 'test@example.com', 'password' => 'password', 'return' => $return])
            ->assertRedirect(self::SITE);
    }

    public function test_every_app_is_a_valid_place_to_return_to(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->post(self::SITE.'/login', ['email' => 'test@example.com', 'password' => 'password', 'return' => 'https://music.test'])
            ->assertRedirect('https://music.test');
    }

    public function test_a_user_can_register_and_is_signed_in(): void
    {
        $this->post(self::SITE.'/register', $this->registration(['return' => self::BACK_TO_CHAT]))
            ->assertRedirect(self::BACK_TO_CHAT);

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_guest);
        $this->assertSame('taylor@example.com', $user->email);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{8}$/', $user->public_id);
    }

    public function test_registering_needs_the_terms_ticked_a_unique_email_and_a_sensible_name(): void
    {
        User::factory()->create(['email' => 'taylor@example.com']);

        $this->post(self::SITE.'/register', $this->registration(['terms' => null]))->assertSessionHasErrors('terms');
        $this->post(self::SITE.'/register', $this->registration())->assertSessionHasErrors('email');

        foreach (['', '   ', "evil\u{202E}name", str_repeat('a', 33)] as $name) {
            $this->post(self::SITE.'/register', $this->registration(['email' => 'new@example.com', 'display_name' => $name]))
                ->assertSessionHasErrors('display_name');
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_display_names_do_not_have_to_be_unique(): void
    {
        User::factory()->create(['display_name' => 'Taylor']);

        $this->post(self::SITE.'/register', $this->registration())->assertRedirect(self::SITE);

        $this->assertSame(2, User::where('display_name', 'Taylor')->count());
    }

    public function test_anyone_can_join_as_a_guest_with_just_a_name_and_the_terms(): void
    {
        $this->post(self::SITE.'/guest', ['display_name' => 'Taylor'])->assertSessionHasErrors('terms');
        $this->assertDatabaseCount('users', 0);

        $this->post(self::SITE.'/guest', ['display_name' => 'Taylor', 'terms' => '1', 'return' => self::BACK_TO_CHAT])
            ->assertRedirect(self::BACK_TO_CHAT);

        $guest = User::sole();
        $this->assertTrue($guest->is_guest);
        $this->assertNull($guest->password);
        $this->assertAuthenticatedAs($guest);
    }

    public function test_once_signed_in_the_forms_send_you_straight_on(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(self::SITE.'/login?return='.urlencode(self::BACK_TO_CHAT))->assertRedirect(self::BACK_TO_CHAT);
        $this->get(self::SITE.'/guest')->assertRedirect(self::SITE);

        // Switching accounts goes through logout, so a guest is never left orphaned behind a new one.
        $this->post(self::SITE.'/guest', ['display_name' => 'Second', 'terms' => '1'])->assertRedirect(self::SITE);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_the_launcher_lists_the_apps_once_signed_in(): void
    {
        $this->get(self::SITE.'/')->assertRedirect('/login');

        $this->actingAs(User::factory()->create(['display_name' => 'Taylor']))
            ->get(self::SITE.'/')
            ->assertOk()
            ->assertSee('Hi, Taylor')
            ->assertSee('href="https://chat.test"', escape: false)
            ->assertSee('href="https://music.test"', escape: false)
            ->assertSee('Log out');
    }

    public function test_the_launcher_warns_a_guest_that_logging_out_deletes_them(): void
    {
        $this->actingAs(User::factory()->guest()->create())
            ->get(self::SITE.'/')
            ->assertSee('Log out and delete guest account');
    }

    public function test_logging_out_keeps_a_user_but_deletes_a_guest(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(self::SITE.'/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $guest = User::factory()->guest()->create();
        $this->actingAs($guest)->post(self::SITE.'/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $guest->id]);
    }

    public function test_the_terms_are_readable_signed_out(): void
    {
        $this->get(self::SITE.'/terms')->assertOk()->assertSee('18 or over');
    }

    public function test_the_api_does_not_answer_on_the_sign_in_site(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson(self::SITE.'/api/auth/me')->assertNotFound();
        $this->get(self::SITE.'/sanctum/csrf-cookie')->assertNotFound();
        $this->postJson(self::SITE.'/api/broadcasting/auth')->assertNotFound();
        $this->get(self::SITE.'/api/music/tracks/1/stream')->assertNotFound();
        $this->postJson(self::SITE.'/api/contact')->assertNotFound();

        // Still there through the apps.
        $this->getJson('http://localhost/api/auth/me')->assertOk();
    }

    public function test_the_sign_in_pages_only_exist_on_the_sign_in_site(): void
    {
        $this->get('https://chat.test/login')->assertNotFound();
        $this->get('http://localhost/login')->assertNotFound();
        $this->getJson('http://localhost/')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_the_pages_send_strict_security_headers(): void
    {
        $response = $this->get(self::SITE.'/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'self' https://chat.test https://music.test;", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_session_cookie_is_shared_with_the_apps_through_the_parent_domain(): void
    {
        config(['session.domain' => 'example.test', 'session.cookie' => 'hms_sso', 'session.secure' => true]);

        $cookie = collect($this->get(self::SITE.'/login')->headers->getCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === 'hms_sso');

        $this->assertSame('example.test', $cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_too_many_attempts_go_back_to_the_form_with_a_note(): void
    {
        User::factory()->create(['email' => 'test@example.com']);
        $attempt = fn () => $this->from(self::SITE.'/login')->withHeader('CF-Connecting-IP', '203.0.113.9')
            ->post(self::SITE.'/login', ['email' => 'test@example.com', 'password' => 'wrong']);

        for ($i = 0; $i < 10; $i++) {
            $attempt()->assertSessionHasErrors('email');
        }

        $attempt()->assertRedirect(self::SITE.'/login')->assertSessionHasErrors(['form' => 'Too many attempts. Wait a minute and try again.']);
    }

    public function test_guest_creation_is_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post(self::SITE.'/guest', ['display_name' => "Guest {$i}", 'terms' => '1'])->assertRedirect(self::SITE);
            $this->post(self::SITE.'/logout');
        }

        $this->post(self::SITE.'/guest', ['display_name' => 'One too many', 'terms' => '1'])->assertSessionHasErrors('form');
        $this->assertDatabaseCount('users', 0);
    }
}
