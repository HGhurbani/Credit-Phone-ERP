import { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Plus, Trash2, Search } from 'lucide-react';
import { FormField, Input, Select, Textarea } from '../../components/ui/FormField';
import { ordersApi, customersApi, productsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency } from '../../utils/format';
import toast from 'react-hot-toast';

export default function OrderFormPage() {
  const { t, isRTL } = useLang();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [type, setType] = useState('cash');
  const [customer, setCustomer] = useState(null);
  const [customerSearch, setCustomerSearch] = useState('');
  const [customerResults, setCustomerResults] = useState([]);
  const [items, setItems] = useState([]);
  const [productSearch, setProductSearch] = useState('');
  const [productResults, setProductResults] = useState([]);
  const [discount, setDiscount] = useState('');
  const [notes, setNotes] = useState('');
  const [loading, setLoading] = useState(false);

  // Pre-fill customer if passed in URL
  useEffect(() => {
    const customerId = searchParams.get('customer_id');
    if (customerId) {
      customersApi.get(customerId).then(r => setCustomer(r.data.data)).catch(() => {});
    }
  }, [searchParams]);

  const searchCustomers = async (q) => {
    if (!q) { setCustomerResults([]); return; }
    const res = await customersApi.list({ search: q, per_page: 5 });
    setCustomerResults(res.data.data);
  };

  const searchProducts = async (q) => {
    if (!q) { setProductResults([]); return; }
    const res = await productsApi.list({ search: q, active_only: true, per_page: 8 });
    setProductResults(res.data.data);
  };

  const addItem = (product) => {
    const price = type === 'cash' ? parseFloat(product.cash_price) : parseFloat(product.installment_price);
    setItems(prev => {
      const existing = prev.find(i => i.product_id === product.id);
      if (existing) {
        return prev.map(i => i.product_id === product.id ? { ...i, quantity: i.quantity + 1, total: (i.quantity + 1) * price } : i);
      }
      return [...prev, { product_id: product.id, product_name: product.name, unit_price: price, quantity: 1, discount_amount: 0, total: price }];
    });
    setProductSearch('');
    setProductResults([]);
  };

  const updateItem = (idx, field, value) => {
    setItems(prev => prev.map((item, i) => {
      if (i !== idx) return item;
      const updated = { ...item, [field]: value };
      updated.total = (updated.unit_price * updated.quantity) - (updated.discount_amount || 0);
      return updated;
    }));
  };

  const removeItem = (idx) => setItems(prev => prev.filter((_, i) => i !== idx));

  const subtotal = items.reduce((s, i) => s + i.total, 0);
  const discountAmt = parseFloat(discount) || 0;
  const total = subtotal - discountAmt;

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!customer) { toast.error(t('orders.toastSelectCustomer')); return; }
    if (items.length === 0) { toast.error(t('orders.toastAddProduct')); return; }

    setLoading(true);
    try {
      const res = await ordersApi.create({
        customer_id: customer.id,
        type,
        items: items.map(i => ({ product_id: i.product_id, quantity: i.quantity, discount_amount: i.discount_amount })),
        discount_amount: discountAmt,
        notes,
      });
      toast.success(t('common.success'));
      navigate(`/orders/${res.data.data.id}`);
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="max-w-3xl">
      <div className="page-header mb-6">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <h1 className="page-title">{t('orders.add')}</h1>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        {/* Order Type */}
        <div className="card p-4">
          <p className="text-sm font-medium text-gray-700 mb-3">{t('orders.type')}</p>
          <div className="flex gap-3">
            {['cash', 'installment'].map(ot => (
              <button key={ot} type="button"
                onClick={() => { setType(ot); setItems(items.map(i => ({ ...i, unit_price: 0, total: 0 }))); }}
                className={`flex-1 py-3 rounded-xl text-sm font-medium border-2 transition-all ${type === ot ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'}`}
              >
                {ot === 'cash' ? t('orders.typeCash') : t('orders.typeInstallment')}
              </button>
            ))}
          </div>
        </div>

        {/* Customer */}
        <div className="card p-4">
          <p className="text-sm font-medium text-gray-700 mb-3">{t('orders.selectCustomer')}</p>
          {customer ? (
            <div className="flex items-center justify-between bg-primary-50 border border-primary-200 rounded-lg p-3">
              <div>
                <p className="font-medium text-primary-800">{customer.name}</p>
                <p className="text-xs text-primary-600">{customer.phone}</p>
              </div>
              <button type="button" onClick={() => setCustomer(null)} className="text-primary-500 hover:text-primary-700 text-sm">{t('orders.changeCustomer')}</button>
            </div>
          ) : (
            <div className="relative">
              <div className="absolute inset-y-0 start-3 flex items-center"><Search size={16} className="text-gray-400" /></div>
              <input
                type="text"
                value={customerSearch}
                onChange={e => { setCustomerSearch(e.target.value); searchCustomers(e.target.value); }}
                className="input ps-9"
                placeholder={t('customers.searchPlaceholder')}
              />
              {customerResults.length > 0 && (
                <div className="absolute top-full start-0 end-0 z-10 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden">
                  {customerResults.map(c => (
                    <button key={c.id} type="button"
                      onClick={() => { setCustomer(c); setCustomerSearch(''); setCustomerResults([]); }}
                      className="w-full text-start px-4 py-2.5 hover:bg-gray-50 border-b last:border-0"
                    >
                      <p className="text-sm font-medium">{c.name}</p>
                      <p className="text-xs text-gray-400">{c.phone}</p>
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Products */}
        <div className="card p-4">
          <p className="text-sm font-medium text-gray-700 mb-3">{t('orders.items')}</p>
          <div className="relative mb-3">
            <div className="absolute inset-y-0 start-3 flex items-center"><Search size={16} className="text-gray-400" /></div>
            <input
              type="text"
              value={productSearch}
              onChange={e => { setProductSearch(e.target.value); searchProducts(e.target.value); }}
              className="input ps-9"
              placeholder={t('products.searchPlaceholder')}
            />
            {productResults.length > 0 && (
              <div className="absolute top-full start-0 end-0 z-10 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden">
                {productResults.map(p => (
                  <button key={p.id} type="button" onClick={() => addItem(p)}
                    className="w-full text-start px-4 py-2.5 hover:bg-gray-50 border-b last:border-0 flex items-center justify-between"
                  >
                    <div>
                      <p className="text-sm font-medium">{p.name}</p>
                      <p className="text-xs text-gray-400">{p.sku}</p>
                    </div>
                    <p className="text-sm font-medium text-primary-600">{formatCurrency(type === 'cash' ? p.cash_price : p.installment_price)}</p>
                  </button>
                ))}
              </div>
            )}
          </div>

          {items.length > 0 && (
            <div className="space-y-2">
              {items.map((item, idx) => (
                <div key={idx} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                  <div className="flex-1">
                    <p className="text-sm font-medium">{item.product_name}</p>
                    <p className="text-xs text-gray-400">{formatCurrency(item.unit_price)} each</p>
                  </div>
                  <input
                    type="number" min="1" value={item.quantity}
                    onChange={e => updateItem(idx, 'quantity', parseInt(e.target.value) || 1)}
                    className="input w-20 text-center"
                  />
                  <span className="text-sm font-medium w-24 text-end">{formatCurrency(item.total)}</span>
                  <button type="button" onClick={() => removeItem(idx)} className="text-red-400 hover:text-red-600">
                    <Trash2 size={16} />
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Totals */}
        <div className="card p-4">
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm text-gray-600">{t('orders.subtotal')}</span>
            <span className="font-medium">{formatCurrency(subtotal)}</span>
          </div>
          <div className="flex items-center gap-3 mb-3">
            <span className="text-sm text-gray-600 w-24">{t('orders.discount')}</span>
            <input type="number" min="0" value={discount} onChange={e => setDiscount(e.target.value)} className="input w-40" placeholder="0" />
          </div>
          <div className="flex items-center justify-between pt-3 border-t border-gray-100">
            <span className="font-semibold">{t('common.total')}</span>
            <span className="text-lg font-bold text-primary-600">{formatCurrency(total)}</span>
          </div>
        </div>

        <div className="card p-4">
          <FormField label={t('common.notes')}>
            <Textarea value={notes} onChange={e => setNotes(e.target.value)} rows={2} />
          </FormField>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" disabled={loading} className="btn-primary btn">
            {loading ? t('common.loading') : t('common.save')}
          </button>
        </div>
      </form>
    </div>
  );
}
