<?php

namespace App\Services\Assistant;

use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantBranchScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AssistantCatalogService
{
    public function execute(User $user, string $module, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($module) {
            'categories' => $this->handleCategories($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'brands' => $this->handleBrands($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'stock' => $this->handleStock($user, $operation, $target, $arguments, $channel),
            default => $this->rejected($user, 'هذه الوحدة غير مدعومة حالياً.', 'This module is not supported right now.'),
        };
    }

    private function handleCategories(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryCategories($user, $target, $arguments),
            'create' => $this->createCategory($user, $arguments, $channel),
            'update' => $this->updateCategory($user, $target, $arguments, $channel),
            'delete' => $this->deleteCategory($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية التصنيفات غير مدعومة.', 'Category operation is not supported.'),
        };
    }

    private function handleBrands(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryBrands($user, $target, $arguments),
            'create' => $this->createBrand($user, $arguments, $channel),
            'update' => $this->updateBrand($user, $target, $arguments, $channel),
            'delete' => $this->deleteBrand($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية الماركات غير مدعومة.', 'Brand operation is not supported.'),
        };
    }

    private function handleStock(User $user, string $operation, ?string $target, array $arguments, string $channel): array
    {
        return match ($operation) {
            'query' => $this->queryStock($user, $target, $arguments),
            'update' => $this->adjustStock($user, $target, $arguments, $channel),
            default => $this->rejected($user, 'عملية المخزون غير مدعومة.', 'Stock operation is not supported.'),
        };
    }

    private function queryCategories(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $items = Category::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($search, fn ($q) => $q->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('name_ar', 'like', '%'.$search.'%')
                ->orWhere('slug', 'like', '%'.$search.'%')))
            ->when(array_key_exists('active_only', $arguments), fn ($q) => $q->where('is_active', (bool) $arguments['active_only']))
            ->withCount('products')
            ->orderBy('name')
            ->limit($this->limitFromArguments($arguments))
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'name_ar' => $category->name_ar,
                'slug' => $category->slug,
                'description' => $category->description,
                'is_active' => $category->is_active,
                'products_count' => $category->products_count,
            ])
            ->values()
            ->all();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.count($items).' تصنيف.', 'Found '.count($items).' categories.'),
            'data' => [
                'items' => $items,
                'count' => count($items),
            ],
        ];
    }

    private function createCategory(User $user, array $arguments, string $channel): array
    {
        $validator = Validator::make($arguments, [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات التصنيف غير صحيحة: '.$validator->errors()->first(), 'Category data is invalid: '.$validator->errors()->first());
        }

        $category = Category::create([
            ...$validator->validated(),
            'tenant_id' => $user->tenant_id,
            'is_active' => $validator->validated()['is_active'] ?? true,
        ]);

        $summary = $this->loc($user, "تم إنشاء التصنيف {$category->name}.", "Category {$category->name} was created.");
        $this->recordAudit($user, 'assistant.category.create', $category, ['channel' => $channel], $summary);

        return [
            'status' => 'completed',
            'summary' => $summary,
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'name_ar' => $category->name_ar,
                'slug' => $category->slug,
                'description' => $category->description,
                'is_active' => $category->is_active,
            ],
        ];
    }

    private function updateCategory(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveCategoryTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Category $category */
        $category = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip(['name', 'name_ar', 'slug', 'description', 'is_active']));
        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للتصنيف.', 'Specify the category fields you want to update.');
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل التصنيف: '.$validator->errors()->first(), 'Could not update the category: '.$validator->errors()->first());
        }

        $oldValues = $category->only(array_keys($validator->validated()));
        $category->update($validator->validated());
        $summary = $this->loc($user, "تم تعديل التصنيف {$category->name}.", "Category {$category->name} was updated.");
        $this->recordAudit($user, 'assistant.category.update', $category, ['channel' => $channel, 'old' => $oldValues, 'new' => $validator->validated()], $summary);

        return [
            'status' => 'completed',
            'summary' => $summary,
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'name_ar' => $category->name_ar,
                'slug' => $category->slug,
                'description' => $category->description,
                'is_active' => $category->is_active,
            ],
        ];
    }

    private function deleteCategory(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolveCategoryTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Category $category */
        $category = $resolved['model'];
        $summary = $this->loc($user, "تم حذف التصنيف {$category->name}.", "Category {$category->name} was deleted.");
        $this->recordAudit($user, 'assistant.category.delete', $category, ['channel' => $channel], $summary);
        $category->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $category->id]];
    }

    private function queryBrands(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $items = Brand::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when(array_key_exists('active_only', $arguments), fn ($q) => $q->where('is_active', (bool) $arguments['active_only']))
            ->withCount('products')
            ->orderBy('name')
            ->limit($this->limitFromArguments($arguments))
            ->get()
            ->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'logo' => $brand->logo,
                'is_active' => $brand->is_active,
                'products_count' => $brand->products_count,
            ])
            ->values()
            ->all();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.count($items).' ماركة.', 'Found '.count($items).' brands.'),
            'data' => [
                'items' => $items,
                'count' => count($items),
            ],
        ];
    }

    private function createBrand(User $user, array $arguments, string $channel): array
    {
        $validator = Validator::make($arguments, [
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات الماركة غير صحيحة: '.$validator->errors()->first(), 'Brand data is invalid: '.$validator->errors()->first());
        }

        $brand = Brand::create([
            ...$validator->validated(),
            'tenant_id' => $user->tenant_id,
            'is_active' => $validator->validated()['is_active'] ?? true,
        ]);

        $summary = $this->loc($user, "تم إنشاء الماركة {$brand->name}.", "Brand {$brand->name} was created.");
        $this->recordAudit($user, 'assistant.brand.create', $brand, ['channel' => $channel], $summary);

        return [
            'status' => 'completed',
            'summary' => $summary,
            'data' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'logo' => $brand->logo,
                'is_active' => $brand->is_active,
            ],
        ];
    }

    private function updateBrand(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveBrandTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Brand $brand */
        $brand = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip(['name', 'logo', 'is_active']));
        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للماركة.', 'Specify the brand fields you want to update.');
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل الماركة: '.$validator->errors()->first(), 'Could not update the brand: '.$validator->errors()->first());
        }

        $oldValues = $brand->only(array_keys($validator->validated()));
        $brand->update($validator->validated());
        $summary = $this->loc($user, "تم تعديل الماركة {$brand->name}.", "Brand {$brand->name} was updated.");
        $this->recordAudit($user, 'assistant.brand.update', $brand, ['channel' => $channel, 'old' => $oldValues, 'new' => $validator->validated()], $summary);

        return [
            'status' => 'completed',
            'summary' => $summary,
            'data' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'logo' => $brand->logo,
                'is_active' => $brand->is_active,
            ],
        ];
    }

    private function deleteBrand(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolveBrandTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Brand $brand */
        $brand = $resolved['model'];
        $summary = $this->loc($user, "تم حذف الماركة {$brand->name}.", "Brand {$brand->name} was deleted.");
        $this->recordAudit($user, 'assistant.brand.delete', $brand, ['channel' => $channel], $summary);
        $brand->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $brand->id]];
    }

    private function queryStock(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['product'] ?? $arguments['search'] ?? null);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);
        $lowStockOnly = filter_var($arguments['low_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $products = Product::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['category', 'brand', 'inventories.branch'])
            ->when($search, fn ($q) => $q->search($search))
            ->orderBy('name')
            ->limit($this->limitFromArguments($arguments))
            ->get();

        if ($branchId !== null) {
            $products->each(function (Product $product) use ($branchId) {
                $product->setRelation('inventories', $product->inventories->where('branch_id', $branchId)->values());
            });
        }

        if ($lowStockOnly) {
            $products = $products->filter(fn (Product $product) => $product->inventories->contains(fn (Inventory $inventory) => $inventory->isLowStock()))->values();
        }

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم تجهيز ملخص المخزون لعدد '.$products->count().' منتج.', 'Prepared the stock summary for '.$products->count().' products.'),
            'data' => [
                'items' => ProductResource::collection($products)->resolve(),
                'count' => $products->count(),
                'branch_id' => $branchId,
                'low_stock_only' => $lowStockOnly,
            ],
        ];
    }

    private function adjustStock(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveProductTarget($user, $target ?? ($arguments['product_id'] ?? $arguments['product'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Product $product */
        $product = $resolved['model'];
        $branchId = $this->resolveBranchIdForCreate($user, $arguments);
        if ($branchId === null) {
            return $this->clarification($user, 'حدد الفرع الذي تريد تعديل مخزونه.', 'Specify the branch whose stock you want to adjust.');
        }

        $data = [
            'quantity' => $arguments['quantity'] ?? null,
            'movement_type' => $arguments['movement_type'] ?? $arguments['type'] ?? null,
            'notes' => $arguments['notes'] ?? null,
        ];

        $validator = Validator::make($data, [
            'quantity' => ['required', 'integer'],
            'movement_type' => ['required', Rule::in(['in', 'out', 'adjustment'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات تعديل المخزون غير صحيحة: '.$validator->errors()->first(), 'Stock adjustment data is invalid: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        $inventory = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branchId],
            ['quantity' => 0]
        );

        $before = (int) $inventory->quantity;
        $movementType = (string) $validated['movement_type'];
        $quantity = (int) $validated['quantity'];

        if ($movementType === 'out' && abs($quantity) > $before) {
            return $this->rejected($user, 'لا يمكن صرف كمية أكبر من الرصيد الحالي للمخزون.', 'You cannot remove more stock than the current available quantity.');
        }

        match ($movementType) {
            'in' => $inventory->increment('quantity', abs($quantity)),
            'out' => $inventory->decrement('quantity', abs($quantity)),
            'adjustment' => $inventory->update(['quantity' => $quantity]),
        };

        $inventory->refresh();
        $branch = Branch::query()->where('tenant_id', $user->tenant_id)->findOrFail($branchId);

        StockMovement::create([
            'product_id' => $product->id,
            'branch_id' => $branchId,
            'created_by' => $user->id,
            'type' => $movementType,
            'quantity' => abs($quantity),
            'quantity_before' => $before,
            'quantity_after' => (int) $inventory->quantity,
            'notes' => $validated['notes'] ?? null,
        ]);

        $product->load(['category', 'brand']);
        $summary = $this->loc(
            $user,
            "تم تحديث مخزون المنتج {$product->name} في فرع {$branch->name}.",
            "Updated the stock for {$product->name} in {$branch->name} branch."
        );

        $this->recordAudit($user, 'assistant.stock.update', $product, [
            'channel' => $channel,
            'branch_id' => $branchId,
            'branch_name' => $branch->name,
            'movement_type' => $movementType,
            'quantity' => abs($quantity),
            'before' => $before,
            'after' => (int) $inventory->quantity,
        ], $summary);

        return [
            'status' => 'completed',
            'summary' => $summary,
            'data' => [
                'product' => ProductResource::make($product)->resolve(),
                'stock' => [
                    'branch_id' => $branchId,
                    'branch_name' => $branch->name,
                    'movement_type' => $movementType,
                    'quantity' => abs($quantity),
                    'before' => $before,
                    'after' => (int) $inventory->quantity,
                ],
            ],
        ];
    }

    private function resolveCategoryTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد التصنيف بالاسم أو المعرّف.', 'Specify the category by name or ID.')];
        }

        $query = Category::query()->where('tenant_id', $user->tenant_id);
        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('name', 'like', '%'.$target.'%')
                ->orWhere('name_ar', 'like', '%'.$target.'%')
                ->orWhere('slug', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'تصنيف', 'category');
    }

    private function resolveBrandTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد الماركة بالاسم أو المعرّف.', 'Specify the brand by name or ID.')];
        }

        $query = Brand::query()->where('tenant_id', $user->tenant_id);
        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)->where('name', 'like', '%'.$target.'%')->limit(3)->get();

        return $this->resolveMatchSet($user, $matches, 'ماركة', 'brand');
    }

    private function resolveProductTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد المنتج بالاسم أو المعرّف.', 'Specify the product by name or ID.')];
        }

        $query = Product::query()->where('tenant_id', $user->tenant_id);
        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('name', 'like', '%'.$target.'%')
                ->orWhere('name_ar', 'like', '%'.$target.'%')
                ->orWhere('sku', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'منتج', 'product');
    }

    private function resolveBranchIdForUser(User $user, ?int $requestedBranchId = null): ?int
    {
        if (TenantBranchScope::isBranchScoped($user)) {
            return $user->branch_id ? (int) $user->branch_id : null;
        }

        if ($requestedBranchId === null) {
            return null;
        }

        $exists = Branch::query()->where('tenant_id', $user->tenant_id)->whereKey($requestedBranchId)->exists();

        return $exists ? $requestedBranchId : null;
    }

    private function resolveBranchIdForCreate(User $user, array $arguments): ?int
    {
        if (TenantBranchScope::isBranchScoped($user)) {
            return $user->branch_id ? (int) $user->branch_id : null;
        }

        $branchTarget = $arguments['branch_id'] ?? ($arguments['branch_name'] ?? $arguments['branch'] ?? $user->branch_id);
        if ($branchTarget === null || $branchTarget === '') {
            return $user->branch_id ? (int) $user->branch_id : null;
        }

        $query = Branch::query()->where('tenant_id', $user->tenant_id);
        if (is_numeric($branchTarget)) {
            $branch = (clone $query)->find((int) $branchTarget);

            return $branch ? (int) $branch->id : null;
        }

        $normalized = Str::lower(trim((string) $branchTarget));
        $branch = (clone $query)
            ->when(
                in_array($normalized, ['main', 'main branch', 'الرئيسي', 'الفرع الرئيسي'], true),
                fn ($builder) => $builder->where('is_main', true),
                fn ($builder) => $builder->where(fn ($nested) => $nested
                    ->where('name', 'like', '%'.$branchTarget.'%')
                    ->orWhere('code', 'like', '%'.$branchTarget.'%')
                    ->orWhere('city', 'like', '%'.$branchTarget.'%'))
            )
            ->first();

        return $branch ? (int) $branch->id : null;
    }

    private function resolveMatchSet(User $user, Collection $matches, string $labelAr, string $labelEn): array
    {
        if ($matches->count() === 1) {
            return ['status' => 'resolved', 'model' => $matches->first()];
        }

        if ($matches->isEmpty()) {
            return [
                'status' => 'not_found',
                'response' => $this->clarification(
                    $user,
                    "لم أجد {$labelAr} مطابقاً. جرّب الاسم الكامل أو المعرّف.",
                    "I could not find a matching {$labelEn}. Try the full name or ID."
                ),
            ];
        }

        $options = $matches->values()->map(function ($item, int $index) {
            return [
                'number' => $index + 1,
                'value' => (string) $item->id,
                'label' => $this->modelChoiceLabel($item),
            ];
        })->all();

        $optionsAr = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode('، ');
        $optionsEn = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode(', ');

        return [
            'status' => 'ambiguous',
            'response' => $this->clarification(
                $user,
                "وجدت أكثر من {$labelAr}. اختر رقم الخيار المناسب: {$optionsAr}",
                "I found more than one matching {$labelEn}. Choose the option number: {$optionsEn}",
                [
                    'clarification' => [
                        'kind' => 'selection',
                        'field' => 'target',
                        'allow_none' => false,
                        'options' => $options,
                    ],
                ]
            ),
        ];
    }

    private function modelChoiceLabel(mixed $model): string
    {
        $parts = array_filter([
            $model->name ?? $model->name_ar ?? $model->slug ?? null,
            isset($model->id) ? '#'.$model->id : null,
        ]);

        return implode(' ', $parts);
    }

    private function limitFromArguments(array $arguments): int
    {
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;

        return max(1, min($limit, 50));
    }

    private function clarification(User $user, string $ar, string $en, array $data = []): array
    {
        return [
            'status' => 'needs_clarification',
            'summary' => $this->loc($user, $ar, $en),
            'data' => $data,
        ];
    }

    private function rejected(User $user, string $ar, string $en, array $data = []): array
    {
        return [
            'status' => 'rejected',
            'summary' => $this->loc($user, $ar, $en),
            'data' => $data,
        ];
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
