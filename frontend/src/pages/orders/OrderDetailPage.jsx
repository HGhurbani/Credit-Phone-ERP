import { useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, CheckCircle, XCircle, FileText } from 'lucide-react';
import Badge, { orderStatusBadge } from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { ordersApi, contractsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
import { INSTALLMENT_DURATION_PRESETS, INSTALLMENT_DURATION_MAX_MONTHS } from '../../constants/installmentDurations';

const DEFAULT_CONTRACT_DURATION = 12;

const roundMoney = (value) => Math.round((Number(value) || 0) * 100) / 100;

const ceilMoney = (value) => Math.ceil((Number(value) || 0) - 0.0000001);

const parseNumber = (value) => {
  const parsed = parseFloat(String(value ?? '').replace(',', '.'));
  return Number.isFinite(parsed) ? parsed : null;
};

const todayString = () => new Date().toISOString().split('T')[0];

const addMonthsToDateString = (dateString, months = 1) => {
  if (!dateString) return '';

  const date = new Date(`${dateString}T00:00:00`);
  if (Number.isNaN(date.getTime())) return '';

  date.setMonth(date.getMonth() + months);
  return date.toISOString().split('T')[0];
};

const getApiErrorMessage = (error, fallback) => {
  const fieldErrors = error?.response?.data?.errors;
  const firstFieldError = fieldErrors && Object.values(fieldErrors).flat()[0];

  return firstFieldError || error?.response?.data?.message || fallback;
};

const getMinimumDownPayment = (order) => roundMoney((order?.items || []).reduce((sum, item) => {
  const qty = Number(item.quantity) || 0;
  const minDown = Number(item.product?.min_down_payment) || 0;
  return sum + (minDown * qty);
}, 0));

const calculateContractPreview = (order, form) => {
  if (!order || order.type !== 'installment') return null;

  const durationMonths = Math.min(
    INSTALLMENT_DURATION_MAX_MONTHS,
    Math.max(1, parseInt(String(form.duration_months).trim(), 10) || DEFAULT_CONTRACT_DURATION),
  );
  const orderTotal = roundMoney(Number(order.total) || 0);
  const minimumDownPayment = getMinimumDownPayment(order);
  const enteredDownPayment = parseNumber(form.down_payment);
  const downPayment = enteredDownPayment ?? minimumDownPayment;
  const financedAmount = roundMoney(Math.max(0, orderTotal - Math.max(0, downPayment)));
  const suggestedMonthly = durationMonths === 1 ? financedAmount : ceilMoney(financedAmount / durationMonths);
  const enteredMonthlyAmount = parseNumber(form.monthly_amount);
  const monthlyAmount = durationMonths === 1
    ? financedAmount
    : (enteredMonthlyAmount != null ? ceilMoney(enteredMonthlyAmount) : suggestedMonthly);
  const lastInstallment = durationMonths === 1
    ? financedAmount
    : roundMoney(financedAmount - (monthlyAmount * (durationMonths - 1)));

  return {
    durationMonths,
    orderTotal,
    minimumDownPayment,
    downPayment,
    financedAmount,
    suggestedMonthly,
    monthlyAmount,
    lastInstallment,
    downPaymentTooLow: downPayment + 0.01 < minimumDownPayment,
    downPaymentTooHigh: downPayment + 0.01 >= orderTotal,
    monthlyInvalid: durationMonths > 1 && lastInstallment <= 0,
  };
};

const buildInitialContractForm = (order) => {
  const minimumDownPayment = getMinimumDownPayment(order);
  const orderTotal = roundMoney(Number(order?.total) || 0);
  const financedAmount = roundMoney(Math.max(0, orderTotal - minimumDownPayment));
  const monthlyAmount = DEFAULT_CONTRACT_DURATION === 1
    ? financedAmount
    : ceilMoney(financedAmount / DEFAULT_CONTRACT_DURATION);

  return {
    down_payment: String(minimumDownPayment),
    monthly_amount: monthlyAmount > 0 ? String(monthlyAmount) : '',
    duration_months: String(DEFAULT_CONTRACT_DURATION),
    start_date: todayString(),
    first_due_date: addMonthsToDateString(todayString(), 1),
    notes: '',
  };
};

export default function OrderDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [rejectModal, setRejectModal] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [contractModal, setContractModal] = useState(false);
  const [contractForm, setContractForm] = useState(() => buildInitialContractForm(null));
  const [monthlyEdited, setMonthlyEdited] = useState(false);
  const [firstDueDateEdited, setFirstDueDateEdited] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    ordersApi.get(id)
      .then((orderRes) => {
        if (!cancelled) {
          setOrder(orderRes.data.data);
        }
      })
      .catch(() => { toast.error(t('common.error')); navigate('/orders'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [id, navigate, t]);

  const contractPreview = useMemo(() => {
    return calculateContractPreview(order, contractForm);
  }, [order, contractForm]);

  const applySuggestedMonthly = (nextForm) => {
    const preview = calculateContractPreview(order, nextForm);
    if (!preview) return nextForm;

    return {
      ...nextForm,
      monthly_amount: preview.suggestedMonthly > 0 ? String(preview.suggestedMonthly) : '',
    };
  };

  const openContractModal = () => {
    setMonthlyEdited(false);
    setFirstDueDateEdited(false);
    setContractForm(buildInitialContractForm(order));
    setContractModal(true);
  };

  const handleApprove = async () => {
    setProcessing(true);
    try {
      const res = await ordersApi.approve(id);
      setOrder(res.data.data);
      toast.success(t('common.success'));
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setProcessing(false);
    }
  };

  const handleReject = async () => {
    if (!rejectReason) return;
    setProcessing(true);
    try {
      const res = await ordersApi.reject(id, rejectReason);
      setOrder(res.data.data);
      setRejectModal(false);
      toast.success(t('common.success'));
    } catch {
      toast.error(t('common.error'));
    } finally {
      setProcessing(false);
    }
  };

  const handleCreateContract = async (e) => {
    e.preventDefault();
    const dm = parseInt(String(contractForm.duration_months).trim(), 10);
    if (!Number.isFinite(dm) || dm < 1 || dm > INSTALLMENT_DURATION_MAX_MONTHS) {
      toast.error(t('validation.durationMonths'));
      return;
    }
    if (!contractPreview) {
      toast.error(t('common.error'));
      return;
    }
    if (contractPreview.downPaymentTooLow) {
      toast.error(`${t('products.minDownPayment')}: ${formatCurrency(contractPreview.minimumDownPayment)}`);
      return;
    }
    if (contractPreview.downPaymentTooHigh) {
      toast.error(t('ui.contractPricingDownTooHigh'));
      return;
    }
    if (contractPreview.monthlyInvalid) {
      toast.error(t('ui.contractPricingMonthlyTooHigh'));
      return;
    }

    setProcessing(true);
    try {
      const payload = {
        ...contractForm,
        down_payment: contractPreview.downPayment,
        monthly_amount: contractPreview.monthlyAmount,
        duration_months: dm,
        order_id: id,
      };
      const res = await contractsApi.create(payload);
      toast.success(t('common.success'));
      navigate(`/contracts/${res.data.data.id}`);
    } catch (err) {
      toast.error(getApiErrorMessage(err, t('common.error')));
    } finally {
      setProcessing(false);
    }
  };

  if (loading) return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>;
  if (!order) return null;

  const statusMeta = orderStatusBadge(order.status);

  return (
    <div className="w-full min-w-0 space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <div>
            <h1 className="page-title">{order.order_number}</h1>
            <p className="page-subtitle">{formatDate(order.created_at)}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Badge label={t(statusMeta.labelKey)} variant={statusMeta.variant} />
          {['draft', 'pending_review'].includes(order.status) && (
            <>
              <button onClick={handleApprove} disabled={processing} className="btn btn-sm bg-green-600 text-white hover:bg-green-700 focus:ring-green-500">
                <CheckCircle size={14} /> {t('orders.approve')}
              </button>
              <button onClick={() => setRejectModal(true)} disabled={processing} className="btn-danger btn btn-sm">
                <XCircle size={14} /> {t('orders.reject')}
              </button>
            </>
          )}
          {order.status === 'approved' && order.type === 'installment' && (
            <button onClick={openContractModal} className="btn-primary btn btn-sm">
              <FileText size={14} /> {t('orders.convertToContract')}
            </button>
          )}
        </div>
      </div>

      {/* Customer + Branch */}
      <div className="grid grid-cols-2 gap-4">
        <div className="card p-4">
          <p className="text-xs text-gray-500 mb-2">{t('customers.title')}</p>
          <p className="font-medium">{order.customer?.name}</p>
          <p className="text-sm text-gray-500">{order.customer?.phone}</p>
        </div>
        <div className="card p-4">
          <p className="text-xs text-gray-500 mb-2">{t('common.branch')}</p>
          <p className="font-medium">{order.branch?.name}</p>
          <p className="text-xs text-gray-500">{t('orders.salesAgent')}: {order.sales_agent?.name || '—'}</p>
        </div>
      </div>

      {/* Items */}
      <div className="card">
        <div className="card-header"><p className="font-semibold">{t('orders.items')}</p></div>
        <div className="divide-y divide-gray-50">
          {order.items?.map((item) => (
            <div key={item.id} className="flex items-center justify-between px-6 py-3">
              <div>
                <p className="text-sm font-medium">{item.product_name}</p>
                <p className="text-xs text-gray-400">{item.product_sku} · {formatCurrency(item.unit_price)} × {item.quantity}</p>
              </div>
              <span className="text-sm font-semibold">{formatCurrency(item.total)}</span>
            </div>
          ))}
        </div>
        <div className="px-6 py-4 border-t border-gray-100 space-y-2">
          <div className="flex justify-between text-sm"><span className="text-gray-500">{t('orders.subtotal')}</span><span>{formatCurrency(order.subtotal)}</span></div>
          {order.discount_amount > 0 && <div className="flex justify-between text-sm"><span className="text-gray-500">{t('orders.discount')}</span><span className="text-red-500">-{formatCurrency(order.discount_amount)}</span></div>}
          <div className="flex justify-between font-semibold pt-2 border-t border-gray-100"><span>{t('common.total')}</span><span className="text-primary-600 text-lg">{formatCurrency(order.total)}</span></div>
        </div>
      </div>

      {order.notes && <div className="card p-4"><p className="text-xs text-gray-500 mb-1">{t('common.notes')}</p><p className="text-sm">{order.notes}</p></div>}
      {order.rejection_reason && <div className="card p-4 border-red-200 bg-red-50"><p className="text-xs text-red-500 mb-1">{t('orders.rejectionReason')}</p><p className="text-sm text-red-700">{order.rejection_reason}</p></div>}
      {order.contract && <div className="card p-4"><p className="text-xs text-gray-500 mb-1">{t('ui.contract')}</p><button type="button" onClick={() => navigate(`/contracts/${order.contract.id}`)} className="text-primary-600 text-sm font-medium hover:underline">{order.contract.contract_number}</button></div>}

      {/* Reject Modal */}
      <Modal open={rejectModal} onClose={() => setRejectModal(false)} title={t('orders.reject')} size="sm"
        footer={<>
          <button onClick={() => setRejectModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button onClick={handleReject} disabled={!rejectReason || processing} className="btn-danger btn">{t('orders.reject')}</button>
        </>}
      >
        <div className="form-group">
          <label className="label">{t('orders.rejectionReason')} *</label>
          <textarea value={rejectReason} onChange={e => setRejectReason(e.target.value)} className="input" rows={3} />
        </div>
      </Modal>

      {/* Contract Modal */}
      <Modal open={contractModal} onClose={() => setContractModal(false)} title={t('orders.convertToContract')} size="lg"
        footer={<>
          <button type="button" onClick={() => setContractModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="contract-form" disabled={processing} className="btn-primary btn">{processing ? '...' : t('common.save')}</button>
        </>}
      >
        <form id="contract-form" onSubmit={handleCreateContract} className="space-y-4">
          <div>
            <label className="label">{t('contracts.downPayment')} *</label>
            <input type="number" min="0" step="0.01" required value={contractForm.down_payment}
              onChange={(e) => {
                const nextForm = { ...contractForm, down_payment: e.target.value };
                setContractForm(monthlyEdited ? nextForm : applySuggestedMonthly(nextForm));
              }} className="input" />
          </div>
          <div>
            <label className="label">{t('contracts.monthlyAmount')} *</label>
            <input
              type="number"
              min="1"
              step="1"
              required
              value={contractForm.monthly_amount}
              onChange={(e) => {
                setMonthlyEdited(true);
                setContractForm((p) => ({ ...p, monthly_amount: e.target.value }));
              }}
              className="input"
            />
          </div>
          <div className="rounded-xl border border-gray-200 bg-gray-50/80 p-4 space-y-3">
            <div>
              <label className="label mb-0">{t('contracts.duration')} *</label>
              <p className="text-xs text-gray-500 mt-1">{t('contracts.durationManualHint')}</p>
            </div>
            <div>
              <p className="text-xs font-medium text-gray-600 mb-2">{t('contracts.durationQuickSelect')}</p>
              <div className="flex flex-wrap gap-2">
                {INSTALLMENT_DURATION_PRESETS.map((m) => {
                  const current = parseInt(contractForm.duration_months, 10);
                  const selected = Number.isFinite(current) && current === m;
                  return (
                    <button
                      key={m}
                      type="button"
                      onClick={() => {
                        const nextForm = { ...contractForm, duration_months: String(m) };
                        setContractForm(monthlyEdited ? nextForm : applySuggestedMonthly(nextForm));
                      }}
                      className={`inline-flex flex-col items-center justify-center min-w-[4.25rem] px-3 py-2.5 rounded-xl border-2 text-sm transition-all ${
                        selected
                          ? 'border-primary-600 bg-primary-50 text-primary-900 ring-2 ring-primary-200 shadow-sm'
                          : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300'
                      }`}
                    >
                      <span className="text-lg font-bold leading-none">{m}</span>
                      <span className="text-[10px] text-gray-500 mt-0.5">{t('contracts.monthShort')}</span>
                    </button>
                  );
                })}
              </div>
            </div>
            <div className="pt-3 border-t border-gray-200">
              <label className="label text-sm" htmlFor="contract-duration-manual">{t('contracts.durationManualLabel')}</label>
              <input
                id="contract-duration-manual"
                type="number"
                min={1}
                max={INSTALLMENT_DURATION_MAX_MONTHS}
                step={1}
                required
                className="input mt-1"
                placeholder={t('contracts.durationManualPlaceholder')}
                value={contractForm.duration_months}
                onChange={(e) => {
                  const v = e.target.value;
                  if (v === '' || /^\d{1,3}$/.test(v)) {
                    const nextForm = { ...contractForm, duration_months: v };
                    setContractForm(monthlyEdited ? nextForm : applySuggestedMonthly(nextForm));
                  }
                }}
                onBlur={() => {
                  const n = parseInt(String(contractForm.duration_months).trim(), 10);
                  const nextForm = {
                    ...contractForm,
                    duration_months: !Number.isFinite(n) || n < 1 ? String(DEFAULT_CONTRACT_DURATION) : contractForm.duration_months,
                  };
                  setContractForm(monthlyEdited ? nextForm : applySuggestedMonthly(nextForm));
                }}
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">{t('contracts.startDate')} *</label>
              <input
                type="date"
                required
                value={contractForm.start_date}
                onChange={(e) => {
                  const nextStartDate = e.target.value;
                  setContractForm((prev) => ({
                    ...prev,
                    start_date: nextStartDate,
                    first_due_date: (!firstDueDateEdited || !prev.first_due_date)
                      ? addMonthsToDateString(nextStartDate, 1)
                      : prev.first_due_date,
                  }));
                }}
                className="input"
              />
            </div>
            <div>
              <label className="label">{t('contracts.firstDueDate')} *</label>
              <input
                type="date"
                required
                value={contractForm.first_due_date}
                onChange={(e) => {
                  setFirstDueDateEdited(true);
                  setContractForm((p) => ({ ...p, first_due_date: e.target.value }));
                }}
                className="input"
              />
            </div>
          </div>
          {order && contractPreview && (
            <div className="bg-blue-50 rounded-lg p-3 text-sm space-y-2">
              <p className="text-blue-700">
                {t('ui.contractExpectedDown')}: <span className="font-semibold">{formatCurrency(contractPreview.minimumDownPayment)}</span>
              </p>
              <p className="text-blue-700">
                {t('ui.monthlyInstallmentEstimate')}: <span className="font-semibold">{formatCurrency(contractPreview.suggestedMonthly)}</span>
              </p>
              <p className="text-blue-700">
                {t('contracts.financedAmount')}: <span className="font-semibold">{formatCurrency(contractPreview.financedAmount)}</span>
              </p>
              {contractPreview.durationMonths > 1 && contractPreview.lastInstallment > 0 && contractPreview.lastInstallment !== contractPreview.monthlyAmount && (
                <p className="text-blue-700">
                  {t('ui.contractFinalInstallment')}: <span className="font-semibold">{formatCurrency(contractPreview.lastInstallment)}</span>
                </p>
              )}
              <p className="text-blue-600 text-xs">{t('ui.contractPricingPreviewHint')}</p>
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  className="btn-secondary btn btn-sm"
                  onClick={() => {
                    const nextForm = { ...contractForm, down_payment: String(contractPreview.minimumDownPayment) };
                    setContractForm(monthlyEdited ? nextForm : applySuggestedMonthly(nextForm));
                  }}
                >
                  {t('ui.useSuggestedDown')}
                </button>
                <button
                  type="button"
                  className="btn-secondary btn btn-sm"
                  onClick={() => {
                    setMonthlyEdited(false);
                    setContractForm(applySuggestedMonthly({ ...contractForm }));
                  }}
                >
                  {t('ui.useSuggestedMonthly')}
                </button>
              </div>
              {contractPreview.downPaymentTooLow && (
                <p className="text-xs text-red-600">{`${t('products.minDownPayment')}: ${formatCurrency(contractPreview.minimumDownPayment)}`}</p>
              )}
              {contractPreview.downPaymentTooHigh && (
                <p className="text-xs text-red-600">{t('ui.contractPricingDownTooHigh')}</p>
              )}
              {contractPreview.monthlyInvalid && (
                <p className="text-xs text-red-600">{t('ui.contractPricingMonthlyTooHigh')}</p>
              )}
            </div>
          )}
        </form>
      </Modal>
    </div>
  );
}
