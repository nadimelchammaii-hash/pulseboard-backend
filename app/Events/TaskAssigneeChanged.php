<?php

namespace App\Events;

use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class TaskAssigneeChanged implements ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(
        public readonly Task $task,
        public readonly Project $project,
        public readonly User $causer,
        public readonly User $newAssignee,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->project->id}")];
    }

    public function broadcastAs(): string
    {
        return 'task.assignee_changed';
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
