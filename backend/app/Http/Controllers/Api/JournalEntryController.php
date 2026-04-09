<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $entries = JournalEntry::query()
            ->forTenant($tenantId)
            ->with(['branch', 'createdBy', 'lines.account', 'source'])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->event, fn ($q) => $q->where('event', $request->event))
            ->when($request->date_from, fn ($q) => $q->whereDate('entry_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('entry_date', '<=', $request->date_to))
            ->when($request->search, function ($q) use ($request) {
                $search = trim((string) $request->search);
                $q->where(function ($inner) use ($search) {
                    $inner->where('entry_number', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhere('source_type', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => JournalEntryResource::collection($entries->items()),
            'meta' => [
                'total' => $entries->total(),
                'per_page' => $entries->perPage(),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if (! $request->user()->isSuperAdmin() && (int) $journalEntry->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $journalEntry->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }

        $journalEntry->load(['branch', 'createdBy', 'lines.account', 'reversedEntry', 'source']);

        return response()->json(['data' => new JournalEntryResource($journalEntry)]);
    }
}
