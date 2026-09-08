<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('a user can register and is authenticated', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.email', 'ada@example.com');

    $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    $this->assertAuthenticated();
});

test('registration requires matching password confirmation', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'not-password',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('password');
});

test('registration requires a unique email', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson('/api/v1/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('email');
});
