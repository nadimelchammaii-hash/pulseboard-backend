<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('repeated failed logins for the same email are throttled after 5 attempts', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    // The 6th attempt within the same minute is throttled before it even
    // touches the password check.
    $response = $this->postJson('/api/v1/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429);

    // The real credentials are locked out too, for the same window — that's
    // the point of throttling by email, not just by "attempts that failed".
    $this->postJson('/api/v1/login', [
        'email' => 'ada@example.com',
        'password' => 'password',
    ])->assertStatus(429);

    $this->assertGuest();
});

test('throttling one email does not block logins for another', function () {
    $locked = User::factory()->create(['email' => 'locked@example.com']);
    User::factory()->create(['email' => 'clear@example.com']);

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/login', [
            'email' => 'locked@example.com',
            'password' => 'wrong-password',
        ]);
    }

    $this->postJson('/api/v1/login', [
        'email' => 'clear@example.com',
        'password' => 'password',
    ])->assertOk();
});
