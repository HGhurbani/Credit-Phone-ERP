import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Edit, Package } from 'lucide-react';
import Badge from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { productsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency } from '../../utils/format';
import toast from 'react-hot-toast';

export default function ProductDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;
  const canUpdateProduct = hasPermission('products.update');

  const [product, setProduct] = useState(null);
  const [loading, setLoading] = useState(true);
  const [stockModal, setStockModal] = useState(false);
  const [stockForm, setStockForm] = useState({ branch_id: '', quantity: '', type: 'in', notes: '' });
  const [adjusting, setAdjusting] = useState(false);

  const load = async () => {
    try {
      const res = await productsApi.get(id);
      setProduct(res.data.data);
    } catch {
      toast.error(t('common.error'));
      navigate('/products');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [id]);

  const handleAdjustStock = async (e) => {
    e.preventDefault();
    setAdjusting(true);
    try {
      await productsApi.adjustStock(id, stockForm);
      toast.success(t('common.success'));
      setStockModal(false);
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setAdjusting(false);
    }
  };

  if (loading) return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>;
  if (!product) return null;

  return (
    <div className="w-full min-w-0 space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <div>
            <h1 className="page-title">{product.name}</h1>
            <p className="page-subtitle">{product.sku}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Badge label={product.is_active ? t('common.active') : t('common.inactive')} variant={product.is_active ? 'green' : 'gray'} />
          {canUpdateProduct && (
            <button type="button" onClick={() => navigate(`/products/${product.id}/edit`)} className="btn-secondary btn btn-sm">
              <Edit size={14} />
              {t('common.edit')}
            </button>
          )}
          <button onClick={() => setStockModal(true)} className="btn-secondary btn btn-sm">
            {t('products.adjustStock')}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: t('products.cashPrice'), value: formatCurrency(product.cash_price) },
          { label: t('products.installmentPrice'), value: formatCurrency(product.installment_price) },
          { label: t('products.minDownPayment'), value: formatCurrency(product.min_down_payment) },
          { label: t('products.category'), value: product.category?.name || '—' },
        ].map(item => (
          <div key={item.label} className="card p-4">
            <p className="text-xs text-gray-500 mb-1">{item.label}</p>
            <p className="font-semibold text-gray-900">{item.value}</p>
          </div>
        ))}
      </div>

      {/* Allowed Durations */}
      {product.allowed_durations?.length > 0 && (
        <div className="card p-4">
          <p className="text-sm font-medium text-gray-700 mb-2">{t('products.allowedDurations')}</p>
          <div className="flex flex-wrap gap-2">
            {product.allowed_durations.map(d => (
              <span key={d} className="px-3 py-1 bg-primary-50 text-primary-700 rounded-lg text-sm font-medium">{d}m</span>
            ))}
          </div>
        </div>
      )}

      {/* Stock by branch */}
      <div className="card">
        <div className="card-header"><p className="font-semibold">{t('products.stock')}</p></div>
        <div className="divide-y divide-gray-50">
          {product.inventories?.map(inv => (
            <div key={inv.branch_id} className="flex items-center justify-between px-6 py-3">
              <p className="text-sm font-medium">{inv.branch_name}</p>
              <div className="flex items-center gap-3">
                {inv.is_low_stock && <Badge label={t('products.lowStock')} variant="red" />}
                <span className={`text-lg font-bold ${inv.quantity === 0 ? 'text-red-600' : 'text-gray-900'}`}>
                  {inv.quantity}
                </span>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Stock Adjustment Modal */}
      <Modal open={stockModal} onClose={() => setStockModal(false)} title={t('products.adjustStock')} size="sm"
        footer={<>
          <button onClick={() => setStockModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="stock-form" disabled={adjusting} className="btn-primary btn">{adjusting ? '...' : t('common.save')}</button>
        </>}
      >
        <form id="stock-form" onSubmit={handleAdjustStock} className="space-y-3">
          <div>
            <label className="label">{t('common.branch')} *</label>
            <select required value={stockForm.branch_id} onChange={e => setStockForm(p => ({ ...p, branch_id: e.target.value }))} className="input">
              <option value="">{t('ui.selectBranch')}</option>
              {product.inventories?.map(inv => <option key={inv.branch_id} value={inv.branch_id}>{inv.branch_name}</option>)}
            </select>
          </div>
          <div>
            <label className="label">{t('ui.stockMovementType')} *</label>
            <select required value={stockForm.type} onChange={e => setStockForm(p => ({ ...p, type: e.target.value }))} className="input">
              <option value="in">{t('ui.stockIn')}</option>
              <option value="out">{t('ui.stockOut')}</option>
              <option value="adjustment">{t('ui.stockAdjust')}</option>
            </select>
          </div>
          <div>
            <label className="label">{t('products.quantity')} *</label>
            <input required type="number" min="0" value={stockForm.quantity}
              onChange={e => setStockForm(p => ({ ...p, quantity: e.target.value }))} className="input" />
          </div>
          <div>
            <label className="label">{t('common.notes')}</label>
            <textarea value={stockForm.notes} onChange={e => setStockForm(p => ({ ...p, notes: e.target.value }))} className="input" rows={2} />
          </div>
        </form>
      </Modal>
    </div>
  );
}
