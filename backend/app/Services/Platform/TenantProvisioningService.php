<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantProvisioningService
{
    public function provision(array $tenantData, array $adminData = [], ?SubscriptionPlan $plan = null): Tenant
    {
        return DB::transaction(function () use ($tenantData, $adminData, $plan) {
            $tenant = Tenant::create($tenantData);

            $mainBranch = $this->createMainBranch($tenant, $tenantData['main_branch_name'] ?? null);
            $this->createCompanyAdmin($tenant, $mainBranch, $adminData);
            $this->seedDefaultSettings($tenant);

            if ($plan) {
                $this->createInitialSubscription($tenant, $plan);
            }

            return $tenant;
        });
    }

    public function ensureCompanyAdmin(Tenant $tenant): User
    {
        $admin = User::role('company_admin')
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->with(['tenant', 'branch', 'roles', 'permissions'])
            ->first();

        if ($admin) {
            return $admin;
        }

        return DB::transaction(function () use ($tenant) {
            $mainBranch = $tenant->mainBranch()->first() ?? $this->createMainBranch($tenant, null);

            return $this->createCompanyAdmin($tenant, $mainBranch, [
                'name' => $tenant->name.' Admin',
                'email' => $this->generateBootstrapAdminEmail($tenant),
                'password' => Str::password(20),
                'phone' => $tenant->phone,
            ]);
        });
    }

    private function createMainBranch(Tenant $tenant, ?string $branchName): Branch
    {
        return Branch::create([
            'tenant_id' => $tenant->id,
            'name' => $branchName ?: $tenant->name.' Main Branch',
            'code' => 'MAIN',
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'address' => $tenant->address,
            'is_main' => true,
            'is_active' => true,
        ]);
    }

    private function createCompanyAdmin(Tenant $tenant, Branch $branch, array $adminData): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => $adminData['name'],
            'email' => $adminData['email'],
            'phone' => $adminData['phone'] ?? $tenant->phone,
            'password' => Hash::make($adminData['password']),
            'is_active' => true,
            'locale' => $tenant->locale,
        ]);

        $user->assignRole('company_admin');

        return $user->load(['tenant', 'branch', 'roles', 'permissions']);
    }

    private function createInitialSubscription(Tenant $tenant, SubscriptionPlan $plan): Subscription
    {
        $isTrial = $tenant->status === 'trial';
        $startsAt = now();
        $endsAt = $isTrial
            ? ($tenant->trial_ends_at ?? now()->addDays(14))
            : match ($plan->interval) {
                'monthly' => now()->addMonth(),
                'yearly' => now()->addYear(),
                default => null,
            };

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $isTrial ? 'trial' : 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'metadata' => [
                'created_by' => 'platform_tenant_provisioning',
            ],
        ]);
    }

    private function seedDefaultSettings(Tenant $tenant): void
    {
        $defaults = [
            'installment_pricing_mode' => 'percentage',
            'installment_monthly_percent_of_cash' => '5',
            'invoice_prefix' => strtoupper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $tenant->slug), 4, '')),
            'show_logo_on_invoice' => false,
            'assistant_enabled' => false,
            'telegram_enabled' => false,
        ];

        foreach ($defaults as $key => $value) {
            Setting::updateOrCreate(
                ['tenant_id' => $tenant->id, 'key' => $key],
                [
                    'group' => SettingsCatalog::groupForKey($key),
                    'type' => SettingsCatalog::typeForKey($key),
                    'value' => $value,
                ]
            );
        }
    }

    private function generateBootstrapAdminEmail(Tenant $tenant): string
    {
        $base = 'platform+'.Str::slug($tenant->slug ?: $tenant->name).'-admin';
        $domain = 'tenant.local';
        $candidate = "{$base}@{$domain}";
        $suffix = 1;

        while (User::where('email', $candidate)->exists()) {
            $candidate = "{$base}{$suffix}@{$domain}";
            $suffix++;
        }

        return $candidate;
    }
}
