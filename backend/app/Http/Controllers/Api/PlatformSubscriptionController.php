<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesSuperAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Http\Requests\Subscription\UpdateSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSubscriptionController extends Controller
{
    use AuthorizesSuperAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $subscriptions = Subscription::query()
            ->with(['tenant', 'plan'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('plan', fn ($planQuery) => $planQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('tenant_id'), fn ($query) => $query->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('plan_id'), fn ($query) => $query->where('plan_id', $request->integer('plan_id')))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => SubscriptionResource::collection($subscriptions->items()),
            'meta' => [
                'total' => $subscriptions->total(),
                'per_page' => $subscriptions->perPage(),
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
            ],
        ]);
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $subscription = Subscription::create($request->validated());

        return response()->json([
            'data' => new SubscriptionResource($subscription->load(['tenant', 'plan'])),
        ], 201);
    }

    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $subscription->update($request->validated());

        return response()->json([
            'data' => new SubscriptionResource($subscription->fresh()->load(['tenant', 'plan'])),
        ]);
    }

    public function destroy(Request $request, Subscription $subscription): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $subscription->delete();

        return response()->json(['message' => 'Subscription deleted.']);
    }
}
