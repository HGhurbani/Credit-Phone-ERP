<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Assignable roles for user management (excludes platform super admin).
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', 'super_admin')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $roles]);
    }
}
