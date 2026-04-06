import { useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, CheckCircle, XCircle, FileText } from 'lucide-react';
import Badge, { orderStatusBadge } from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { ordersApi, contractsApi, settingsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
import { INSTALLMENT_DURATION_PRESETS, INSTALLMENT_DURATION_MAX_MONTHS } from '../../constants/installmentDurations';

export default function OrderDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [order, setOrder] = useState(null);
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [rejectModal, setRejectModal] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [contractModal, setContractModal] = useState(false);
  const [contractForm, setContractForm] = useState({ down_payment: '', duration_months: '12', start_date: new Date().toISOString().split('T')[0], first_due_date: '', notes: '' });

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    Promise.all([
      ordersApi.get(id),
      settingsApi.get().catch(() => ({ data: { data: {} } })),
    ])
      .then(([orderRes, settingsRes]) => {
        if (!cancelled) {
          setOrder(orderRes.data.data);
          setSettings(settingsRes.data?.data ?? {});
        }
      })
      .catch(() => { toast.error(t('common.error')); navigate('/orders'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [id, navigate, t]);

  const contractPreview = useMemo(() => {
    if (!order || !settings || order.type !== 'installment') return null;
    const durationMonths = parseInt(contractForm.duration_months, 10) || 12;
    const items = order.items || [];
    const mode = settings.installment_pricing_mode === 'fixed' ? 'fixed' : 'percentage';
    const defaultPct = parseFloat(settings.installment_monthly_percent_of_cash ?? 5) || 5;

    let cashTotal = 0;
    let weighted = 0;
    let fixedSum = 0;
    let hasProduct = false;

    for (const item of items) {
      const p = item.product;
      if (!p) continue;
      hasProduct = true;
      const qty = item.quantity;
      const lineCash = (p.cash_price || 0) * qty;
      cashTotal += lineCash;
      if (mode === 'percentage') {
        const pct = p.monthly_percent_of_cash != null ? p.monthly_percent_of_cash : defaultPct;
        weighted += lineCash * pct;
      } else if (p.fixed_monthly_amount != null) {
        fixedSum += p.fixed_monthly_amount * qty;
      }
    }

    let monthly = 0;
    if (hasProduct) {
      if (mode === 'percentage') {
        const effectivePct = cashTotal > 0 ? weighted / cashTotal : defaultPct;
        monthly = Math.round(cashTotal * (effectivePct / 100) * 100) / 100;
      } else {
        monthly = Math.round(fixedSum * 100) / 100;
      }
    }

    const financed = Math.round(monthly * durationMonths * 100) / 100;
    const expectedDown = Math.round((Number(order.total) - financed) * 100) / 100;

    return { monthly, financed, expectedDown, hasProduct, mode };
  }, [order, settings, contractForm.duration_months]);

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
    setProcessing(true);
    try {
      const res = await contractsApi.create({ ...contractForm, duration_months: dm, order_id: id });
      toast.success(t('common.success'));
      navigate(`/contracts/${res.data.data.id}`);
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setProcessing(false);
    }
  };

  if (loading) return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>;
  if (!order) return null;

  const statusMeta = orderStatusBadge(order.status);

  return (
    <div className="max-w-3xl space-y-4">
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
            <button onClick={() => setContractModal(true)} className="btn-primary btn btn-sm">
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
              onChange={e => setContractForm(p => ({ ...p, down_payment: e.target.value }))} className="input" />
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
                      onClick={() => setContractForm((p) => ({ ...p, duration_months: String(m) }))}
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
                  if (v === '' || /^\d{1,3}$/.test(v)) setContractForm((p) => ({ ...p, duration_months: v }));
                }}
                onBlur={() => {
                  if (contractForm.duration_months === '') return;
                  const n = parseInt(String(contractForm.duration_months).trim(), 10);
                  if (!Number.isFinite(n) || n < 1) setContractForm((p) => ({ ...p, duration_months: '12' }));
                }}
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">{t('contracts.startDate')} *</label>
              <input type="date" required value={contractForm.start_date} onChange={e => setContractForm(p => ({ ...p, start_date: e.target.value }))} className="input" />
            </div>
            <div>
              <label className="label">{t('contracts.firstDueDate')} *</label>
              <input type="date" required value={contractForm.first_due_date} onChange={e => setContractForm(p => ({ ...p, first_due_date: e.target.value }))} className="input" />
            </div>
          </div>
          {order && contractPreview?.hasProduct && contractPreview.monthly > 0 && (
            <div className="bg-blue-50 rounded-lg p-3 text-sm space-y-2">
              <p className="text-blue-700">
                {t('ui.monthlyInstallmentEstimate')}: <span className="font-semibold">{formatCurrency(contractPreview.monthly)}</span>
              </p>
              <p className="text-blue-700">
                {t('ui.contractExpectedDown')}: <span className="font-semibold">{formatCurrency(contractPreview.expectedDown)}</span>
              </p>
              <p className="text-blue-600 text-xs">{t('ui.contractPricingPreviewHint')}</p>
              <button
                type="button"
                className="btn-secondary btn btn-sm"
                onClick={() => setContractForm((p) => ({ ...p, down_payment: String(contractPreview.expectedDown) }))}
              >
                {t('ui.useSuggestedDown')}
              </button>
            </div>
          )}
          {order && contractPreview && !contractPreview.hasProduct && (
            <div className="bg-amber-50 rounded-lg p-3 text-sm text-amber-800">
              {t('ui.contractPricingFallbackHint')}
              {contractForm.down_payment && (
                <p className="mt-2 text-amber-900">
                  {t('ui.monthlyInstallmentEstimate')}: {formatCurrency((order.total - parseFloat(contractForm.down_payment || 0)) / parseInt(contractForm.duration_months, 10))}
                </p>
              )}
            </div>
          )}
          {order && contractPreview?.hasProduct && contractPreview.mode === 'fixed' && contractPreview.monthly <= 0 && (
            <div className="bg-red-50 rounded-lg p-3 text-sm text-red-700">
              {t('ui.contractPricingMissingFixed')}
            </div>
          )}
        </form>
      </Modal>
    </div>
  );
}
