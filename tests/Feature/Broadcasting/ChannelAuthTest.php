<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // The 'null' broadcaster (used by default in tests) no-ops auth() entirely,
    // so these tests switch to the real reverb driver to exercise the actual
    // channel authorization callbacks in routes/channels.php. This performs a
    // local signature check only — no network call to a running Reverb server.
    //
    // Broadcast::channel() registers on whichever driver is default AT CALL
    // TIME (routes/channels.php already ran against the 'null' driver during
    // boot), so channels.php is re-required here to register the same
    // callbacks on the now-default 'reverb' driver too.
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
});

function workspaceAndProjectFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $outsider = User::factory()->create();

    $project = $workspace->projects()->create(['name' => 'Design System', 'slug' => 'design-system']);
    $project->members()->create(['user_id' => $owner->id]);

    return compact('owner', 'member', 'outsider', 'workspace', 'project');
}

test('a workspace member can authorize the workspace private channel', function () {
    ['member' => $member, 'workspace' => $workspace] = workspaceAndProjectFixture();

    $this->actingAs($member)->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-workspace.{$workspace->id}",
    ])->assertOk();
});

test('a non-member cannot authorize the workspace private channel', function () {
    ['outsider' => $outsider, 'workspace' => $workspace] = workspaceAndProjectFixture();

    $this->actingAs($outsider)->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-workspace.{$workspace->id}",
    ])->assertForbidden();
});

test('a project member can authorize the project private channel', function () {
    ['owner' => $owner, 'project' => $project] = workspaceAndProjectFixture();

    $this->actingAs($owner)->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-project.{$project->id}",
    ])->assertOk();
});

test('a workspace member who is not a project member cannot authorize the project private channel', function () {
    ['member' => $member, 'project' => $project] = workspaceAndProjectFixture();

    $this->actingAs($member)->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-project.{$project->id}",
    ])->assertForbidden();
});

test('an outsider cannot authorize the project private channel', function () {
    ['outsider' => $outsider, 'project' => $project] = workspaceAndProjectFixture();

    $this->actingAs($outsider)->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-project.{$project->id}",
    ])->assertForbidden();
});

test('a guest cannot authorize any private channel', function () {
    ['workspace' => $workspace] = workspaceAndProjectFixture();

    $this->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-workspace.{$workspace->id}",
    ])->assertUnauthorized();
});

test('a user can authorize their own private notification channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-App.Models.User.{$user->id}",
    ])->assertOk();
});

test('a user cannot authorize another user private notification channel', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-App.Models.User.{$other->id}",
    ])->assertForbidden();
});
