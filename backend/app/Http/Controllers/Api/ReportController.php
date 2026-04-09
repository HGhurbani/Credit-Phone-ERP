<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Support\TenantBranchScope;
use Carbon\Carbon;
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
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->type, fn ($q) => $q->where('type', $request->type));

        $summaryRow = (clone $base)->selectRaw("
            COUNT(*) as total_orders,
            COALESCE(SUM(total), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN type = 'cash' THEN total ELSE 0 END), 0) as cash_revenue,
            COALESCE(SUM(CASE WHEN type = 'installment' THEN total ELSE 0 END), 0) as installment_revenue,
            COALESCE(AVG(total), 0) as avg_order_value,
            COALESCE(SUM(CASE WHEN type = 'cash' THEN 1 ELSE 0 END), 0) as cash_orders,
            COALESCE(SUM(CASE WHEN type = 'installment' THEN 1 ELSE 0 END), 0) as installment_orders
        ")->first();

        $summary = [
            'total_orders' => $this->toInt($summaryRow?->total_orders),
            'total_revenue' => $this->toFloat($summaryRow?->total_revenue),
            'cash_revenue' => $this->toFloat($summaryRow?->cash_revenue),
            'installment_revenue' => $this->toFloat($summaryRow?->installment_revenue),
            'avg_order_value' => $this->toFloat($summaryRow?->avg_order_value),
            'cash_orders' => $this->toInt($summaryRow?->cash_orders),
            'installment_orders' => $this->toInt($summaryRow?->installment_orders),
        ];

        $summary['cash_share_percentage'] = $this->percentage($summary['cash_revenue'], $summary['total_revenue']);
        $summary['installment_share_percentage'] = $this->percentage($summary['installment_revenue'], $summary['total_revenue']);

        $daily = (clone $base)->selectRaw("
                DATE(created_at) as date,
                COUNT(*) as orders,
                COALESCE(SUM(total), 0) as revenue,
                COALESCE(AVG(total), 0) as avg_order_value,
                COALESCE(SUM(CASE WHEN type = 'cash' THEN total ELSE 0 END), 0) as cash_revenue,
                COALESCE(SUM(CASE WHEN type = 'installment' THEN total ELSE 0 END), 0) as installment_revenue
            ")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'orders' => $this->toInt($row->orders),
                'revenue' => $this->toFloat($row->revenue),
                'avg_order_value' => $this->toFloat($row->avg_order_value),
                'cash_revenue' => $this->toFloat($row->cash_revenue),
                'installment_revenue' => $this->toFloat($row->installment_revenue),
            ])
            ->values();

        return response()->json([
            'summary' => $summary,
            'daily' => $daily,
            'period' => ['from' => $from, 'to' => $to],
            'generated_at' => now()->toIso8601String(),
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
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = Payment::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('payment_date', [$from, $to])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch));

        $summaryRow = (clone $base)->selectRaw("
            COUNT(*) as total_payments,
            COALESCE(SUM(amount), 0) as total_collected,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END), 0) as cash_collected,
            COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END), 0) as bank_collected,
            COALESCE(AVG(amount), 0) as avg_payment_value,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END), 0) as cash_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN 1 ELSE 0 END), 0) as bank_payments
        ")->first();

        $summary = [
            'total_payments' => $this->toInt($summaryRow?->total_payments),
            'total_collected' => $this->toFloat($summaryRow?->total_collected),
            'cash_collected' => $this->toFloat($summaryRow?->cash_collected),
            'bank_collected' => $this->toFloat($summaryRow?->bank_collected),
            'avg_payment_value' => $this->toFloat($summaryRow?->avg_payment_value),
            'cash_payments' => $this->toInt($summaryRow?->cash_payments),
            'bank_payments' => $this->toInt($summaryRow?->bank_payments),
        ];

        $summary['cash_share_percentage'] = $this->percentage($summary['cash_collected'], $summary['total_collected']);
        $summary['bank_share_percentage'] = $this->percentage($summary['bank_collected'], $summary['total_collected']);

        $daily = (clone $base)->selectRaw("
                payment_date as date,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as amount,
                COALESCE(AVG(amount), 0) as avg_payment_value,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END), 0) as cash_collected,
                COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END), 0) as bank_collected
            ")
            ->groupBy('payment_date')
            ->orderBy('payment_date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'payments' => $this->toInt($row->payments),
                'amount' => $this->toFloat($row->amount),
                'avg_payment_value' => $this->toFloat($row->avg_payment_value),
                'cash_collected' => $this->toFloat($row->cash_collected),
                'bank_collected' => $this->toFloat($row->bank_collected),
            ])
            ->values();

        return response()->json([
            'summary' => $summary,
            'daily' => $daily,
            'period' => ['from' => $from, 'to' => $to],
            'generated_at' => now()->toIso8601String(),
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
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = InstallmentContract::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch));

        $summaryRow = (clone $base)->selectRaw('
            COUNT(*) as total,
            COALESCE(SUM(remaining_amount), 0) as total_remaining,
            COALESCE(SUM(paid_amount), 0) as total_paid,
            COALESCE(SUM(total_amount), 0) as portfolio_value,
            COALESCE(AVG(remaining_amount), 0) as avg_remaining_per_contract,
            COALESCE(AVG(paid_amount), 0) as avg_paid_per_contract,
            COALESCE(AVG(monthly_amount), 0) as avg_monthly_amount
        ')->first();

        $summary = [
            'total' => $this->toInt($summaryRow?->total),
            'total_remaining' => $this->toFloat($summaryRow?->total_remaining),
            'total_paid' => $this->toFloat($summaryRow?->total_paid),
            'portfolio_value' => $this->toFloat($summaryRow?->portfolio_value),
            'avg_remaining_per_contract' => $this->toFloat($summaryRow?->avg_remaining_per_contract),
            'avg_paid_per_contract' => $this->toFloat($summaryRow?->avg_paid_per_contract),
            'avg_monthly_amount' => $this->toFloat($summaryRow?->avg_monthly_amount),
        ];

        $summary['collection_progress_percent'] = $this->percentage($summary['total_paid'], $summary['portfolio_value']);

        $contracts = (clone $base)
            ->with(['customer:id,name', 'branch:id,name'])
            ->withCount(['schedules as overdue_installments_count' => fn ($q) => $q->where('status', 'overdue')])
            ->withSum(['schedules as overdue_amount' => fn ($q) => $q->where('status', 'overdue')], 'remaining_amount')
            ->withMin([
                'schedules as next_due_date' => fn ($q) => $q
                    ->whereIn('status', ['upcoming', 'due_today', 'partial', 'overdue'])
                    ->where('remaining_amount', '>', 0),
            ], 'due_date')
            ->withMax('payments as last_payment_date', 'payment_date')
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        $data = collect($contracts->items())
            ->map(function (InstallmentContract $contract) {
                $totalAmount = $this->toFloat($contract->total_amount);
                $paidAmount = $this->toFloat($contract->paid_amount);

                return [
                    'contract_number' => $contract->contract_number,
                    'customer' => $contract->customer?->name,
                    'branch' => $contract->branch?->name,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'remaining_amount' => $this->toFloat($contract->remaining_amount),
                    'monthly_amount' => $this->toFloat($contract->monthly_amount),
                    'paid_progress_percent' => $this->percentage($paidAmount, $totalAmount),
                    'next_due_date' => $contract->next_due_date,
                    'overdue_installments_count' => $this->toInt($contract->overdue_installments_count),
                    'overdue_amount' => $this->toFloat($contract->overdue_amount),
                    'last_payment_date' => $contract->last_payment_date ? Carbon::parse($contract->last_payment_date)->toDateString() : null,
                    'status' => $contract->status,
                ];
            })
            ->values();

        return response()->json([
            'summary' => $summary,
            'data' => $data,
            'meta' => [
                'total' => $contracts->total(),
                'current_page' => $contracts->currentPage(),
                'last_page' => $contracts->lastPage(),
            ],
            'generated_at' => now()->toIso8601String(),
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
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $base = InstallmentSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'overdue')
            ->when($effectiveBranch !== null, fn ($q) => $q->whereHas('contract', fn ($cq) => $cq->where('branch_id', $effectiveBranch)));

        $total = (clone $base)->count();
        $totalOverdue = $this->toFloat((clone $base)->sum('remaining_amount'));
        $uniqueContracts = (clone $base)->distinct('contract_id')->count('contract_id');
        $uniqueCustomers = InstallmentContract::query()
            ->whereIn('id', (clone $base)->select('contract_id'))
            ->distinct('customer_id')
            ->count('customer_id');

        $daysOverdue = (clone $base)->pluck('due_date')
            ->map(fn ($date) => $this->daysOverdue($date))
            ->values();

        $summary = [
            'total' => $this->toInt($total),
            'total_overdue' => $totalOverdue,
            'unique_contracts' => $this->toInt($uniqueContracts),
            'unique_customers' => $this->toInt($uniqueCustomers),
            'avg_days_overdue' => $this->round($daysOverdue->avg() ?? 0),
            'max_days_overdue' => $this->toInt($daysOverdue->max() ?? 0),
            'critical_count' => $this->toInt($daysOverdue->filter(fn ($days) => $days >= 30)->count()),
        ];

        $schedules = (clone $base)
            ->with(['contract.customer:id,name', 'contract.branch:id,name'])
            ->withMax('payments as last_payment_date', 'payment_date')
            ->orderBy('due_date')
            ->paginate($request->per_page ?? 20);

        $data = collect($schedules->items())
            ->map(function (InstallmentSchedule $schedule) {
                $daysOverdue = $this->daysOverdue($schedule->due_date);

                return [
                    'contract_number' => $schedule->contract?->contract_number,
                    'customer' => $schedule->contract?->customer?->name,
                    'branch' => $schedule->contract?->branch?->name,
                    'installment_number' => $this->toInt($schedule->installment_number),
                    'due_date' => optional($schedule->due_date)->toDateString(),
                    'amount' => $this->toFloat($schedule->amount),
                    'remaining_amount' => $this->toFloat($schedule->remaining_amount),
                    'days_overdue' => $daysOverdue,
                    'severity' => $this->overdueSeverity($daysOverdue),
                    'last_payment_date' => $schedule->last_payment_date ? Carbon::parse($schedule->last_payment_date)->toDateString() : null,
                    'status' => $schedule->status,
                ];
            })
            ->values();

        return response()->json([
            'summary' => $summary,
            'data' => $data,
            'meta' => [
                'total' => $schedules->total(),
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function branchPerformance(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $from = $request->date_from ?? now()->startOfMonth()->toDateString();
        $to = $request->date_to ?? now()->toDateString();

        if ($tenantId === null) {
            return response()->json([
                'summary' => null,
                'data' => [],
                'period' => ['from' => $from, 'to' => $to],
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        $ordersSummary = DB::table('orders')
            ->selectRaw('
                branch_id,
                COUNT(*) as total_orders,
                COALESCE(SUM(total), 0) as total_sales,
                COALESCE(AVG(total), 0) as avg_order_value
            ')
            ->where('tenant_id', $tenantId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
            ->groupBy('branch_id');

        $paymentsSummary = DB::table('payments')
            ->selectRaw('branch_id, COALESCE(SUM(amount), 0) as total_collections')
            ->where('tenant_id', $tenantId)
            ->whereBetween('payment_date', [$from, $to])
            ->groupBy('branch_id');

        $branches = DB::table('branches')
            ->where('branches.tenant_id', $tenantId)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($q) => $q->where('branches.id', $user->branch_id))
            ->leftJoinSub($ordersSummary, 'orders_summary', fn ($join) => $join->on('branches.id', '=', 'orders_summary.branch_id'))
            ->leftJoinSub($paymentsSummary, 'payments_summary', fn ($join) => $join->on('branches.id', '=', 'payments_summary.branch_id'))
            ->select(
                'branches.id',
                'branches.name',
                DB::raw('COALESCE(orders_summary.total_orders, 0) as total_orders'),
                DB::raw('COALESCE(orders_summary.total_sales, 0) as total_sales'),
                DB::raw('COALESCE(orders_summary.avg_order_value, 0) as avg_order_value'),
                DB::raw('COALESCE(payments_summary.total_collections, 0) as total_collections')
            )
            ->orderByDesc('total_sales')
            ->get()
            ->map(function ($branch) {
                $totalSales = $this->toFloat($branch->total_sales);
                $totalCollections = $this->toFloat($branch->total_collections);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'total_orders' => $this->toInt($branch->total_orders),
                    'total_sales' => $totalSales,
                    'avg_order_value' => $this->toFloat($branch->avg_order_value),
                    'total_collections' => $totalCollections,
                    'collection_to_sales_ratio' => $this->percentage($totalCollections, $totalSales),
                    'outstanding_gap' => $this->round($totalSales - $totalCollections),
                ];
            })
            ->values();

        $topBranch = $branches->sortByDesc('total_sales')->first();

        $summary = [
            'total_branches' => $branches->count(),
            'total_orders' => $branches->sum('total_orders'),
            'total_sales' => $this->round($branches->sum('total_sales')),
            'total_collections' => $this->round($branches->sum('total_collections')),
            'avg_collection_to_sales_ratio' => $this->percentage(
                $branches->sum('total_collections'),
                $branches->sum('total_sales')
            ),
            'top_branch_name' => $topBranch['name'] ?? null,
            'top_branch_sales' => $topBranch['total_sales'] ?? 0,
        ];

        return response()->json([
            'summary' => $summary,
            'data' => $branches,
            'period' => ['from' => $from, 'to' => $to],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function agentPerformance(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $from = $request->date_from ?? now()->startOfMonth()->toDateString();
        $to = $request->date_to ?? now()->toDateString();

        if ($tenantId === null) {
            return response()->json([
                'summary' => null,
                'data' => [],
                'period' => ['from' => $from, 'to' => $to],
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        $ordersSummary = DB::table('orders')
            ->selectRaw("
                sales_agent_id,
                COUNT(*) as total_orders,
                COALESCE(SUM(total), 0) as total_sales,
                COALESCE(AVG(total), 0) as avg_order_value,
                COALESCE(SUM(CASE WHEN type = 'cash' THEN total ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN type = 'installment' THEN total ELSE 0 END), 0) as installment_sales
            ")
            ->where('tenant_id', $tenantId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
            ->when(TenantBranchScope::isBranchScoped($user), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->groupBy('sales_agent_id');

        $agents = DB::table('users')
            ->where('users.tenant_id', $tenantId)
            ->leftJoinSub($ordersSummary, 'orders_summary', fn ($join) => $join->on('users.id', '=', 'orders_summary.sales_agent_id'))
            ->select(
                'users.id',
                'users.name',
                DB::raw('COALESCE(orders_summary.total_orders, 0) as total_orders'),
                DB::raw('COALESCE(orders_summary.total_sales, 0) as total_sales'),
                DB::raw('COALESCE(orders_summary.avg_order_value, 0) as avg_order_value'),
                DB::raw('COALESCE(orders_summary.cash_sales, 0) as cash_sales'),
                DB::raw('COALESCE(orders_summary.installment_sales, 0) as installment_sales')
            )
            ->orderByDesc('total_sales')
            ->get()
            ->map(function ($agent) {
                $totalSales = $this->toFloat($agent->total_sales);
                $installmentSales = $this->toFloat($agent->installment_sales);

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'total_orders' => $this->toInt($agent->total_orders),
                    'total_sales' => $totalSales,
                    'avg_order_value' => $this->toFloat($agent->avg_order_value),
                    'cash_sales' => $this->toFloat($agent->cash_sales),
                    'installment_sales' => $installmentSales,
                    'installment_share_percentage' => $this->percentage($installmentSales, $totalSales),
                ];
            })
            ->values();

        $summary = [
            'total_agents' => $agents->count(),
            'active_agents' => $agents->filter(fn ($agent) => $agent['total_orders'] > 0)->count(),
            'total_orders' => $agents->sum('total_orders'),
            'total_sales' => $this->round($agents->sum('total_sales')),
            'avg_sales_per_agent' => $agents->count() > 0
                ? $this->round($agents->avg('total_sales'))
                : 0.0,
        ];

        return response()->json([
            'summary' => $summary,
            'data' => $agents,
            'period' => ['from' => $from, 'to' => $to],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function toFloat(mixed $value): float
    {
        return $this->round((float) ($value ?? 0));
    }

    private function toInt(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }

    private function percentage(float $part, float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return $this->round(($part / $whole) * 100);
    }

    private function daysOverdue(mixed $date): int
    {
        if (!$date) {
            return 0;
        }

        return Carbon::parse($date)->startOfDay()->diffInDays(now()->startOfDay());
    }

    private function overdueSeverity(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue >= 60 => 'critical',
            $daysOverdue >= 30 => 'high',
            $daysOverdue >= 15 => 'medium',
            default => 'low',
        };
    }
}

