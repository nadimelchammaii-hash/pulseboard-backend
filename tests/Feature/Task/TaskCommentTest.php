<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function projectForCommentTests(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);
    $project->members()->create(['user_id' => $member->id]);

    $task = $project->tasks()->create([
        'title' => 'Comment target',
        'status' => 'todo',
        'priority' => 'medium',
        'position' => 0,
        'created_by' => $owner->id,
    ]);

    return compact('owner', 'member', 'workspace', 'project', 'task');
}

test('a project member can add and list comments on a task', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project, 'task' => $task] = projectForCommentTests();

    $response = $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments",
        ['body' => 'Looks good to me.']
    );

    $response->assertCreated();
    $response->assertJsonPath('data.body', 'Looks good to me.');
    $response->assertJsonPath('data.user.id', $member->id);

    $this->actingAs($member)
        ->getJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a comment author can delete their own comment', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project, 'task' => $task] = projectForCommentTests();
    $comment = $task->comments()->create(['user_id' => $member->id, 'body' => 'Mine']);

    $this->actingAs($member)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments/{$comment->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
});

test('a comment body over 5000 characters fails validation', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project, 'task' => $task] = projectForCommentTests();

    $this->actingAs($member)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments",
        ['body' => str_repeat('a', 5001)]
    )->assertUnprocessable()->assertJsonValidationErrors('body');
});

test('a workspace owner can moderate-delete someone else\'s comment', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project, 'task' => $task] = projectForCommentTests();
    $comment = $task->comments()->create(['user_id' => $member->id, 'body' => 'Member comment']);

    $this->actingAs($owner)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments/{$comment->id}")
        ->assertNoContent();
});

test('a plain member cannot delete someone else\'s comment', function () {
    ['member' => $member, 'workspace' => $workspace, 'project' => $project, 'task' => $task] = projectForCommentTests();

    $another = User::factory()->create();
    $workspace->members()->create(['user_id' => $another->id, 'role' => WorkspaceRole::Member]);
    $project->members()->create(['user_id' => $another->id]);

    $comment = $task->comments()->create(['user_id' => $another->id, 'body' => 'Not yours']);

    $this->actingAs($member)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments/{$comment->id}")
        ->assertForbidden();
});
