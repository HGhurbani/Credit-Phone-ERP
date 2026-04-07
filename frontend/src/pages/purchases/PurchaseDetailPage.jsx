import { useEffect, useState, useMemo } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, PackageCheck, Ban, Send, Pencil, Trash2, Printer } from 'lucide-react';
import Badge from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { purchaseOrdersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate, formatDateTime } from '../../utils/format';
import toast from 'react-hot-toast';

function poStatusVariant(s) {
  const map = { draft: 'gray', ordered: 'blue', partially_received: 'yellow', received: 'green', cancelled: 'red' };
  return map[s] || 'gray';
}

export default function PurchaseDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [po, setPo] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [receiveOpen, setReceiveOpen] = useState(false);
  const [receiveQty, setReceiveQty] = useState({});

  const load = () => {
    setLoading(true);
    purchaseOrdersApi.get(id)
      .then((res) => setPo(res.data.data))
      .catch(() => { toast.error(t('common.error')); navigate('/purchases'); })
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [id, navigate, t]);

  const remainingByLine = useMemo(() => {
    if (!po?.items) return {};
    const m = {};
    for (const line of po.items) {
      const rem = (line.quantity || 0) - (line.quantity_received || 0);
      m[line.id] = Math.max(0, rem);
    }
    return m;
  }, [po]);

  useEffect(() => {
    if (!receiveOpen || !po?.items) return;
    const init = {};
    for (const line of po.items) {
      const rem = (line.quantity || 0) - (line.quantity_received || 0);
      init[line.id] = rem > 0 ? rem : 0;
    }
    setReceiveQty(init);
  }, [receiveOpen, po]);

  const handleStatus = async (status) => {
    setProcessing(true);
    try {
      const res = await purchaseOrdersApi.updateStatus(id, { status });
      setPo(res.data.data);
      toast.success(t('common.success'));
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setProcessing(false);
    }
  };

  const handleDelete = async () => {
    if (!window.confirm(t('purchases.confirmDeleteDraft'))) return;
    setProcessing(true);
    try {
      await purchaseOrdersApi.delete(id);
      toast.success(t('common.success'));
      navigate('/purchases');
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setProcessing(false);
    }
  };

  const handleReceive = async () => {
    const lines = [];
    for (const line of po.items) {
      const q = parseInt(receiveQty[line.id], 10) || 0;
      if (q > 0) lines.push({ purchase_order_item_id: line.id, quantity: q });
    }
    if (lines.length === 0) {
      toast.error(t('purchases.receiveNeedQty'));
      return;
    }
    setProcessing(true);
    try {
      const res = await purchaseOrdersApi.receive(id, { items: lines });
      setPo(res.data.data.purchase_order);
      setReceiveOpen(false);
      toast.success(t('common.success'));
    } catch (err) {
      const msg = err.response?.data?.message;
      const errs = err.response?.data?.errors;
      toast.error(msg || (errs && Object.values(errs).flat()[0]) || t('common.error'));
    } finally {
      setProcessing(false);
    }
  };

  if (loading || !po) {
    return (
      <div className="min-h-[40vh] flex items-center justify-center text-gray-500">{t('common.loading')}</div>
    );
  }

  const canReceive = ['ordered', 'partially_received'].includes(po.status) && hasPermission('purchases.receive');
  const canMarkOrdered = po.status === 'draft' && hasPermission('purchases.update_status');
  const canCancel = ['draft', 'ordered'].includes(po.status) && hasPermission('purchases.update_status');
  const canEdit = po.status === 'draft' && hasPermission('purchases.update');
  const canDelete = po.status === 'draft' && hasPermission('purchases.delete');

  return (
    <div className="w-full min-w-0 space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3 flex-wrap">
          <button type="button" onClick={() => navigate('/purchases')} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <div>
            <h1 className="page-title font-mono">{po.purchase_number}</h1>
            <p className="text-sm text-gray-500">{formatDate(po.order_date)}</p>
          </div>
          <Badge label={t(`purchases.status.${po.status}`)} variant={poStatusVariant(po.status)} />
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => navigate(`/print/purchase-order/${id}`)}
            className="btn-secondary btn btn-sm"
          >
            <Printer size={14} /> {t('purchases.printPo')}
          </button>
          {canEdit && (
            <button type="button" onClick={() => navigate(`/purchases/${id}/edit`)} className="btn-secondary btn btn-sm">
              <Pencil size={14} /> {t('common.edit')}
            </button>
          )}
          {canMarkOrdered && (
            <button type="button" disabled={processing} onClick={() => handleStatus('ordered')} className="btn-primary btn btn-sm">
              <Send size={14} /> {t('purchases.markOrdered')}
            </button>
          )}
          {canCancel && (
            <button type="button" disabled={processing} onClick={() => handleStatus('cancelled')} className="btn-secondary btn btn-sm text-red-600 border-red-200">
              <Ban size={14} /> {t('purchases.cancel')}
            </button>
          )}
          {canDelete && (
            <button type="button" disabled={processing} onClick={handleDelete} className="btn-ghost btn btn-sm text-red-600">
              <Trash2 size={14} /> {t('common.delete')}
            </button>
          )}
          {canReceive && (
            <button type="button" onClick={() => setReceiveOpen(true)} className="btn-primary btn btn-sm">
              <PackageCheck size={14} /> {t('purchases.receive')}
            </button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="card p-4 lg:col-span-2 space-y-3">
          <h2 className="text-sm font-semibold text-gray-900">{t('purchases.summary')}</h2>
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
            <div><dt className="text-gray-500">{t('purchases.supplier')}</dt><dd className="font-medium">{po.supplier?.name}</dd></div>
            <div><dt className="text-gray-500">{t('common.branch')}</dt><dd className="font-medium">{po.branch?.name}</dd></div>
            <div><dt className="text-gray-500">{t('purchases.expectedDate')}</dt><dd>{po.expected_date ? formatDate(po.expected_date) : t('common.emDash')}</dd></div>
            <div><dt className="text-gray-500">{t('purchases.vendorBill')}</dt><dd className="font-medium">{formatCurrency(po.vendor_bill_total || po.total)}</dd></div>
          </dl>
          {po.notes && <p className="text-sm text-gray-600 border-t pt-3">{po.notes}</p>}
        </div>
        <div className="card p-4">
          <h2 className="text-sm font-semibold text-gray-900 mb-2">{t('orders.subtotal')}</h2>
          <p className="text-lg font-semibold text-primary-700">{formatCurrency(po.total)}</p>
          <p className="text-xs text-gray-500 mt-1">{t('orders.discount')}: {formatCurrency(po.discount_amount)}</p>
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="px-4 py-3 border-b bg-gray-50">
          <h2 className="text-sm font-semibold text-gray-900">{t('purchases.lines')}</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-start text-gray-500 border-b">
                <th className="px-4 py-2">{t('products.name')}</th>
                <th className="px-4 py-2">{t('products.quantity')}</th>
                <th className="px-4 py-2">{t('purchases.received')}</th>
                <th className="px-4 py-2">{t('purchases.unitCost')}</th>
                <th className="px-4 py-2">{t('common.total')}</th>
              </tr>
            </thead>
            <tbody>
              {(po.items || []).map((line) => (
                <tr key={line.id} className="border-b last:border-0">
                  <td className="px-4 py-2">{line.product?.name || '—'}</td>
                  <td className="px-4 py-2">{line.quantity}</td>
                  <td className="px-4 py-2">{line.quantity_received ?? 0}</td>
                  <td className="px-4 py-2">{formatCurrency(line.unit_cost)}</td>
                  <td className="px-4 py-2 font-medium">{formatCurrency(line.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="px-4 py-3 border-b bg-gray-50">
          <h2 className="text-sm font-semibold text-gray-900">{t('purchases.receipts')}</h2>
        </div>
        <div className="divide-y">
          {(po.goods_receipts || []).length === 0 ? (
            <p className="px-4 py-6 text-sm text-gray-500 text-center">{t('common.noData')}</p>
          ) : (
            (po.goods_receipts || []).map((gr) => (
              <div key={gr.id} className="px-4 py-3 text-sm">
                <div className="flex items-center justify-between gap-2 flex-wrap">
                  <span className="font-mono font-medium">{gr.receipt_number}</span>
                  <span className="text-gray-500">{gr.received_at ? formatDateTime(gr.received_at) : ''}</span>
                </div>
                {gr.received_by && (
                  <p className="text-xs text-gray-500 mt-1">{t('common.by')}: {gr.received_by.name}</p>
                )}
              </div>
            ))
          )}
        </div>
      </div>

      <Modal
        open={receiveOpen}
        onClose={() => setReceiveOpen(false)}
        title={t('purchases.receiveTitle')}
        size="lg"
        footer={(
          <>
            <button type="button" onClick={() => setReceiveOpen(false)} className="btn-secondary btn">{t('common.cancel')}</button>
            <button type="button" disabled={processing} onClick={handleReceive} className="btn-primary btn">
              {processing ? '…' : t('purchases.confirmReceive')}
            </button>
          </>
        )}
      >
        <p className="text-sm text-gray-600 mb-3">{t('purchases.receiveHint')}</p>
        <div className="space-y-3 max-h-96 overflow-y-auto">
          {(po.items || []).map((line) => {
            const rem = remainingByLine[line.id] ?? 0;
            if (rem <= 0) return null;
            return (
              <div key={line.id} className="flex flex-wrap items-center gap-2 p-3 bg-gray-50 rounded-lg">
                <div className="flex-1 min-w-[140px]">
                  <p className="text-sm font-medium">{line.product?.name}</p>
                  <p className="text-xs text-gray-500">{t('purchases.remaining')}: {rem}</p>
                </div>
                <label className="text-xs text-gray-600">{t('purchases.receiveQty')}</label>
                <input
                  type="number"
                  min="0"
                  max={rem}
                  className="input w-24"
                  value={receiveQty[line.id] ?? 0}
                  onChange={(e) => setReceiveQty((prev) => ({ ...prev, [line.id]: e.target.value }))}
                />
              </div>
            );
          })}
        </div>
      </Modal>
    </div>
  );
}
