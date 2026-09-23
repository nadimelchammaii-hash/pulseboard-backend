<?php

namespace App\Events;

use App\Contracts\ActivityLoggable;
use App\Enums\ActivityAction;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class TaskCreated implements ActivityLoggable, ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(
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
            'action' => ActivityAction::TaskCreated,
            'subject_type' => Task::class,
            'subject_id' => $this->task->id,
            'data' => [
                'task_title' => $this->task->title,
            ],
        ];
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->project->id}")];
    }

    public function broadcastAs(): string
    {
        return 'task.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task' => TaskResource::make($this->task->load(['assignee', 'creator']))->resolve(),
        ];
    }
}
