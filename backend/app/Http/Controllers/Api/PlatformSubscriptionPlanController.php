<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesSuperAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubscriptionPlan\StoreSubscriptionPlanRequest;
use App\Http\Requests\SubscriptionPlan\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSubscriptionPlanController extends Controller
{
    use AuthorizesSuperAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $plans = SubscriptionPlan::query()
            ->withCount('subscriptions')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('price')
            ->get();

        return response()->json([
            'data' => SubscriptionPlanResource::collection($plans),
        ]);
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $plan = SubscriptionPlan::create($request->validated());

        return response()->json([
            'data' => new SubscriptionPlanResource($plan->loadCount('subscriptions')),
        ], 201);
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $plan->update($request->validated());

        return response()->json([
            'data' => new SubscriptionPlanResource($plan->fresh()->loadCount('subscriptions')),
        ]);
    }

    public function destroy(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $plan->delete();

        return response()->json(['message' => 'Subscription plan deleted.']);
    }
}
