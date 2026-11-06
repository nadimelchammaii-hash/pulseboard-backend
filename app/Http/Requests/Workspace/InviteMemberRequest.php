<?php

namespace App\Http\Requests\Workspace;

use App\Enums\WorkspaceRole;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    /**
     * Validation runs before the controller's own authorization, so a demo
     * account is refused here, before the address is looked up: whatever it
     * types, it is told that invitations are turned off in the demo (the
     * message comes from WorkspacePolicy::inviteMember).
     */
    public function authorize(): Response|bool
    {
        return $this->user()->isDemo()
            ? Gate::inspect('inviteMember', $this->route('workspace'))
            : true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Demo accounts (visitors and their fictional teammates) cannot be
            // invited: they are deleted after a day, and each demo stays private.
            'email' => ['required', 'email', Rule::exists('users', 'email')->whereNull('demo_expires_at')],
            'role' => ['required', Rule::in([WorkspaceRole::Admin->value, WorkspaceRole::Member->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'email.exists' => 'No PulseBoard account exists for that email address.',
        ];
    }
}
