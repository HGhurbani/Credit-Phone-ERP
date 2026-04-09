import { useMemo, useState } from 'react';
import { Building2, CreditCard, FileText, TrendingUp, AlertTriangle, User } from 'lucide-react';
import { clsx } from 'clsx';
import toast from 'react-hot-toast';
import { reportsApi } from '../../api/client';
import { Pagination, getPerPageRequestValue, useLocalPagination } from '../../components/ui/Table';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate, formatDateTime, formatNumber } from '../../utils/format';
import {
  formatReportCellValue,
  isReportCurrencyKey,
  isReportPercentageKey,
  reportFieldLabel,
  reportSummaryLabel,
} from '../../i18n/reportLabels';

const REPORT_TYPES = [
  { key: 'sales', label: 'reports.sales', icon: TrendingUp },
  { key: 'collections', label: 'reports.collections', icon: CreditCard },
  { key: 'active_contracts', label: 'reports.activeContracts', icon: FileText },
  { key: 'overdue', label: 'reports.overdueInstallments', icon: AlertTriangle },
  { key: 'branch_performance', label: 'reports.branchPerformance', icon: Building2 },
  { key: 'agent_performance', label: 'reports.agentPerformance', icon: User },
];

const TONE_STYLES = {
  neutral: 'border-slate-200 bg-white',
  success: 'border-emerald-200 bg-emerald-50/70',
  warning: 'border-amber-200 bg-amber-50/70',
  danger: 'border-rose-200 bg-rose-50/70',
  info: 'border-sky-200 bg-sky-50/70',
};

function toNumber(value) {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))) return Number(value);
  return null;
}

function formatMetricValue(key, value, t) {
  if (value == null || value === '') return t('common.emDash');

  const numeric = toNumber(value);
  if (numeric != null) {
    if (isReportPercentageKey(key)) return `${formatNumber(numeric)}%`;
    if (isReportCurrencyKey(key)) return formatCurrency(numeric);
    return formatNumber(numeric);
  }

  return String(value);
}

function formatReportTableCell(key, value, t) {
  const normalized = formatReportCellValue(value);
  const resolved = normalized ?? value;

  if (resolved == null || resolved === '') return t('common.emDash');
  if (typeof resolved === 'boolean') return resolved ? t('common.yes') : t('common.no');

  if (typeof resolved === 'string') {
    if (key === 'severity') {
      const label = t(`reports.enums.severity.${resolved}`);
      if (label !== `reports.enums.severity.${resolved}`) return label;
    }

    if (key === 'status') {
      const reportStatus = t(`reports.enums.status.${resolved}`);
      if (reportStatus !== `reports.enums.status.${resolved}`) return reportStatus;

      const scheduleStatus = t(`collections.scheduleStatus.${resolved}`);
      if (scheduleStatus !== `collections.scheduleStatus.${resolved}`) return scheduleStatus;
    }
  }

  const numeric = toNumber(resolved);
  if (numeric != null) return formatMetricValue(key, numeric, t);

  if (typeof resolved === 'string' && (key.includes('date') || key.endsWith('_at'))) {
    const d = Date.parse(resolved);
    if (!Number.isNaN(d)) {
      return key.endsWith('_at') ? formatDateTime(resolved) : formatDate(resolved);
    }
  }

  return String(resolved);
}

function summarizeRowDate(row, key = 'date') {
  if (!row?.[key]) return null;
  return formatDate(row[key]);
}

function getMaxRow(rows, key) {
  if (!rows?.length) return null;
  return rows.reduce((best, row) => {
    const current = toNumber(row[key]) ?? 0;
    const bestVal = toNumber(best?.[key]) ?? -Infinity;
    return current > bestVal ? row : best;
  }, null);
}

function getMinRow(rows, key, predicate = () => true) {
  const filtered = rows?.filter(predicate) ?? [];
  if (!filtered.length) return null;
  return filtered.reduce((best, row) => {
    const current = toNumber(row[key]) ?? Infinity;
    const bestVal = toNumber(best?.[key]) ?? Infinity;
    return current < bestVal ? row : best;
  }, null);
}

function buildDecisionCues(activeReport, data, t) {
  const summary = data?.summary ?? {};
  const daily = data?.daily ?? [];
  const rows = data?.data ?? [];

  switch (activeReport) {
    case 'sales': {
      const bestDay = getMaxRow(daily, 'revenue');
      const avgDailyRevenue = daily.length
        ? daily.reduce((sum, row) => sum + (toNumber(row.revenue) ?? 0), 0) / daily.length
        : 0;

      return [
        {
          key: 'avg_order_value',
          label: t('reports.fields.avg_order_value'),
          value: formatMetricValue('avg_order_value', summary.avg_order_value, t),
          caption: t('reports.cues.avgOrderValueCaption'),
          tone: 'info',
        },
        {
          key: 'installment_share_percentage',
          label: t('reports.fields.installment_share_percentage'),
          value: formatMetricValue('installment_share_percentage', summary.installment_share_percentage, t),
          caption: t('reports.cues.installmentShareCaption'),
          tone: (toNumber(summary.installment_share_percentage) ?? 0) >= 60 ? 'warning' : 'neutral',
        },
        {
          key: 'best_sales_day',
          label: t('reports.cues.bestSalesDay'),
          value: bestDay ? summarizeRowDate(bestDay) : t('common.emDash'),
          caption: bestDay ? formatMetricValue('revenue', bestDay.revenue, t) : t('reports.noDataForPeriod'),
          tone: 'success',
        },
        {
          key: 'average_daily_revenue',
          label: t('reports.cues.averageDailyRevenue'),
          value: formatMetricValue('revenue', avgDailyRevenue, t),
          caption: t('reports.cues.averageDailyRevenueCaption'),
          tone: 'neutral',
        },
      ];
    }

    case 'collections': {
      const bestDay = getMaxRow(daily, 'amount');
      const avgDailyCollections = daily.length
        ? daily.reduce((sum, row) => sum + (toNumber(row.amount) ?? 0), 0) / daily.length
        : 0;

      return [
        {
          key: 'avg_payment_value',
          label: t('reports.fields.avg_payment_value'),
          value: formatMetricValue('avg_payment_value', summary.avg_payment_value, t),
          caption: t('reports.cues.avgPaymentValueCaption'),
          tone: 'info',
        },
        {
          key: 'cash_share_percentage',
          label: t('reports.fields.cash_share_percentage'),
          value: formatMetricValue('cash_share_percentage', summary.cash_share_percentage, t),
          caption: t('reports.cues.cashShareCaption'),
          tone: (toNumber(summary.cash_share_percentage) ?? 0) >= 80 ? 'warning' : 'neutral',
        },
        {
          key: 'best_collection_day',
          label: t('reports.cues.bestCollectionDay'),
          value: bestDay ? summarizeRowDate(bestDay) : t('common.emDash'),
          caption: bestDay ? formatMetricValue('amount', bestDay.amount, t) : t('reports.noDataForPeriod'),
          tone: 'success',
        },
        {
          key: 'average_daily_collections',
          label: t('reports.cues.averageDailyCollections'),
          value: formatMetricValue('amount', avgDailyCollections, t),
          caption: t('reports.cues.averageDailyCollectionsCaption'),
          tone: 'neutral',
        },
      ];
    }

    case 'active_contracts': {
      const atRiskContracts = rows.filter((row) => (toNumber(row.overdue_installments_count) ?? 0) > 0).length;

      return [
        {
          key: 'portfolio_value',
          label: t('reports.fields.portfolio_value'),
          value: formatMetricValue('portfolio_value', summary.portfolio_value, t),
          caption: t('reports.cues.portfolioValueCaption'),
          tone: 'info',
        },
        {
          key: 'collection_progress_percent',
          label: t('reports.fields.collection_progress_percent'),
          value: formatMetricValue('collection_progress_percent', summary.collection_progress_percent, t),
          caption: t('reports.cues.collectionProgressCaption'),
          tone: (toNumber(summary.collection_progress_percent) ?? 0) >= 50 ? 'success' : 'warning',
        },
        {
          key: 'at_risk_contracts',
          label: t('reports.cues.atRiskContracts'),
          value: formatNumber(atRiskContracts),
          caption: t('reports.cues.atRiskContractsCaption'),
          tone: atRiskContracts > 0 ? 'warning' : 'success',
        },
        {
          key: 'avg_remaining_per_contract',
          label: t('reports.fields.avg_remaining_per_contract'),
          value: formatMetricValue('avg_remaining_per_contract', summary.avg_remaining_per_contract, t),
          caption: t('reports.cues.avgRemainingCaption'),
          tone: 'neutral',
        },
      ];
    }

    case 'overdue': {
      return [
        {
          key: 'total_overdue',
          label: t('reports.fields.total_overdue'),
          value: formatMetricValue('total_overdue', summary.total_overdue, t),
          caption: t('reports.cues.totalOverdueCaption'),
          tone: 'danger',
        },
        {
          key: 'avg_days_overdue',
          label: t('reports.fields.avg_days_overdue'),
          value: formatMetricValue('avg_days_overdue', summary.avg_days_overdue, t),
          caption: t('reports.cues.avgDaysOverdueCaption'),
          tone: (toNumber(summary.avg_days_overdue) ?? 0) >= 30 ? 'danger' : 'warning',
        },
        {
          key: 'critical_count',
          label: t('reports.fields.critical_count'),
          value: formatMetricValue('critical_count', summary.critical_count, t),
          caption: t('reports.cues.criticalCasesCaption'),
          tone: (toNumber(summary.critical_count) ?? 0) > 0 ? 'danger' : 'neutral',
        },
        {
          key: 'unique_customers',
          label: t('reports.fields.unique_customers'),
          value: formatMetricValue('unique_customers', summary.unique_customers, t),
          caption: t('reports.cues.uniqueCustomersCaption'),
          tone: 'neutral',
        },
      ];
    }

    case 'branch_performance': {
      const topBranch = getMaxRow(rows, 'total_sales');
      const weakBranch = getMinRow(rows, 'collection_to_sales_ratio', (row) => (toNumber(row.total_sales) ?? 0) > 0);
      const branchesNeedingFollowUp = rows.filter((row) => (toNumber(row.outstanding_gap) ?? 0) > 0).length;

      return [
        {
          key: 'top_branch',
          label: t('reports.cues.topBranch'),
          value: topBranch?.name ?? t('common.emDash'),
          caption: topBranch ? formatMetricValue('total_sales', topBranch.total_sales, t) : t('reports.noDataForPeriod'),
          tone: 'success',
        },
        {
          key: 'avg_collection_to_sales_ratio',
          label: t('reports.fields.avg_collection_to_sales_ratio'),
          value: formatMetricValue('avg_collection_to_sales_ratio', summary.avg_collection_to_sales_ratio, t),
          caption: t('reports.cues.avgCollectionRatioCaption'),
          tone: (toNumber(summary.avg_collection_to_sales_ratio) ?? 0) >= 70 ? 'success' : 'warning',
        },
        {
          key: 'weakest_branch',
          label: t('reports.cues.weakestCollectionBranch'),
          value: weakBranch?.name ?? t('common.emDash'),
          caption: weakBranch ? formatMetricValue('collection_to_sales_ratio', weakBranch.collection_to_sales_ratio, t) : t('reports.noDataForPeriod'),
          tone: weakBranch ? 'warning' : 'neutral',
        },
        {
          key: 'branches_needing_followup',
          label: t('reports.cues.branchesNeedingFollowUp'),
          value: formatNumber(branchesNeedingFollowUp),
          caption: t('reports.cues.branchesNeedingFollowUpCaption'),
          tone: branchesNeedingFollowUp > 0 ? 'warning' : 'success',
        },
      ];
    }

    case 'agent_performance': {
      const topAgent = getMaxRow(rows, 'total_sales');
      const highInstallmentAgent = getMaxRow(rows.filter((row) => (toNumber(row.total_sales) ?? 0) > 0), 'installment_share_percentage');

      return [
        {
          key: 'top_agent',
          label: t('reports.cues.topAgent'),
          value: topAgent?.name ?? t('common.emDash'),
          caption: topAgent ? formatMetricValue('total_sales', topAgent.total_sales, t) : t('reports.noDataForPeriod'),
          tone: 'success',
        },
        {
          key: 'avg_sales_per_agent',
          label: t('reports.fields.avg_sales_per_agent'),
          value: formatMetricValue('avg_sales_per_agent', summary.avg_sales_per_agent, t),
          caption: t('reports.cues.avgSalesPerAgentCaption'),
          tone: 'info',
        },
        {
          key: 'active_agents',
          label: t('reports.fields.active_agents'),
          value: formatMetricValue('active_agents', summary.active_agents, t),
          caption: t('reports.cues.activeAgentsCaption'),
          tone: 'neutral',
        },
        {
          key: 'highest_installment_mix',
          label: t('reports.cues.highestInstallmentMix'),
          value: highInstallmentAgent?.name ?? t('common.emDash'),
          caption: highInstallmentAgent
            ? formatMetricValue('installment_share_percentage', highInstallmentAgent.installment_share_percentage, t)
            : t('reports.noDataForPeriod'),
          tone: 'warning',
        },
      ];
    }

    default:
      return [];
  }
}

function buildDecisionHints(activeReport, data, t) {
  const summary = data?.summary ?? {};
  const rows = data?.data ?? [];
  const hints = [];

  switch (activeReport) {
    case 'sales':
      if ((toNumber(summary.installment_share_percentage) ?? 0) >= 60) {
        hints.push(t('reports.hints.salesInstallmentHeavy', {
          value: formatMetricValue('installment_share_percentage', summary.installment_share_percentage, t),
        }));
      }
      if ((toNumber(summary.avg_order_value) ?? 0) > 0) {
        hints.push(t('reports.hints.salesAverageOrder', {
          value: formatMetricValue('avg_order_value', summary.avg_order_value, t),
        }));
      }
      break;

    case 'collections':
      if ((toNumber(summary.cash_share_percentage) ?? 0) >= 80) {
        hints.push(t('reports.hints.collectionsCashHeavy', {
          value: formatMetricValue('cash_share_percentage', summary.cash_share_percentage, t),
        }));
      }
      if ((toNumber(summary.avg_payment_value) ?? 0) > 0) {
        hints.push(t('reports.hints.collectionsAveragePayment', {
          value: formatMetricValue('avg_payment_value', summary.avg_payment_value, t),
        }));
      }
      break;

    case 'active_contracts': {
      const atRisk = rows.filter((row) => (toNumber(row.overdue_installments_count) ?? 0) > 0).length;
      if (atRisk > 0) {
        hints.push(t('reports.hints.activeContractsRisk', { count: formatNumber(atRisk) }));
      }
      hints.push(t('reports.hints.activeContractsProgress', {
        value: formatMetricValue('collection_progress_percent', summary.collection_progress_percent, t),
      }));
      break;
    }

    case 'overdue':
      if ((toNumber(summary.critical_count) ?? 0) > 0) {
        hints.push(t('reports.hints.overdueCriticalCases', {
          count: formatMetricValue('critical_count', summary.critical_count, t),
        }));
      }
      hints.push(t('reports.hints.overdueAverageAge', {
        value: formatMetricValue('avg_days_overdue', summary.avg_days_overdue, t),
      }));
      break;

    case 'branch_performance': {
      const weakBranch = getMinRow(rows, 'collection_to_sales_ratio', (row) => (toNumber(row.total_sales) ?? 0) > 0);
      if (weakBranch) {
        hints.push(t('reports.hints.branchWeakCollection', {
          branch: weakBranch.name,
          value: formatMetricValue('collection_to_sales_ratio', weakBranch.collection_to_sales_ratio, t),
        }));
      }
      break;
    }

    case 'agent_performance': {
      const topAgent = getMaxRow(rows, 'total_sales');
      if (topAgent) {
        hints.push(t('reports.hints.agentTopPerformer', {
          agent: topAgent.name,
          value: formatMetricValue('total_sales', topAgent.total_sales, t),
        }));
      }
      break;
    }

    default:
      break;
  }

  return hints.slice(0, 3);
}

export default function ReportsPage() {
  const { t } = useLang();
  const [activeReport, setActiveReport] = useState('sales');
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0]);
  const [to, setTo] = useState(new Date().toISOString().split('T')[0]);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [reportPage, setReportPage] = useState(1);
  const [reportPerPage, setReportPerPage] = useState(20);

  const usesServerRowsPagination = ['active_contracts', 'overdue'].includes(activeReport);
  const localRowsPagination = useLocalPagination(usesServerRowsPagination ? [] : (data?.data ?? []), 20);
  const dailyPagination = useLocalPagination(data?.daily ?? [], 20);

  const runReport = async (overrides = {}) => {
    setLoading(true);
    try {
      const params = { date_from: from, date_to: to };
      if (usesServerRowsPagination) {
        params.page = overrides.page ?? reportPage;
        params.per_page = getPerPageRequestValue(overrides.perPage ?? reportPerPage);
      }
      let res;
      switch (activeReport) {
        case 'sales': res = await reportsApi.sales(params); break;
        case 'collections': res = await reportsApi.collections(params); break;
        case 'active_contracts': res = await reportsApi.activeContracts(params); break;
        case 'overdue': res = await reportsApi.overdueInstallments(params); break;
        case 'branch_performance': res = await reportsApi.branchPerformance(params); break;
        case 'agent_performance': res = await reportsApi.agentPerformance(params); break;
        default: return;
      }
      setData(res.data);
      if (overrides.page !== undefined) setReportPage(overrides.page);
      if (overrides.perPage !== undefined) setReportPerPage(overrides.perPage);
    } catch (e) {
      console.error(e);
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  const decisionCues = useMemo(() => buildDecisionCues(activeReport, data, t), [activeReport, data, t]);
  const decisionHints = useMemo(() => buildDecisionHints(activeReport, data, t), [activeReport, data, t]);

  const summaryEntries = Object.entries(data?.summary ?? {}).filter(([key]) => key !== 'id');
  const hasTableData = Array.isArray(data?.data) && data.data.length > 0;
  const hasDailyData = Array.isArray(data?.daily) && data.daily.length > 0;
  const hasResults = Boolean(data) && (summaryEntries.length > 0 || hasTableData || hasDailyData);

  return (
    <div className="space-y-4">
      <div className="page-header">
        <h1 className="page-title">{t('reports.title')}</h1>
      </div>

      <div className="grid grid-cols-3 gap-2 md:grid-cols-6">
        {REPORT_TYPES.map((rt) => (
          <button
            key={rt.key}
            type="button"
            onClick={() => {
              setActiveReport(rt.key);
              setData(null);
              setReportPage(1);
              setReportPerPage(20);
            }}
            className={clsx(
              'flex flex-col items-center gap-1.5 rounded-xl border-2 p-3 text-xs font-medium transition-all',
              activeReport === rt.key
                ? 'border-primary-600 bg-primary-50 text-primary-700'
                : 'border-gray-200 text-gray-500 hover:border-gray-300'
            )}
          >
            <rt.icon size={18} />
            {t(rt.label)}
          </button>
        ))}
      </div>

      {['sales', 'collections', 'branch_performance', 'agent_performance'].includes(activeReport) && (
        <div className="card flex flex-wrap items-end gap-3 p-4">
          <div>
            <label className="label text-xs">{t('reports.dateFrom')}</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="input w-40" />
          </div>
          <div>
            <label className="label text-xs">{t('reports.dateTo')}</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="input w-40" />
          </div>
          <button type="button" onClick={runReport} disabled={loading} className="btn btn-primary">
            {loading ? t('common.loading') : t('reports.runReport')}
          </button>
        </div>
      )}

      {!['sales', 'collections', 'branch_performance', 'agent_performance'].includes(activeReport) && (
        <div className="flex">
          <button type="button" onClick={runReport} disabled={loading} className="btn btn-primary">
            {loading ? t('common.loading') : t('reports.runReport')}
          </button>
        </div>
      )}

      {data && (
        <div className="space-y-4">
          <div className="card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="space-y-1">
                <p className="text-sm font-semibold text-gray-900">{t('reports.executiveSummary')}</p>
                {data.period && (
                  <p className="text-sm text-gray-500">
                    {t('reports.periodLabel', { from: formatDate(data.period.from), to: formatDate(data.period.to) })}
                  </p>
                )}
              </div>
              {data.generated_at && (
                <p className="text-xs text-gray-400">
                  {t('reports.generatedAt', { value: formatDateTime(data.generated_at) })}
                </p>
              )}
            </div>

            {decisionCues.length > 0 && (
              <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                {decisionCues.map((cue) => (
                  <div key={cue.key} className={clsx('rounded-2xl border p-4', TONE_STYLES[cue.tone] ?? TONE_STYLES.neutral)}>
                    <p className="text-xs font-medium text-gray-500">{cue.label}</p>
                    <p className="mt-2 text-xl font-bold text-gray-900">{cue.value}</p>
                    <p className="mt-1 text-xs text-gray-500">{cue.caption}</p>
                  </div>
                ))}
              </div>
            )}

            {decisionHints.length > 0 && (
              <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p className="text-sm font-semibold text-slate-900">{t('reports.decisionHints')}</p>
                <ul className="mt-2 space-y-2 text-sm text-slate-700">
                  {decisionHints.map((hint, index) => (
                    <li key={index}>{hint}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>

          {summaryEntries.length > 0 && (
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              {summaryEntries.map(([key, value]) => (
                <div key={key} className="card p-4">
                  <p className="mb-1 text-xs text-gray-500">{reportSummaryLabel(key, activeReport, t)}</p>
                  <p className="text-lg font-bold text-gray-900">{formatMetricValue(key, value, t)}</p>
                </div>
              ))}
            </div>
          )}

          {hasTableData && (
            <div className="card overflow-x-auto">
              <div className="card-header flex items-center justify-between gap-3">
                <p className="font-semibold">{t('reports.detailedRows')}</p>
                {data.meta?.total != null && (
                  <p className="text-xs text-gray-500">
                    {t('reports.totalRows', { count: formatNumber(data.meta.total) })}
                  </p>
                )}
              </div>
              <table className="data-table">
                <thead>
                  <tr>
                    {Object.keys(data.data[0]).map((key) => (
                      <th key={key}>{reportFieldLabel(key, t)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {(usesServerRowsPagination ? data.data : localRowsPagination.rows).map((row, index) => (
                    <tr key={index}>
                      {Object.keys(data.data[0]).map((key) => (
                        <td key={key}>{formatReportTableCell(key, row[key], t)}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="px-4 pb-4">
                {usesServerRowsPagination ? (
                  <Pagination
                    meta={data.meta}
                    onPageChange={(nextPage) => runReport({ page: nextPage })}
                    pageSize={reportPerPage}
                    onPageSizeChange={(value) => runReport({ page: 1, perPage: value })}
                  />
                ) : (
                  <Pagination
                    total={localRowsPagination.total}
                    currentPage={localRowsPagination.page}
                    lastPage={localRowsPagination.lastPage}
                    perPage={localRowsPagination.perPage}
                    pageSize={localRowsPagination.pageSize}
                    onPageChange={localRowsPagination.setPage}
                    onPageSizeChange={(value) => { localRowsPagination.setPageSize(value); localRowsPagination.setPage(1); }}
                  />
                )}
              </div>
            </div>
          )}

          {hasDailyData && (
            <div className="card overflow-x-auto">
              <div className="card-header">
                <p className="font-semibold">{t('reports.dailyBreakdown')}</p>
              </div>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{reportFieldLabel('date', t)}</th>
                    {Object.keys(data.daily[0]).filter((key) => key !== 'date').map((key) => (
                      <th key={key}>{reportFieldLabel(key, t)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {dailyPagination.rows.map((row, index) => (
                    <tr key={index}>
                      <td>{formatDate(row.date)}</td>
                      {Object.entries(row).filter(([key]) => key !== 'date').map(([key, value]) => (
                        <td key={key}>{formatReportTableCell(key, value, t)}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="px-4 pb-4">
                <Pagination
                  total={dailyPagination.total}
                  currentPage={dailyPagination.page}
                  lastPage={dailyPagination.lastPage}
                  perPage={dailyPagination.perPage}
                  pageSize={dailyPagination.pageSize}
                  onPageChange={dailyPagination.setPage}
                  onPageSizeChange={(value) => { dailyPagination.setPageSize(value); dailyPagination.setPage(1); }}
                />
              </div>
            </div>
          )}

          {!hasResults && (
            <div className="card p-8 text-center text-sm text-gray-500">
              {t('reports.noDataForPeriod')}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
