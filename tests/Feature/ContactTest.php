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
}
