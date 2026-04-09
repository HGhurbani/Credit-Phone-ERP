<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TenantSubscriptionService
{
    public function currentForTenant(int $tenantId): ?Subscription
    {
        $now = now();

        return Subscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trial'])
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    public function assertCanCreateUser(int $tenantId): void
    {
        $subscription = $this->currentForTenant($tenantId);
        $plan = $subscription?->plan;

        if (! $plan) {
            return;
        }

        $count = User::where('tenant_id', $tenantId)->count();

        if ($count >= $plan->max_users) {
            throw ValidationException::withMessages([
                'tenant' => ["Current subscription allows up to {$plan->max_users} users only."],
            ]);
        }
    }

    public function assertCanCreateBranch(int $tenantId): void
    {
        $subscription = $this->currentForTenant($tenantId);
        $plan = $subscription?->plan;

        if (! $plan) {
            return;
        }

        $count = Branch::where('tenant_id', $tenantId)->count();

        if ($count >= $plan->max_branches) {
            throw ValidationException::withMessages([
                'tenant' => ["Current subscription allows up to {$plan->max_branches} branches only."],
            ]);
        }
    }

    public function tenantAccessMessage(?User $user): ?string
    {
        if (! $user || $user->isSuperAdmin()) {
            return null;
        }

        $tenant = $user->tenant;

        if (! $tenant) {
            return 'No tenant associated with this account.';
        }

        if ($tenant->status === 'inactive') {
            return 'Tenant account is inactive.';
        }

        if ($tenant->status === 'suspended') {
            return 'Tenant account is suspended.';
        }

        if ($tenant->status === 'trial' && $tenant->trial_ends_at && $tenant->trial_ends_at->isPast()) {
            return 'Tenant trial has expired.';
        }

        $subscription = $this->currentForTenant($tenant->id);

        if ($tenant->subscriptions()->exists() && ! $subscription) {
            return 'Tenant subscription is inactive or expired.';
        }

        return null;
    }
}
