<?php

use App\Actions\Demo\StartDemo;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * What a demo account may not do, and the limits that keep the database small.
 * Demos are built with StartDemo directly; starting one over HTTP is covered in
 * StartDemoTest.
 */

beforeEach(function () {
    $this->visitor = app(StartDemo::class)->handle();
    $this->workspace = $this->visitor->workspaces()->sole();
    $this->project = $this->workspace->projects()->where('name', 'Mobile App Launch')->sole();
    $this->task = $this->project->tasks()->where('title', 'Build the task board screen')->sole();
});

function projectsUrl(Workspace $workspace): string
{
    return "/api/v1/workspaces/{$workspace->id}/projects";
}

function tasksUrl(Project $project): string
{
    return "/api/v1/workspaces/{$project->workspace_id}/projects/{$project->id}/tasks";
}

describe('account settings', function () {
    it('cannot be changed by a demo account', function () {
        $this->actingAs($this->visitor)->putJson('/api/v1/user/profile', ['name' => 'Visitor', 'email' => 'me@example.com'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Demo accounts cannot change account settings. Create your own account to use your own name and email.');

        $this->actingAs($this->visitor)->putJson('/api/v1/user/password', [
            'current_password' => 'anything',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertForbidden();

        $visitor = $this->visitor->fresh();
        expect($visitor->name)->toBe('Demo Visitor')
            ->and($visitor->email)->toEndWith('@demo.pulseboardapp.invalid')
            ->and(Hash::check('new-password', $visitor->password))->toBeFalse();
    });

    it('can still be changed by a normal account', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/user/profile', ['name' => 'Still Mine', 'email' => 'mine@example.com'])->assertOk();
    });
});

describe('invitations', function () {
    it('cannot be sent from a demo account, not even to a real account', function () {
        $realPerson = User::factory()->create(['email' => 'real.person@example.com']);

        $this->actingAs($this->visitor)->postJson("/api/v1/workspaces/{$this->workspace->id}/members", [
            'email' => 'real.person@example.com',
            'role' => 'member',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Inviting people is turned off in the demo. Create your own account to invite your team.');

        expect($this->workspace->members()->where('user_id', $realPerson->id)->exists())->toBeFalse();
    });

    it('are refused with the demo message whatever address is typed', function () {
        // Found in the browser: validation ran first, so an unknown address got
        // "No PulseBoard account exists" instead of the demo message.
        $this->actingAs($this->visitor)->postJson("/api/v1/workspaces/{$this->workspace->id}/members", [
            'email' => 'nobody@example.com',
            'role' => 'member',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Inviting people is turned off in the demo. Create your own account to invite your team.');
    });

    it('cannot bring a demo account into a real workspace', function () {
        $owner = User::factory()->create();
        $realWorkspace = Workspace::factory()->create();
        $realWorkspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);
        $teammate = User::query()->where('name', 'Maya Chen')->sole();

        foreach ([$this->visitor, $teammate] as $demoAccount) {
            $this->actingAs($owner)->postJson("/api/v1/workspaces/{$realWorkspace->id}/members", [
                'email' => $demoAccount->email,
                'role' => 'member',
            ])->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'No PulseBoard account exists for that email address.');
        }

        expect($realWorkspace->members()->count())->toBe(1);
    });
});

describe('password reset emails', function () {
    it('are never sent to demo addresses, with the same answer as for any address', function () {
        Notification::fake();

        $this->postJson('/api/v1/forgot-password', ['email' => $this->visitor->email])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, password reset instructions have been sent.');

        Notification::assertNothingSent();
    });

    it('are still sent to normal addresses', function () {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'ada@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    });
});

describe('size limits', function () {
    it('allows a limited number of workspaces', function () {
        $this->actingAs($this->visitor)->postJson('/api/v1/workspaces', ['name' => 'Second'])->assertCreated();

        $this->actingAs($this->visitor)->postJson('/api/v1/workspaces', ['name' => 'Third'])
            ->assertForbidden()
            ->assertJsonPath('message', 'The demo allows up to 2 workspaces. Create your own account to add more.');
    });

    it('allows a limited number of projects per workspace', function () {
        config(['demo.limits.projects_per_workspace' => 4]); // the sample workspace has 3

        $this->actingAs($this->visitor)->postJson(projectsUrl($this->workspace), ['name' => 'Fourth'])->assertCreated();

        $this->actingAs($this->visitor)->postJson(projectsUrl($this->workspace), ['name' => 'Fifth'])
            ->assertForbidden()
            ->assertJsonPath('message', 'The demo allows up to 4 projects per workspace. Create your own account to add more.');
    });

    it('allows a limited number of tasks, and deleting one frees room', function () {
        config(['demo.limits.tasks' => 19]); // the samples have 18

        $this->actingAs($this->visitor)->postJson(tasksUrl($this->project), ['title' => 'One more'])->assertCreated();

        $this->actingAs($this->visitor)->postJson(tasksUrl($this->project), ['title' => 'Too many'])
            ->assertForbidden()
            ->assertJsonPath('message', 'The demo allows up to 19 tasks. Delete a task, or create your own account to add more.');

        $this->actingAs($this->visitor)->deleteJson(tasksUrl($this->project)."/{$this->task->id}")->assertNoContent();
        $this->actingAs($this->visitor)->postJson(tasksUrl($this->project), ['title' => 'Fits again'])->assertCreated();
    });

    it('allows a limited number of comments', function () {
        config(['demo.limits.comments' => 7]); // the samples have 6
        $commentsUrl = tasksUrl($this->project)."/{$this->task->id}/comments";

        $this->actingAs($this->visitor)->postJson($commentsUrl, ['body' => 'Looks good!'])->assertCreated();

        $this->actingAs($this->visitor)->postJson($commentsUrl, ['body' => 'One too many'])
            ->assertForbidden()
            ->assertJsonPath('message', 'The demo allows up to 7 comments. Create your own account to keep the conversation going.');
    });

    it('do not apply to normal accounts', function () {
        config(['demo.limits' => ['workspaces' => 0, 'projects_per_workspace' => 0, 'tasks' => 0, 'comments' => 0]]);
        $user = User::factory()->create();

        $workspace = Workspace::find($this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'Mine'])->assertCreated()->json('data.id'));
        $project = Project::find($this->actingAs($user)->postJson(projectsUrl($workspace), ['name' => 'Mine'])->assertCreated()->json('data.id'));
        $task = Task::find($this->actingAs($user)->postJson(tasksUrl($project), ['title' => 'Mine'])->assertCreated()->json('data.id'));
        $this->actingAs($user)->postJson(tasksUrl($project)."/{$task->id}/comments", ['body' => 'Mine'])->assertCreated();
    });
});

describe('real-time updates', function () {
    beforeEach(function () {
        // As in ChannelAuthTest: the 'null' test broadcaster authorizes every channel,
        // so switch to the real driver and register the channel callbacks on it.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    });

    function authorizeChannel(User $user, string $channel): TestResponse
    {
        return test()->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-{$channel}",
        ]);
    }

    it('reach a demo visitor for their own demo workspace and projects', function () {
        authorizeChannel($this->visitor, "workspace.{$this->workspace->id}")->assertOk();
        authorizeChannel($this->visitor, "project.{$this->project->id}")->assertOk();
        authorizeChannel($this->visitor, "App.Models.User.{$this->visitor->id}")->assertOk();
    });

    // One acting user per test, as in ChannelAuthTest: the test client does not
    // switch identities cleanly between requests within one test.

    it('never reach a demo visitor from another demo or from real workspaces', function () {
        $otherWorkspace = app(StartDemo::class)->handle()->workspaces()->sole();
        $realWorkspace = Workspace::factory()->create();
        $realWorkspace->members()->create(['user_id' => User::factory()->create()->id, 'role' => WorkspaceRole::Owner]);
        $otherVisitor = $otherWorkspace->members()->where('role', WorkspaceRole::Owner)->sole()->user;

        authorizeChannel($this->visitor, "workspace.{$otherWorkspace->id}")->assertForbidden();
        authorizeChannel($this->visitor, "project.{$otherWorkspace->projects()->first()->id}")->assertForbidden();
        authorizeChannel($this->visitor, "App.Models.User.{$otherVisitor->id}")->assertForbidden();
        authorizeChannel($this->visitor, "workspace.{$realWorkspace->id}")->assertForbidden();
    });

    it('never reach a real account from a demo workspace', function () {
        $realUser = User::factory()->create();

        authorizeChannel($realUser, "workspace.{$this->workspace->id}")->assertForbidden();
        authorizeChannel($realUser, "project.{$this->project->id}")->assertForbidden();
    });

    it('never reach another demo visitor from this demo', function () {
        $otherVisitor = app(StartDemo::class)->handle();

        authorizeChannel($otherVisitor, "workspace.{$this->workspace->id}")->assertForbidden();
        authorizeChannel($otherVisitor, "project.{$this->project->id}")->assertForbidden();
    });
});
