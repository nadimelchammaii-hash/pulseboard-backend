<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\InviteMemberRequest;
use App\Http\Requests\Workspace\UpdateMemberRoleRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceMemberController extends Controller
{
    public function index(Workspace $workspace)
    {
        $workspace->load('members');

        $this->authorize('view', $workspace);

        return WorkspaceMemberResource::collection(
            $workspace->members()->with('user')->get()
        );
    }

    public function store(InviteMemberRequest $request, Workspace $workspace): JsonResponse
    {
        $workspace->load('members');

        $this->authorize('inviteMember', $workspace);

        $invitedUser = User::where('email', $request->validated('email'))->firstOrFail();

        if ($workspace->members->contains('user_id', $invitedUser->id)) {
            throw ValidationException::withMessages([
                'email' => ['That person is already a member of this workspace.'],
            ]);
        }

        $member = $workspace->members()->create([
            'user_id' => $invitedUser->id,
            'role' => WorkspaceRole::from($request->validated('role')),
        ]);

        return WorkspaceMemberResource::make($member->load('user'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateMemberRoleRequest $request, Workspace $workspace, WorkspaceMember $member): WorkspaceMemberResource
    {
        abort_unless($member->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $workspace->load('members');

        $this->authorize('updateMemberRole', [$workspace, $member]);

        $newRole = WorkspaceRole::from($request->validated('role'));

        if ($member->role === WorkspaceRole::Owner && $newRole !== WorkspaceRole::Owner) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Transfer ownership to another member before changing the owner's role.");
        }

        if ($newRole === WorkspaceRole::Owner) {
            DB::transaction(function () use ($request, $workspace, $member) {
                $workspace->members()
                    ->where('user_id', $request->user()->id)
                    ->update(['role' => WorkspaceRole::Admin]);

                $member->update(['role' => WorkspaceRole::Owner]);
            });
        } else {
            $member->update(['role' => $newRole]);
        }

        return WorkspaceMemberResource::make($member->fresh('user'));
    }

    public function destroy(Workspace $workspace, WorkspaceMember $member): Response
    {
        abort_unless($member->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $workspace->load('members');

        $this->authorize('removeMember', [$workspace, $member]);

        $member->delete();

        return response()->noContent();
    }
}
