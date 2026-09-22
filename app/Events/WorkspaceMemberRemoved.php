<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class WorkspaceMemberRemoved implements ActivityLoggable, ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $causer,
        public readonly User $removedUser,
    ) {}

    public function toActivityLog(): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'causer_id' => $this->causer->id,
            'action' => ActivityAction::WorkspaceMemberRemoved,
            'subject_type' => User::class,
            'subject_id' => $this->removedUser->id,
            'data' => [
                'user_name' => $this->removedUser->name,
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
        return 'workspace.member_removed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => ActivityAction::WorkspaceMemberRemoved->value,
            'causer' => ['id' => $this->causer->id, 'name' => $this->causer->name, 'email' => $this->causer->email],
            'project' => null,
            'data' => $this->toActivityLog()['data'],
            'created_at' => now()->toISOString(),
        ];
    }
}
