<?php

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        RateLimiter::for('ping', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: ClientIp::of($request)));
    }
}
