<?php

namespace App\Actions\Demo;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deletes a demo account (a visitor or one of their fictional teammates) with
 * everything that belongs to it.
 *
 * Workspaces have no owner column, so they are deleted here; the database then
 * removes their projects, tasks, comments, memberships and activity. As a
 * safeguard, a workspace is deleted only when every member is a demo account:
 * if a real account were ever in it, only the demo member's own rows go. The
 * user's notifications, sessions and API tokens have no foreign key, so they are
 * deleted explicitly; deleting the user removes the rest (its memberships, the
 * tasks it created, its comments) by ON DELETE CASCADE.
 */
class DeleteDemoAccount
{
    public function handle(User $user): void
    {
        if (! $user->isDemo()) {
            throw new InvalidArgumentException('Only demo accounts can be deleted this way.');
        }

        DB::transaction(function () use ($user): void {
            $user->workspaces()
                ->whereDoesntHave('users', fn ($members) => $members->whereNull('demo_expires_at'))
                ->get()
                ->each(fn (Workspace $workspace) => $workspace->delete());

            $user->notifications()->delete();
            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            $user->delete();
        });
    }
}
