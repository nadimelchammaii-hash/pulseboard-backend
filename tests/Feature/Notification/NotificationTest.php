<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function workspaceWithTwoMembers(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $member = User::factory()->create(['name' => 'Jamie Colleague']);
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);
    $project->members()->create(['user_id' => $member->id]);

    return compact('owner', 'member', 'workspace', 'project');
}

test('assigning a task to someone else notifies them', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Ship the release', 'assignee_id' => $member->id]
    )->assertCreated();

    expect($member->notifications()->count())->toBe(1);
    expect($member->notifications()->first()->data['category'])->toBe('assigned');
    expect($owner->notifications()->count())->toBe(0);
});

test('assigning a task to yourself does not notify you', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Self assigned', 'assignee_id' => $owner->id]
    )->assertCreated();

    expect($owner->notifications()->count())->toBe(0);
});

test('changing a task assignee via update notifies the new assignee', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();
    $task = $project->tasks()->create([
        'title' => 'Reassign me', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)->putJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}",
        ['title' => 'Reassign me', 'assignee_id' => $member->id]
    )->assertOk();

    expect($member->notifications()->count())->toBe(1);
});

test('mentioning a project member by name in a comment notifies them', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();
    $task = $project->tasks()->create([
        'title' => 'Discuss', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments",
        ['body' => 'Hey @Jamie Colleague, can you take a look?']
    )->assertCreated();

    expect($member->notifications()->count())->toBe(1);
    expect($member->notifications()->first()->data['category'])->toBe('mention');
});

test('a comment with no mention does not notify anyone', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();
    $task = $project->tasks()->create([
        'title' => 'Discuss', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$task->id}/comments",
        ['body' => 'No mentions here.']
    )->assertCreated();

    expect($member->notifications()->count())->toBe(0);
});

test('inviting a member to a workspace notifies them', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $invitee = User::factory()->create();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/members",
        ['email' => $invitee->email, 'role' => 'member']
    )->assertCreated();

    expect($invitee->notifications()->count())->toBe(1);
    expect($invitee->notifications()->first()->data['category'])->toBe('system');
});

test('a user can list their notifications and see an unread count', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Ship it', 'assignee_id' => $member->id]
    )->assertCreated();

    $this->actingAs($member)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'assigned');

    $this->actingAs($member)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('count', 1);
});

test('a user can mark a single notification as read', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Ship it', 'assignee_id' => $member->id]
    )->assertCreated();

    $notificationId = $member->notifications()->first()->id;

    $this->actingAs($member)
        ->patchJson("/api/v1/notifications/{$notificationId}/read")
        ->assertNoContent();

    expect($member->notifications()->first()->read_at)->not->toBeNull();
});

test('a user cannot mark another user notification as read', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();

    $this->actingAs($owner)->postJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks",
        ['title' => 'Ship it', 'assignee_id' => $member->id]
    )->assertCreated();

    $notificationId = $member->notifications()->first()->id;

    $this->actingAs($owner)
        ->patchJson("/api/v1/notifications/{$notificationId}/read")
        ->assertNotFound();
});

test('a user can mark all notifications as read', function () {
    ['owner' => $owner, 'member' => $member, 'workspace' => $workspace, 'project' => $project] = workspaceWithTwoMembers();
    $taskA = $project->tasks()->create(['title' => 'A', 'status' => 'todo', 'priority' => 'medium', 'position' => 0, 'created_by' => $owner->id]);
    $taskB = $project->tasks()->create(['title' => 'B', 'status' => 'todo', 'priority' => 'medium', 'position' => 1, 'created_by' => $owner->id]);

    $this->actingAs($owner)->putJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$taskA->id}",
        ['title' => 'A', 'assignee_id' => $member->id]
    )->assertOk();

    $this->actingAs($owner)->putJson(
        "/api/v1/workspaces/{$workspace->id}/projects/{$project->id}/tasks/{$taskB->id}",
        ['title' => 'B', 'assignee_id' => $member->id]
    )->assertOk();

    expect($member->unreadNotifications()->count())->toBe(2);

    $this->actingAs($member)->postJson('/api/v1/notifications/read-all')->assertNoContent();

    expect($member->unreadNotifications()->count())->toBe(0);
});

test('a guest cannot access notification endpoints', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
});
