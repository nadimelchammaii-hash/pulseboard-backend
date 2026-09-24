<?php

use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Deliberately does not use RefreshDatabase: TRUSTED_PROXIES is read while the app
// boots, so each test reboots the application, and a reboot gives a brand-new
// in-memory sqlite database. The tests migrate it themselves after rebooting.
uses(TestCase::class);

afterEach(function () {
    putenv('TRUSTED_PROXIES');
    TrustProxies::flushState();
});

// In production every request arrives from the Caddy/nginx proxy, so REMOTE_ADDR is
// always the proxy's address and the real visitor's IP only exists in the
// X-Forwarded-For header. These tests pin down that the login throttle keys on the
// real visitor when the proxy is trusted, and does NOT trust the header otherwise.

function bootWithTrustedProxies(?string $value): void
{
    $value === null ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$value}");

    test()->refreshApplication();
    test()->artisan('migrate');
    User::factory()->create(['email' => 'ada@example.com']);
}

function failedLoginFrom(string $forwardedFor): TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', $forwardedFor)
        ->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
}

test('behind a trusted proxy, each visitor gets their own login throttle bucket', function () {
    bootWithTrustedProxies('*');

    for ($i = 0; $i < 5; $i++) {
        failedLoginFrom('203.0.113.7')->assertUnprocessable();
    }

    failedLoginFrom('203.0.113.7')->assertStatus(429);

    // A different visitor behind the very same proxy is unaffected.
    failedLoginFrom('198.51.100.9')->assertUnprocessable();
});

test('without trusted proxies, a client-supplied X-Forwarded-For cannot dodge the throttle', function () {
    bootWithTrustedProxies(null);

    for ($i = 0; $i < 5; $i++) {
        failedLoginFrom("203.0.113.{$i}")->assertUnprocessable();
    }

    // Rotating the forwarded IP each time changed nothing: the header is ignored,
    // so all six attempts counted against the real address.
    failedLoginFrom('198.51.100.9')->assertStatus(429);
});
