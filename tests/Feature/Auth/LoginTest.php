<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('a user can login with correct credentials', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'ada@example.com',
        'password' => 'password',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.id', $user->id);
    $this->assertAuthenticatedAs($user);
});

test('login fails with incorrect password', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('email');
    $this->assertGuest();
});

test('login fails for unknown email', function () {
    $response = $this->postJson('/api/v1/login', [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ]);

    $response->assertUnprocessable();
    $this->assertGuest();
});
