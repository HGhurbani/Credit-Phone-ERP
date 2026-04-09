import { useEffect, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import {
  TrendingUp, Banknote, FileText, AlertTriangle,
  Users, ShoppingCart, AlertCircle, Package, Receipt,
} from 'lucide-react';
import StatCard from '../../components/ui/StatCard';
import { dashboardApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate } from '../../utils/format';
import { dashboardAlertMessage } from '../../i18n/statusLabels';
import { clsx } from 'clsx';
import SuperAdminDashboardPage from './SuperAdminDashboardPage';

export default function DashboardPage() {
  const { t } = useLang();
  const navigate = useNavigate();
  const { user, hasPermission } = useAuth();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  if (user?.is_super_admin) {
    return <Navigate to="/platform/overview" replace />;
  }

  useEffect(() => {
    dashboardApi.get().then(res => {
      setData(res.data);
    }).catch(console.error).finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  const stats = data?.stats || {};

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('dashboard.title')}</h1>
          <p className="page-subtitle">{formatDate(new Date())}</p>
        </div>
      </div>

      {/* Urgent Alerts */}
      {data?.urgent_alerts?.length > 0 && (
        <div className="space-y-2">
          {data.urgent_alerts.map((alert, i) => (
            <div key={i} className={clsx(
              'flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium',
              alert.severity === 'high' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-yellow-50 text-yellow-700 border border-yellow-200'
            )}>
              <AlertCircle size={16} />
              <span>{dashboardAlertMessage(alert, t) || alert.message}</span>
            </div>
          ))}
        </div>
      )}

      {/* Stats Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <StatCard
          icon={TrendingUp}
          label={t('dashboard.todaySales')}
          value={formatCurrency(stats.today_sales)}
          color="blue"
        />
        <StatCard
          icon={Banknote}
          label={t('dashboard.todayCollections')}
          value={formatCurrency(stats.today_collections)}
          color="green"
        />
        <StatCard
          icon={FileText}
          label={t('dashboard.activeContracts')}
          value={stats.active_contracts}
          color="purple"
        />
        <StatCard
          icon={AlertTriangle}
          label={t('dashboard.overdueInstallments')}
          value={stats.overdue_installments}
          color="red"
        />
        <StatCard
          icon={Users}
          label={t('dashboard.newCustomers')}
          value={stats.new_customers}
          color="yellow"
        />
        <StatCard
          icon={ShoppingCart}
          label={t('dashboard.newOrders')}
          value={stats.new_orders}
          color="blue"
        />
      </div>

      {hasPermission('invoices.view') && (
        <div className="card p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-lg bg-primary-50 flex items-center justify-center flex-shrink-0">
              <Receipt className="text-primary-600" size={22} />
            </div>
            <div>
              <h2 className="font-semibold text-gray-900">{t('dashboard.invoiceActions')}</h2>
              <p className="text-sm text-gray-500 mt-0.5">{t('dashboard.invoiceActionsHint')}</p>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {hasPermission('payments.create') && (
              <button
                type="button"
                onClick={() => navigate('/invoices?status=unpaid')}
                className="btn-primary btn btn-sm"
              >
                {t('dashboard.viewUnpaidInvoices')}
              </button>
            )}
            <button
              type="button"
              onClick={() => navigate('/invoices')}
              className="btn-secondary btn btn-sm"
            >
              {t('dashboard.openInvoices')}
            </button>
          </div>
        </div>
      )}

      {/* Bottom Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Latest Payments */}
        <div className="card">
          <div className="card-header">
            <h2 className="font-semibold text-gray-900">{t('dashboard.latestPayments')}</h2>
          </div>
          <div className="divide-y divide-gray-50">
            {data?.latest_payments?.length === 0 ? (
              <div className="p-6 text-center text-gray-400 text-sm">{t('common.noData')}</div>
            ) : (
              data?.latest_payments?.map((payment) => (
                <div key={payment.id} className="flex items-center justify-between px-6 py-3">
                  <div>
                    <p className="text-sm font-medium text-gray-800">{payment.customer?.name}</p>
                    <p className="text-xs text-gray-400">{payment.contract?.contract_number} · {formatDate(payment.payment_date)}</p>
                  </div>
                  <span className="text-sm font-semibold text-green-600">{formatCurrency(payment.amount)}</span>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Top Products */}
        <div className="card">
          <div className="card-header">
            <h2 className="font-semibold text-gray-900">{t('dashboard.topProducts')}</h2>
          </div>
          <div className="divide-y divide-gray-50">
            {data?.top_products?.length === 0 ? (
              <div className="p-6 text-center text-gray-400 text-sm">{t('common.noData')}</div>
            ) : (
              data?.top_products?.map((product, i) => (
                <div key={product.id} className="flex items-center gap-3 px-6 py-3">
                  <span className="w-6 h-6 rounded-full bg-primary-50 text-primary-600 text-xs font-bold flex items-center justify-center">
                    {i + 1}
                  </span>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-800 truncate">{product.name}</p>
                    <p className="text-xs text-gray-400">{t('dashboard.unitsSold', { qty: product.total_qty })}</p>
                  </div>
                  <span className="text-sm font-semibold text-gray-700">{formatCurrency(product.total_revenue)}</span>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
