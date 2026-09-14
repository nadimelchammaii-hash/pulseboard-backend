<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskMoved implements ActivityLoggable
{
    public function __construct(
        public readonly Task $task,
        public readonly Project $project,
        public readonly User $causer,
        public readonly TaskStatus $fromStatus,
        public readonly TaskStatus $toStatus,
    ) {}

    public function toActivityLog(): array
    {
        return [
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'causer_id' => $this->causer->id,
            'action' => ActivityAction::TaskMoved,
            'subject_type' => Task::class,
            'subject_id' => $this->task->id,
            'data' => [
                'task_title' => $this->task->title,
                'from_status' => $this->fromStatus->value,
                'to_status' => $this->toStatus->value,
            ],
        ];
    }
}
