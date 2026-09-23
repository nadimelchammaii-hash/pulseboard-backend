<?php

namespace App\Enums;

enum ActivityAction: string
{
    case WorkspaceMemberInvited = 'workspace.member_invited';
    case WorkspaceMemberRemoved = 'workspace.member_removed';
    case WorkspaceMemberRoleChanged = 'workspace.member_role_changed';
    case ProjectCreated = 'project.created';
    case ProjectMemberAdded = 'project.member_added';
    case ProjectMemberRemoved = 'project.member_removed';
    case TaskCreated = 'task.created';
    case TaskMoved = 'task.moved';
    case TaskDeleted = 'task.deleted';
    case TaskCommentAdded = 'task_comment.added';
}
