<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Demo accounts show a clean address instead of their unique stored one.
            'email' => $this->displayEmail(),
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // "Try the demo" accounts: the SPA shows a banner with the end time.
            'is_demo' => $this->isDemo(),
            'demo_expires_at' => $this->demo_expires_at?->toIso8601String(),
        ];
    }
}
