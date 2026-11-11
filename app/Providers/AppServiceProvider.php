<?php

namespace App\Providers;

use App\Services\TenantContextService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
        $this->app->singleton(TenantContextService::class, function () {
            return new TenantContextService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    /**
     * Configure rate limiters for the API endpoints.
     */
    protected function configureRateLimiting(): void
    {
        // General API Rate Limiter: 120/min authenticated, 60/min unauthenticated
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by((string) $request->user()->id)
                : Limit::perMinute(60)->by($request->ip());
        });

        // Public API Rate Limiter: 60 requests/minute
        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Auth Login: 5 attempts/minute per email & IP
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // Auth Registration: 5 requests/minute per IP
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Forgot Password: 3 requests/minute per email & IP
        RateLimiter::for('forgot-password', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinute(3)->by($email.'|'.$request->ip());
        });

        // Password Reset: 5 requests/minute per email & IP
        RateLimiter::for('password-reset', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // Email Verification: 5 requests/minute per user or IP
        RateLimiter::for('email-verification', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ? (string) $request->user()->id : $request->ip());
        });

        // Resource Creation: 30 requests/minute authenticated, 10 requests/minute unauthenticated
        RateLimiter::for('resource-creation', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(30)->by((string) $request->user()->id)
                : Limit::perMinute(10)->by($request->ip());
        });

        // Sensitive Operations: 10 requests/minute authenticated, 5 requests/minute unauthenticated
        RateLimiter::for('sensitive', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(10)->by((string) $request->user()->id)
                : Limit::perMinute(5)->by($request->ip());
        });
    }
}
