<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Str;

class TaskCommentAdded implements ActivityLoggable
{
    public function __construct(
        public readonly TaskComment $comment,
        public readonly Task $task,
        public readonly Project $project,
        public readonly User $causer,
    ) {}

    public function toActivityLog(): array
    {
        return [
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'causer_id' => $this->causer->id,
            'action' => ActivityAction::TaskCommentAdded,
            'subject_type' => TaskComment::class,
            'subject_id' => $this->comment->id,
            'data' => [
                'task_id' => $this->task->id,
                'task_title' => $this->task->title,
                'comment_excerpt' => Str::limit($this->comment->body, 140),
            ],
        ];
    }
}
