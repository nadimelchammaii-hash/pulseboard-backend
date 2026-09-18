<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TaskMentionedNotification extends Notification
{
    public function __construct(
        public readonly TaskComment $comment,
        public readonly Task $task,
        public readonly Project $project,
        public readonly User $mentioner,
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
            'category' => 'mention',
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'mentioner_name' => $this->mentioner->name,
            'comment_excerpt' => Str::limit($this->comment->body, 140),
        ];
    }
}
