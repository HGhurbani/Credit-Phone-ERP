<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'is_main' => $this->is_main,
            'is_active' => $this->is_active,
            'users_count' => $this->users_count ?? null,
            'orders_count' => $this->orders_count ?? null,
            'contracts_count' => $this->contracts_count ?? null,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
