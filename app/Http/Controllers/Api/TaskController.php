<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskMoved;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\MoveTaskRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function index(Workspace $workspace, Project $project)
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $project->load('members');

        $this->authorize('viewAny', [Task::class, $project]);

        $tasks = $project->tasks()
            ->with(['assignee', 'creator'])
            ->withCount('comments')
            ->orderBy('status')
            ->orderBy('position')
            ->get();

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request, Workspace $workspace, Project $project): JsonResponse
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $project->load('members');

        $this->authorize('create', [Task::class, $project]);

        $status = $request->validated('status')
            ? TaskStatus::from($request->validated('status'))
            : TaskStatus::Todo;

        $assigneeId = $request->validated('assignee_id');

        if ($assigneeId) {
            $this->ensureAssigneeIsProjectMember($project, (int) $assigneeId);
        }

        $task = DB::transaction(function () use ($request, $project, $status, $assigneeId) {
            $position = $project->tasks()->where('status', $status)->count();

            return $project->tasks()->create([
                'title' => $request->validated('title'),
                'description' => $request->validated('description'),
                'status' => $status,
                'priority' => $request->validated('priority') ?? 'medium',
                'assignee_id' => $assigneeId,
                'due_date' => $request->validated('due_date'),
                'position' => $position,
                'created_by' => $request->user()->id,
            ]);
        });

        event(new TaskCreated($task, $project, $request->user()));

        return TaskResource::make($task->load(['assignee', 'creator']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Workspace $workspace, Project $project, Task $task): TaskResource
    {
        $this->authorizeTaskScope($workspace, $project, $task);

        $this->authorize('view', $task);

        return TaskResource::make($task->load(['assignee', 'creator'])->loadCount('comments'));
    }

    public function update(UpdateTaskRequest $request, Workspace $workspace, Project $project, Task $task): TaskResource
    {
        $this->authorizeTaskScope($workspace, $project, $task);

        $this->authorize('update', $task);

        if ($request->has('assignee_id') && $request->validated('assignee_id')) {
            $this->ensureAssigneeIsProjectMember($project, (int) $request->validated('assignee_id'));
        }

        $task->update([
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'priority' => $request->validated('priority') ?? $task->priority,
            'assignee_id' => $request->validated('assignee_id'),
            'due_date' => $request->validated('due_date'),
        ]);

        return TaskResource::make($task->fresh(['assignee', 'creator']));
    }

    public function move(MoveTaskRequest $request, Workspace $workspace, Project $project, Task $task): TaskResource
    {
        $this->authorizeTaskScope($workspace, $project, $task);

        $this->authorize('update', $task);

        $newStatus = TaskStatus::from($request->validated('status'));
        $newPosition = (int) $request->validated('position');
        $oldStatus = $task->status;

        DB::transaction(function () use ($task, $project, $oldStatus, $newStatus, $newPosition) {
            if ($oldStatus !== $newStatus) {
                $project->tasks()
                    ->where('status', $oldStatus)
                    ->where('position', '>', $task->position)
                    ->decrement('position');
            }

            $siblings = $project->tasks()
                ->where('status', $newStatus)
                ->where('id', '!=', $task->id)
                ->orderBy('position')
                ->get();

            $clampedPosition = min($newPosition, $siblings->count());
            $siblings->splice($clampedPosition, 0, [$task]);

            foreach ($siblings->values() as $index => $sibling) {
                if ($sibling->is($task)) {
                    $task->status = $newStatus;
                    $task->position = $index;
                    $task->save();
                } else {
                    $sibling->update(['position' => $index]);
                }
            }
        });

        if ($oldStatus !== $newStatus) {
            event(new TaskMoved($task, $project, $request->user(), $oldStatus, $newStatus));
        }

        return TaskResource::make($task->fresh(['assignee', 'creator']));
    }

    public function destroy(Request $request, Workspace $workspace, Project $project, Task $task): Response
    {
        $this->authorizeTaskScope($workspace, $project, $task);

        $this->authorize('delete', $task);

        $taskId = $task->id;
        $taskTitle = $task->title;
        $task->delete();

        event(new TaskDeleted($project, $request->user(), $taskId, $taskTitle));

        return response()->noContent();
    }

    private function authorizeTaskScope(Workspace $workspace, Project $project, Task $task): void
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        abort_unless($task->project_id === $project->id, Response::HTTP_NOT_FOUND);
        $project->load('members');
    }

    private function ensureAssigneeIsProjectMember(Project $project, int $assigneeId): void
    {
        if (! $project->members->contains('user_id', $assigneeId)) {
            throw ValidationException::withMessages([
                'assignee_id' => ['The assignee must be a member of this project.'],
            ]);
        }
    }
}
