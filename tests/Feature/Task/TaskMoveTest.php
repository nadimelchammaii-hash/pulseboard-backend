<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function projectForMoveTests(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $project = $workspace->projects()->create(['name' => 'Board', 'slug' => 'board']);
    $project->members()->create(['user_id' => $owner->id]);

    return compact('owner', 'workspace', 'project');
}

function createTaskAt(Project $project, User $user, string $status, int $position): Task
{
    return $project->tasks()->create([
        'title' => "Task {$status}-{$position}",
        'status' => $status,
        'priority' => 'medium',
        'position' => $position,
        'created_by' => $user->id,
    ]);
}

test('moving a task to a different status places it there and closes the gap it left behind', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = projectForMoveTests();

    $todoA = createTaskAt($project, $owner, 'todo', 0);
    $todoB = createTaskAt($project, $owner, 'todo', 1);

    $response = $this->actingAs($owner)->patchJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$todoA->id}/move",
        ['status' => 'in_progress', 'position' => 0]
    );

    $response->assertOk();
    $response->assertJsonPath('data.status', 'in_progress');
    $response->assertJsonPath('data.position', 0);

    expect($todoB->fresh()->position)->toBe(0);
});

test('reordering within the same status places the task at the requested position', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = projectForMoveTests();

    $first = createTaskAt($project, $owner, 'todo', 0);
    $second = createTaskAt($project, $owner, 'todo', 1);
    $third = createTaskAt($project, $owner, 'todo', 2);

    $this->actingAs($owner)->patchJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$third->id}/move",
        ['status' => 'todo', 'position' => 0]
    )->assertOk();

    expect($third->fresh()->position)->toBe(0);
    expect($first->fresh()->position)->toBe(1);
    expect($second->fresh()->position)->toBe(2);
});

test('move validates the status is a real task status', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = projectForMoveTests();
    $task = createTaskAt($project, $owner, 'todo', 0);

    $this->actingAs($owner)->patchJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/move",
        ['status' => 'archived', 'position' => 0]
    )->assertUnprocessable()->assertJsonValidationErrors('status');
});
