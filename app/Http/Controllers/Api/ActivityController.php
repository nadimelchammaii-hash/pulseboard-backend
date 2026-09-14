<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $workspace->load('members');

        $this->authorize('view', $workspace);

        $query = $workspace->activities()
            ->with(['causer', 'project'])
            ->latest();

        if (! in_array($workspace->roleFor($request->user()), [WorkspaceRole::Owner, WorkspaceRole::Admin], true)) {
            $accessibleProjectIds = $workspace->projects()
                ->whereHas('members', fn ($members) => $members->where('user_id', $request->user()->id))
                ->pluck('id');

            $query->where(function ($q) use ($accessibleProjectIds) {
                $q->whereNull('project_id')->orWhereIn('project_id', $accessibleProjectIds);
            });
        }

        return ActivityResource::collection($query->paginate(20));
    }
}
