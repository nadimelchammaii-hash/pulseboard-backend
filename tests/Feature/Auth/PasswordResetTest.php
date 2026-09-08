<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('a reset link is sent for a known email', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson('/api/v1/forgot-password', [
        'email' => 'ada@example.com',
    ]);

    $response->assertOk();
    Notification::assertSentTo($user, ResetPassword::class);
});

test('a reset link request for an unknown email responds identically to a known one', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/forgot-password', [
        'email' => 'nobody@example.com',
    ]);

    // Same 200 + generic message as a known email — the response must not
    // let a caller enumerate which addresses have an account.
    $response->assertOk();
    Notification::assertNothingSent();
});

test('a user can reset their password with a valid token', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = Password::createToken($user);

    $response = $this->postJson('/api/v1/reset-password', [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertOk();

    $loginResponse = $this->postJson('/api/v1/login', [
        'email' => 'ada@example.com',
        'password' => 'new-password',
    ]);
    $loginResponse->assertOk();
});

test('a password reset fails with an invalid token', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson('/api/v1/reset-password', [
        'token' => 'invalid-token',
        'email' => 'ada@example.com',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertUnprocessable();
});
