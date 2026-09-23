<?php

namespace App\Listeners;

use App\Events\TaskAssigneeChanged;
use App\Events\TaskCreated;
use App\Notifications\TaskAssignedNotification;

class NotifyTaskAssignee
{
    public function handleTaskCreated(TaskCreated $event): void
    {
        $assignee = $event->task->assignee;

        if (! $assignee || $assignee->id === $event->causer->id) {
            return;
        }

        $assignee->notify(new TaskAssignedNotification($event->task, $event->project, $event->causer));
    }

    public function handleTaskAssigneeChanged(TaskAssigneeChanged $event): void
    {
        if ($event->newAssignee->id === $event->causer->id) {
            return;
        }

        $event->newAssignee->notify(new TaskAssignedNotification($event->task, $event->project, $event->causer));
    }
}
