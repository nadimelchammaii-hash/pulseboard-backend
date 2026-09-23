<?php

use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function workspaceWithOwnerAndMember(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);
    $project->members()->create(['user_id' => $member->id]);

    return compact('owner', 'member', 'workspace', 'project');
}

test('creating a task logs an activity', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithOwnerAndMember();

    $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Design the login screen']
    )->assertCreated();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'causer_id' => $member->id,
        'action' => 'task.created',
    ]);

    $response = $this->actingAs($member)->getJson("/api/v1/workspaces/{$workspace->id}/activities");

    $response->assertOk();
    $response->assertJsonPath('data.0.action', 'task.created');
    $response->assertJsonPath('data.0.causer.id', $member->id);
    $response->assertJsonPath('data.0.data.task_title', 'Design the login screen');
});

test('moving a task to a different status logs an activity with old and new status', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithOwnerAndMember();
    $task = $project->tasks()->create([
        'title' => 'Ship it', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $member->id,
    ]);

    $this->actingAs($member)->patchJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/move",
        ['status' => 'in_progress', 'position' => 0]
    )->assertOk();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'action' => 'task.moved',
    ]);

    $activity = Activity::where('action', 'task.moved')->firstOrFail();
    expect($activity->data['from_status'])->toBe('todo');
    expect($activity->data['to_status'])->toBe('in_progress');
});

test('reordering a task within the same status does not log a move activity', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithOwnerAndMember();
    $project->tasks()->create(['title' => 'A', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $member->id]);
    $task = $project->tasks()->create(['title' => 'B', 'status' => 'todo', 'priority' => 'medium', 'position' => 1, 'created_by' => $member->id]);

    $this->actingAs($member)->patchJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/move",
        ['status' => 'todo', 'position' => 0]
    )->assertOk();

    $this->assertDatabaseMissing('activities', ['action' => 'task.moved']);
});

test('deleting a task logs an activity with the task title preserved', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithOwnerAndMember();
    $task = $project->tasks()->create([
        'title' => 'Doomed task', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $member->id,
    ]);

    $this->actingAs($member)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}")
        ->assertNoContent();

    $activity = Activity::where('action', 'task.deleted')->firstOrFail();
    expect($activity->data['task_title'])->toBe('Doomed task');
});

test('adding a comment logs an activity', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithOwnerAndMember();
    $task = $project->tasks()->create([
        'title' => 'Discuss', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $member->id,
    ]);

    $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments",
        ['body' => 'Looks good to me.']
    )->assertCreated();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'action' => 'task_comment.added',
    ]);
});

test('creating a project logs an activity', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects",
        ['name' => 'New Project']
    )->assertCreated();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'causer_id' => $owner->id,
        'action' => 'project.created',
    ]);
});

test('adding and removing a project member logs activities', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = workspaceWithOwnerAndMember();
    $newcomer = User::factory()->create();
    $workspace->members()->create(['user_id' => $newcomer->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members",
        ['user_id' => $newcomer->id]
    )->assertCreated();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'action' => 'project.member_added',
    ]);

    $projectMember = $project->members()->where('user_id', $newcomer->id)->firstOrFail();

    $this->actingAs($owner)->deleteJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/members/{$projectMember->id}"
    )->assertNoContent();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'action' => 'project.member_removed',
    ]);
});

test('inviting, removing, and changing the role of a workspace member logs activities', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $invitee = User::factory()->create();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/members",
        ['email' => $invitee->email, 'role' => 'member']
    )->assertCreated();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'action' => 'workspace.member_invited',
    ]);

    $member = $workspace->members()->where('user_id', $invitee->id)->firstOrFail();

    $this->actingAs($owner)->patchJson(
        "/api/v1/workspaces/{$workspace->id}/members/{$member->id}",
        ['role' => 'admin']
    )->assertOk();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'action' => 'workspace.member_role_changed',
    ]);

    $this->actingAs($owner)->deleteJson(
        "/api/v1/workspaces/{$workspace->id}/members/{$member->id}"
    )->assertNoContent();

    $this->assertDatabaseHas('activities', [
        'workspace_id' => $workspace->id,
        'action' => 'workspace.member_removed',
    ]);
});

test('a plain workspace member only sees activity for projects they belong to', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $outsider = User::factory()->create();
    $workspace->members()->create(['user_id' => $outsider->id, 'role' => WorkspaceRole::Member]);

    $privateProject = $workspace->projects()->create(['name' => 'Private', 'slug' => 'private']);
    $privateProject->members()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$privateProject->id}/tasks",
        ['title' => 'Secret task']
    )->assertCreated();

    $response = $this->actingAs($outsider)->getJson("/api/v1/workspaces/{$workspace->id}/activities");

    $response->assertOk();
    $response->assertJsonCount(0, 'data');

    $ownerResponse = $this->actingAs($owner)->getJson("/api/v1/workspaces/{$workspace->id}/activities");
    $ownerResponse->assertJsonCount(1, 'data');
});

test('a page beyond the last one returns an empty list, not an error', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithOwnerAndMember();

    $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Only task']
    )->assertCreated();

    $response = $this->actingAs($member)->getJson("/api/v1/workspaces/{$workspace->id}/activities?page=99");

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('a guest cannot access the activity feed', function () {
    $workspace = Workspace::factory()->create();

    $this->getJson("/api/v1/workspaces/{$workspace->id}/activities")->assertUnauthorized();
});

test('a non-member cannot access the activity feed', function () {
    $workspace = Workspace::factory()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->getJson("/api/v1/workspaces/{$workspace->id}/activities")->assertForbidden();
});
