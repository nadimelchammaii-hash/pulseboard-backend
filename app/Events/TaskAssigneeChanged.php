<?php

namespace App\Events;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskAssigneeChanged
{
    public function __construct(
        public readonly Task $task,
        public readonly Project $project,
        public readonly User $causer,
        public readonly User $newAssignee,
    ) {}
}
