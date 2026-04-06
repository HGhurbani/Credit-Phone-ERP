import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Eye } from 'lucide-react';
import { DataTable, Pagination } from '../../components/ui/Table';
import Badge, { invoiceStatusBadge } from '../../components/ui/Badge';
import { invoicesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';

export default function InvoicesPage() {
  const { t } = useLang();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const statusFilter = searchParams.get('status') ?? '';
  const setStatusFilter = (value) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (value) next.set('status', value);
      else next.delete('status');
      return next;
    });
    setPage(1);
  };
  const [invoices, setInvoices] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = await invoicesApi.list({
        ...(statusFilter ? { status: statusFilter } : {}),
        page,
        per_page: 15,
      });
      setInvoices(res.data.data);
      setMeta(res.data.meta);
    } catch { toast.error(t('common.error')); }
    finally { setLoading(false); }
  }, [statusFilter, page, t]);

  useEffect(() => { fetch(); }, [fetch]);

  const columns = [
    { key: 'invoice_number', title: t('invoices.invoiceNumber'), render: r => <span className="font-mono text-sm font-medium">{r.invoice_number}</span> },
    { key: 'customer', title: t('customers.name'), render: r => r.customer?.name || '—' },
    { key: 'type', title: t('invoices.type'), render: r => <Badge label={r.type === 'cash' ? t('invoices.typeCash') : t('invoices.typeInstallment')} variant={r.type === 'cash' ? 'blue' : 'purple'} /> },
    { key: 'total', title: t('common.total'), render: r => formatCurrency(r.total) },
    { key: 'remaining_amount', title: t('contracts.remainingAmount'), render: r => formatCurrency(r.remaining_amount) },
    {
      key: 'status', title: t('common.status'),
      render: r => { const s = invoiceStatusBadge(r.status); return <Badge label={t(s.labelKey)} variant={s.variant} />; },
    },
    { key: 'issue_date', title: t('invoices.issueDate'), render: r => formatDate(r.issue_date) },
    { key: 'actions', title: '', render: r => <button onClick={() => navigate(`/invoices/${r.id}`)} className="btn-ghost btn btn-sm"><Eye size={14} /></button> },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('invoices.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        <select value={statusFilter} onChange={e => setStatusFilter(e.target.value)} className="input w-40">
          <option value="">{t('common.all')}</option>
          <option value="unpaid">{t('invoices.statusUnpaid')}</option>
          <option value="partial">{t('invoices.statusPartial')}</option>
          <option value="paid">{t('invoices.statusPaid')}</option>
        </select>
      </div>
      <DataTable columns={columns} data={invoices} loading={loading} />
      <Pagination meta={meta} onPageChange={setPage} />
    </div>
  );
}
