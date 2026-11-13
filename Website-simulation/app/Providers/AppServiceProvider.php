<?php

namespace App\Providers;

use App\Contracts\SpamDetector;
use App\Services\NullSpamDetector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap NullSpamDetector for the AI-backed implementation when it exists.
        $this->app->singleton(SpamDetector::class, NullSpamDetector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named limiters get a separate counter each. (The "throttle:30,1" shorthand shares
        // one counter per user across every route that uses it, so busy endpoints would
        // starve the strict ones such as group creation.)
        $perUser = fn (int $max) => fn (Request $r) => Limit::perMinute($max)->by($r->user()?->id ?: $r->ip());

        RateLimiter::for('auth-login', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('auth-register', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('chat-token', $perUser(30));
        RateLimiter::for('chat-search', $perUser(30));
        RateLimiter::for('chat-friend-request', $perUser(30));
        RateLimiter::for('chat-send', $perUser(60));
        RateLimiter::for('chat-group', $perUser(10));
    }
}
