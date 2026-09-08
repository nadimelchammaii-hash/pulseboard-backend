<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function workspaceWithRole(User $user, WorkspaceRole $role): Workspace
{
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => $role]);

    return $workspace;
}

test('a workspace owner can create a project and is added as its member', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithRole($owner, WorkspaceRole::Owner);

    $response = $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->id}/projects", [
        'name' => 'Website Relaunch',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.name', 'Website Relaunch');
    $response->assertJsonPath('data.is_member', true);
    $response->assertJsonPath('data.members_count', 1);

    $this->assertDatabaseHas('project_members', [
        'project_id' => $response->json('data.id'),
        'user_id' => $owner->id,
    ]);
});

test('a workspace admin can create a project', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithRole($admin, WorkspaceRole::Admin);

    $this->actingAs($admin)->postJson("/api/v1/workspaces/{$workspace->id}/projects", [
        'name' => 'Mobile App',
    ])->assertCreated();
});

test('a plain workspace member cannot create a project', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithRole($member, WorkspaceRole::Member);

    $this->actingAs($member)->postJson("/api/v1/workspaces/{$workspace->id}/projects", [
        'name' => 'Not Allowed',
    ])->assertForbidden();
});

test('an owner or admin sees every project in the workspace, a plain member sees only their own', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithRole($owner, WorkspaceRole::Owner);

    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $ownedByOwner = $workspace->projects()->create(['name' => 'Owner Project', 'slug' => 'owner-project']);
    $ownedByOwner->members()->create(['user_id' => $owner->id]);

    $ownedByMember = $workspace->projects()->create(['name' => 'Member Project', 'slug' => 'member-project']);
    $ownedByMember->members()->create(['user_id' => $member->id]);

    $ownerResponse = $this->actingAs($owner)->getJson("/api/v1/workspaces/{$workspace->id}/projects");
    $ownerResponse->assertOk()->assertJsonCount(2, 'data');

    $memberResponse = $this->actingAs($member)->getJson("/api/v1/workspaces/{$workspace->id}/projects");
    $memberResponse->assertOk()->assertJsonCount(1, 'data');
    $memberResponse->assertJsonPath('data.0.id', $ownedByMember->id);
});

test('a workspace admin can view a project even without being an explicit member', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithRole($owner, WorkspaceRole::Owner);

    $admin = User::factory()->create();
    $workspace->members()->create(['user_id' => $admin->id, 'role' => WorkspaceRole::Admin]);

    $project = $workspace->projects()->create(['name' => 'Owner Only', 'slug' => 'owner-only']);
    $project->members()->create(['user_id' => $owner->id]);

    $this->actingAs($admin)
        ->getJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}")
        ->assertOk();
});

test('a plain workspace member who is not a project member cannot view the project', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithRole($owner, WorkspaceRole::Owner);

    $outsider = User::factory()->create();
    $workspace->members()->create(['user_id' => $outsider->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Private', 'slug' => 'private']);
    $project->members()->create(['user_id' => $owner->id]);

    $this->actingAs($outsider)
        ->getJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}")
        ->assertForbidden();
});

test('a workspace admin can rename or delete a project, a plain project member cannot', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithRole($owner, WorkspaceRole::Owner);

    $member = User::factory()->create();
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);

    $project = $workspace->projects()->create(['name' => 'Original', 'slug' => 'original']);
    $project->members()->create(['user_id' => $owner->id]);
    $project->members()->create(['user_id' => $member->id]);

    $this->actingAs($member)
        ->putJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($member)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}")
        ->assertForbidden();

    $this->actingAs($owner)
        ->putJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    $this->actingAs($owner)
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
});

test('a guest cannot access any project endpoint', function () {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->for($workspace)->create();

    $this->getJson("/api/v1/workspaces/{$workspace->id}/projects")->assertUnauthorized();
    $this->postJson("/api/v1/workspaces/{$workspace->id}/projects", ['name' => 'X'])->assertUnauthorized();
    $this->getJson("/api/v1/workspaces/{$workspace->id}/projects/{$project->id}")->assertUnauthorized();
});
