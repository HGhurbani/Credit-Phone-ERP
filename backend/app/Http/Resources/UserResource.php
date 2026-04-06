<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'avatar' => $this->avatar,
            'is_super_admin' => $this->is_super_admin,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toDateTimeString(),
            'tenant_id' => $this->tenant_id,
            'branch_id' => $this->branch_id,
            'tenant' => $this->whenLoaded('tenant', fn() => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'currency' => $this->tenant->currency,
                'locale' => $this->tenant->locale,
                'logo' => $this->tenant->logo,
            ]),
            'branch' => $this->whenLoaded('branch', fn() => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'roles' => $this->whenLoaded('roles', fn() => $this->roles->pluck('name')->values()),
            'permissions' => $this->when(
                $this->relationLoaded('roles') || $this->relationLoaded('permissions'),
                fn() => $this->getAllPermissions()->pluck('name')->values()
            ),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
