<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Models\Project;
use App\Models\User;

class ProjectCreated implements ActivityLoggable
{
    public function __construct(
        public readonly Project $project,
        public readonly User $causer,
    ) {}

    public function toActivityLog(): array
    {
        return [
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'causer_id' => $this->causer->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => Project::class,
            'subject_id' => $this->project->id,
            'data' => [
                'project_name' => $this->project->name,
            ],
        ];
    }
}
