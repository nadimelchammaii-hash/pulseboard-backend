<?php

namespace App\Actions\Demo;

use App\Contracts\ActivityLoggable;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Events\ProjectCreated;
use App\Events\TaskCommentAdded;
use App\Events\TaskCreated;
use App\Events\TaskMoved;
use App\Events\WorkspaceMemberInvited;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskMentionedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * "Try the demo": creates a temporary demo account with its own, freshly built
 * sample workspace, and returns the account (the controller signs it in).
 *
 * Every demo gets the same starting state: a workspace the visitor owns, three
 * fictional teammates with different roles, three projects with different
 * memberships, tasks in every column, comments, an activity feed and a few
 * notifications. All of it belongs to this demo only, so nothing a visitor does
 * reaches anyone else. The teammates are demo accounts too (same expiry, an
 * undeliverable ".invalid" address, an unknowable password): nobody can sign
 * in as them, and demo:prune deletes them with the visitor.
 *
 * Activity and notification rows are written through the app's own events and
 * notification classes, so they have exactly the shape real actions produce, but
 * nothing is dispatched: no broadcasts, no queued jobs.
 */
class StartDemo
{
    public const VISITOR_NAME = 'Demo Visitor';

    /** The board a visitor lands on after starting a demo. */
    public const LANDING_PROJECT = 'Mobile App Launch';

    private CarbonImmutable $now;

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, Task> */
    private array $tasks = [];

    private ?Project $landingProject = null;

    public function handle(): User
    {
        if (! config('demo.enabled')) {
            abort(503, 'The demo is not available right now. Please try again later.');
        }

        $this->now = CarbonImmutable::now();

        return DB::transaction(fn (): User => $this->build());
    }

    /**
     * Where the visitor of the demo just built should land: the sample board.
     * Call after handle().
     *
     * @return array{workspace_id: int, project_id: int}
     */
    public function landing(): array
    {
        if ($this->landingProject === null) {
            throw new \LogicException('Start a demo before asking where it lands.');
        }

        return [
            'workspace_id' => $this->landingProject->workspace_id,
            'project_id' => $this->landingProject->id,
        ];
    }

    private function build(): User
    {
        $token = Str::lower((string) Str::ulid());
        $expiresAt = $this->now->addHours((int) config('demo.lifetime_hours'));

        // Nobody ever signs in to a demo account with a password, so one random,
        // unknowable hash serves the whole demo: bcrypt runs once, not per account.
        $password = Hash::make(Str::random(64));

        $this->people['visitor'] = $this->demoUser(self::VISITOR_NAME, 'visitor', $token, $password, $expiresAt);
        foreach ($this->team() as $key => [$name]) {
            $this->people[$key] = $this->demoUser($name, $key, $token, $password, $expiresAt);
        }

        $workspace = $this->saveAt(new Workspace([
            'name' => 'Lumen Labs',
            'slug' => "lumen-labs-{$token}",
        ]), $this->daysAgo(14));

        $this->saveAt(new WorkspaceMember([
            'workspace_id' => $workspace->id,
            'user_id' => $this->people['visitor']->id,
            'role' => WorkspaceRole::Owner,
        ]), $this->daysAgo(14));

        foreach ($this->team() as $key => [, $role]) {
            $this->saveAt(new WorkspaceMember([
                'workspace_id' => $workspace->id,
                'user_id' => $this->people[$key]->id,
                'role' => $role,
            ]), $this->daysAgo(13));

            $this->logActivity(
                new WorkspaceMemberInvited($workspace, $this->people['visitor'], $this->people[$key], $role),
                $this->daysAgo(13),
            );
        }

        foreach ($this->projects() as $projectData) {
            $this->buildProject($workspace, $projectData);
        }

        foreach ($this->comments() as $commentData) {
            $this->buildComment($commentData);
        }

        return $this->people['visitor'];
    }

    /**
     * @param  array{name: string, created_by: string, days_ago: int, members: list<string>, tasks: list<array<string, mixed>>}  $data
     */
    private function buildProject(Workspace $workspace, array $data): void
    {
        $createdAt = $this->daysAgo($data['days_ago']);
        $creator = $this->people[$data['created_by']];

        $project = $this->saveAt(new Project([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
        ]), $createdAt);

        foreach ($data['members'] as $key) {
            $this->saveAt(new ProjectMember([
                'project_id' => $project->id,
                'user_id' => $this->people[$key]->id,
            ]), $createdAt);
        }

        $this->logActivity(new ProjectCreated($project, $creator), $createdAt);

        if ($project->name === self::LANDING_PROJECT) {
            $this->landingProject = $project;
        }

        // Positions count from 0 within each column, in the order listed.
        $positions = [];

        foreach ($data['tasks'] as $taskData) {
            /** @var TaskStatus $status */
            $status = $taskData['status'];
            $position = $positions[$status->value] = ($positions[$status->value] ?? -1) + 1;

            $this->buildTask($project, $taskData, $position);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildTask(Project $project, array $data, int $position): void
    {
        $createdAt = $this->daysAgo($data['days_ago']);
        $creator = $this->people[$data['created_by']];
        $assignee = $data['assignee'] === null ? null : $this->people[$data['assignee']];
        /** @var TaskStatus $status */
        $status = $data['status'];

        $task = $this->saveAt(new Task([
            'project_id' => $project->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => $status,
            'priority' => $data['priority'],
            'assignee_id' => $assignee?->id,
            'created_by' => $creator->id,
            'due_date' => $data['due_in_days'] === null ? null : $this->now->addDays($data['due_in_days'])->toDateString(),
            'position' => $position,
        ]), $createdAt);
        $this->tasks[$task->title] = $task;

        $this->logActivity(new TaskCreated($task, $project, $creator), $createdAt);

        // One move into its current column, by whoever is working on it, halfway
        // between its creation and now.
        $previousStatus = match ($status) {
            TaskStatus::Todo => null,
            TaskStatus::InProgress => TaskStatus::Todo,
            TaskStatus::Review => TaskStatus::InProgress,
            TaskStatus::Done => TaskStatus::Review,
        };

        if ($previousStatus !== null) {
            $movedAt = $createdAt->addSeconds(intdiv($this->now->getTimestamp() - $createdAt->getTimestamp(), 2));
            $this->logActivity(new TaskMoved($task, $project, $assignee ?? $creator, $previousStatus, $status), $movedAt);
        }

        if ($assignee?->is($this->people['visitor']) && ! $creator->is($assignee)) {
            $this->notifyAt($assignee, new TaskAssignedNotification($task, $project, $creator), $createdAt);
        }
    }

    /**
     * @param  array{task: string, author: string, hours_ago: int, body: string}  $data
     */
    private function buildComment(array $data): void
    {
        $task = $this->tasks[$data['task']];
        $project = $task->project;
        $author = $this->people[$data['author']];
        $createdAt = $this->now->subHours($data['hours_ago']);

        $comment = $this->saveAt(new TaskComment([
            'task_id' => $task->id,
            'user_id' => $author->id,
            'body' => $data['body'],
        ]), $createdAt);

        $this->logActivity(new TaskCommentAdded($comment, $task, $project, $author), $createdAt);

        // Same "@Full Name" convention as NotifyMentionedUsers.
        $visitor = $this->people['visitor'];
        if (! $author->is($visitor) && str_contains($comment->body, '@'.$visitor->name)) {
            $this->notifyAt($visitor, new TaskMentionedNotification($comment, $task, $project, $author), $createdAt);
        }
    }

    private function demoUser(string $name, string $handle, string $token, string $passwordHash, CarbonImmutable $expiresAt): User
    {
        $user = new User([
            'name' => $name,
            'email' => "{$handle}-{$token}@".config('demo.email_domain'),
            'password' => $passwordHash,
        ]);
        $user->demo_expires_at = $expiresAt;
        $user->save();

        return $user;
    }

    /**
     * Saves a new model as if it had been created at the given time.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function saveAt(Model $model, CarbonImmutable $at): Model
    {
        $model->created_at = $at;
        $model->updated_at = $at;
        $model->save();

        return $model;
    }

    private function logActivity(ActivityLoggable $event, CarbonImmutable $at): void
    {
        $this->saveAt(new Activity($event->toActivityLog()), $at);
    }

    /** Database channel only: stored like a real notification, but neither broadcast nor queued. */
    private function notifyAt(User $user, Notification $notification, CarbonImmutable $at): void
    {
        // Set beforehand: the sender stores a copy, so this is the only way to know the row's ID.
        $notification->id = (string) Str::uuid();
        $user->notifyNow($notification, ['database']);

        DatabaseNotification::query()->whereKey($notification->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function daysAgo(int $days): CarbonImmutable
    {
        return $this->now->subDays($days);
    }

    /**
     * The fictional teammates: key => [name, workspace role].
     *
     * @return array<string, array{string, WorkspaceRole}>
     */
    private function team(): array
    {
        return [
            'maya' => ['Maya Chen', WorkspaceRole::Admin],
            'sam' => ['Sam Rivera', WorkspaceRole::Member],
            'priya' => ['Priya Patel', WorkspaceRole::Member],
        ];
    }

    /**
     * Sam is not on "Website Redesign" and Priya is not on "Customer Onboarding",
     * so project-level permissions are visible (Maya, an admin, sees every project).
     *
     * @return list<array{name: string, created_by: string, days_ago: int, members: list<string>, tasks: list<array<string, mixed>>}>
     */
    private function projects(): array
    {
        $task = fn (string $title, string $description, TaskStatus $status, TaskPriority $priority, ?string $assignee, ?int $dueInDays, string $createdBy, int $daysAgo): array => [
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'assignee' => $assignee,
            'due_in_days' => $dueInDays,
            'created_by' => $createdBy,
            'days_ago' => $daysAgo,
        ];

        return [
            [
                'name' => 'Mobile App Launch',
                'created_by' => 'visitor',
                'days_ago' => 13,
                'members' => ['visitor', 'maya', 'sam', 'priya'],
                'tasks' => [
                    $task('Define the MVP scope with stakeholders', 'Agree on the must-have features for the first release and park the rest for v1.1.', TaskStatus::Done, TaskPriority::High, 'maya', -10, 'visitor', 12),
                    $task('Set up CI for iOS and Android builds', 'Build and test both apps on every pull request.', TaskStatus::Done, TaskPriority::Medium, 'sam', -6, 'maya', 11),
                    $task('Design the onboarding screens', 'Three short screens: welcome, permissions, first board.', TaskStatus::Review, TaskPriority::High, 'priya', 1, 'maya', 9),
                    $task('Push notification permission flow', 'Ask for permission after the first task is created, not on launch.', TaskStatus::Review, TaskPriority::Medium, 'sam', 2, 'visitor', 8),
                    $task('Build the task board screen', 'Columns, drag and drop between them, and a quick-add field at the top.', TaskStatus::InProgress, TaskPriority::Urgent, 'visitor', 3, 'maya', 6),
                    $task('Offline sync for draft tasks', 'Keep drafts on the device and sync them when the connection is back.', TaskStatus::InProgress, TaskPriority::High, 'sam', 5, 'visitor', 5),
                    $task('Fix login timeout on slow networks', 'The first request sometimes times out on 3G before the session is ready.', TaskStatus::Todo, TaskPriority::Urgent, 'sam', -1, 'maya', 4),
                    $task('App Store screenshots and listing copy', 'Five screenshots per platform and a short description.', TaskStatus::Todo, TaskPriority::Medium, 'priya', 9, 'visitor', 3),
                    $task('Crash reporting and analytics', 'Pick a crash reporter and decide which events we track at launch.', TaskStatus::Todo, TaskPriority::Medium, null, 12, 'visitor', 2),
                ],
            ],
            [
                'name' => 'Website Redesign',
                'created_by' => 'maya',
                'days_ago' => 12,
                'members' => ['visitor', 'maya', 'priya'],
                'tasks' => [
                    $task('Audit the current site analytics', 'Find the pages people actually visit before redesigning anything.', TaskStatus::Done, TaskPriority::Medium, 'maya', -7, 'maya', 11),
                    $task('Accessibility pass on all forms', 'Labels, focus order and contrast on the sign-up and contact forms.', TaskStatus::Review, TaskPriority::High, 'visitor', 2, 'maya', 7),
                    $task('New homepage hero and pricing section', 'Shorter headline, one clear call to action, a pricing table.', TaskStatus::InProgress, TaskPriority::High, 'priya', 4, 'visitor', 6),
                    $task('Migrate blog posts to the new CMS', 'Around 40 posts; keep the publication dates.', TaskStatus::Todo, TaskPriority::Low, null, 15, 'maya', 4),
                    $task('Redirect the old URLs', 'Permanent redirects so search results and old links keep working.', TaskStatus::Todo, TaskPriority::Medium, 'maya', null, 'visitor', 3),
                ],
            ],
            [
                'name' => 'Customer Onboarding',
                'created_by' => 'visitor',
                'days_ago' => 10,
                'members' => ['visitor', 'sam'],
                'tasks' => [
                    $task('Interview five recent customers', 'What almost made them give up during the first week?', TaskStatus::Done, TaskPriority::High, 'visitor', -4, 'visitor', 9),
                    $task('Write the welcome email sequence', 'Three emails over the first week, each with one tip.', TaskStatus::InProgress, TaskPriority::Medium, 'visitor', 6, 'visitor', 5),
                    $task('Record a two-minute product walkthrough', 'Screen recording with voice-over; no music.', TaskStatus::Todo, TaskPriority::Low, 'sam', 10, 'visitor', 3),
                    $task('Create an onboarding checklist template', 'A reusable checklist new teams can copy into their first project.', TaskStatus::Todo, TaskPriority::Medium, null, null, 'sam', 2),
                ],
            ],
        ];
    }

    /**
     * @return list<array{task: string, author: string, hours_ago: int, body: string}>
     */
    private function comments(): array
    {
        return [
            ['task' => 'Design the onboarding screens', 'author' => 'maya', 'hours_ago' => 30, 'body' => 'Love the illustrations. Can we drop step 3? It repeats the permissions screen.'],
            ['task' => 'Design the onboarding screens', 'author' => 'priya', 'hours_ago' => 26, 'body' => 'Good call, merged steps 3 and 4. The updated mockups are attached to the design file.'],
            ['task' => 'Accessibility pass on all forms', 'author' => 'priya', 'hours_ago' => 22, 'body' => 'The placeholder text fails the contrast check in dark mode; noted in the checklist.'],
            ['task' => 'Build the task board screen', 'author' => 'maya', 'hours_ago' => 20, 'body' => 'Assigning this one to you: it is the first screen people see after onboarding.'],
            ['task' => 'Fix login timeout on slow networks', 'author' => 'sam', 'hours_ago' => 5, 'body' => 'Reproduced on a throttled 3G profile: the token refresh races the first request. The fix is small.'],
            ['task' => 'Build the task board screen', 'author' => 'sam', 'hours_ago' => 3, 'body' => '@'.self::VISITOR_NAME.' drag and drop works on my test phone now. Can you take a look before I open the pull request?'],
        ];
    }
}
