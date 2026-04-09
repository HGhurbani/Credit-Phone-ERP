<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => (float) $this->price,
            'interval' => $this->interval,
            'max_branches' => $this->max_branches,
            'max_users' => $this->max_users,
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
