<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;

class TaskPolicy
{
    /**
     * Determine whether the user can list tasks in the project.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can view the task.
     */
    public function view(User $user, Task $task): bool
    {
        return $task->project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can create a task in the project.
     */
    public function create(User $user, Project $project): bool
    {
        return $project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can update the task (including moving it).
     */
    public function update(User $user, Task $task): bool
    {
        return $task->project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can delete the task.
     */
    public function delete(User $user, Task $task): bool
    {
        return $task->project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can comment on the task.
     */
    public function addComment(User $user, Task $task): bool
    {
        return $task->project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can delete the given comment.
     *
     * The comment's author can always remove it; workspace owners/admins
     * can also moderate any comment in their workspace.
     */
    public function deleteComment(User $user, Task $task, TaskComment $comment): bool
    {
        if ($comment->user_id === $user->id) {
            return true;
        }

        $workspaceRole = $task->project->workspace->roleFor($user);

        return in_array($workspaceRole, [WorkspaceRole::Owner, WorkspaceRole::Admin], true);
    }
}
