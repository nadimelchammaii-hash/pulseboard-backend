<?php

namespace App\Notifications;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $inviter,
        public readonly WorkspaceRole $role,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'system',
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'inviter_name' => $this->inviter->name,
            'role' => $this->role->value,
        ];
    }
}
