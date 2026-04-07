import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams, useLocation } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Trash2, Search } from 'lucide-react';
import { Input, Select, Textarea } from '../../components/ui/FormField';
import { suppliersApi, purchaseOrdersApi, productsApi, branchesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency } from '../../utils/format';
import toast from 'react-hot-toast';

export default function PurchaseFormPage() {
  const { id } = useParams();
  const location = useLocation();
  const isEdit = Boolean(id && location.pathname.endsWith('/edit'));
  const { t, isRTL } = useLang();
  const { user, hasRole, hasPermission } = useAuth();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const showBranchField = !user?.branch_id && (hasRole('company_admin') || hasPermission('branches.view'));

  const [supplierId, setSupplierId] = useState('');
  const [branchId, setBranchId] = useState(user?.branch_id ? String(user.branch_id) : '');
  const [orderDate, setOrderDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [expectedDate, setExpectedDate] = useState('');
  const [discount, setDiscount] = useState('');
  const [notes, setNotes] = useState('');
  const [status, setStatus] = useState('draft');
  const [items, setItems] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [branches, setBranches] = useState([]);
  const [productSearch, setProductSearch] = useState('');
  const [productResults, setProductResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [loadPo, setLoadPo] = useState(isEdit);

  useEffect(() => {
    if (user?.branch_id) {
      setBranchId(String(user.branch_id));
    }
  }, [user?.branch_id]);

  useEffect(() => {
    suppliersApi.list({ per_page: 200 }).then((r) => setSuppliers(r.data.data || [])).catch(() => {});
    if (showBranchField) {
      branchesApi.list().then((r) => setBranches(r.data.data || [])).catch(() => {});
    }
  }, [showBranchField]);

  useEffect(() => {
    if (!isEdit || !id) return;
    let cancelled = false;
    setLoadPo(true);
    purchaseOrdersApi.get(id)
      .then((res) => {
        if (cancelled) return;
        const po = res.data.data;
        if (po.status !== 'draft') {
          toast.error(t('purchases.onlyDraftEdit'));
          navigate(`/purchases/${id}`);
          return;
        }
        setSupplierId(String(po.supplier_id));
        setBranchId(String(po.branch?.id ?? ''));
        setOrderDate(po.order_date?.slice(0, 10) || orderDate);
        setExpectedDate(po.expected_date?.slice(0, 10) || '');
        setDiscount(po.discount_amount != null ? String(po.discount_amount) : '');
        setNotes(po.notes || '');
        setStatus(po.status || 'draft');
        setItems((po.items || []).map((line) => ({
          product_id: line.product_id,
          product_name: line.product?.name || '',
          quantity: line.quantity,
          unit_cost: parseFloat(line.unit_cost),
          total: parseFloat(line.total),
        })));
      })
      .catch(() => { toast.error(t('common.error')); navigate('/purchases'); })
      .finally(() => { if (!cancelled) setLoadPo(false); });
    return () => { cancelled = true; };
  }, [isEdit, id, navigate, t]);

  const searchProducts = async (q) => {
    if (!q) { setProductResults([]); return; }
    const res = await productsApi.list({ search: q, active_only: true, per_page: 8 });
    setProductResults(res.data.data);
  };

  const addItem = (product) => {
    const unit = parseFloat(product.cost_price) || 0;
    setItems((prev) => {
      const existing = prev.find((i) => i.product_id === product.id);
      if (existing) {
        return prev.map((i) => (i.product_id === product.id
          ? { ...i, quantity: i.quantity + 1, total: (i.quantity + 1) * i.unit_cost }
          : i));
      }
      return [...prev, {
        product_id: product.id,
        product_name: product.name,
        quantity: 1,
        unit_cost: unit,
        total: unit,
      }];
    });
    setProductSearch('');
    setProductResults([]);
  };

  const updateItem = (idx, field, value) => {
    setItems((prev) => prev.map((item, i) => {
      if (i !== idx) return item;
      const next = { ...item, [field]: field === 'quantity' ? parseInt(value, 10) || 1 : parseFloat(value) || 0 };
      next.total = next.quantity * next.unit_cost;
      return next;
    }));
  };

  const removeItem = (idx) => setItems((prev) => prev.filter((_, i) => i !== idx));

  const subtotal = items.reduce((s, i) => s + i.total, 0);
  const discountAmt = parseFloat(discount) || 0;
  const total = Math.max(0, subtotal - discountAmt);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!supplierId) {
      toast.error(t('purchases.selectSupplier'));
      return;
    }
    const bId = branchId || (user?.branch_id ? String(user.branch_id) : '');
    if (!bId) {
      toast.error(t('purchases.branchRequired'));
      return;
    }
    if (status === 'ordered' && items.length === 0) {
      toast.error(t('purchases.needLines'));
      return;
    }

    const payload = {
      supplier_id: parseInt(supplierId, 10),
      branch_id: parseInt(bId, 10),
      order_date: orderDate,
      expected_date: expectedDate || null,
      discount_amount: discountAmt,
      notes: notes || null,
      status,
      items: items.map((i) => ({
        product_id: i.product_id,
        quantity: i.quantity,
        unit_cost: i.unit_cost,
      })),
    };

    setLoading(true);
    try {
      if (isEdit) {
        await purchaseOrdersApi.update(id, payload);
        toast.success(t('common.success'));
        navigate(`/purchases/${id}`);
      } else {
        const res = await purchaseOrdersApi.create(payload);
        toast.success(t('common.success'));
        navigate(`/purchases/${res.data.data.id}`);
      }
    } catch (err) {
      const msg = err.response?.data?.message;
      const errs = err.response?.data?.errors;
      toast.error(msg || (errs && Object.values(errs).flat()[0]) || t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  if (isEdit && loadPo) {
    return (
      <div className="min-h-[40vh] flex items-center justify-center text-gray-500">{t('common.loading')}</div>
    );
  }

  return (
    <div className="w-full min-w-0">
      <div className="page-header mb-6">
        <div className="flex items-center gap-3">
          <button type="button" onClick={() => navigate(-1)} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <h1 className="page-title">{isEdit ? t('purchases.edit') : t('purchases.add')}</h1>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="card p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('purchases.supplier')} *</label>
            <Select value={supplierId} onChange={(e) => setSupplierId(e.target.value)} required>
              <option value="">{t('purchases.selectSupplier')}</option>
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </Select>
          </div>
          {showBranchField && (
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.branch')} *</label>
              <Select value={branchId} onChange={(e) => setBranchId(e.target.value)} required>
                <option value="">{t('ui.selectBranch')}</option>
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </Select>
            </div>
          )}
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('purchases.orderDate')} *</label>
            <Input type="date" value={orderDate} onChange={(e) => setOrderDate(e.target.value)} required />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('purchases.expectedDate')}</label>
            <Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('purchases.initialStatus')}</label>
            <Select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="draft">{t('purchases.status.draft')}</option>
              <option value="ordered">{t('purchases.status.ordered')}</option>
            </Select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('orders.discount')}</label>
            <Input type="number" min="0" step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)} />
          </div>
          <div className="md:col-span-2">
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.notes')}</label>
            <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
        </div>

        <div className="card p-4">
          <p className="text-sm font-medium text-gray-700 mb-3">{t('purchases.lines')}</p>
          <div className="relative mb-3">
            <div className="absolute inset-y-0 start-3 flex items-center"><Search size={16} className="text-gray-400" /></div>
            <input
              type="text"
              value={productSearch}
              onChange={(e) => { setProductSearch(e.target.value); searchProducts(e.target.value); }}
              className="input ps-9"
              placeholder={t('products.searchPlaceholder')}
            />
            {productResults.length > 0 && (
              <div className="absolute top-full start-0 end-0 z-10 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden max-h-60 overflow-y-auto">
                {productResults.map((p) => (
                  <button key={p.id} type="button" onClick={() => addItem(p)}
                    className="w-full text-start px-4 py-2.5 hover:bg-gray-50 border-b last:border-0 flex justify-between gap-2"
                  >
                    <span>
                      <span className="text-sm font-medium block">{p.name}</span>
                      <span className="text-xs text-gray-400">{p.sku || '—'}</span>
                    </span>
                    <span className="text-xs text-gray-500 shrink-0">{t('products.costPrice')}: {formatCurrency(p.cost_price)}</span>
                  </button>
                ))}
              </div>
            )}
          </div>

          {items.length > 0 && (
            <div className="space-y-2">
              {items.map((item, idx) => (
                <div key={`${item.product_id}-${idx}`} className="flex flex-wrap items-end gap-2 p-3 bg-gray-50 rounded-lg">
                  <div className="flex-1 min-w-[160px]">
                    <p className="text-sm font-medium">{item.product_name}</p>
                  </div>
                  <div className="w-24">
                    <label className="text-xs text-gray-500">{t('products.quantity')}</label>
                    <Input
                      type="number"
                      min="1"
                      value={item.quantity}
                      onChange={(e) => updateItem(idx, 'quantity', e.target.value)}
                    />
                  </div>
                  <div className="w-32">
                    <label className="text-xs text-gray-500">{t('purchases.unitCost')}</label>
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={item.unit_cost}
                      onChange={(e) => updateItem(idx, 'unit_cost', e.target.value)}
                    />
                  </div>
                  <div className="w-28 text-end">
                    <p className="text-xs text-gray-500">{t('common.total')}</p>
                    <p className="text-sm font-medium">{formatCurrency(item.total)}</p>
                  </div>
                  <button type="button" onClick={() => removeItem(idx)} className="btn-ghost btn btn-sm text-red-500 mb-0.5">
                    <Trash2 size={16} />
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="card p-4 flex flex-col sm:flex-row justify-between gap-4 items-start sm:items-center">
          <div className="text-sm text-gray-600 space-y-1">
            <p>{t('orders.subtotal')}: <strong>{formatCurrency(subtotal)}</strong></p>
            <p>{t('common.total')}: <strong className="text-primary-700">{formatCurrency(total)}</strong></p>
          </div>
          <button type="submit" disabled={loading} className="btn-primary btn">
            {loading ? '…' : t('common.save')}
          </button>
        </div>
      </form>
    </div>
  );
}
