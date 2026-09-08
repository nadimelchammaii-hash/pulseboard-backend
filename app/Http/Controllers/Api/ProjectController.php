<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function index(Request $request, Workspace $workspace)
    {
        $workspace->load('members');

        $this->authorize('view', $workspace);

        $query = $workspace->projects()->with('members');

        if (! in_array($workspace->roleFor($request->user()), [WorkspaceRole::Owner, WorkspaceRole::Admin], true)) {
            $query->whereHas('members', fn ($members) => $members->where('user_id', $request->user()->id));
        }

        return ProjectResource::collection($query->get());
    }

    public function store(StoreProjectRequest $request, Workspace $workspace): JsonResponse
    {
        $workspace->load('members');

        $this->authorize('create', [Project::class, $workspace]);

        $project = DB::transaction(function () use ($request, $workspace) {
            $project = $workspace->projects()->create([
                'name' => $request->validated('name'),
                'slug' => $this->uniqueSlug($workspace, $request->validated('name')),
            ]);

            $project->members()->create(['user_id' => $request->user()->id]);

            return $project;
        });

        return ProjectResource::make($project->load('members'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Workspace $workspace, Project $project): ProjectResource
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $project->load('members');

        $this->authorize('view', $project);

        return ProjectResource::make($project);
    }

    public function update(UpdateProjectRequest $request, Workspace $workspace, Project $project): ProjectResource
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $project->load('members');

        $this->authorize('update', $project);

        $project->update($request->validated());

        return ProjectResource::make($project);
    }

    public function destroy(Workspace $workspace, Project $project): Response
    {
        abort_unless($project->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $project->load('members');

        $this->authorize('delete', $project);

        $project->delete();

        return response()->noContent();
    }

    private function uniqueSlug(Workspace $workspace, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while ($workspace->projects()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
