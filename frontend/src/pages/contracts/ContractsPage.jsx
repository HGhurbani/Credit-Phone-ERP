import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye } from 'lucide-react';
import { DataTable, Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge, { contractStatusBadge } from '../../components/ui/Badge';
import { contractsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import { useDebounce } from '../../hooks/useDebounce';
import toast from 'react-hot-toast';

export default function ContractsPage() {
  const { t } = useLang();
  const navigate = useNavigate();
  const [contracts, setContracts] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);

  const debouncedSearch = useDebounce(search, 400);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = await contractsApi.list({ search: debouncedSearch, status: statusFilter, page, per_page: getPerPageRequestValue(perPage) });
      setContracts(res.data.data);
      setMeta(res.data.meta);
    } catch { toast.error(t('common.error')); }
    finally { setLoading(false); }
  }, [debouncedSearch, statusFilter, page, perPage, t]);

  useEffect(() => { fetch(); }, [fetch]);

  const columns = [
    { key: 'contract_number', title: t('contracts.contractNumber'), render: r => <span className="font-mono text-sm font-medium">{r.contract_number}</span> },
    { key: 'customer', title: t('customers.name'), render: r => r.customer?.name || '—' },
    { key: 'monthly_amount', title: t('contracts.monthlyAmount'), render: r => formatCurrency(r.monthly_amount) },
    { key: 'remaining_amount', title: t('contracts.remainingAmount'), render: r => formatCurrency(r.remaining_amount) },
    { key: 'end_date', title: t('contracts.endDate'), render: r => formatDate(r.end_date) },
    {
      key: 'status', title: t('common.status'),
      render: r => { const s = contractStatusBadge(r.status); return <Badge label={t(s.labelKey)} variant={s.variant} />; },
    },
    { key: 'actions', title: '', render: r => <button onClick={() => navigate(`/contracts/${r.id}`)} className="btn-ghost btn btn-sm"><Eye size={14} /></button> },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('contracts.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
      </div>

      <div className="card p-4 flex flex-wrap gap-3">
        <SearchInput value={search} onChange={v => { setSearch(v); setPage(1); }} placeholder={t('customers.searchPlaceholder')} className="flex-1 min-w-48" />
        <select value={statusFilter} onChange={e => { setStatusFilter(e.target.value); setPage(1); }} className="input w-40">
          <option value="">{t('common.all')}</option>
          <option value="active">{t('contracts.statusActive')}</option>
          <option value="overdue">{t('contracts.statusOverdue')}</option>
          <option value="completed">{t('contracts.statusCompleted')}</option>
          <option value="cancelled">{t('contracts.statusCancelled')}</option>
        </select>
      </div>

      <DataTable columns={columns} data={contracts} loading={loading} />
      <Pagination
        meta={meta}
        onPageChange={setPage}
        pageSize={perPage}
        onPageSizeChange={(value) => { setPerPage(value); setPage(1); }}
      />
    </div>
  );
}
