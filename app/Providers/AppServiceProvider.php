<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registered here, not from a routes file: production runs `route:cache`, after
        // which routes/*.php are never executed and the callbacks would vanish (every
        // private channel then answers 403). Providers boot on every request.
        require base_path('routes/channels.php');

        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $query = http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

            return rtrim(config('app.frontend_url'), '/')."/reset-password?{$query}";
        });

        // A generous baseline across the whole API, keyed by user when authenticated
        // and by IP otherwise, so one guest can't starve everyone else on the network.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Brute-force protection on login specifically: keyed by the attempted email
        // *and* IP together, so one bad actor can't lock a real user out by spamming
        // their email from elsewhere, and a shared IP can't lock out everyone on it.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        // "Try the demo": a few new demos per visitor per hour, and a cap for the whole
        // site per day, so scripted clicks cannot fill the database. Both answer with a
        // message written for the visitor, which the login page shows as is.
        RateLimiter::for('demo', function (Request $request) {
            return [
                Limit::perHour((int) config('demo.starts_per_hour'))->by('ip:'.$request->ip())
                    ->response(fn (Request $request, array $headers) => response()->json([
                        'message' => 'Several demos were started from your network recently. Please try again in an hour.',
                    ], 429, $headers)),
                Limit::perDay((int) config('demo.starts_per_day'))->by('site')
                    ->response(fn (Request $request, array $headers) => response()->json([
                        'message' => 'The demo is very busy today. Please try again tomorrow.',
                    ], 503, $headers)),
            ];
        });
    }
}
