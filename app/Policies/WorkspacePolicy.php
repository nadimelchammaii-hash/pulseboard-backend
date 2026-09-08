<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

class WorkspacePolicy
{
    /**
     * Determine whether the user can view the workspace.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user) !== null;
    }

    /**
     * Determine whether the user can create workspaces.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the workspace's own attributes (e.g. name).
     */
    public function update(User $user, Workspace $workspace): bool
    {
        return in_array($workspace->roleFor($user), [WorkspaceRole::Owner, WorkspaceRole::Admin], true);
    }

    /**
     * Determine whether the user can delete the workspace.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user) === WorkspaceRole::Owner;
    }

    /**
     * Determine whether the user can invite a new member to the workspace.
     */
    public function inviteMember(User $user, Workspace $workspace): bool
    {
        return in_array($workspace->roleFor($user), [WorkspaceRole::Owner, WorkspaceRole::Admin], true);
    }

    /**
     * Determine whether the user can remove the given member from the workspace.
     *
     * The owner can never be removed (ownership must be transferred first).
     * A member may always remove themselves, except the owner. Admins may
     * remove members, but only the owner may remove another admin.
     */
    public function removeMember(User $user, Workspace $workspace, WorkspaceMember $member): bool
    {
        if ($member->role === WorkspaceRole::Owner) {
            return false;
        }

        $actorRole = $workspace->roleFor($user);

        if ($member->user_id === $user->id) {
            return $actorRole !== null;
        }

        if ($actorRole === WorkspaceRole::Owner) {
            return true;
        }

        return $actorRole === WorkspaceRole::Admin && $member->role === WorkspaceRole::Member;
    }

    /**
     * Determine whether the user can change the given member's role
     * (including transferring ownership to them). Owner only.
     */
    public function updateMemberRole(User $user, Workspace $workspace, WorkspaceMember $member): bool
    {
        return $workspace->roleFor($user) === WorkspaceRole::Owner;
    }
}
