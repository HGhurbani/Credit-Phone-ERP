<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function sales(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $from = $request->date_from ?? now()->startOfMonth()->toDateString();
        $to = $request->date_to ?? now()->toDateString();

        if ($tenantId === null) {
            return response()->json([
                'summary' => null,
                'daily' => [],
                'period' => ['from' => $from, 'to' => $to],
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = Order::where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->type, fn ($q) => $q->where('type', $request->type));

        $summary = (clone $base)->selectRaw('
            COUNT(*) as total_orders,
            SUM(total) as total_revenue,
            SUM(CASE WHEN type = "cash" THEN total ELSE 0 END) as cash_revenue,
            SUM(CASE WHEN type = "installment" THEN total ELSE 0 END) as installment_revenue
        ')->first();

        $daily = (clone $base)->selectRaw('DATE(created_at) as date, COUNT(*) as orders, SUM(total) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        return response()->json([
            'summary' => $summary,
            'daily' => $daily,
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function collections(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $from = $request->date_from ?? now()->startOfMonth()->toDateString();
        $to = $request->date_to ?? now()->toDateString();

        if ($tenantId === null) {
            return response()->json([
                'summary' => null,
                'daily' => [],
                'period' => ['from' => $from, 'to' => $to],
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = Payment::where('tenant_id', $tenantId)
            ->whereBetween('payment_date', [$from, $to])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch));

        $summary = (clone $base)->selectRaw('
            COUNT(*) as total_payments,
            SUM(amount) as total_collected,
            SUM(CASE WHEN payment_method = "cash" THEN amount ELSE 0 END) as cash_collected,
            SUM(CASE WHEN payment_method = "bank_transfer" THEN amount ELSE 0 END) as bank_collected
        ')->first();

        $daily = (clone $base)->selectRaw('payment_date as date, COUNT(*) as payments, SUM(amount) as amount')
            ->groupBy('payment_date')
            ->orderBy('payment_date')
            ->get();

        return response()->json([
            'summary' => $summary,
            'daily' => $daily,
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function activeContracts(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            return response()->json([
                'summary' => null,
                'data' => [],
                'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = InstallmentContract::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch));

        $summary = (clone $base)->selectRaw('COUNT(*) as total, SUM(remaining_amount) as total_remaining, SUM(paid_amount) as total_paid')
            ->first();

        $contracts = (clone $base)->with(['customer', 'branch'])
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'summary' => $summary,
            'data' => $contracts->items(),
            'meta' => [
                'total' => $contracts->total(),
                'current_page' => $contracts->currentPage(),
                'last_page' => $contracts->lastPage(),
            ],
        ]);
    }

    public function overdueInstallments(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            return response()->json([
                'summary' => null,
                'data' => [],
                'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = InstallmentSchedule::where('tenant_id', $tenantId)
            ->where('status', 'overdue')
            ->when($effectiveBranch !== null, fn ($q) => $q->whereHas('contract', fn ($cq) => $cq->where('branch_id', $effectiveBranch)));

        $summary = (clone $base)->selectRaw('COUNT(*) as total, SUM(remaining_amount) as total_overdue')
            ->first();

        $schedules = (clone $base)->with(['contract.customer', 'contract.branch'])
            ->orderBy('due_date')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'summary' => $summary,
            'data' => $schedules->items(),
            'meta' => [
                'total' => $schedules->total(),
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
            ],
        ]);
    }

    public function branchPerformance(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $from = $request->date_from ?? now()->startOfMonth()->toDateString();
        $to = $request->date_to ?? now()->toDateString();

        if ($tenantId === null) {
            return response()->json(['data' => [], 'period' => ['from' => $from, 'to' => $to]]);
        }

        $branches = DB::table('branches')
            ->where('tenant_id', $tenantId)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($q) => $q->where('branches.id', $user->branch_id))
            ->leftJoin('orders', function ($join) use ($tenantId, $from, $to) {
                $join->on('branches.id', '=', 'orders.branch_id')
                    ->where('orders.tenant_id', $tenantId)
                    ->whereBetween(DB::raw('DATE(orders.created_at)'), [$from, $to])
                    ->whereIn('orders.status', ['completed', 'converted_to_contract', 'approved']);
            })
            ->leftJoin('payments', function ($join) use ($tenantId, $from, $to) {
                $join->on('branches.id', '=', 'payments.branch_id')
                    ->where('payments.tenant_id', $tenantId)
                    ->whereBetween('payments.payment_date', [$from, $to]);
            })
            ->select(
                'branches.id',
                'branches.name',
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(DISTINCT orders.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(DISTINCT payments.amount), 0) as total_collections')
            )
            ->groupBy('branches.id', 'branches.name')
            ->get();

        return response()->json(['data' => $branches, 'period' => ['from' => $from, 'to' => $to]]);
    }

    public function agentPerformance(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $from = $request->date_from ?? now()->startOfMonth()->toDateString();
        $to = $request->date_to ?? now()->toDateString();

        if ($tenantId === null) {
            return response()->json(['data' => [], 'period' => ['from' => $from, 'to' => $to]]);
        }

        $agents = DB::table('users')
            ->where('users.tenant_id', $tenantId)
            ->leftJoin('orders', function ($join) use ($from, $to, $tenantId, $user) {
                $join->on('users.id', '=', 'orders.sales_agent_id')
                    ->where('orders.tenant_id', $tenantId)
                    ->whereBetween(DB::raw('DATE(orders.created_at)'), [$from, $to])
                    ->whereIn('orders.status', ['completed', 'converted_to_contract', 'approved']);
                if (TenantBranchScope::isBranchScoped($user)) {
                    $join->where('orders.branch_id', $user->branch_id);
                }
            })
            ->select(
                'users.id',
                'users.name',
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_sales')
            ->get();

        return response()->json(['data' => $agents, 'period' => ['from' => $from, 'to' => $to]]);
    }
}
