<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
class AppServiceProvider extends ServiceProvider {
 public function register(): void {}
 public function boot(): void {
  RateLimiter::for('ams-login', fn(Request $r) => [Limit::perMinute(30)->by('ip:'.$r->ip()), Limit::perMinute(5)->by('account:'.$r->ip().'|'.strtolower((string)$r->input('email')))]);
  RateLimiter::for('ams-api', fn(Request $r) => Limit::perMinute(120)->by(hash('sha256', $r->bearerToken() ?: $r->ip())));
  RateLimiter::for('ams-face', fn(Request $r) => Limit::perMinute(10)->by(hash('sha256', $r->bearerToken() ?: $r->ip())));
 }
}
