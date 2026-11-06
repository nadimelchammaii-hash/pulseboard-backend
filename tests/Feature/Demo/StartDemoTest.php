<?php

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * "Try the demo": each click creates a private, temporary demo account with its
 * own sample workspace and signs the visitor in.
 */

function startDemo(): TestResponse
{
    return test()->postJson('/api/v1/demo');
}

function demoVisitor(): User
{
    return User::query()->where('email', 'like', 'visitor-%')->whereNotNull('demo_expires_at')->latest('id')->firstOrFail();
}

describe('starting a demo', function () {
    it('signs the visitor in to a new demo account that expires after 24 hours', function () {
        $this->freezeSecond();

        startDemo()->assertCreated()
            ->assertJsonPath('data.name', 'Demo Visitor')
            ->assertJsonPath('data.is_demo', true)
            ->assertJsonPath('data.demo_expires_at', now()->addHours(24)->toIso8601String());

        $visitor = demoVisitor();
        expect($visitor->email)->toEndWith('@demo.pulseboardapp.invalid');
        $this->assertAuthenticatedAs($visitor);
    });

    it('gives the visitor a sample workspace with fictional teammates in different roles', function () {
        startDemo()->assertCreated();

        $workspaces = $this->getJson('/api/v1/workspaces')->assertOk()->json('data');
        expect($workspaces)->toHaveCount(1)
            ->and($workspaces[0]['name'])->toBe('Lumen Labs')
            ->and($workspaces[0]['role'])->toBe('owner');

        $members = collect($this->getJson("/api/v1/workspaces/{$workspaces[0]['id']}/members")->assertOk()->json('data'));
        expect($members->mapWithKeys(fn (array $member) => [$member['user']['name'] => $member['role']])->all())->toEqual([
            'Demo Visitor' => 'owner',
            'Maya Chen' => 'admin',
            'Sam Rivera' => 'member',
            'Priya Patel' => 'member',
        ]);

        // The teammates are demo accounts too: undeliverable addresses, same expiry.
        $visitor = demoVisitor();
        User::query()->whereKeyNot($visitor->id)->get()->each(function (User $teammate) use ($visitor) {
            expect($teammate->email)->toEndWith('@demo.pulseboardapp.invalid')
                ->and($teammate->demo_expires_at->equalTo($visitor->demo_expires_at))->toBeTrue();
        });
    });

    it('fills three projects with tasks in every column, due dates and comments', function () {
        startDemo()->assertCreated();
        $workspaceId = Workspace::query()->sole()->id;

        $projects = collect($this->getJson("/api/v1/workspaces/{$workspaceId}/projects")->assertOk()->json('data'));
        expect($projects->pluck('name')->sort()->values()->all())->toBe(['Customer Onboarding', 'Mobile App Launch', 'Website Redesign']);

        $mobileApp = $projects->firstWhere('name', 'Mobile App Launch');
        $tasks = collect($this->getJson("/api/v1/workspaces/{$workspaceId}/projects/{$mobileApp['id']}/tasks")->assertOk()->json('data'));

        expect($tasks->pluck('status')->unique()->sort()->values()->all())->toBe(['done', 'in_progress', 'review', 'todo'])
            ->and($tasks->where('due_date', '<', today()->toDateString())->where('status', '!=', 'done'))->not->toBeEmpty() // something overdue
            ->and($tasks->sum('comments_count'))->toBeGreaterThan(0);

        // Positions run 0, 1, 2... inside each column, as the board expects.
        $tasks->groupBy('status')->each(fn ($column) => expect($column->pluck('position')->all())->toBe(range(0, $column->count() - 1)));

        expect(Task::count())->toBe(18)->and(TaskComment::count())->toBe(6);
    });

    it('shows project-level permissions: a member only sees the projects they are on', function () {
        startDemo()->assertCreated();
        $workspaceId = Workspace::query()->sole()->id;
        $sam = User::query()->where('name', 'Sam Rivera')->sole();

        $samsProjects = $this->actingAs($sam)->getJson("/api/v1/workspaces/{$workspaceId}/projects")->assertOk()->json('data');

        expect(collect($samsProjects)->pluck('name')->sort()->values()->all())->toBe(['Customer Onboarding', 'Mobile App Launch']);
    });

    it('fills the activity feed and notifications without broadcasting or queueing anything', function () {
        Queue::fake();

        startDemo()->assertCreated();
        $workspaceId = Workspace::query()->sole()->id;

        $feed = $this->getJson("/api/v1/workspaces/{$workspaceId}/activities")->assertOk();
        expect($feed->json('meta.total'))->toBe(41)
            ->and($feed->json('data.0.action'))->toBe(ActivityAction::TaskCommentAdded->value) // newest first
            ->and(Activity::query()->distinct()->pluck('action')->map->value->sort()->values()->all())->toBe([
                'project.created', 'task.created', 'task.moved', 'task_comment.added', 'workspace.member_invited',
            ]);

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('count', 3);
        $categories = collect($this->getJson('/api/v1/notifications')->json('data'))->pluck('category')->sort()->values()->all();
        expect($categories)->toBe(['assigned', 'assigned', 'mention']);

        // Real broadcasts and broadcast notifications go through the queue: nothing was pushed.
        Queue::assertNothingPushed();
    });

    it('gives every visitor their own private copy', function () {
        startDemo()->assertCreated();
        $first = demoVisitor();
        $firstWorkspace = Workspace::query()->sole();

        $this->postJson('/api/v1/logout')->assertNoContent();
        startDemo()->assertCreated();
        $second = demoVisitor();

        expect($second->id)->not->toBe($first->id)
            ->and(Workspace::count())->toBe(2)
            ->and(Project::count())->toBe(6)
            ->and(User::query()->whereNotNull('demo_expires_at')->count())->toBe(8);

        // The test client does not carry the session cookie between requests, so act
        // as the second visitor explicitly.
        $this->actingAs($second)->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($second)->getJson("/api/v1/workspaces/{$firstWorkspace->id}")->assertForbidden();
    });
});

describe('limits on starting demos', function () {
    it('allows only a few new demos per visitor per hour, with a message for the visitor', function () {
        config(['demo.starts_per_hour' => 2]);

        startDemo()->assertCreated();
        startDemo()->assertCreated();
        startDemo()->assertTooManyRequests()->assertExactJson([
            'message' => 'Several demos were started from your network recently. Please try again in an hour.',
        ]);

        $this->travel(61)->minutes();
        startDemo()->assertCreated();
    });

    it('caps new demos for the whole site per day', function () {
        config(['demo.starts_per_hour' => 10, 'demo.starts_per_day' => 1]);

        startDemo()->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        startDemo()->assertServiceUnavailable()->assertExactJson([
            'message' => 'The demo is very busy today. Please try again tomorrow.',
        ]);
    });

    it('is unavailable when switched off, with a message for the visitor', function () {
        config(['demo.enabled' => false]);

        startDemo()->assertServiceUnavailable()
            ->assertJsonPath('message', 'The demo is not available right now. Please try again later.');

        expect(User::count())->toBe(0);
        $this->assertGuest();
    });
});

it('reports a normal account as not a demo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/user')->assertOk()
        ->assertJsonPath('data.is_demo', false)
        ->assertJsonPath('data.demo_expires_at', null);
});
