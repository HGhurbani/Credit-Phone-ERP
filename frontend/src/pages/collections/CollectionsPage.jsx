import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { CreditCard, AlertTriangle, Clock } from 'lucide-react';
import { DataTable, Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import Badge, { scheduleStatusBadge } from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { paymentsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
import { clsx } from 'clsx';

export default function CollectionsPage() {
  const { t } = useLang();
  const navigate = useNavigate();
  const [tab, setTab] = useState('due_today');
  const [data, setData] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [paymentModal, setPaymentModal] = useState(false);
  const [selectedSchedule, setSelectedSchedule] = useState(null);
  const [paymentForm, setPaymentForm] = useState({ amount: '', payment_method: 'cash', payment_date: new Date().toISOString().split('T')[0], reference_number: '', collector_notes: '' });
  const [submitting, setSubmitting] = useState(false);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const fn = tab === 'due_today' ? paymentsApi.dueToday : paymentsApi.overdue;
      const res = await fn({ page, per_page: getPerPageRequestValue(perPage) });
      setData(res.data.data);
      setMeta(res.data.meta);
    } catch { toast.error(t('common.error')); }
    finally { setLoading(false); }
  }, [tab, page, perPage, t]);

  useEffect(() => { fetch(); }, [fetch]);

  const openPaymentModal = (schedule) => {
    setSelectedSchedule(schedule);
    setPaymentForm(p => ({ ...p, amount: schedule.remaining_amount || '' }));
    setPaymentModal(true);
  };

  const handlePayment = async (e) => {
    e.preventDefault();
    if (!selectedSchedule) return;
    setSubmitting(true);
    try {
      await paymentsApi.create({
        ...paymentForm,
        contract_id: selectedSchedule.contract_id,
        schedule_id: selectedSchedule.id,
      });
      toast.success(t('common.success'));
      setPaymentModal(false);
      fetch();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSubmitting(false);
    }
  };

  const columns = [
    {
      key: 'contract', title: t('contracts.contractNumber'),
      render: r => (
        <button onClick={() => navigate(`/contracts/${r.contract_id}`)} className="text-primary-600 font-mono text-sm hover:underline">
          {r.contract?.contract_number}
        </button>
      ),
    },
    {
      key: 'customer',
      title: t('customers.name'),
      render: r => {
        const cid = r.contract?.customer?.id;
        const name = r.contract?.customer?.name;
        if (!cid || !name) return '—';
        return (
          <button type="button" onClick={() => navigate(`/customers/${cid}`)} className="text-primary-600 hover:underline text-left">
            {name}
          </button>
        );
      },
    },
    { key: 'installment_number', title: t('collections.installmentNumber'), render: r => `#${r.installment_number}` },
    { key: 'due_date', title: t('collections.dueDate'), render: r => formatDate(r.due_date) },
    { key: 'amount', title: t('common.amount'), render: r => formatCurrency(r.amount) },
    { key: 'remaining_amount', title: t('contracts.remainingAmount'), render: r => <span className="text-red-600 font-medium">{formatCurrency(r.remaining_amount)}</span> },
    {
      key: 'status', title: t('common.status'),
      render: r => { const s = scheduleStatusBadge(r.status); return <Badge label={t(s.labelKey)} variant={s.variant} />; },
    },
    {
      key: 'actions', title: '',
      render: r => (
        <button onClick={() => openPaymentModal(r)} className="btn-primary btn btn-sm">
          <CreditCard size={14} /> {t('collections.recordPayment')}
        </button>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('collections.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-gray-200">
        {[
          { key: 'due_today', label: t('collections.dueToday'), icon: Clock },
          { key: 'overdue', label: t('collections.overdue'), icon: AlertTriangle },
        ].map(tabItem => (
          <button key={tabItem.key}
            onClick={() => { setTab(tabItem.key); setPage(1); }}
            className={clsx(
              'flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors',
              tab === tabItem.key ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'
            )}
          >
            <tabItem.icon size={15} />
            {tabItem.label}
          </button>
        ))}
      </div>

      <DataTable columns={columns} data={data} loading={loading} />
      <Pagination
        meta={meta}
        onPageChange={setPage}
        pageSize={perPage}
        onPageSizeChange={(value) => { setPerPage(value); setPage(1); }}
      />

      {/* Payment Modal */}
      <Modal open={paymentModal} onClose={() => setPaymentModal(false)} title={t('collections.recordPayment')} size="md"
        footer={<>
          <button onClick={() => setPaymentModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="payment-form" disabled={submitting} className="btn-primary btn">
            {submitting ? '...' : t('collections.recordPayment')}
          </button>
        </>}
      >
        {selectedSchedule && (
          <div className="mb-4 p-3 bg-blue-50 rounded-lg text-sm">
            <p className="font-medium text-blue-800">{selectedSchedule.contract?.customer?.name}</p>
            <p className="text-blue-600">{t('collections.contractInstallmentLine', {
              contract: selectedSchedule.contract?.contract_number ?? '',
              num: selectedSchedule.installment_number,
            })}</p>
            <p className="text-blue-600">{t('collections.dueRemainingSummary', {
              due: formatDate(selectedSchedule.due_date),
              remaining: formatCurrency(selectedSchedule.remaining_amount),
            })}</p>
          </div>
        )}
        <form id="payment-form" onSubmit={handlePayment} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">{t('collections.amount')} *</label>
              <input type="number" required min="0.01" step="0.01" value={paymentForm.amount}
                onChange={e => setPaymentForm(p => ({ ...p, amount: e.target.value }))} className="input" />
            </div>
            <div>
              <label className="label">{t('collections.paymentMethod')} *</label>
              <select value={paymentForm.payment_method} onChange={e => setPaymentForm(p => ({ ...p, payment_method: e.target.value }))} className="input">
                <option value="cash">{t('collections.methodCash')}</option>
                <option value="bank_transfer">{t('collections.methodBankTransfer')}</option>
                <option value="cheque">{t('collections.methodCheque')}</option>
                <option value="card">{t('collections.methodCard')}</option>
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">{t('collections.paymentDate')} *</label>
              <input type="date" required value={paymentForm.payment_date}
                onChange={e => setPaymentForm(p => ({ ...p, payment_date: e.target.value }))} className="input" />
            </div>
            <div>
              <label className="label">{t('collections.referenceNumber')}</label>
              <input type="text" value={paymentForm.reference_number}
                onChange={e => setPaymentForm(p => ({ ...p, reference_number: e.target.value }))} className="input" />
            </div>
          </div>
          <div>
            <label className="label">{t('collections.collectorNotes')}</label>
            <textarea value={paymentForm.collector_notes}
              onChange={e => setPaymentForm(p => ({ ...p, collector_notes: e.target.value }))} className="input" rows={2} />
          </div>
        </form>
      </Modal>
    </div>
  );
}
