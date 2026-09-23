<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class WorkspaceMemberRoleChanged implements ActivityLoggable, ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $causer,
        public readonly User $member,
        public readonly WorkspaceRole $oldRole,
        public readonly WorkspaceRole $newRole,
    ) {}

    public function toActivityLog(): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'causer_id' => $this->causer->id,
            'action' => ActivityAction::WorkspaceMemberRoleChanged,
            'subject_type' => User::class,
            'subject_id' => $this->member->id,
            'data' => [
                'user_name' => $this->member->name,
                'old_role' => $this->oldRole->value,
                'new_role' => $this->newRole->value,
            ],
        ];
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("workspace.{$this->workspace->id}")];
    }

    public function broadcastAs(): string
    {
        return 'workspace.member_role_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => ActivityAction::WorkspaceMemberRoleChanged->value,
            'causer' => ['id' => $this->causer->id, 'name' => $this->causer->name, 'email' => $this->causer->email],
            'project' => null,
            'data' => $this->toActivityLog()['data'],
            'created_at' => now()->toISOString(),
        ];
    }
}
