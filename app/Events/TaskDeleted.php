<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskDeleted implements ActivityLoggable
{
    public function __construct(
        public readonly Project $project,
        public readonly User $causer,
        public readonly int $taskId,
        public readonly string $taskTitle,
    ) {}

    public function toActivityLog(): array
    {
        return [
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'causer_id' => $this->causer->id,
            'action' => ActivityAction::TaskDeleted,
            'subject_type' => Task::class,
            'subject_id' => $this->taskId,
            'data' => [
                'task_title' => $this->taskTitle,
            ],
        ];
    }
}
