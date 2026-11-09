<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'demo_expires_at' => 'datetime',
        ];
    }

    /**
     * A temporary "Try the demo" account: the visitor or one of their fictional
     * teammates. Deleted by demo:prune once demo_expires_at has passed.
     */
    public function isDemo(): bool
    {
        return $this->demo_expires_at !== null;
    }

    /**
     * The address shown in the app. A demo account's stored address carries a
     * unique token ("sam-01m6...@demo.pulseboardapp.invalid"); it is shown as
     * "sam@lumenlabs.demo". Other accounts show their real address.
     */
    public function displayEmail(): string
    {
        if (! $this->isDemo()) {
            return $this->email;
        }

        $handle = Str::before(Str::before($this->email, '@'), '-');

        return $handle.'@'.config('demo.display_email_domain');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function workspaceMembers(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }
}
