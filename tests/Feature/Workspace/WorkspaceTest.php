<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('creating a workspace makes the creator its owner', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/workspaces', [
        'name' => 'Acme Engineering',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.name', 'Acme Engineering');
    $response->assertJsonPath('data.role', WorkspaceRole::Owner->value);
    $response->assertJsonPath('data.members_count', 1);

    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $response->json('data.id'),
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner->value,
    ]);
});

test('a user only sees workspaces they belong to', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $ownWorkspace = Workspace::factory()->create();
    $ownWorkspace->members()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Owner]);

    $otherWorkspace = Workspace::factory()->create();
    $otherWorkspace->members()->create(['user_id' => $otherUser->id, 'role' => WorkspaceRole::Owner]);

    $response = $this->actingAs($user)->getJson('/api/v1/workspaces');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $ownWorkspace->id);
});

test('a non-member cannot view a workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => User::factory()->create()->id, 'role' => WorkspaceRole::Owner]);

    $this->actingAs($user)->getJson("/api/v1/workspaces/{$workspace->id}")->assertForbidden();
});

test('a member can view a workspace they belong to', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($user)->getJson("/api/v1/workspaces/{$workspace->id}")
        ->assertOk()
        ->assertJsonPath('data.role', WorkspaceRole::Member->value);
});

test('the owner can update the workspace name', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Owner]);

    $this->actingAs($user)->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

test('an admin can update the workspace name', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Admin]);

    $this->actingAs($user)->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Renamed'])
        ->assertOk();
});

test('a plain member cannot update the workspace name', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($user)->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Renamed'])
        ->assertForbidden();
});

test('only the owner can delete the workspace', function () {
    $admin = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $admin->id, 'role' => WorkspaceRole::Admin]);

    $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$workspace->id}")->assertForbidden();

    $owner = User::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$workspace->id}")->assertNoContent();
    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
});

test('deleting a workspace cascades to its members, projects, and activity log', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $membership = $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);
    $project = $workspace->projects()->create(['name' => 'Doomed', 'slug' => 'doomed']);
    $project->members()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Leaves an activity behind']
    )->assertCreated();

    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$workspace->id}")->assertNoContent();

    $this->assertDatabaseMissing('workspace_members', ['id' => $membership->id]);
    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('activities', ['workspace_id' => $workspace->id]);
});

test('a guest cannot access any workspace endpoint', function () {
    $workspace = Workspace::factory()->create();

    $this->getJson('/api/v1/workspaces')->assertUnauthorized();
    $this->postJson('/api/v1/workspaces', ['name' => 'X'])->assertUnauthorized();
    $this->getJson("/api/v1/workspaces/{$workspace->id}")->assertUnauthorized();
});
