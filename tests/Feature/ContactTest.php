<?php

namespace Tests\Feature;

use App\Mail\ContactMessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'admin@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.admin_email' => self::ADMIN]);
        Mail::fake();
    }

    /** @return array<string, string> */
    private function validMessage(): array
    {
        return [
            'email' => 'visitor@example.com',
            'subject' => 'Hello',
            'message' => 'I like your site.',
        ];
    }

    public function test_a_message_is_stored_and_emailed_to_the_admin(): void
    {
        $this->postJson('/api/contact', $this->validMessage())
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('messages', [
            'email' => 'visitor@example.com',
            'subject' => 'Hello',
            'body' => 'I like your site.',
            'read_at' => null,
        ]);

        Mail::assertSent(ContactMessageReceived::class, fn (ContactMessageReceived $mail) => $mail->hasTo(self::ADMIN)
            && $mail->hasReplyTo('visitor@example.com')
            && $mail->hasSubject('Portfolio: Hello'));
    }

    public function test_no_email_is_sent_without_an_admin_address(): void
    {
        config(['app.admin_email' => null]);

        $this->postJson('/api/contact', $this->validMessage())->assertCreated();

        $this->assertDatabaseCount('messages', 1);
        Mail::assertNothingSent();
    }

    public function test_a_message_needs_an_email_subject_and_body(): void
    {
        $this->postJson('/api/contact', ['email' => 'not-an-email', 'subject' => '', 'message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'subject', 'message']);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_the_honeypot_quietly_drops_bots(): void
    {
        $this->postJson('/api/contact', [...$this->validMessage(), 'website' => 'https://spam.example'])
            ->assertCreated();

        $this->assertDatabaseCount('messages', 0);
        Mail::assertNothingSent();
    }

    public function test_senders_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/contact', $this->validMessage())->assertCreated();
        }

        $this->postJson('/api/contact', $this->validMessage())->assertTooManyRequests();

        $this->assertDatabaseCount('messages', 5);
    }

    public function test_the_rate_limit_is_per_cloudflare_client_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('CF-Connecting-IP', '203.0.113.1')
                ->postJson('/api/contact', $this->validMessage())->assertCreated();
        }

        $this->withHeader('CF-Connecting-IP', '203.0.113.1')
            ->postJson('/api/contact', $this->validMessage())->assertTooManyRequests();

        $this->withHeader('CF-Connecting-IP', '203.0.113.2')
            ->postJson('/api/contact', $this->validMessage())->assertCreated();

        $this->assertDatabaseHas('messages', ['ip' => '203.0.113.2']);
    }

    public function test_the_form_gets_no_session(): void
    {
        $this->postJson('/api/contact', $this->validMessage())
            ->assertCreated()
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_the_about_page_has_contact_links_copy_controls_and_a_csrf_form(): void
    {
        $this->get('https://accounts.test/')->assertOk()
            ->assertSee('href="mailto:guardeded@gmail.com"', escape: false)
            ->assertSee('href="tel:+447858501493"', escape: false)
            ->assertSee('aria-label="Copy email address"', escape: false)
            ->assertSee('aria-label="Copy phone number"', escape: false)
            ->assertSee('action="https://accounts.test/contact"', escape: false)
            ->assertSee('name="_token"', escape: false);
    }

    public function test_the_web_contact_form_uses_the_same_storage_and_mail_as_the_api(): void
    {
        $this->postJson('https://accounts.test/contact', $this->validMessage())
            ->assertCreated()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('messages', ['email' => 'visitor@example.com', 'body' => 'I like your site.']);
        Mail::assertSent(ContactMessageReceived::class, fn (ContactMessageReceived $mail) => $mail->hasTo(self::ADMIN)
            && $mail->hasReplyTo('visitor@example.com'));
    }

    public function test_the_web_form_works_without_javascript(): void
    {
        $this->post('https://accounts.test/contact', $this->validMessage())
            ->assertRedirect('/#contact')->assertSessionHas('contact_sent', true);

        $this->assertDatabaseCount('messages', 1);
        Mail::assertSent(ContactMessageReceived::class);
    }

    public function test_web_validation_preserves_the_draft_without_storing_or_sending(): void
    {
        $this->from('https://accounts.test/')
            ->post('https://accounts.test/contact', [...$this->validMessage(), 'email' => 'invalid'])
            ->assertRedirect('https://accounts.test/')
            ->assertSessionHasErrors('email')->assertSessionHasInput('message', 'I like your site.');

        $this->postJson('https://accounts.test/contact', ['email' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'subject', 'message']);
        $this->assertDatabaseCount('messages', 0);
        Mail::assertNothingSent();
    }

    public function test_the_web_honeypot_drops_messages_and_the_rate_limit_is_shared_with_the_api(): void
    {
        $this->postJson('https://accounts.test/contact', [...$this->validMessage(), 'website' => 'spam'])
            ->assertCreated();
        $this->assertDatabaseCount('messages', 0);
        Mail::assertNothingSent();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('http://localhost/api/contact', $this->validMessage())->assertCreated();
        }

        $this->postJson('https://accounts.test/contact', $this->validMessage())->assertTooManyRequests();
        $this->assertDatabaseCount('messages', 4);
    }

    public function test_mail_failure_does_not_lose_the_saved_message(): void
    {
        Mail::shouldReceive('to')->once()->with(self::ADMIN)->andThrow(new \RuntimeException('Mail unavailable'));

        $this->postJson('https://accounts.test/contact', $this->validMessage())->assertCreated();
        $this->assertDatabaseCount('messages', 1);
    }
}
