<?php

use App\Actions\Demo\DeleteDemoAccount;
use App\Actions\Demo\StartDemo;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * demo:prune (hourly) deletes demo accounts whose time is up, with everything in them.
 */

/** Everything one demo created, to check it is gone (or still there) afterwards. */
function demoFootprint(User $visitor): array
{
    $workspace = $visitor->workspaces()->sole();
    $projectIds = $workspace->projects()->pluck('id');
    $taskIds = Task::query()->whereIn('project_id', $projectIds)->pluck('id');
    $userIds = $workspace->members()->pluck('user_id');

    return compact('workspace', 'projectIds', 'taskIds', 'userIds');
}

function sessionFor(User $user): void
{
    DB::table('sessions')->insert([
        'id' => (string) str()->random(40),
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

it('deletes expired demos with everything in them, and nothing else', function () {
    config(['session.driver' => 'database']);

    // Real data that must survive.
    $realUser = User::factory()->create();
    $realWorkspace = Workspace::factory()->create();
    $realWorkspace->members()->create(['user_id' => $realUser->id, 'role' => WorkspaceRole::Owner]);
    $realTask = Task::factory()->for(Project::factory()->for($realWorkspace))->create(['created_by' => $realUser->id]);
    TaskComment::factory()->for($realTask)->create(['user_id' => $realUser->id]);
    sessionFor($realUser);

    $expired = demoFootprint(app(StartDemo::class)->handle());
    sessionFor(User::find($expired['userIds']->first()));

    $this->travel(25)->hours();
    $running = demoFootprint(app(StartDemo::class)->handle()); // started after the first one expired

    $this->artisan('demo:prune')->expectsOutput('Deleted 4 expired demo accounts.')->assertSuccessful();

    // The expired demo: visitor, teammates, workspace and everything inside it.
    expect(User::query()->whereKey($expired['userIds'])->exists())->toBeFalse()
        ->and(Workspace::find($expired['workspace']->id))->toBeNull()
        ->and(Project::query()->whereKey($expired['projectIds'])->exists())->toBeFalse()
        ->and(Task::query()->whereKey($expired['taskIds'])->exists())->toBeFalse()
        ->and(TaskComment::query()->whereIn('task_id', $expired['taskIds'])->exists())->toBeFalse()
        ->and(Activity::query()->where('workspace_id', $expired['workspace']->id)->exists())->toBeFalse()
        ->and(DatabaseNotification::query()->whereIn('notifiable_id', $expired['userIds'])->exists())->toBeFalse()
        ->and(DB::table('sessions')->whereIn('user_id', $expired['userIds'])->exists())->toBeFalse();

    // The running demo is untouched.
    expect(User::query()->whereKey($running['userIds'])->count())->toBe(4)
        ->and(Task::query()->whereKey($running['taskIds'])->count())->toBe(18)
        ->and(DatabaseNotification::query()->whereIn('notifiable_id', $running['userIds'])->count())->toBe(3);

    // So is the real account.
    expect(User::find($realUser->id))->not->toBeNull()
        ->and($realWorkspace->fresh())->not->toBeNull()
        ->and($realTask->fresh())->not->toBeNull()
        ->and($realTask->comments()->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $realUser->id)->exists())->toBeTrue();
});

it('never deletes a workspace that a real account belongs to', function () {
    $visitor = app(StartDemo::class)->handle();
    $workspace = $visitor->workspaces()->sole();
    // Not possible through the API (invitations are blocked both ways), but if it
    // ever happened, the real member's workspace must survive the cleanup.
    $realUser = User::factory()->create();
    WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $realUser->id, 'role' => WorkspaceRole::Member]);

    $this->travel(25)->hours();
    $this->artisan('demo:prune')->assertSuccessful();

    expect($workspace->fresh())->not->toBeNull()
        ->and($workspace->members()->pluck('user_id')->all())->toBe([$realUser->id])
        ->and(User::query()->whereNotNull('demo_expires_at')->exists())->toBeFalse();
});

it('keeps demos that have not expired yet', function () {
    app(StartDemo::class)->handle();

    $this->travel(23)->hours();
    $this->artisan('demo:prune')->expectsOutput('Deleted 0 expired demo accounts.')->assertSuccessful();

    expect(User::query()->whereNotNull('demo_expires_at')->count())->toBe(4);
});

it('refuses to delete a normal account', function () {
    app(DeleteDemoAccount::class)->handle(User::factory()->create());
})->throws(InvalidArgumentException::class, 'Only demo accounts can be deleted this way.');

it('runs every hour', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('0 * * * *  php artisan demo:prune')->assertSuccessful();
});
