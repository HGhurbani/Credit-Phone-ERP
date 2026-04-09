<?php

namespace App\Services\Assistant;

use App\Models\User;

class AssistantPolicyService
{
    public function denialReason(User $user, array $plan): ?string
    {
        $module = $plan['module'] ?? 'unsupported';
        $operation = $plan['operation'] ?? 'unsupported';

        if ($module === 'unsupported' || $operation === 'unsupported') {
            return $this->message($user, 'هذه الوحدة غير مدعومة في الإصدار الحالي من الوكيل.', 'This module is not supported in the current assistant release.');
        }

        $permission = $this->permissionForPlan($plan);
        if ($permission === null) {
            return $this->message($user, 'الطلب غير مسموح ضمن سياسات الوكيل الحالية.', 'This request is not allowed by the assistant policies.');
        }

        if (! $user->hasPermissionTo($permission)) {
            return $this->message($user, 'ليست لديك الصلاحية اللازمة لتنفيذ هذا الطلب.', 'You do not have permission to perform this request.');
        }

        return null;
    }

    private function permissionForPlan(array $plan): ?string
    {
        $module = (string) ($plan['module'] ?? 'unsupported');
        $operation = (string) ($plan['operation'] ?? 'unsupported');
        $arguments = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];

        return match ($module) {
            'collections' => $this->collectionsPermissionFor($operation, $arguments),
            'platform' => $this->platformPermissionFor($operation, $arguments),
            default => $this->permissionFor($module, $operation),
        };
    }

    public function permissionFor(string $module, string $operation): ?string
    {
        return match ($module) {
            'customers' => match ($operation) {
                'query' => 'customers.view',
                'create' => 'customers.create',
                'update' => 'customers.update',
                'delete' => 'customers.delete',
                'print' => 'customers.view',
                default => null,
            },
            'products' => match ($operation) {
                'query' => 'products.view',
                'create' => 'products.create',
                'update' => 'products.update',
                'delete' => 'products.delete',
                'print' => 'products.view',
                default => null,
            },
            'categories' => match ($operation) {
                'query' => 'categories.view',
                'create' => 'categories.create',
                'update' => 'categories.update',
                'delete' => 'categories.delete',
                default => null,
            },
            'brands' => match ($operation) {
                'query' => 'brands.view',
                'create' => 'brands.create',
                'update' => 'brands.update',
                'delete' => 'brands.delete',
                default => null,
            },
            'stock' => match ($operation) {
                'query' => 'products.view',
                'update' => 'products.stock.adjust',
                default => null,
            },
            'orders' => match ($operation) {
                'query' => 'orders.view',
                'create' => 'orders.create',
                'update' => 'orders.update',
                'delete' => 'orders.delete',
                'print' => 'orders.view',
                default => null,
            },
            'users' => match ($operation) {
                'query' => 'users.view',
                'create' => 'users.create',
                'update' => 'users.update',
                'delete' => 'users.delete',
                'print' => 'users.view',
                default => null,
            },
            'branches' => match ($operation) {
                'query' => 'branches.view',
                'create' => 'branches.create',
                'update' => 'branches.update',
                'delete' => 'branches.delete',
                'print' => 'branches.view',
                default => null,
            },
            'suppliers' => match ($operation) {
                'query' => 'suppliers.view',
                'create' => 'suppliers.create',
                'update' => 'suppliers.update',
                'delete' => 'suppliers.delete',
                'print' => 'suppliers.view',
                default => null,
            },
            'purchases' => match ($operation) {
                'query' => 'purchases.view',
                'create' => 'purchases.create',
                'update' => 'purchases.update',
                'delete' => 'purchases.delete',
                'print' => 'purchases.view',
                default => null,
            },
            'contracts' => match ($operation) {
                'query' => 'contracts.view',
                'create' => 'contracts.create',
                'print' => 'contracts.view',
                default => null,
            },
            'payments' => match ($operation) {
                'query' => 'payments.view',
                'create' => 'payments.create',
                'print' => 'payments.view',
                default => null,
            },
            'invoices' => match ($operation) {
                'query' => 'invoices.view',
                'update' => 'invoices.update',
                'create' => 'invoices.record_payment',
                'print' => 'invoices.view',
                default => null,
            },
            'cashboxes' => match ($operation) {
                'query' => 'cashboxes.view',
                'create' => 'cashboxes.manage',
                'update' => 'cashboxes.manage',
                'delete' => 'cashboxes.manage',
                'print' => 'cashboxes.view',
                default => null,
            },
            'cash_transactions' => match ($operation) {
                'query' => 'cash_transactions.view',
                'print' => 'cash_transactions.view',
                default => null,
            },
            'expenses' => match ($operation) {
                'query' => 'expenses.view',
                'create' => 'expenses.create',
                'update' => 'expenses.update',
                'delete' => 'expenses.delete',
                'print' => 'expenses.view',
                default => null,
            },
            'reports' => $operation === 'run' ? 'reports.view' : null,
            'database' => $operation === 'query' ? 'assistant.database.query' : null,
            'settings' => match ($operation) {
                'query' => 'settings.view',
                'update' => 'settings.update',
                default => null,
            },
            default => null,
        };
    }

    private function collectionsPermissionFor(string $operation, array $arguments): ?string
    {
        $type = strtolower((string) ($arguments['collection_type'] ?? $arguments['collection_action'] ?? ''));

        return match ($operation) {
            'query' => match ($type) {
                'statement' => 'customer_statement.view',
                'due_today', 'overdue', 'copilot' => 'payments.collections',
                'follow_ups', 'promises_to_pay', 'reschedule_requests', '' => 'collections.followup.view',
                default => 'collections.followup.view',
            },
            'create' => 'collections.followup.create',
            'run' => 'payments.collections',
            default => null,
        };
    }

    private function platformPermissionFor(string $operation, array $arguments): ?string
    {
        $resource = strtolower((string) ($arguments['resource'] ?? ''));

        return match ($resource) {
            'tenants', 'tenant' => match ($operation) {
                'query' => 'tenants.view',
                'create' => 'tenants.create',
                'update' => 'tenants.update',
                'delete' => 'tenants.delete',
                default => null,
            },
            'plans', 'plan', 'subscription_plans' => match ($operation) {
                'query' => 'plans.view',
                'create' => 'plans.create',
                'update' => 'plans.update',
                'delete' => 'plans.delete',
                default => null,
            },
            'subscriptions', 'subscription' => match ($operation) {
                'query' => 'subscriptions.view',
                'create' => 'subscriptions.create',
                'update' => 'subscriptions.update',
                'delete' => 'subscriptions.delete',
                default => null,
            },
            default => null,
        };
    }

    private function message(User $user, string $ar, string $en): string
    {
        return ($user->locale ?? 'ar') === 'en' ? $en : $ar;
    }
}
