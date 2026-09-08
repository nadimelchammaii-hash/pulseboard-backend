<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function memberWorkspace(User $owner): Workspace
{
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);

    return $workspace;
}

test('an owner can invite a new member', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $invitee = User::factory()->create();

    $response = $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => $invitee->email,
        'role' => WorkspaceRole::Member->value,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.user.id', $invitee->id);
    $response->assertJsonPath('data.role', WorkspaceRole::Member->value);
});

test('an admin can invite a new member', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $admin = User::factory()->create();
    $workspace->members()->create(['user_id' => $admin->id, 'role' => WorkspaceRole::Admin]);
    $invitee = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => $invitee->email,
        'role' => WorkspaceRole::Member->value,
    ])->assertCreated();
});

test('a plain member cannot invite anyone', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $invitee = User::factory()->create();

    $this->actingAs($member)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => $invitee->email,
        'role' => WorkspaceRole::Member->value,
    ])->assertForbidden();
});

test('inviting an email with no PulseBoard account fails validation', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => 'nobody@example.com',
        'role' => WorkspaceRole::Member->value,
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('inviting someone already in the workspace fails validation', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $existingMember = User::factory()->create();
    $workspace->members()->create(['user_id' => $existingMember->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => $existingMember->email,
        'role' => WorkspaceRole::Member->value,
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('a member cannot be invited directly as owner', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $invitee = User::factory()->create();

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->id}/members", [
        'email' => $invitee->email,
        'role' => WorkspaceRole::Owner->value,
    ])->assertUnprocessable()->assertJsonValidationErrors('role');
});

test('a member can remove themselves from a workspace', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $member = User::factory()->create();
    $membership = $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($member)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$membership->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('workspace_members', ['id' => $membership->id]);
});

test('the owner cannot remove themselves without transferring ownership first', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $ownerMembership = $workspace->members()->firstWhere('user_id', $owner->id);

    $this->actingAs($owner)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$ownerMembership->id}")
        ->assertForbidden();
});

test('an admin can remove a plain member but not another admin', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);

    $admin = User::factory()->create();
    $workspace->members()->create(['user_id' => $admin->id, 'role' => WorkspaceRole::Admin]);

    $otherAdmin = User::factory()->create();
    $otherAdminMembership = $workspace->members()->create(['user_id' => $otherAdmin->id, 'role' => WorkspaceRole::Admin]);

    $member = User::factory()->create();
    $memberMembership = $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$otherAdminMembership->id}")
        ->assertForbidden();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$memberMembership->id}")
        ->assertNoContent();
});

test('the owner can promote a member to admin', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $member = User::factory()->create();
    $membership = $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($owner)
        ->patchJson("/api/v1/workspaces/{$workspace->id}/members/{$membership->id}", ['role' => WorkspaceRole::Admin->value])
        ->assertOk()
        ->assertJsonPath('data.role', WorkspaceRole::Admin->value);
});

test('an admin cannot change member roles', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $admin = User::factory()->create();
    $workspace->members()->create(['user_id' => $admin->id, 'role' => WorkspaceRole::Admin]);
    $member = User::factory()->create();
    $membership = $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/workspaces/{$workspace->id}/members/{$membership->id}", ['role' => WorkspaceRole::Admin->value])
        ->assertForbidden();
});

test('the owner can transfer ownership, demoting themselves to admin', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $successor = User::factory()->create();
    $successorMembership = $workspace->members()->create(['user_id' => $successor->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($owner)
        ->patchJson("/api/v1/workspaces/{$workspace->id}/members/{$successorMembership->id}", ['role' => WorkspaceRole::Owner->value])
        ->assertOk()
        ->assertJsonPath('data.role', WorkspaceRole::Owner->value);

    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => WorkspaceRole::Admin->value,
    ]);
    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $workspace->id,
        'user_id' => $successor->id,
        'role' => WorkspaceRole::Owner->value,
    ]);
});

test('the owner role cannot be changed away directly without transferring to someone else', function () {
    $owner = User::factory()->create();
    $workspace = memberWorkspace($owner);
    $ownerMembership = $workspace->members()->firstWhere('user_id', $owner->id);

    $this->actingAs($owner)
        ->patchJson("/api/v1/workspaces/{$workspace->id}/members/{$ownerMembership->id}", ['role' => WorkspaceRole::Admin->value])
        ->assertUnprocessable();
});
