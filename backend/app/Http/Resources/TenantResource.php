<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'logo' => $this->logo,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at?->toDateString(),
            'branches_count' => $this->whenCounted('branches'),
            'users_count' => $this->whenCounted('users'),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'latest_subscription' => $this->whenLoaded('latestSubscription', function () {
                if (!$this->latestSubscription) {
                    return null;
                }

                return [
                    'id' => $this->latestSubscription->id,
                    'status' => $this->latestSubscription->status,
                    'starts_at' => $this->latestSubscription->starts_at?->toDateString(),
                    'ends_at' => $this->latestSubscription->ends_at?->toDateString(),
                    'plan' => $this->latestSubscription->relationLoaded('plan') && $this->latestSubscription->plan
                        ? [
                            'id' => $this->latestSubscription->plan->id,
                            'name' => $this->latestSubscription->plan->name,
                            'interval' => $this->latestSubscription->plan->interval,
                            'price' => (float) $this->latestSubscription->plan->price,
                        ]
                        : null,
                ];
            }),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
