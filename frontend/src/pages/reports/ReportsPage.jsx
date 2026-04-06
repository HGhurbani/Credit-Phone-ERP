import { useState } from 'react';
import { BarChart3, TrendingUp, CreditCard, FileText, AlertTriangle, Building2, User } from 'lucide-react';
import { reportsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import { reportFieldLabel, reportSummaryLabel, formatReportCellValue, isReportCurrencyKey } from '../../i18n/reportLabels';
import { clsx } from 'clsx';
import toast from 'react-hot-toast';

const REPORT_TYPES = [
  { key: 'sales', label: 'reports.sales', icon: TrendingUp },
  { key: 'collections', label: 'reports.collections', icon: CreditCard },
  { key: 'active_contracts', label: 'reports.activeContracts', icon: FileText },
  { key: 'overdue', label: 'reports.overdueInstallments', icon: AlertTriangle },
  { key: 'branch_performance', label: 'reports.branchPerformance', icon: Building2 },
  { key: 'agent_performance', label: 'reports.agentPerformance', icon: User },
];

function formatReportTableCell(key, val, t) {
  const nested = formatReportCellValue(val);
  if (nested != null) return nested;
  if (val == null || val === '') return t('common.emDash');
  if (typeof val === 'boolean') return val ? t('common.yes') : t('common.no');
  if (typeof val === 'number') {
    if (isReportCurrencyKey(key)) return formatCurrency(val);
    return val;
  }
  if (typeof val === 'string' && (key.includes('date') || key.endsWith('_at'))) {
    const d = Date.parse(val);
    if (!Number.isNaN(d)) return formatDate(val);
  }
  return String(val);
}

export default function ReportsPage() {
  const { t } = useLang();
  const [activeReport, setActiveReport] = useState('sales');
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0]);
  const [to, setTo] = useState(new Date().toISOString().split('T')[0]);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);

  const runReport = async () => {
    setLoading(true);
    try {
      const params = { date_from: from, date_to: to };
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
    } catch (e) {
      console.error(e);
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  const summaryValue = (key, value) => {
    if (value == null) return t('common.emDash');
    if (typeof value === 'number' && isReportCurrencyKey(key)) return formatCurrency(value);
    if (typeof value === 'number') return value;
    return String(value);
  };

  return (
    <div className="space-y-4">
      <div className="page-header">
        <h1 className="page-title">{t('reports.title')}</h1>
      </div>

      {/* Report Type Tabs */}
      <div className="grid grid-cols-3 md:grid-cols-6 gap-2">
        {REPORT_TYPES.map(rt => (
          <button
            key={rt.key}
            type="button"
            onClick={() => { setActiveReport(rt.key); setData(null); }}
            className={clsx(
              'flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 text-xs font-medium transition-all',
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

      {/* Date Filters */}
      {['sales', 'collections', 'branch_performance', 'agent_performance'].includes(activeReport) && (
        <div className="card p-4 flex flex-wrap items-end gap-3">
          <div>
            <label className="label text-xs">{t('reports.dateFrom')}</label>
            <input type="date" value={from} onChange={e => setFrom(e.target.value)} className="input w-40" />
          </div>
          <div>
            <label className="label text-xs">{t('reports.dateTo')}</label>
            <input type="date" value={to} onChange={e => setTo(e.target.value)} className="input w-40" />
          </div>
          <button type="button" onClick={runReport} disabled={loading} className="btn-primary btn">
            {loading ? t('common.loading') : t('reports.runReport')}
          </button>
        </div>
      )}

      {/* Results */}
      {!['sales', 'collections', 'branch_performance', 'agent_performance'].includes(activeReport) && (
        <div className="flex">
          <button type="button" onClick={runReport} disabled={loading} className="btn-primary btn">
            {loading ? t('common.loading') : t('reports.runReport')}
          </button>
        </div>
      )}

      {data && (
        <div className="space-y-4">
          {data.period && (
            <p className="text-sm text-gray-500">
              {t('reports.periodLabel', { from: formatDate(data.period.from), to: formatDate(data.period.to) })}
            </p>
          )}

          {/* Summary */}
          {data.summary && (
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              {Object.entries(data.summary).filter(([k]) => k !== 'id').map(([key, value]) => (
                <div key={key} className="card p-4">
                  <p className="text-xs text-gray-500 mb-1">{reportSummaryLabel(key, activeReport, t)}</p>
                  <p className="text-lg font-bold text-gray-900">
                    {summaryValue(key, value)}
                  </p>
                </div>
              ))}
            </div>
          )}

          {/* Table Data */}
          {data.data && data.data.length > 0 && (
            <div className="card overflow-x-auto">
              <table className="data-table">
                <thead>
                  <tr>
                    {Object.keys(data.data[0]).map(k => (
                      <th key={k}>{reportFieldLabel(k, t)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((row, i) => (
                    <tr key={i}>
                      {Object.keys(data.data[0]).map((key) => (
                        <td key={key}>
                          {formatReportTableCell(key, row[key], t)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Daily Breakdown */}
          {data.daily && data.daily.length > 0 && (
            <div className="card overflow-x-auto">
              <div className="card-header"><p className="font-semibold">{t('reports.dailyBreakdown')}</p></div>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{reportFieldLabel('date', t)}</th>
                    {Object.keys(data.daily[0]).filter(k => k !== 'date').map(k => (
                      <th key={k}>{reportFieldLabel(k, t)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.daily.map((row, i) => (
                    <tr key={i}>
                      <td>{formatDate(row.date)}</td>
                      {Object.entries(row).filter(([k]) => k !== 'date').map(([key, val]) => (
                        <td key={key}>
                          {typeof val === 'number' && isReportCurrencyKey(key)
                            ? formatCurrency(val)
                            : formatReportTableCell(key, val, t)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
