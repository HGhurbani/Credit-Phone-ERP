import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Eye } from 'lucide-react';
import { DataTable, Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge, { orderStatusBadge } from '../../components/ui/Badge';
import { ordersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import { useDebounce } from '../../hooks/useDebounce';
import toast from 'react-hot-toast';

export default function OrdersPage() {
  const { t } = useLang();
  const navigate = useNavigate();
  const [orders, setOrders] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);

  const debouncedSearch = useDebounce(search, 400);

  const fetchOrders = useCallback(async () => {
    setLoading(true);
    try {
      const res = await ordersApi.list({ search: debouncedSearch, status: statusFilter, type: typeFilter, page, per_page: getPerPageRequestValue(perPage) });
      setOrders(res.data.data);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, statusFilter, typeFilter, page, perPage, t]);

  useEffect(() => { fetchOrders(); }, [fetchOrders]);

  const columns = [
    { key: 'order_number', title: t('orders.orderNumber'), render: (row) => <span className="font-mono text-sm font-medium">{row.order_number}</span> },
    { key: 'customer', title: t('customers.name'), render: (row) => row.customer?.name || '—' },
    { key: 'type', title: t('orders.type'), render: (row) => <Badge label={row.type === 'cash' ? t('orders.typeCash') : t('orders.typeInstallment')} variant={row.type === 'cash' ? 'blue' : 'purple'} /> },
    { key: 'total', title: t('common.total'), render: (row) => formatCurrency(row.total) },
    {
      key: 'status', title: t('common.status'),
      render: (row) => {
        const s = orderStatusBadge(row.status);
        return <Badge label={t(s.labelKey)} variant={s.variant} />;
      },
    },
    { key: 'created_at', title: t('common.createdAt'), render: (row) => formatDate(row.created_at) },
    {
      key: 'actions', title: t('common.actions'),
      render: (row) => (
        <button onClick={() => navigate(`/orders/${row.id}`)} className="btn-ghost btn btn-sm"><Eye size={14} /></button>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('orders.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        <button onClick={() => navigate('/orders/new')} className="btn-primary btn">
          <Plus size={16} /> {t('orders.add')}
        </button>
      </div>

      <div className="card p-4 flex flex-wrap gap-3">
        <SearchInput value={search} onChange={v => { setSearch(v); setPage(1); }} placeholder={t('orders.searchPlaceholder')} className="flex-1 min-w-48" />
        <select value={typeFilter} onChange={e => { setTypeFilter(e.target.value); setPage(1); }} className="input w-40">
          <option value="">{t('common.all')} - {t('orders.type')}</option>
          <option value="cash">{t('orders.typeCash')}</option>
          <option value="installment">{t('orders.typeInstallment')}</option>
        </select>
        <select value={statusFilter} onChange={e => { setStatusFilter(e.target.value); setPage(1); }} className="input w-48">
          <option value="">{t('common.all')} - {t('common.status')}</option>
          <option value="draft">{t('orders.statusDraft')}</option>
          <option value="pending_review">{t('orders.statusPending')}</option>
          <option value="approved">{t('orders.statusApproved')}</option>
          <option value="converted_to_contract">{t('orders.statusConverted')}</option>
          <option value="completed">{t('orders.statusCompleted')}</option>
          <option value="cancelled">{t('orders.statusCancelled')}</option>
        </select>
      </div>

      <DataTable columns={columns} data={orders} loading={loading} />
      <Pagination
        meta={meta}
        onPageChange={setPage}
        pageSize={perPage}
        onPageSizeChange={(value) => { setPerPage(value); setPage(1); }}
      />
    </div>
  );
}
