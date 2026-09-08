<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AddProjectMemberRequest;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ProjectMemberController extends Controller
{
    public function index(Workspace $workspace, Project $project)
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $workspace->load('members');
        $project->load('members');

        $this->authorize('view', $project);

        return ProjectMemberResource::collection(
            $project->members()->with('user')->get()
        );
    }

    public function store(AddProjectMemberRequest $request, Workspace $workspace, Project $project): JsonResponse
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $workspace->load('members');
        $project->load('members');

        $this->authorize('addMember', $project);

        $targetUserId = (int) $request->validated('user_id');

        if (! $workspace->members->contains('user_id', $targetUserId)) {
            throw ValidationException::withMessages([
                'user_id' => ['That user is not a member of this workspace.'],
            ]);
        }

        if ($project->members->contains('user_id', $targetUserId)) {
            throw ValidationException::withMessages([
                'user_id' => ['That user is already a member of this project.'],
            ]);
        }

        $member = $project->members()->create(['user_id' => $targetUserId]);

        return ProjectMemberResource::make($member->load('user'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Workspace $workspace, Project $project, ProjectMember $member): Response
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        abort_unless($member->project_id === $project->id, Response::HTTP_NOT_FOUND);
        $workspace->load('members');

        $this->authorize('removeMember', [$project, $member]);

        $member->delete();

        return response()->noContent();
    }
}
