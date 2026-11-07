<?php

namespace App\Providers;

use App\Jobs\SyncPresence;
use App\Support\ClientIp;
use App\Support\Presence;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Events\ChannelRemoved;
use Laravel\Reverb\Jobs\PingInactiveConnections;
use Laravel\Reverb\Jobs\PruneStaleConnections;
use React\EventLoop\Loop;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(5)->by(ClientIp::of($request)));

        // Logging in and registering: enough for typos, not for guessing passwords.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by(ClientIp::of($request)));

        // Anyone can become a guest without a password, so cap how fast one address mints them.
        RateLimiter::for('guest', fn (Request $request) => [
            Limit::perMinute(3)->by(ClientIp::of($request)),
            Limit::perDay(30)->by(ClientIp::of($request)),
        ]);

        // Sending friend requests or opening DMs by ID. IDs are too many to guess, this just slows a spammer.
        RateLimiter::for('find-users', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: ClientIp::of($request)));

        RateLimiter::for('groups', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: ClientIp::of($request)));

        // About one message a second, with room for a quick burst.
        RateLimiter::for('messages', fn (Request $request) => Limit::perSecond(10, 10)->by($request->user()?->id ?: ClientIp::of($request)));

        RateLimiter::for('ping', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: ClientIp::of($request)));

        // A typing signal every few seconds, in more than one conversation at a time.
        RateLimiter::for('typing', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: ClientIp::of($request)));

        RateLimiter::for('presence', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: ClientIp::of($request)));

        $this->trackConnections();
    }

    /**
     * These events fire inside the Reverb server: a user's private channel appears with their
     * first tab and goes with their last. The queue worker does the rest, since Reverb can't
     * broadcast to itself from inside its own event loop. Going offline waits a few seconds,
     * so a page reload doesn't flash it to everyone.
     */
    private function trackConnections(): void
    {
        $sync = function (string $channel, int $delay) {
            if (Str::startsWith($channel, Presence::CHANNEL_PREFIX)) {
                rescue(fn () => SyncPresence::dispatch(Str::after($channel, Presence::CHANNEL_PREFIX))->delay($delay));
            }
        };

        Event::listen(ChannelCreated::class, fn (ChannelCreated $event) => $sync($event->channel->name(), 0));
        Event::listen(ChannelRemoved::class, fn (ChannelRemoved $event) => $sync($event->channel->name(), 5));

        // Reverb looks for dead connections (a laptop closed, Wi-Fi gone) once a minute and
        // drops them a minute after that. Looking every 10 s, with REVERB_APP_PING_INTERVAL=20,
        // shows them offline within about 45 s.
        if ($this->app->runningConsoleCommand('reverb:start')) {
            Loop::addPeriodicTimer(10, function () {
                PruneStaleConnections::dispatch();
                PingInactiveConnections::dispatch();
            });
        }
    }
}
