<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('a workspace owner can add an existing workspace member to a project', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $colleague = User::factory()->create();
    $workspace->members()->create(['user_id' => $colleague->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);

    $response = $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members",
        ['user_id' => $colleague->id]
    );

    $response->assertCreated();
    $response->assertJsonPath('data.user.id', $colleague->id);
});

test('adding someone who is not a workspace member fails validation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);

    $outsider = User::factory()->create();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members",
        ['user_id' => $outsider->id]
    )->assertUnprocessable()->assertJsonValidationErrors('user_id');
});

test('adding someone already on the project fails validation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $colleague = User::factory()->create();
    $workspace->members()->create(['user_id' => $colleague->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);
    $project->members()->create(['user_id' => $colleague->id]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members",
        ['user_id' => $colleague->id]
    )->assertUnprocessable()->assertJsonValidationErrors('user_id');
});

test('a plain project member cannot add or remove project members', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $another = User::factory()->create();
    $workspace->members()->create(['user_id' => $another->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);
    $memberMembership = $project->members()->create(['user_id' => $member->id]);

    $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members",
        ['user_id' => $another->id]
    )->assertForbidden();

    $this->actingAs($member)->deleteJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members/{$memberMembership->id}"
    )->assertForbidden();
});

test('a workspace admin can remove a project member', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $admin = User::factory()->create();
    $workspace->members()->create(['user_id' => $admin->id, 'role' => WorkspaceRole::Admin]);

    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);
    $memberMembership = $project->members()->create(['user_id' => $member->id]);

    $this->actingAs($admin)->deleteJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members/{$memberMembership->id}"
    )->assertNoContent();

    $this->assertDatabaseMissing('project_members', ['id' => $memberMembership->id]);
});
