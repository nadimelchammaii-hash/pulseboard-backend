<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Models\User;
use App\Models\Workspace;

class WorkspaceMemberRemoved implements ActivityLoggable
{
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
}
