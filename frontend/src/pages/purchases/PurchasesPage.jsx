import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Eye } from 'lucide-react';
import { DataTable, Pagination } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge from '../../components/ui/Badge';
import { Select } from '../../components/ui/FormField';
import { purchaseOrdersApi, branchesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
import { useDebounce } from '../../hooks/useDebounce';

function poStatusVariant(s) {
  const map = { draft: 'gray', ordered: 'blue', partially_received: 'yellow', received: 'green', cancelled: 'red' };
  return map[s] || 'gray';
}

export default function PurchasesPage() {
  const { t } = useLang();
  const { user, hasPermission, hasRole } = useAuth();
  const navigate = useNavigate();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [branchId, setBranchId] = useState('');
  const [branches, setBranches] = useState([]);

  const debouncedSearch = useDebounce(search, 400);

  const showBranchFilter = !user?.branch_id && (hasRole('company_admin') || hasPermission('branches.view'));

  useEffect(() => {
    if (!showBranchFilter) return;
    branchesApi.list().then((r) => setBranches(r.data.data || [])).catch(() => {});
  }, [showBranchFilter]);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const params = { search: debouncedSearch, page, per_page: 15 };
      if (status) params.status = status;
      if (branchId) params.branch_id = branchId;
      const res = await purchaseOrdersApi.list(params);
      setRows(res.data.data);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, page, status, branchId, t]);

  useEffect(() => { fetchList(); }, [fetchList]);

  const columns = [
    {
      key: 'purchase_number',
      title: t('purchases.number'),
      render: (r) => <span className="font-mono text-sm font-medium">{r.purchase_number}</span>,
    },
    { key: 'supplier', title: t('purchases.supplier'), render: (r) => r.supplier?.name ?? '—' },
    { key: 'branch', title: t('common.branch'), render: (r) => r.branch?.name ?? '—' },
    { key: 'order_date', title: t('purchases.orderDate'), render: (r) => formatDate(r.order_date) },
    {
      key: 'status',
      title: t('common.status'),
      render: (r) => <Badge label={t(`purchases.status.${r.status}`)} variant={poStatusVariant(r.status)} />,
    },
    { key: 'total', title: t('common.total'), render: (r) => formatCurrency(r.total) },
    {
      key: 'actions',
      title: t('common.actions'),
      render: (r) => (
        <button type="button" onClick={() => navigate(`/purchases/${r.id}`)} className="btn-ghost btn btn-sm">
          <Eye size={14} />
        </button>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('purchases.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        {hasPermission('purchases.create') && (
          <button type="button" onClick={() => navigate('/purchases/new')} className="btn-primary btn">
            <Plus size={16} /> {t('purchases.add')}
          </button>
        )}
      </div>

      <div className="card p-4 flex flex-col sm:flex-row gap-3 flex-wrap">
        <SearchInput
          value={search}
          onChange={(v) => { setSearch(v); setPage(1); }}
          placeholder={t('purchases.searchPlaceholder')}
          className="max-w-sm flex-1 min-w-[200px]"
        />
        <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="w-full sm:w-44">
          <option value="">{t('common.all')} — {t('common.status')}</option>
          {['draft', 'ordered', 'partially_received', 'received', 'cancelled'].map((s) => (
            <option key={s} value={s}>{t(`purchases.status.${s}`)}</option>
          ))}
        </Select>
        {showBranchFilter && (
          <Select value={branchId} onChange={(e) => { setBranchId(e.target.value); setPage(1); }} className="w-full sm:w-48">
            <option value="">{t('ui.selectBranch')}</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </Select>
        )}
      </div>

      <DataTable columns={columns} data={rows} loading={loading} />
      <Pagination meta={meta} onPageChange={setPage} />
    </div>
  );
}
