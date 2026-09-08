<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('an authenticated user can logout', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $this->postJson('/api/v1/logout')->assertNoContent();

    // Asserted against the 'web' guard explicitly: authenticating through
    // auth:sanctum makes Laravel's default guard resolve to 'sanctum' for
    // the rest of the test process (Auth::shouldUse()), and Sanctum's guard
    // caches its resolved user independently of 'web' — an artifact of tests
    // reusing one process across requests, not something that happens in
    // separate production requests. 'web' is the guard logout() actually acts on.
    $this->assertGuest('web');
});

test('a guest cannot logout', function () {
    $response = $this->postJson('/api/v1/logout');

    $response->assertUnauthorized();
});
