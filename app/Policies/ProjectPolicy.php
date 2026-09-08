<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;

class ProjectPolicy
{
    /**
     * Determine whether the user can create a project in the workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $this->isWorkspaceManager($user, $workspace);
    }

    /**
     * Determine whether the user can view the project.
     *
     * Project members can always view it. Workspace owners/admins retain
     * administrative visibility even when not explicitly a project member.
     */
    public function view(User $user, Project $project): bool
    {
        if ($this->isWorkspaceManager($user, $project->workspace)) {
            return true;
        }

        return $project->members->contains('user_id', $user->id);
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        return $this->isWorkspaceManager($user, $project->workspace);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->isWorkspaceManager($user, $project->workspace);
    }

    /**
     * Determine whether the user can add a member to the project.
     */
    public function addMember(User $user, Project $project): bool
    {
        return $this->isWorkspaceManager($user, $project->workspace);
    }

    /**
     * Determine whether the user can remove the given member from the project.
     */
    public function removeMember(User $user, Project $project, ProjectMember $member): bool
    {
        return $this->isWorkspaceManager($user, $project->workspace);
    }

    private function isWorkspaceManager(User $user, Workspace $workspace): bool
    {
        return in_array($workspace->roleFor($user), [WorkspaceRole::Owner, WorkspaceRole::Admin], true);
    }
}
