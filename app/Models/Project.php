<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'slug'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Whether the user can access this project at all: an explicit project
     * member, or a workspace owner/admin (who retain administrative
     * visibility even without being an explicit project member).
     */
    public function isAccessibleBy(User $user): bool
    {
        if (in_array($this->workspace->roleFor($user), [WorkspaceRole::Owner, WorkspaceRole::Admin], true)) {
            return true;
        }

        return $this->members->contains('user_id', $user->id);
    }
}
