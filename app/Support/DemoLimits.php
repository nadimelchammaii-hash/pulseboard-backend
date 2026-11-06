<?php

namespace App\Support;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

/**
 * Size limits for "Try the demo" accounts (config demo.limits), checked by the
 * policies. Each method returns null when the action is allowed, otherwise a
 * denial whose message is written for the visitor (the API answers 403 with it).
 * Normal accounts are never limited here.
 */
class DemoLimits
{
    public static function newWorkspace(User $user): ?Response
    {
        if (! $user->isDemo() || $user->workspaces()->count() < config('demo.limits.workspaces')) {
            return null;
        }

        return self::deny('The demo allows up to :limit workspaces. Create your own account to add more.', 'workspaces');
    }

    public static function newProject(User $user, Workspace $workspace): ?Response
    {
        if (! $user->isDemo() || $workspace->projects()->count() < config('demo.limits.projects_per_workspace')) {
            return null;
        }

        return self::deny('The demo allows up to :limit projects per workspace. Create your own account to add more.', 'projects_per_workspace');
    }

    public static function newTask(User $user): ?Response
    {
        if (! $user->isDemo() || Task::query()->whereHas('project', self::inWorkspacesOf($user))->count() < config('demo.limits.tasks')) {
            return null;
        }

        return self::deny('The demo allows up to :limit tasks. Delete a task, or create your own account to add more.', 'tasks');
    }

    public static function newComment(User $user): ?Response
    {
        if (! $user->isDemo() || TaskComment::query()->whereHas('task.project', self::inWorkspacesOf($user))->count() < config('demo.limits.comments')) {
            return null;
        }

        return self::deny('The demo allows up to :limit comments. Create your own account to keep the conversation going.', 'comments');
    }

    /** Constrains a project query to the workspaces the user belongs to. */
    private static function inWorkspacesOf(User $user): \Closure
    {
        return fn (Builder $projects) => $projects->whereHas(
            'workspace.members',
            fn (Builder $members) => $members->where('user_id', $user->id),
        );
    }

    private static function deny(string $message, string $limit): Response
    {
        return Response::deny(str_replace(':limit', (string) config("demo.limits.{$limit}"), $message));
    }
}
