<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cacheTestWorkspace(User $owner): Workspace
{
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    return $workspace;
}

test('the member list is served from cache once populated', function () {
    $owner = User::factory()->create();
    $workspace = cacheTestWorkspace($owner);

    $this->actingAs($owner)
        ->getJson("/api/v1/workspaces/{$workspace->id}/members")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Bypass the controller entirely to add a member "behind the cache's back" —
    // if the endpoint were hitting the database every time, this would already
    // show up. It won't, because the first request above cached the result.
    $workspace->members()->create(['user_id' => User::factory()->create()->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($owner)
        ->getJson("/api/v1/workspaces/{$workspace->id}/members")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('inviting a member invalidates the cached member list', function () {
    $owner = User::factory()->create();
    $workspace = cacheTestWorkspace($owner);
    $invitee = User::factory()->create();

    $this->actingAs($owner)
        ->getJson("/api/v1/workspaces/{$workspace->id}/members")
        ->assertJsonCount(1, 'data');

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => $invitee->email,
        'role' => WorkspaceRole::Member->value,
    ])->assertCreated();

    $this->actingAs($owner)
        ->getJson("/api/v1/workspaces/{$workspace->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('removing a member invalidates the cached member list', function () {
    $owner = User::factory()->create();
    $workspace = cacheTestWorkspace($owner);
    $member = User::factory()->create();
    $membership = $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($owner)
        ->getJson("/api/v1/workspaces/{$workspace->id}/members")
        ->assertJsonCount(2, 'data');

    $this->actingAs($owner)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$membership->id}")
        ->assertNoContent();

    $this->actingAs($owner)
        ->getJson("/api/v1/workspaces/{$workspace->id}/members")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
