<?php

namespace App\Services\Assistant;

use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\TenantResource;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AssistantPlatformService
{
    public function execute(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        $resource = $this->normalizeResource($arguments['resource'] ?? null);
        if ($resource === null) {
            return $this->clarification($user, 'حدد مورد المنصة المطلوب: مستأجرون، خطط، أو اشتراكات.', 'Specify the platform resource: tenants, plans, or subscriptions.');
        }

        return match ($resource) {
            'tenants' => $this->handleTenants($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'plans' => $this->handlePlans($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'subscriptions' => $this->handleSubscriptions($user, $operation, $target, $arguments, $channel, $confirmedDelete),
        };
    }

    private function handleTenants(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryTenants($user, $target, $arguments),
            'create' => $this->createTenant($user, $arguments, $channel),
            'update' => $this->updateTenant($user, $target, $arguments, $channel),
            'delete' => $this->deleteTenant($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية المستأجرين غير مدعومة.', 'Tenant operation is not supported.'),
        };
    }

    private function handlePlans(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryPlans($user, $target, $arguments),
            'create' => $this->createPlan($user, $arguments, $channel),
            'update' => $this->updatePlan($user, $target, $arguments, $channel),
            'delete' => $this->deletePlan($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية الخطط غير مدعومة.', 'Plan operation is not supported.'),
        };
    }

    private function handleSubscriptions(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->querySubscriptions($user, $target, $arguments),
            'create' => $this->createSubscription($user, $arguments, $channel),
            'update' => $this->updateSubscription($user, $target, $arguments, $channel),
            'delete' => $this->deleteSubscription($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية الاشتراكات غير مدعومة.', 'Subscription operation is not supported.'),
        };
    }

    private function queryTenants(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $items = Tenant::query()
            ->withCount(['branches', 'users', 'subscriptions'])
            ->with(['latestSubscription.plan'])
            ->when($search, fn ($query) => $query->where(fn ($subQuery) => $subQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('domain', 'like', "%{$search}%")))
            ->when(isset($arguments['status']), fn ($query) => $query->where('status', $arguments['status']))
            ->latest()
            ->limit($this->limitFromArguments($arguments))
            ->get();

        return ['status' => 'completed', 'summary' => $this->loc($user, 'تم العثور على '.$items->count().' مستأجر.', 'Found '.$items->count().' tenants.'), 'data' => ['resource' => 'tenants', 'items' => TenantResource::collection($items)->resolve(), 'count' => $items->count()]];
    }

    private function queryPlans(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $items = SubscriptionPlan::query()
            ->withCount('subscriptions')
            ->when($search, fn ($query) => $query->where(fn ($subQuery) => $subQuery->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when(isset($arguments['active']), fn ($query) => $query->where('is_active', filter_var($arguments['active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('price')
            ->limit($this->limitFromArguments($arguments))
            ->get();

        return ['status' => 'completed', 'summary' => $this->loc($user, 'تم العثور على '.$items->count().' خطة اشتراك.', 'Found '.$items->count().' subscription plans.'), 'data' => ['resource' => 'plans', 'items' => SubscriptionPlanResource::collection($items)->resolve(), 'count' => $items->count()]];
    }

    private function querySubscriptions(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $items = Subscription::query()
            ->with(['tenant', 'plan'])
            ->when($search, fn ($query) => $query->where(fn ($subQuery) => $subQuery
                ->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('name', 'like', "%{$search}%"))
                ->orWhereHas('plan', fn ($planQuery) => $planQuery->where('name', 'like', "%{$search}%"))))
            ->when(isset($arguments['status']), fn ($query) => $query->where('status', $arguments['status']))
            ->when(isset($arguments['tenant_id']), fn ($query) => $query->where('tenant_id', (int) $arguments['tenant_id']))
            ->when(isset($arguments['plan_id']), fn ($query) => $query->where('plan_id', (int) $arguments['plan_id']))
            ->latest()
            ->limit($this->limitFromArguments($arguments))
            ->get();

        return ['status' => 'completed', 'summary' => $this->loc($user, 'تم العثور على '.$items->count().' اشتراك.', 'Found '.$items->count().' subscriptions.'), 'data' => ['resource' => 'subscriptions', 'items' => SubscriptionResource::collection($items)->resolve(), 'count' => $items->count()]];
    }
    private function createTenant(User $user, array $arguments, string $channel): array
    {
        $validator = Validator::make($arguments, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('tenants', 'slug')],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'currency' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended', 'trial'])],
            'trial_ends_at' => ['nullable', 'date'],
        ]);
        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات المستأجر غير صحيحة: '.$validator->errors()->first(), 'Tenant data is invalid: '.$validator->errors()->first());
        }
        $tenant = Tenant::create($validator->validated());
        $tenant->load(['latestSubscription.plan'])->loadCount(['branches', 'users', 'subscriptions']);
        $summary = $this->loc($user, "تم إنشاء المستأجر {$tenant->name}.", "Tenant {$tenant->name} was created.");
        $this->recordAudit($user, 'assistant.platform.tenant.create', $tenant, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => TenantResource::make($tenant)->resolve()];
    }

    private function updateTenant(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveTenantTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }
        /** @var Tenant $tenant */
        $tenant = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip(['name', 'slug', 'domain', 'email', 'phone', 'address', 'logo', 'currency', 'timezone', 'locale', 'status', 'trial_ends_at']));
        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للمستأجر.', 'Specify the tenant fields you want to update.');
        }
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'currency' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'trial'])],
            'trial_ends_at' => ['nullable', 'date'],
        ]);
        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل المستأجر: '.$validator->errors()->first(), 'Could not update the tenant: '.$validator->errors()->first());
        }
        $oldValues = $tenant->only(array_keys($validator->validated()));
        $tenant->update($validator->validated());
        $tenant->load(['latestSubscription.plan'])->loadCount(['branches', 'users', 'subscriptions']);
        $summary = $this->loc($user, "تم تعديل المستأجر {$tenant->name}.", "Tenant {$tenant->name} was updated.");
        $this->recordAudit($user, 'assistant.platform.tenant.update', $tenant, ['channel' => $channel, 'old' => $oldValues, 'new' => $validator->validated()], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => TenantResource::make($tenant)->resolve()];
    }

    private function deleteTenant(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }
        $resolved = $this->resolveTenantTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }
        /** @var Tenant $tenant */
        $tenant = $resolved['model'];
        $summary = $this->loc($user, "تم حذف المستأجر {$tenant->name}.", "Tenant {$tenant->name} was deleted.");
        $this->recordAudit($user, 'assistant.platform.tenant.delete', $tenant, ['channel' => $channel], $summary);
        $tenant->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $tenant->id]];
    }

    private function createPlan(User $user, array $arguments, string $channel): array
    {
        $validator = Validator::make($arguments, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('subscription_plans', 'slug')],
            'price' => ['required', 'numeric', 'min:0'],
            'interval' => ['required', Rule::in(['monthly', 'yearly', 'lifetime'])],
            'max_branches' => ['required', 'integer', 'min:1'],
            'max_users' => ['required', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات الخطة غير صحيحة: '.$validator->errors()->first(), 'Plan data is invalid: '.$validator->errors()->first());
        }
        $plan = SubscriptionPlan::create([...$validator->validated(), 'is_active' => $validator->validated()['is_active'] ?? true]);
        $plan->loadCount('subscriptions');
        $summary = $this->loc($user, "تم إنشاء الخطة {$plan->name}.", "Plan {$plan->name} was created.");
        $this->recordAudit($user, 'assistant.platform.plan.create', $plan, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => SubscriptionPlanResource::make($plan)->resolve()];
    }

    private function updatePlan(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolvePlanTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }
        /** @var SubscriptionPlan $plan */
        $plan = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip(['name', 'slug', 'price', 'interval', 'max_branches', 'max_users', 'features', 'is_active']));
        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للخطة.', 'Specify the plan fields you want to update.');
        }
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('subscription_plans', 'slug')->ignore($plan->id)],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'interval' => ['sometimes', Rule::in(['monthly', 'yearly', 'lifetime'])],
            'max_branches' => ['sometimes', 'integer', 'min:1'],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل الخطة: '.$validator->errors()->first(), 'Could not update the plan: '.$validator->errors()->first());
        }
        $oldValues = $plan->only(array_keys($validator->validated()));
        $plan->update($validator->validated());
        $plan->loadCount('subscriptions');
        $summary = $this->loc($user, "تم تعديل الخطة {$plan->name}.", "Plan {$plan->name} was updated.");
        $this->recordAudit($user, 'assistant.platform.plan.update', $plan, ['channel' => $channel, 'old' => $oldValues, 'new' => $validator->validated()], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => SubscriptionPlanResource::make($plan)->resolve()];
    }

    private function deletePlan(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }
        $resolved = $this->resolvePlanTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }
        /** @var SubscriptionPlan $plan */
        $plan = $resolved['model'];
        $summary = $this->loc($user, "تم حذف الخطة {$plan->name}.", "Plan {$plan->name} was deleted.");
        $this->recordAudit($user, 'assistant.platform.plan.delete', $plan, ['channel' => $channel], $summary);
        $plan->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $plan->id]];
    }

    private function createSubscription(User $user, array $arguments, string $channel): array
    {
        $validator = Validator::make($arguments, [
            'tenant_id' => ['required', 'exists:tenants,id'],
            'plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'status' => ['required', Rule::in(['active', 'cancelled', 'expired', 'trial'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'cancelled_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات الاشتراك غير صحيحة: '.$validator->errors()->first(), 'Subscription data is invalid: '.$validator->errors()->first());
        }
        $subscription = Subscription::create($validator->validated());
        $subscription->load(['tenant', 'plan']);
        $summary = $this->loc($user, "تم إنشاء الاشتراك #{$subscription->id}.", "Subscription #{$subscription->id} was created.");
        $this->recordAudit($user, 'assistant.platform.subscription.create', $subscription, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => SubscriptionResource::make($subscription)->resolve()];
    }

    private function updateSubscription(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveSubscriptionTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }
        /** @var Subscription $subscription */
        $subscription = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip(['tenant_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'cancelled_at', 'metadata']));
        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للاشتراك.', 'Specify the subscription fields you want to update.');
        }
        $validator = Validator::make($data, [
            'tenant_id' => ['sometimes', 'exists:tenants,id'],
            'plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'status' => ['sometimes', Rule::in(['active', 'cancelled', 'expired', 'trial'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'cancelled_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل الاشتراك: '.$validator->errors()->first(), 'Could not update the subscription: '.$validator->errors()->first());
        }
        $oldValues = $subscription->only(array_keys($validator->validated()));
        $subscription->update($validator->validated());
        $subscription->load(['tenant', 'plan']);
        $summary = $this->loc($user, "تم تعديل الاشتراك #{$subscription->id}.", "Subscription #{$subscription->id} was updated.");
        $this->recordAudit($user, 'assistant.platform.subscription.update', $subscription, ['channel' => $channel, 'old' => $oldValues, 'new' => $validator->validated()], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => SubscriptionResource::make($subscription)->resolve()];
    }

    private function deleteSubscription(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }
        $resolved = $this->resolveSubscriptionTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }
        /** @var Subscription $subscription */
        $subscription = $resolved['model'];
        $summary = $this->loc($user, "تم حذف الاشتراك #{$subscription->id}.", "Subscription #{$subscription->id} was deleted.");
        $this->recordAudit($user, 'assistant.platform.subscription.delete', $subscription, ['channel' => $channel], $summary);
        $subscription->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $subscription->id]];
    }

    private function normalizeResource(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $normalized = str_replace(['-', ' '], '_', strtolower(trim($value)));

        return match (true) {
            str_contains($normalized, 'tenant') || str_contains($normalized, 'مستأجر') => 'tenants',
            str_contains($normalized, 'plan') || str_contains($normalized, 'خط') => 'plans',
            str_contains($normalized, 'subscription') || str_contains($normalized, 'اشتراك') => 'subscriptions',
            default => null,
        };
    }

    private function resolveTenantTarget(User $user, mixed $target): array
    {
        return $this->resolveMatchSet($user, $target, Tenant::query()->withCount(['branches', 'users', 'subscriptions'])->with(['latestSubscription.plan']), ['name', 'slug', 'email', 'domain'], 'مستأجر', 'tenant');
    }

    private function resolvePlanTarget(User $user, mixed $target): array
    {
        return $this->resolveMatchSet($user, $target, SubscriptionPlan::query()->withCount('subscriptions'), ['name', 'slug'], 'خطة', 'plan');
    }

    private function resolveSubscriptionTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد الاشتراك المقصود.', 'Specify the subscription.')];
        }
        $query = Subscription::query()->with(['tenant', 'plan']);
        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }
        $matches = (clone $query)->where(fn ($q) => $q->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('name', 'like', '%'.$target.'%'))->orWhereHas('plan', fn ($planQuery) => $planQuery->where('name', 'like', '%'.$target.'%')))->limit(3)->get();

        return $this->resolveCollectionMatchSet($user, $matches, 'اشتراك', 'subscription');
    }

    private function resolveMatchSet(User $user, mixed $target, $query, array $fields, string $labelAr, string $labelEn): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, "حدد {$labelAr} المقصود.", "Specify the {$labelEn}.")];
        }
        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }
        $matches = (clone $query)->where(function ($builder) use ($fields, $target) {
            foreach ($fields as $index => $field) {
                if ($index === 0) {
                    $builder->where($field, 'like', '%'.$target.'%');
                    continue;
                }
                $builder->orWhere($field, 'like', '%'.$target.'%');
            }
        })->limit(3)->get();

        return $this->resolveCollectionMatchSet($user, $matches, $labelAr, $labelEn);
    }

    private function resolveCollectionMatchSet(User $user, Collection $matches, string $labelAr, string $labelEn): array
    {
        if ($matches->count() === 1) {
            return ['status' => 'resolved', 'model' => $matches->first()];
        }
        if ($matches->isEmpty()) {
            return ['status' => 'not_found', 'response' => $this->clarification($user, "لم أجد {$labelAr} مطابقاً. جرّب الاسم الكامل أو المعرّف.", "I could not find a matching {$labelEn}. Try the full name or ID.")];
        }
        $options = $matches->values()->map(function ($item, int $index) {
            $label = $item->name ?? (($item->tenant?->name && $item->plan?->name) ? $item->tenant->name.' / '.$item->plan->name : '#'.$item->id);

            return ['number' => $index + 1, 'value' => (string) $item->id, 'label' => $label.' #'.$item->id];
        })->all();
        $optionsAr = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode('، ');
        $optionsEn = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode(', ');

        return ['status' => 'ambiguous', 'response' => $this->clarification($user, "وجدت أكثر من {$labelAr}. اختر رقم الخيار المناسب: {$optionsAr}", "I found more than one matching {$labelEn}. Choose the option number: {$optionsEn}", ['clarification' => ['kind' => 'selection', 'field' => 'target', 'allow_none' => false, 'options' => $options]])];
    }

    private function limitFromArguments(array $arguments): int
    {
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;

        return max(1, min($limit, 50));
    }

    private function clarification(User $user, string $ar, string $en, array $data = []): array
    {
        return ['status' => 'needs_clarification', 'summary' => $this->loc($user, $ar, $en), 'data' => $data];
    }

    private function rejected(User $user, string $ar, string $en, array $data = []): array
    {
        return ['status' => 'rejected', 'summary' => $this->loc($user, $ar, $en), 'data' => $data];
    }

    private function loc(User $user, string $ar, string $en): string
    {
        return ($user->locale ?? 'ar') === 'en' ? $en : $ar;
    }

    private function recordAudit(User $user, string $action, mixed $model, array $payload, string $description): void
    {
        AuditLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->id,
            'old_values' => null,
            'new_values' => $payload,
            'description' => $description,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
