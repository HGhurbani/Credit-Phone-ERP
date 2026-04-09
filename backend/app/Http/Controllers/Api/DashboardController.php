<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return $this->platformOverview();
        }

        $tenantId = $user->tenant_id;
        $branchId = $user->branch_id;

        $today = today();

        // Scope queries to branch if user is branch-scoped
        $branchScope = fn($q) => $user->isSuperAdmin() || $user->isCompanyAdmin() || !$branchId
            ? $q
            : $q->where('branch_id', $branchId);

        $todaySales = Order::where('tenant_id', $tenantId)
            ->whereDate('created_at', $today)
            ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $todayCollections = Payment::where('tenant_id', $tenantId)
            ->whereDate('payment_date', $today)
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $activeContracts = InstallmentContract::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('branch_id', $branchId))
            ->count();

        $overdueInstallments = InstallmentSchedule::where('tenant_id', $tenantId)
            ->where('status', 'overdue')
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->whereHas('contract', fn($cq) => $cq->where('branch_id', $branchId)))
            ->count();

        $newCustomers = Customer::where('tenant_id', $tenantId)
            ->whereDate('created_at', $today)
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('branch_id', $branchId))
            ->count();

        $newOrders = Order::where('tenant_id', $tenantId)
            ->whereDate('created_at', $today)
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('branch_id', $branchId))
            ->count();

        $latestPayments = Payment::where('tenant_id', $tenantId)
            ->with(['customer', 'contract'])
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->limit(5)
            ->get();

        $urgentAlerts = [];

        $overdueCount = InstallmentContract::where('tenant_id', $tenantId)
            ->where('status', 'overdue')
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('branch_id', $branchId))
            ->count();

        if ($overdueCount > 0) {
            $urgentAlerts[] = [
                'type' => 'overdue_contracts',
                'message' => "{$overdueCount} contracts are overdue",
                'count' => $overdueCount,
                'severity' => 'high',
            ];
        }

        $dueTodayCount = InstallmentSchedule::where('tenant_id', $tenantId)
            ->where('status', 'due_today')
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->whereHas('contract', fn($cq) => $cq->where('branch_id', $branchId)))
            ->count();

        if ($dueTodayCount > 0) {
            $urgentAlerts[] = [
                'type' => 'due_today',
                'message' => "{$dueTodayCount} installments due today",
                'count' => $dueTodayCount,
                'severity' => 'medium',
            ];
        }

        // Top selling products (last 30 days)
        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.created_at', '>=', now()->subDays(30))
            ->whereIn('orders.status', ['completed', 'converted_to_contract', 'approved'])
            ->when($branchId && !$user->isCompanyAdmin(), fn($q) => $q->where('orders.branch_id', $branchId))
            ->select('products.id', 'products.name', DB::raw('SUM(order_items.quantity) as total_qty'), DB::raw('SUM(order_items.total) as total_revenue'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => [
                'today_sales' => (float) $todaySales,
                'today_collections' => (float) $todayCollections,
                'active_contracts' => $activeContracts,
                'overdue_installments' => $overdueInstallments,
                'new_customers' => $newCustomers,
                'new_orders' => $newOrders,
            ],
            'latest_payments' => $latestPayments,
            'urgent_alerts' => $urgentAlerts,
            'top_products' => $topProducts,
        ]);
    }

    private function platformOverview(): JsonResponse
    {
        $now = now();
        $activeSubscriptions = Subscription::query()
            ->with('plan')
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get();

        $monthlyRecurringRevenue = $activeSubscriptions->sum(function (Subscription $subscription) {
            $plan = $subscription->plan;

            if (!$plan) {
                return 0;
            }

            return match ($plan->interval) {
                'monthly' => (float) $plan->price,
                'yearly' => (float) $plan->price / 12,
                default => 0,
            };
        });

        $expiringSoon = Subscription::query()
            ->with(['tenant', 'plan'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', $now)
            ->where('ends_at', '<=', $now->copy()->addDays(30))
            ->orderBy('ends_at')
            ->limit(10)
            ->get();

        $recentTenants = Tenant::query()
            ->withCount(['branches', 'users', 'subscriptions'])
            ->with(['latestSubscription.plan'])
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => [
                'total_tenants' => Tenant::count(),
                'active_tenants' => Tenant::where('status', 'active')->count(),
                'trial_tenants' => Tenant::where('status', 'trial')->count(),
                'suspended_tenants' => Tenant::where('status', 'suspended')->count(),
                'total_subscriptions' => Subscription::count(),
                'active_subscriptions' => $activeSubscriptions->count(),
                'expiring_subscriptions' => $expiringSoon->count(),
                'total_users' => User::where('is_super_admin', false)->count(),
                'total_branches' => Branch::count(),
                'monthly_recurring_revenue' => round($monthlyRecurringRevenue, 2),
            ],
            'tenant_status_breakdown' => Tenant::query()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'subscription_status_breakdown' => Subscription::query()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'recent_tenants' => $recentTenants,
            'expiring_subscriptions' => $expiringSoon,
        ]);
    }
}
