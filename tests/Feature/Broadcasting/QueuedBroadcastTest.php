<?php

use App\Enums\WorkspaceRole;
use App\Events\TaskCreated;
use App\Events\WorkspaceMemberInvited;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Phase 8 moved broadcasting off ShouldBroadcastNow (a Phase 7 workaround for not
// having a queue worker yet) onto real queued ShouldBroadcast, now that Redis +
// a worker exist. These tests confirm broadcasts actually go through the queue
// instead of firing inline, which a plain assertOk() response could never catch.

function queueTestWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);

    return compact('owner', 'workspace', 'project');
}

test('creating a task queues a broadcast job instead of broadcasting inline', function () {
    Queue::fake();
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = queueTestWorkspace();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Queued broadcast test task']
    )->assertCreated();

    Queue::assertPushed(BroadcastEvent::class, fn ($job) => $job->event instanceof TaskCreated);
});

test('inviting a workspace member queues a broadcast job instead of broadcasting inline', function () {
    Queue::fake();
    ['owner' => $owner, 'workspace' => $workspace] = queueTestWorkspace();
    $invitee = User::factory()->create();

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => $invitee->email,
        'role' => WorkspaceRole::Member->value,
    ])->assertCreated();

    Queue::assertPushed(BroadcastEvent::class, fn ($job) => $job->event instanceof WorkspaceMemberInvited);
});

test('assigning a task queues a notification broadcast job instead of broadcasting inline', function () {
    Queue::fake();
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = queueTestWorkspace();
    $assignee = User::factory()->create();
    $workspace->members()->create(['user_id' => $assignee->id, 'role' => WorkspaceRole::Member]);
    $project->members()->create(['user_id' => $assignee->id]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Assigned queue test task', 'assignee_id' => $assignee->id]
    )->assertCreated();

    Queue::assertPushed(BroadcastEvent::class, fn ($job) => $job->event instanceof BroadcastNotificationCreated);
});
