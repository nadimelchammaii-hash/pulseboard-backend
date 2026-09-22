<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('workspace.{workspaceId}', function (User $user, int $workspaceId) {
    $workspace = Workspace::find($workspaceId);

    if (! $workspace) {
        return false;
    }

    $workspace->load('members');

    return $workspace->roleFor($user) !== null;
});

Broadcast::channel('project.{projectId}', function (User $user, int $projectId) {
    $project = Project::with('members')->find($projectId);

    if (! $project) {
        return false;
    }

    return $project->isAccessibleBy($user);
});
