<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function projectWithOwnerAndMember(): array
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

test('a project member can create a task with default status and priority', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = projectWithOwnerAndMember();

    $response = $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Design the login screen']
    );

    $response->assertCreated();
    $response->assertJsonPath('data.title', 'Design the login screen');
    $response->assertJsonPath('data.status', 'todo');
    $response->assertJsonPath('data.priority', 'medium');
    $response->assertJsonPath('data.position', 0);
    $response->assertJsonPath('data.creator.id', $member->id);
});

test('a project member can create a task with an explicit status, priority, and assignee', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = projectWithOwnerAndMember();

    $response = $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        [
            'title' => 'Ship the release',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'assignee_id' => $member->id,
        ]
    );

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'in_progress');
    $response->assertJsonPath('data.priority', 'urgent');
    $response->assertJsonPath('data.assignee.id', $member->id);
});

test('a task description over 10000 characters fails validation', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = projectWithOwnerAndMember();

    $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Too much detail', 'description' => str_repeat('a', 10001)]
    )->assertUnprocessable()->assertJsonValidationErrors('description');
});

test('assigning a task to someone who is not a project member fails validation', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = projectWithOwnerAndMember();
    $outsider = User::factory()->create();

    $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Broken assignment', 'assignee_id' => $outsider->id]
    )->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
});

test('a plain workspace member who is not a project member cannot create a task', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $outsider = User::factory()->create();
    $workspace->members()->create(['user_id' => $outsider->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Private', 'slug' => 'private']);
    $project->members()->create(['user_id' => $owner->id]);

    $this->actingAs($outsider)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Not allowed']
    )->assertForbidden();
});

test('a workspace admin can create a task without being an explicit project member', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $admin = User::factory()->create();
    $workspace->members()->create(['user_id' => $admin->id, 'role' => WorkspaceRole::Admin]);

    $project = $workspace->projects()->create(['name' => 'Owner Only', 'slug' => 'owner-only']);
    $project->members()->create(['user_id' => $owner->id]);

    $this->actingAs($admin)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Admin override']
    )->assertCreated();
});

test('a project member can list and view tasks', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = projectWithOwnerAndMember();
    $task = $project->tasks()->create([
        'title' => 'Existing task',
        'status' => 'todo',
        'priority' => 'medium',
        'position' => 0,
        'created_by' => $member->id,
    ]);

    $this->actingAs($member)
        ->getJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($member)
        ->getJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id);
});

test('a project member can update a task', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = projectWithOwnerAndMember();
    $task = $project->tasks()->create([
        'title' => 'Original',
        'status' => 'todo',
        'priority' => 'low',
        'position' => 0,
        'created_by' => $member->id,
    ]);

    $response = $this->actingAs($member)->putJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}",
        ['title' => 'Updated', 'priority' => 'high', 'assignee_id' => $member->id]
    );

    $response->assertOk();
    $response->assertJsonPath('data.title', 'Updated');
    $response->assertJsonPath('data.priority', 'high');
    $response->assertJsonPath('data.assignee.id', $member->id);
});

test('a project member can delete a task', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project] = projectWithOwnerAndMember();
    $task = $project->tasks()->create([
        'title' => 'To delete',
        'status' => 'todo',
        'priority' => 'medium',
        'position' => 0,
        'created_by' => $member->id,
    ]);

    $this->actingAs($member)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
});

test('a guest cannot access any task endpoint', function () {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->for($workspace)->create();

    $this->getJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks")->assertUnauthorized();
    $this->postJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks", ['title' => 'X'])->assertUnauthorized();
});
