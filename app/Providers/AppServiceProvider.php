<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        Passport::loadKeysFrom(storage_path('app/private/passport'));
        Passport::personalAccessTokensExpireIn(now()->addHours(8));
        Gate::before(fn ($user) => $user->isOwner() ? true : null);
        RateLimiter::for('ams-login', fn (Request $r) => [Limit::perMinute(30)->by('ip:'.$r->ip()), Limit::perMinute(5)->by('account:'.$r->ip().'|'.strtolower((string) $r->input('email')))]);
        RateLimiter::for('ams-api', fn (Request $r) => Limit::perMinute(120)->by((string) ($r->user()?->id ?? $r->ip())));
        RateLimiter::for('ams-face', fn (Request $r) => Limit::perMinute(45)->by((string) ($r->user()?->id ?? $r->ip())));
    }
}
