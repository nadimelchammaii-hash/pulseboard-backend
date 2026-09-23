<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Every nested controller (Project/Task/TaskComment/WorkspaceMember/ProjectMember)
// re-validates that each route-bound model actually belongs to its stated parent,
// via an explicit abort_unless() rather than trusting Laravel's implicit route model
// binding alone. These tests exist to prove that scoping actually holds — otherwise
// a user who legitimately belongs to Workspace A could reach Workspace B's data
// simply by swapping an ID in the URL, since each {param} resolves independently.
//
// Every "actor" here has real, legitimate access to Tree A, isolating each test to
// the ID-mismatch check itself rather than re-testing ordinary authorization (that's
// covered elsewhere per-feature).

function crossTenancyTrees(): array
{
    $actor = User::factory()->create();

    $workspaceA = Workspace::factory()->create();
    $workspaceA->members()->create(['user_id' => $actor->id, 'role' => WorkspaceRole::Owner]);
    $projectA = $workspaceA->projects()->create(['name' => 'Tree A', 'slug' => 'tree-a']);
    $projectA->members()->create(['user_id' => $actor->id]);
    $taskA = $projectA->tasks()->create([
        'title' => 'Task A', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $actor->id,
    ]);
    $commentA = $taskA->comments()->create(['user_id' => $actor->id, 'body' => 'Comment A']);
    $memberA = $workspaceA->members()->firstWhere('user_id', $actor->id);
    $projectMemberA = $projectA->members()->firstWhere('user_id', $actor->id);

    $otherOwner = User::factory()->create();
    $workspaceB = Workspace::factory()->create();
    $workspaceB->members()->create(['user_id' => $otherOwner->id, 'role' => WorkspaceRole::Owner]);
    $projectB = $workspaceB->projects()->create(['name' => 'Tree B', 'slug' => 'tree-b']);
    $projectB->members()->create(['user_id' => $otherOwner->id]);
    $taskB = $projectB->tasks()->create([
        'title' => 'Task B', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $otherOwner->id,
    ]);
    $commentB = $taskB->comments()->create(['user_id' => $otherOwner->id, 'body' => 'Comment B']);
    $memberB = $workspaceB->members()->firstWhere('user_id', $otherOwner->id);
    $projectMemberB = $projectB->members()->firstWhere('user_id', $otherOwner->id);

    return compact(
        'actor', 'workspaceA', 'projectA', 'taskA', 'commentA', 'memberA', 'projectMemberA',
        'workspaceB', 'projectB', 'taskB', 'commentB', 'memberB', 'projectMemberB',
    );
}

test('a project from another workspace 404s under this workspace', function () {
    ['actor' => $actor, 'workspaceA' => $workspaceA, 'projectB' => $projectB] = crossTenancyTrees();

    $this->actingAs($actor)->getJson("/api/v1/workspaces/{$workspaceA->id}/projects/{$projectB->id}")->assertNotFound();
    $this->actingAs($actor)->patchJson("/api/v1/workspaces/{$workspaceA->id}/projects/{$projectB->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->actingAs($actor)->deleteJson("/api/v1/workspaces/{$workspaceA->id}/projects/{$projectB->id}")->assertNotFound();
});

test('a task from another project 404s under this project', function () {
    ['actor' => $actor, 'workspaceA' => $workspaceA, 'projectA' => $projectA, 'taskB' => $taskB] = crossTenancyTrees();
    $base = "/api/v1/workspaces/{$workspaceA->id}/projects/{$projectA->id}/tasks/{$taskB->id}";

    $this->actingAs($actor)->getJson($base)->assertNotFound();
    $this->actingAs($actor)->patchJson($base, ['title' => 'Hijacked'])->assertNotFound();
    $this->actingAs($actor)->patchJson("{$base}/move", ['status' => 'done', 'position' => 0])->assertNotFound();
    $this->actingAs($actor)->deleteJson($base)->assertNotFound();
    $this->actingAs($actor)->getJson("{$base}/comments")->assertNotFound();
    $this->actingAs($actor)->postJson("{$base}/comments", ['body' => 'Hijacked'])->assertNotFound();
});

test('a task under a project from another workspace 404s', function () {
    ['actor' => $actor, 'workspaceA' => $workspaceA, 'projectB' => $projectB, 'taskB' => $taskB] = crossTenancyTrees();

    $this->actingAs($actor)
        ->getJson("/api/v1/workspaces/{$workspaceA->id}/projects/{$projectB->id}/tasks/{$taskB->id}")
        ->assertNotFound();
});

test('a comment from another task 404s under this task', function () {
    ['actor' => $actor, 'workspaceA' => $workspaceA, 'projectA' => $projectA, 'taskA' => $taskA, 'commentB' => $commentB] = crossTenancyTrees();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/workspaces/{$workspaceA->id}/projects/{$projectA->id}/tasks/{$taskA->id}/comments/{$commentB->id}")
        ->assertNotFound();
});

test('a workspace member from another workspace 404s under this workspace', function () {
    ['actor' => $actor, 'workspaceA' => $workspaceA, 'memberB' => $memberB] = crossTenancyTrees();

    $this->actingAs($actor)
        ->patchJson("/api/v1/workspaces/{$workspaceA->id}/members/{$memberB->id}", ['role' => 'admin'])
        ->assertNotFound();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/workspaces/{$workspaceA->id}/members/{$memberB->id}")
        ->assertNotFound();
});

test('a project member from another project 404s under this project', function () {
    ['actor' => $actor, 'workspaceA' => $workspaceA, 'projectA' => $projectA, 'projectMemberB' => $projectMemberB] = crossTenancyTrees();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/workspaces/{$workspaceA->id}/projects/{$projectA->id}/members/{$projectMemberB->id}")
        ->assertNotFound();
});
