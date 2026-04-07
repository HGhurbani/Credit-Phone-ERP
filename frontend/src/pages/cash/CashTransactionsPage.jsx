import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { DataTable, Pagination } from '../../components/ui/Table';
import { Select } from '../../components/ui/FormField';
import { cashTransactionsApi, branchesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
export default function CashTransactionsPage() {
  const { t, isRTL } = useLang();
  const { user, hasRole, hasPermission } = useAuth();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [direction, setDirection] = useState('');
  const [branchId, setBranchId] = useState('');
  const [branches, setBranches] = useState([]);

  const showBranchFilter = !user?.branch_id && (hasRole('company_admin') || hasPermission('branches.view'));

  useEffect(() => {
    if (showBranchFilter) {
      branchesApi.list().then((r) => setBranches(r.data.data || [])).catch(() => {});
    }
  }, [showBranchFilter]);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const params = { page, per_page: 20 };
      if (direction) params.direction = direction;
      if (branchId) params.branch_id = branchId;
      const res = await cashTransactionsApi.list(params);
      setRows(res.data.data);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [page, direction, branchId, t]);

  useEffect(() => { fetchList(); }, [fetchList]);

  const columns = [
    { key: 'voucher', title: t('cash.voucher'), render: (r) => <span className="font-mono text-xs">{r.voucher_number || '—'}</span> },
    { key: 'd', title: t('common.date'), render: (r) => formatDate(r.transaction_date) },
    { key: 'b', title: t('common.branch'), render: (r) => r.branch?.name ?? '—' },
    { key: 'type', title: t('cash.txType'), render: (r) => r.transaction_type },
    {
      key: 'amt',
      title: t('common.amount'),
      render: (r) => (
        <span className={r.direction === 'in' ? 'text-green-700' : 'text-red-700'}>
          {r.direction === 'in' ? '+' : '−'}{formatCurrency(r.amount)}
        </span>
      ),
    },
    {
      key: 'a',
      title: t('common.actions'),
      render: (r) => (
        <Link to={`/cash/voucher/${r.id}`} target="_blank" rel="noreferrer" className="text-primary-600 text-sm hover:underline">
          {t('cash.print')}
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3">
          <Link to="/cash" className="btn-ghost btn btn-sm"><BackIcon size={16} /></Link>
          <div>
            <h1 className="page-title">{t('cash.ledgerTitle')}</h1>
            <p className="page-subtitle text-gray-500">{meta?.total ?? 0} {t('common.results')}</p>
          </div>
        </div>
      </div>

      <div className="card p-4 flex flex-wrap gap-3">
        <Select value={direction} onChange={(e) => { setDirection(e.target.value); setPage(1); }} className="w-full sm:w-44">
          <option value="">{t('common.all')} — {t('cash.direction')}</option>
          <option value="in">{t('cash.directionIn')}</option>
          <option value="out">{t('cash.directionOut')}</option>
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
