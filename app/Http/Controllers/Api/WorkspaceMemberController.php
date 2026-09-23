<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Events\WorkspaceMemberInvited;
use App\Events\WorkspaceMemberRemoved;
use App\Events\WorkspaceMemberRoleChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\InviteMemberRequest;
use App\Http\Requests\Workspace\UpdateMemberRoleRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceMemberController extends Controller
{
    public function index(Workspace $workspace)
    {
        $workspace->load('members');

        $this->authorize('view', $workspace);

        // The member list is read on nearly every workspace page but only changes on
        // invite/role-change/removal, so it's cached and explicitly invalidated below
        // rather than re-querying the members+users join on every request.
        $members = Cache::remember(
            self::membersCacheKey($workspace),
            now()->addMinutes(10),
            fn () => $workspace->members()->with('user')->get()
        );

        return WorkspaceMemberResource::collection($members);
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

        $role = WorkspaceRole::from($request->validated('role'));

        $member = $workspace->members()->create([
            'user_id' => $invitedUser->id,
            'role' => $role,
        ]);

        Cache::forget(self::membersCacheKey($workspace));

        event(new WorkspaceMemberInvited($workspace, $request->user(), $invitedUser, $role));

        return WorkspaceMemberResource::make($member->load('user'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateMemberRoleRequest $request, Workspace $workspace, WorkspaceMember $member): WorkspaceMemberResource
    {
        abort_unless($member->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $workspace->load('members');

        $this->authorize('updateMemberRole', [$workspace, $member]);

        $oldRole = $member->role;
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

        Cache::forget(self::membersCacheKey($workspace));

        if ($oldRole !== $newRole) {
            event(new WorkspaceMemberRoleChanged($workspace, $request->user(), $member->user, $oldRole, $newRole));
        }

        return WorkspaceMemberResource::make($member->fresh('user'));
    }

    public function destroy(Request $request, Workspace $workspace, WorkspaceMember $member): Response
    {
        abort_unless($member->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
        $workspace->load('members');

        $this->authorize('removeMember', [$workspace, $member]);

        $removedUser = $member->user;
        $member->delete();

        Cache::forget(self::membersCacheKey($workspace));

        event(new WorkspaceMemberRemoved($workspace, $request->user(), $removedUser));

        return response()->noContent();
    }

    private static function membersCacheKey(Workspace $workspace): string
    {
        return "workspace:{$workspace->id}:members";
    }
}
