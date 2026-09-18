<?php

namespace App\Listeners;

use App\Events\TaskCommentAdded;
use App\Notifications\TaskMentionedNotification;

class NotifyMentionedUsers
{
    public function handle(TaskCommentAdded $event): void
    {
        $body = $event->comment->body;

        foreach ($event->project->members()->with('user')->get() as $member) {
            $user = $member->user;

            if ($user->id === $event->causer->id) {
                continue;
            }

            if (! str_contains($body, '@'.$user->name)) {
                continue;
            }

            $user->notify(new TaskMentionedNotification($event->comment, $event->task, $event->project, $event->causer));
        }
    }
}
