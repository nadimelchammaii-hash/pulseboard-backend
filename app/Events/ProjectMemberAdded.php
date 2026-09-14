<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Models\Project;
use App\Models\User;

class ProjectMemberAdded implements ActivityLoggable
{
    public function __construct(
        public readonly Project $project,
        public readonly User $causer,
        public readonly User $addedUser,
    ) {}

    public function toActivityLog(): array
    {
        return [
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'causer_id' => $this->causer->id,
            'action' => ActivityAction::ProjectMemberAdded,
            'subject_type' => User::class,
            'subject_id' => $this->addedUser->id,
            'data' => [
                'user_name' => $this->addedUser->name,
                'project_name' => $this->project->name,
            ],
        ];
    }
}
