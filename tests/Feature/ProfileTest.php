<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('an authenticated user can view their profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/user');

    $response->assertOk();
    $response->assertJsonPath('data.id', $user->id);
});

test('an authenticated user can update their profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/user/profile', [
        'name' => 'New Name',
        'email' => 'new-email@example.com',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'New Name');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new-email@example.com']);
});

test('profile update rejects an email already taken by another user', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/user/profile', [
        'name' => $user->name,
        'email' => 'taken@example.com',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('email');
});

test('an authenticated user can update their password with the correct current password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertNoContent();
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('password update fails with an incorrect current password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/user/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('current_password');
});

test('a guest cannot access the profile', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});
