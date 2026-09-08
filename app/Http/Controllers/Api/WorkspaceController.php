<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $workspaces = $request->user()->workspaces()->with('members')->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $this->authorize('create', Workspace::class);

        $workspace = DB::transaction(function () use ($request) {
            $workspace = Workspace::create([
                'name' => $request->validated('name'),
                'slug' => $this->uniqueSlug($request->validated('name')),
            ]);

            $workspace->members()->create([
                'user_id' => $request->user()->id,
                'role' => WorkspaceRole::Owner,
            ]);

            return $workspace;
        });

        return WorkspaceResource::make($workspace->load('members'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Workspace $workspace): WorkspaceResource
    {
        $workspace->load('members');

        $this->authorize('view', $workspace);

        return WorkspaceResource::make($workspace);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        $workspace->load('members');

        $this->authorize('update', $workspace);

        $workspace->update($request->validated());

        return WorkspaceResource::make($workspace);
    }

    public function destroy(Workspace $workspace): Response
    {
        $workspace->load('members');

        $this->authorize('delete', $workspace);

        $workspace->delete();

        return response()->noContent();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Workspace::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
