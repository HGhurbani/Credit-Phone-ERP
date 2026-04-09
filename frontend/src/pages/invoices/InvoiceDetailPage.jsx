import { useEffect, useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Receipt, Printer } from 'lucide-react';
import Badge, { invoiceStatusBadge } from '../../components/ui/Badge';
import { ConfirmModal } from '../../components/ui/Modal';
import { Pagination, useLocalPagination } from '../../components/ui/Table';
import { cashboxesApi, invoicesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';

const PAYMENT_METHOD_KEY = {
  cash: 'Cash',
  bank_transfer: 'BankTransfer',
  cheque: 'Cheque',
  card: 'Card',
  other: 'Other',
};

export default function InvoiceDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [invoice, setInvoice] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [cashboxes, setCashboxes] = useState([]);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [payForm, setPayForm] = useState({
    amount: '',
    payment_method: 'cash',
    cashbox_id: '',
    payment_date: new Date().toISOString().split('T')[0],
    reference_number: '',
    collector_notes: '',
  });

  const canRecordPayment = hasPermission('payments.create');
  const canViewCashboxes = hasPermission('cashboxes.view');
  const load = () => {
    invoicesApi.get(id)
      .then(r => {
        const inv = r.data.data;
        setInvoice(inv);
        setPayForm(p => ({
          ...p,
          amount: inv.remaining_amount != null ? String(inv.remaining_amount) : '',
        }));
      })
      .catch(() => {
        toast.error(t('common.error'));
        navigate('/invoices');
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [id]);

  useEffect(() => {
    if (!invoice || !canViewCashboxes || !invoice.branch?.id) {
      setCashboxes([]);
      return;
    }

    cashboxesApi.list({ branch_id: invoice.branch.id })
      .then((r) => {
        const rows = r.data.data || [];
        setCashboxes(rows);
        setPayForm((current) => {
          if (current.cashbox_id) return current;
          const preferred = rows.find((item) => item.is_primary) || rows[0];
          return {
            ...current,
            cashbox_id: preferred ? String(preferred.id) : '',
          };
        });
      })
      .catch(() => {
        setCashboxes([]);
      });
  }, [invoice, canViewCashboxes]);

  const handlePay = async (e) => {
    e.preventDefault();
    if (!invoice) return;
    const amt = parseFloat(String(payForm.amount).replace(',', '.'), 10);
    if (!Number.isFinite(amt) || amt <= 0) {
      toast.error(t('validation.required'));
      return;
    }
    setSaving(true);
    try {
      await invoicesApi.recordPayment(invoice.id, {
        amount: amt,
        payment_method: payForm.payment_method,
        cashbox_id: payForm.payment_method === 'cash' && payForm.cashbox_id ? Number(payForm.cashbox_id) : undefined,
        payment_date: payForm.payment_date,
        reference_number: payForm.reference_number || undefined,
        collector_notes: payForm.collector_notes || undefined,
      });
      toast.success(t('common.success'));
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const handleCancel = async () => {
    if (!invoice) return;
    setSaving(true);
    try {
      await invoicesApi.update(invoice.id, { status: 'cancelled' });
      toast.success(t('common.success'));
      setCancelOpen(false);
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }
  const payments = [...(invoice?.payments || [])].sort((a, b) => new Date(b.payment_date || 0) - new Date(a.payment_date || 0));
  const itemsPagination = useLocalPagination(invoice?.items || []);
  const paymentsPagination = useLocalPagination(payments);
  if (!invoice) return null;

  const statusMeta = invoiceStatusBadge(invoice.status);
  const canPay = canRecordPayment && ['unpaid', 'partial'].includes(invoice.status);
  const canCancel = hasPermission('invoices.update') && ['unpaid', 'partial'].includes(invoice.status);
  const paymentMethodLabel = (method) => t(`collections.method${PAYMENT_METHOD_KEY[method] || 'Other'}`);

  return (
    <div className="w-full min-w-0 space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3">
          <button type="button" onClick={() => navigate('/invoices')} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <div>
            <h1 className="page-title font-mono">{invoice.invoice_number}</h1>
            <p className="page-subtitle">{invoice.customer?.name} · {formatDate(invoice.issue_date)}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => navigate(`/print/invoice/${id}`)}
            className="btn-secondary btn btn-sm"
          >
            <Printer size={14} /> {t('invoices.printInvoice')}
          </button>
          <Badge label={t(statusMeta.labelKey)} variant={statusMeta.variant} />
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {[
          { label: t('common.total'), value: formatCurrency(invoice.total) },
          { label: t('contracts.paidAmount'), value: formatCurrency(invoice.paid_amount) },
          { label: t('invoices.remainingToPay'), value: formatCurrency(invoice.remaining_amount) },
          { label: t('common.branch'), value: invoice.branch?.name || t('common.emDash') },
        ].map(row => (
          <div key={row.label} className="card p-4">
            <p className="text-xs text-gray-500 mb-1">{row.label}</p>
            <p className="text-sm font-semibold text-gray-900">{row.value}</p>
          </div>
        ))}
      </div>

      {invoice.order && (
        <p className="text-sm text-gray-600">
          {t('invoices.linkedOrder')}:{' '}
          <button type="button" className="text-primary-600 hover:underline font-medium" onClick={() => navigate(`/orders/${invoice.order.id}`)}>
            {invoice.order.order_number}
          </button>
        </p>
      )}

      <div className="card p-4">
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('products.name')}</th>
                <th>{t('products.quantity')}</th>
                <th>{t('common.amount')}</th>
              </tr>
            </thead>
            <tbody>
              {itemsPagination.rows.map((line) => (
                <tr key={line.id}>
                  <td>{line.description}</td>
                  <td>{line.quantity}</td>
                  <td>{formatCurrency(line.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          total={itemsPagination.total}
          currentPage={itemsPagination.page}
          lastPage={itemsPagination.lastPage}
          perPage={itemsPagination.perPage}
          pageSize={itemsPagination.pageSize}
          onPageChange={itemsPagination.setPage}
          onPageSizeChange={(value) => { itemsPagination.setPageSize(value); itemsPagination.setPage(1); }}
        />
      </div>

      {canPay && (
        <div className="card p-4">
          <h2 className="font-semibold text-gray-900 mb-3 flex items-center gap-2">
            <Receipt size={18} className="text-primary-600" />
            {t('invoices.recordPayment')}
          </h2>
          <form onSubmit={handlePay} className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="label">{t('invoices.paymentAmount')} *</label>
              <input
                type="number"
                min="0.01"
                step="0.01"
                required
                value={payForm.amount}
                onChange={e => setPayForm(p => ({ ...p, amount: e.target.value }))}
                className="input"
              />
            </div>
            <div>
              <label className="label">{t('collections.paymentMethod')} *</label>
              <select
                value={payForm.payment_method}
                onChange={e => setPayForm(p => ({ ...p, payment_method: e.target.value, cashbox_id: e.target.value === 'cash' ? p.cashbox_id : '' }))}
                className="input"
              >
                <option value="cash">{t('collections.methodCash')}</option>
                <option value="bank_transfer">{t('collections.methodBankTransfer')}</option>
                <option value="cheque">{t('collections.methodCheque')}</option>
                <option value="card">{t('collections.methodCard')}</option>
                <option value="other">{t('collections.methodOther')}</option>
              </select>
            </div>
            {payForm.payment_method === 'cash' && canViewCashboxes && (
              <div>
                <label className="label">{t('journal.cashbox')}</label>
                <select
                  value={payForm.cashbox_id}
                  onChange={e => setPayForm(p => ({ ...p, cashbox_id: e.target.value }))}
                  className="input"
                >
                  <option value="">{t('invoices.selectCashbox')}</option>
                  {cashboxes.map((cashbox) => (
                    <option key={cashbox.id} value={cashbox.id}>
                      {cashbox.name}{cashbox.is_primary ? ` (${t('cash.primary')})` : ''}
                    </option>
                  ))}
                </select>
              </div>
            )}
            <div>
              <label className="label">{t('collections.paymentDate')} *</label>
              <input
                type="date"
                required
                value={payForm.payment_date}
                onChange={e => setPayForm(p => ({ ...p, payment_date: e.target.value }))}
                className="input"
              />
            </div>
            <div>
              <label className="label">{t('collections.referenceNumber')}</label>
              <input
                value={payForm.reference_number}
                onChange={e => setPayForm(p => ({ ...p, reference_number: e.target.value }))}
                className="input"
              />
            </div>
            <div className="sm:col-span-2">
              <label className="label">{t('collections.collectorNotes')}</label>
              <textarea
                value={payForm.collector_notes}
                onChange={e => setPayForm(p => ({ ...p, collector_notes: e.target.value }))}
                className="input"
                rows={2}
              />
            </div>
            <div className="sm:col-span-2 flex flex-wrap gap-2">
              <button type="submit" disabled={saving} className="btn-primary btn">{saving ? t('common.loading') : t('invoices.recordPayment')}</button>
              {canCancel && (
                <button type="button" onClick={() => setCancelOpen(true)} className="btn-danger btn">
                  {t('invoices.cancelInvoice')}
                </button>
              )}
            </div>
          </form>
        </div>
      )}

      <div className="card p-4">
        <h2 className="font-semibold text-gray-900 mb-3">{t('invoices.paymentHistory')}</h2>
        {payments.length === 0 ? (
          <p className="text-sm text-gray-500">{t('common.noData')}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('collections.receiptNumber')}</th>
                    <th>{t('common.date')}</th>
                    <th>{t('collections.paymentMethod')}</th>
                    <th>{t('common.amount')}</th>
                    <th>{t('common.by')}</th>
                    <th>{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {paymentsPagination.rows.map((payment) => (
                    <tr key={payment.id}>
                      <td className="font-mono text-xs">{payment.receipt_number || t('common.emDash')}</td>
                      <td>{formatDate(payment.payment_date)}</td>
                      <td>{paymentMethodLabel(payment.payment_method)}</td>
                      <td>{formatCurrency(payment.amount)}</td>
                      <td>{payment.collected_by?.name || t('common.emDash')}</td>
                      <td>
                        <Link to={`/print/payment/${payment.id}`} className="text-primary-600 hover:underline text-sm">
                          {t('collections.printReceipt')}
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination
              total={paymentsPagination.total}
              currentPage={paymentsPagination.page}
              lastPage={paymentsPagination.lastPage}
              perPage={paymentsPagination.perPage}
              pageSize={paymentsPagination.pageSize}
              onPageChange={paymentsPagination.setPage}
              onPageSizeChange={(value) => { paymentsPagination.setPageSize(value); paymentsPagination.setPage(1); }}
            />
          </>
        )}
      </div>

      {!canRecordPayment && ['unpaid', 'partial'].includes(invoice.status) && (
        <p className="text-sm text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">{t('invoices.noPaymentPermission')}</p>
      )}

      <ConfirmModal
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        onConfirm={handleCancel}
        title={t('invoices.cancelInvoice')}
        message={t('invoices.cancelInvoiceConfirm', { number: invoice.invoice_number })}
        confirmText={t('invoices.cancelInvoice')}
        loading={saving}
      />
    </div>
  );
}
