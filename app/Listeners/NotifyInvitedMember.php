<?php

namespace App\Listeners;

use App\Events\WorkspaceMemberInvited;
use App\Notifications\WorkspaceInvitationNotification;

class NotifyInvitedMember
{
    public function handle(WorkspaceMemberInvited $event): void
    {
        $event->invitedUser->notify(new WorkspaceInvitationNotification($event->workspace, $event->causer, $event->role));
    }
}
