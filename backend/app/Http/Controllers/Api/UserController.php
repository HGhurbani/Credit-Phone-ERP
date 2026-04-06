<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $users = User::where('tenant_id', $tenantId)
            ->with(['branch', 'roles'])
            ->when($request->search, fn($q) => $q->where(fn($sq) => $sq->where('name', 'like', "%{$request->search}%")->orWhere('email', 'like', "%{$request->search}%")))
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
            'password' => Hash::make($request->password),
        ]);

        if ($request->role) {
            $user->assignRole($request->role);
        }

        return response()->json(['data' => new UserResource($user->load(['branch', 'roles']))], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeTenant($request, $user);
        $user->load(['branch', 'roles', 'permissions']);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorizeTenant($request, $user);

        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        if ($request->role) {
            $user->syncRoles([$request->role]);
        }

        return response()->json(['data' => new UserResource($user->fresh(['branch', 'roles']))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeTenant($request, $user);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot delete your own account.'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted.']);
    }

    private function authorizeTenant(Request $request, User $user): void
    {
        if (!$request->user()->isSuperAdmin() && $user->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }
    }
}
