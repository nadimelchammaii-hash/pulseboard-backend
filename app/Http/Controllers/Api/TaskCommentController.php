<?php

namespace App\Http\Controllers\Api;

use App\Events\TaskCommentAdded;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskComment\StoreTaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TaskCommentController extends Controller
{
    public function index(Workspace $workspace, Project $project, Task $task)
    {
        $this->authorizeTaskScope($workspace, $project, $task);

        $this->authorize('view', $task);

        return TaskCommentResource::collection(
            $task->comments()->with('user')->orderBy('created_at')->get()
        );
    }

    public function store(StoreTaskCommentRequest $request, Workspace $workspace, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTaskScope($workspace, $project, $task);

        $this->authorize('addComment', $task);

        $comment = $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        event(new TaskCommentAdded($comment, $task, $project, $request->user()));

        return TaskCommentResource::make($comment->load('user'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Workspace $workspace, Project $project, Task $task, TaskComment $comment): Response
    {
        $this->authorizeTaskScope($workspace, $project, $task);
        abort_unless($comment->task_id === $task->id, Response::HTTP_NOT_FOUND);

        $this->authorize('deleteComment', [$task, $comment]);

        $comment->delete();

        return response()->noContent();
    }

    private function authorizeTaskScope(Workspace $workspace, Project $project, Task $task): void
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        abort_unless($task->project_id === $project->id, Response::HTTP_NOT_FOUND);
        $project->load('members');
    }
}
