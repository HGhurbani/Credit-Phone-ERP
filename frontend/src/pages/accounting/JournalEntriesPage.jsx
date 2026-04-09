import { useCallback, useEffect, useMemo, useState } from 'react';
import { BookText } from 'lucide-react';
import { branchesApi, journalEntriesApi } from '../../api/client';
import { Input, Select } from '../../components/ui/FormField';
import { Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate, formatDateTime } from '../../utils/format';
import toast from 'react-hot-toast';

const EVENT_OPTIONS = [
  'cash_sale_invoice',
  'cash_sale_invoice_reversal',
  'installment_contract',
  'contract_payment',
  'invoice_payment',
  'expense',
  'expense_reversal',
  'goods_receipt',
  'manual_cash_transaction',
];

const accountLabel = (t, account) => {
  if (!account) {
    return t('common.emDash');
  }

  if (account.system_key) {
    const translated = t(`journal.accounts.${account.system_key}`);
    if (translated !== `journal.accounts.${account.system_key}`) {
      return translated;
    }
  }

  return account.name || t('common.emDash');
};

const fallbackReference = (value) => {
  if (!value) return null;
  const match = String(value).match(/([A-Z]{2,5}-\d{3}-\d{6})$/);
  return match ? match[1] : null;
};

const entryDescriptionLabel = (t, entry) => {
  const reference = entry.source_reference || fallbackReference(entry.description);
  const params = { reference: reference || entry.entry_number };
  const translated = t(`journal.entryDescriptions.${entry.event}`, params);

  if (translated !== `journal.entryDescriptions.${entry.event}`) {
    return translated;
  }

  return entry.description || t('common.emDash');
};

const lineDescriptionKey = (entry, line) => {
  const accountKey = line.account?.system_key;

  switch (entry.event) {
    case 'cash_sale_invoice':
    case 'cash_sale_invoice_reversal':
      if (accountKey === 'accounts_receivable_trade') return 'invoice_receivable';
      if (accountKey === 'sales_revenue_cash') return 'cash_sale';
      if (accountKey === 'cost_of_goods_sold') return 'cogs';
      if (accountKey === 'inventory') return 'inventory_issued';
      return null;

    case 'installment_contract':
      if (accountKey === 'accounts_receivable_installment') return 'installment_receivable';
      if (accountKey === 'sales_revenue_installment') return 'installment_sale';
      if (accountKey === 'cost_of_goods_sold') return 'cogs';
      if (accountKey === 'inventory') return 'inventory_issued';
      return null;

    case 'contract_payment':
    case 'invoice_payment':
      return Number(line.debit) > 0 ? 'receipt' : 'settlement';

    case 'expense':
    case 'expense_reversal':
      return accountKey === 'general_expense' ? 'expense' : 'expense_source';

    case 'goods_receipt':
      if (accountKey === 'inventory') return 'inventory_received';
      if (accountKey === 'goods_received_not_billed') return 'goods_received_not_billed';
      return null;

    case 'manual_cash_transaction': {
      const txType = entry.source_meta?.transaction_type;
      const direction = entry.source_meta?.direction;

      if (txType === 'other_in') return 'manual_cash_in';
      if (txType === 'other_out') return 'manual_cash_out';
      if (txType === 'purchase_payment_out') return 'purchase_payment';
      if (txType === 'manual_adjustment' && direction === 'in') return 'cash_adjustment_in';
      if (txType === 'manual_adjustment' && direction === 'out') return 'cash_adjustment_out';
      return null;
    }

    default:
      return null;
  }
};

const lineDescriptionLabel = (t, entry, line) => {
  const key = lineDescriptionKey(entry, line);
  const reference = entry.source_reference || fallbackReference(line.description) || fallbackReference(entry.description);
  const params = { reference: reference || entry.entry_number };

  if (key) {
    const translated = t(`journal.lineDescriptions.${key}`, params);
    if (translated !== `journal.lineDescriptions.${key}`) {
      return translated;
    }
  }

  return line.description || t('common.emDash');
};

export default function JournalEntriesPage() {
  const { t } = useLang();
  const { user, hasRole, hasPermission } = useAuth();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [event, setEvent] = useState('');
  const [branchId, setBranchId] = useState('');
  const [branches, setBranches] = useState([]);
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  const showBranchFilter = !user?.branch_id && (hasRole('company_admin') || hasPermission('branches.view'));

  useEffect(() => {
    if (showBranchFilter) {
      branchesApi.list().then((r) => setBranches(r.data.data || [])).catch(() => {});
    }
  }, [showBranchFilter]);

  const eventOptions = useMemo(() => EVENT_OPTIONS.map((value) => ({
    value,
    label: t(`journal.events.${value}`),
  })), [t]);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const params = { page, per_page: getPerPageRequestValue(perPage) };
      if (query) params.search = query;
      if (event) params.event = event;
      if (branchId) params.branch_id = branchId;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;

      const res = await journalEntriesApi.list(params);
      setRows(res.data.data || []);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [page, perPage, query, event, branchId, dateFrom, dateTo, t]);

  useEffect(() => { fetchList(); }, [fetchList]);

  const applySearch = (e) => {
    e.preventDefault();
    setPage(1);
    setQuery(search.trim());
  };

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl bg-primary-50 flex items-center justify-center">
            <BookText className="text-primary-600" size={20} />
          </div>
          <div>
            <h1 className="page-title">{t('journal.title')}</h1>
            <p className="page-subtitle text-gray-500">{meta?.total ?? 0} {t('common.results')}</p>
          </div>
        </div>
      </div>

      <form onSubmit={applySearch} className="card p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t('journal.searchPlaceholder')}
        />
        <Select value={event} onChange={(e) => { setEvent(e.target.value); setPage(1); }}>
          <option value="">{t('journal.allEvents')}</option>
          {eventOptions.map((item) => (
            <option key={item.value} value={item.value}>{item.label}</option>
          ))}
        </Select>
        {showBranchFilter && (
          <Select value={branchId} onChange={(e) => { setBranchId(e.target.value); setPage(1); }}>
            <option value="">{t('ui.selectBranch')}</option>
            {branches.map((branch) => (
              <option key={branch.id} value={branch.id}>{branch.name}</option>
            ))}
          </Select>
        )}
        <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} />
        <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} />
        <div className="xl:col-span-5 flex gap-2">
          <button type="submit" className="btn-primary btn btn-sm">{t('common.search')}</button>
          <button
            type="button"
            className="btn-secondary btn btn-sm"
            onClick={() => {
              setSearch('');
              setQuery('');
              setEvent('');
              setBranchId('');
              setDateFrom('');
              setDateTo('');
              setPage(1);
            }}
          >
            {t('common.filter')}
          </button>
        </div>
      </form>

      {loading ? (
        <div className="flex items-center justify-center h-40">
          <div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" />
        </div>
      ) : rows.length === 0 ? (
        <div className="card p-10 text-center text-gray-500">{t('common.noData')}</div>
      ) : (
        <div className="space-y-4">
          {rows.map((entry) => (
            <div key={entry.id} className="card p-4 space-y-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2 flex-wrap">
                    <h2 className="font-mono text-sm font-semibold text-gray-900">{entry.entry_number}</h2>
                    <span className={`text-xs px-2 py-1 rounded-full ${entry.status === 'reversed' ? 'bg-amber-50 text-amber-700' : 'bg-green-50 text-green-700'}`}>
                      {t(`journal.status.${entry.status}`)}
                    </span>
                  </div>
                  <p className="text-sm text-gray-700 mt-1">{entryDescriptionLabel(t, entry)}</p>
                  <p className="text-xs text-gray-500 mt-1">
                    {formatDate(entry.entry_date)}
                    {' · '}
                    {t(`journal.events.${entry.event}`)}
                    {' · '}
                    {entry.branch?.name || t('common.emDash')}
                  </p>
                </div>
                <div className="text-xs text-gray-500 text-right">
                  <p>{t('journal.source')}: {entry.source_type} #{entry.source_id}</p>
                  <p>{t('journal.postedAt')}: {formatDateTime(entry.posted_at)}</p>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('journal.account')}</th>
                      <th>{t('common.notes')}</th>
                      <th>{t('journal.debit')}</th>
                      <th>{t('journal.credit')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {entry.lines?.map((line) => (
                      <tr key={line.id}>
                        <td>
                          <div className="font-medium text-gray-900">{accountLabel(t, line.account)}</div>
                          <div className="text-xs text-gray-500 font-mono">{line.account?.code || t('common.emDash')}</div>
                        </td>
                        <td>{lineDescriptionLabel(t, entry, line)}</td>
                        <td>{Number(line.debit) > 0 ? formatCurrency(line.debit) : t('common.emDash')}</td>
                        <td>{Number(line.credit) > 0 ? formatCurrency(line.credit) : t('common.emDash')}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr>
                      <td colSpan={2} className="font-semibold">{t('common.total')}</td>
                      <td className="font-semibold">{formatCurrency(entry.totals?.debit)}</td>
                      <td className="font-semibold">{formatCurrency(entry.totals?.credit)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          ))}
        </div>
      )}

      <Pagination
        meta={meta}
        onPageChange={setPage}
        pageSize={perPage}
        onPageSizeChange={(value) => { setPerPage(value); setPage(1); }}
      />
    </div>
  );
}
