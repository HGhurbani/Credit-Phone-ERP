import { useState, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { FormField, Input, Select, Textarea } from '../../components/ui/FormField';
import { productsApi, settingsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency } from '../../utils/format';
import toast from 'react-hot-toast';
import { INSTALLMENT_DURATION_PRESETS, INSTALLMENT_DURATION_MAX_MONTHS } from '../../constants/installmentDurations';

export default function ProductFormPage() {
  const { t, isRTL } = useLang();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [pricingMode, setPricingMode] = useState('percentage');
  const [form, setForm] = useState({
    name: '', name_ar: '', sku: '', description: '',
    category_id: '', brand_id: '',
    cash_price: '', installment_price: '', cost_price: '',
    min_down_payment: '', allowed_durations: [3, 6, 12],
    monthly_percent_of_cash: '',
    fixed_monthly_amount: '',
    track_serial: false, is_active: true,
  });
  const [categories, setCategories] = useState([]);
  const [brands, setBrands] = useState([]);
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);
  const [durationDraft, setDurationDraft] = useState('');

  useEffect(() => {
    productsApi.categories().then(r => setCategories(r.data.data));
    productsApi.brands().then(r => setBrands(r.data.data));
    settingsApi.get().then(r => {
      const s = r.data.data || {};
      setPricingMode(s.installment_pricing_mode || 'percentage');
      if (s.installment_monthly_percent_of_cash != null && s.installment_monthly_percent_of_cash !== '') {
        setForm(p => ({ ...p, monthly_percent_of_cash: String(s.installment_monthly_percent_of_cash) }));
      }
    }).catch(() => {});
  }, []);

  const set = (field) => (e) => {
    const val = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
    setForm(p => ({ ...p, [field]: val }));
  };

  const togglePresetDuration = (d) => {
    setForm(p => ({
      ...p,
      allowed_durations: p.allowed_durations.includes(d)
        ? p.allowed_durations.filter(x => x !== d)
        : [...p.allowed_durations, d].sort((a, b) => a - b),
    }));
  };

  const addCustomDuration = () => {
    const n = parseInt(String(durationDraft).trim(), 10);
    if (!Number.isFinite(n) || n < 1 || n > INSTALLMENT_DURATION_MAX_MONTHS) {
      toast.error(t('validation.durationMonths'));
      return;
    }
    setForm((p) => {
      if (p.allowed_durations.includes(n)) return p;
      return { ...p, allowed_durations: [...p.allowed_durations, n].sort((a, b) => a - b) };
    });
    setDurationDraft('');
  };

  const removeDuration = (d) => {
    setForm((p) => ({ ...p, allowed_durations: p.allowed_durations.filter((x) => x !== d) }));
  };

  const extraDurations = form.allowed_durations.filter((d) => !INSTALLMENT_DURATION_PRESETS.includes(d));

  const fixedPreviewTotal = useMemo(() => {
    const minDown = parseFloat(String(form.min_down_payment).replace(',', '.'));
    const fm = parseFloat(String(form.fixed_monthly_amount).replace(',', '.'));
    const months = form.allowed_durations.length ? Math.min(...form.allowed_durations.map(Number)) : 0;
    if (!Number.isFinite(minDown) || !Number.isFinite(fm) || !months) return null;
    return minDown + fm * months;
  }, [form.min_down_payment, form.fixed_monthly_amount, form.allowed_durations]);

  const validate = () => {
    const errs = {};
    if (!form.name) errs.name = t('validation.required');
    if (!form.cash_price) errs.cash_price = t('validation.required');
    if (pricingMode === 'percentage') {
      if (!form.installment_price) errs.installment_price = t('validation.required');
    } else {
      if (!form.fixed_monthly_amount) errs.fixed_monthly_amount = t('validation.required');
      if (!form.min_down_payment && form.min_down_payment !== 0) errs.min_down_payment = t('validation.required');
      if (!form.allowed_durations?.length) errs.allowed_durations = t('validation.required');
    }
    return errs;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const errs = validate();
    if (Object.keys(errs).length) { setErrors(errs); return; }
    setLoading(true);
    try {
      const payload = {
        ...form,
        category_id: form.category_id || null,
        brand_id: form.brand_id || null,
        monthly_percent_of_cash: form.monthly_percent_of_cash === '' ? null : parseFloat(String(form.monthly_percent_of_cash).replace(',', '.')),
        fixed_monthly_amount: form.fixed_monthly_amount === '' ? null : parseFloat(String(form.fixed_monthly_amount).replace(',', '.')),
      };
      if (pricingMode === 'percentage') {
        delete payload.fixed_monthly_amount;
      } else {
        delete payload.monthly_percent_of_cash;
        delete payload.installment_price;
      }
      await productsApi.create(payload);
      toast.success(t('common.success'));
      navigate('/products');
    } catch (err) {
      if (err.response?.data?.errors) setErrors(err.response.data.errors);
      else toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="w-full min-w-0">
      <div className="page-header mb-6">
        <div className="flex items-center gap-3">
          <button type="button" onClick={() => navigate(-1)} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <h1 className="page-title">{t('products.add')}</h1>
        </div>
      </div>

      <div className="mb-4 p-3 rounded-lg bg-gray-50 border border-gray-100 text-sm text-gray-600">
        <span className="font-medium text-gray-800">{t('settings.fields.installment_pricing_mode')}: </span>
        {pricingMode === 'percentage' ? t('settings.installmentModePercentage') : t('settings.installmentModeFixed')}
      </div>

      <form onSubmit={handleSubmit} className="card p-6 space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('products.name')} error={errors.name} required>
            <Input value={form.name} onChange={set('name')} error={errors.name} />
          </FormField>
          <FormField label={t('products.nameAr')} error={errors.name_ar}>
            <Input value={form.name_ar} onChange={set('name_ar')} dir="rtl" />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('products.sku')}>
            <Input value={form.sku} onChange={set('sku')} />
          </FormField>
          <FormField label={t('products.category')}>
            <Select value={form.category_id} onChange={set('category_id')}>
              <option value="">{t('common.all')}</option>
              {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </FormField>
        </div>

        <FormField label={t('products.brand')}>
          <Select value={form.brand_id} onChange={set('brand_id')}>
            <option value="">{t('common.all')}</option>
            {brands.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select>
        </FormField>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('products.cashPrice')} error={errors.cash_price} required>
            <Input value={form.cash_price} onChange={set('cash_price')} type="number" min="0" step="0.01" error={errors.cash_price} />
          </FormField>
          {pricingMode === 'percentage' ? (
            <FormField label={t('products.installmentPrice')} error={errors.installment_price} required>
              <Input value={form.installment_price} onChange={set('installment_price')} type="number" min="0" step="0.01" error={errors.installment_price} />
            </FormField>
          ) : (
            <FormField label={t('products.costPrice')}>
              <Input value={form.cost_price} onChange={set('cost_price')} type="number" min="0" step="0.01" />
            </FormField>
          )}
        </div>

        {pricingMode === 'percentage' && (
          <>
            <FormField label={t('products.monthlyPercentOfCash')} error={errors.monthly_percent_of_cash}>
              <Input value={form.monthly_percent_of_cash} onChange={set('monthly_percent_of_cash')} type="number" min="0" max="100" step="0.01" placeholder="5" />
              <p className="text-xs text-gray-400 mt-1">{t('products.monthlyPercentHint')}</p>
            </FormField>
            <FormField label={t('products.costPrice')}>
              <Input value={form.cost_price} onChange={set('cost_price')} type="number" min="0" step="0.01" />
            </FormField>
          </>
        )}

        {pricingMode === 'fixed' && (
          <>
            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('products.minDownPayment')} error={errors.min_down_payment} required>
                <Input value={form.min_down_payment} onChange={set('min_down_payment')} type="number" min="0" step="0.01" />
              </FormField>
              <FormField label={t('products.fixedMonthlyAmount')} error={errors.fixed_monthly_amount} required>
                <Input value={form.fixed_monthly_amount} onChange={set('fixed_monthly_amount')} type="number" min="0.01" step="0.01" />
              </FormField>
            </div>
            <p className="text-xs text-gray-500">{t('products.fixedMonthlyHint')}</p>
            {fixedPreviewTotal != null && (
              <p className="text-sm font-medium text-primary-700">
                {t('products.installmentTotalPreview')}: {formatCurrency(fixedPreviewTotal)}
              </p>
            )}
          </>
        )}

        {pricingMode === 'percentage' && (
          <FormField label={t('products.minDownPayment')}>
            <Input value={form.min_down_payment} onChange={set('min_down_payment')} type="number" min="0" step="0.01" />
          </FormField>
        )}

        <FormField label={t('products.allowedDurations')} error={errors.allowed_durations}>
          <p className="text-xs text-gray-500 mb-2">{t('contracts.durationManualHint')}</p>
          <p className="text-xs font-medium text-gray-600 mb-2">{t('contracts.durationQuickSelect')}</p>
          <div className="flex flex-wrap gap-2">
            {INSTALLMENT_DURATION_PRESETS.map((d) => {
              const selected = form.allowed_durations.includes(d);
              return (
                <button
                  key={d}
                  type="button"
                  onClick={() => togglePresetDuration(d)}
                  className={`inline-flex flex-col items-center justify-center min-w-[4.25rem] px-3 py-2 rounded-xl border-2 text-sm transition-all ${
                    selected
                      ? 'border-primary-600 bg-primary-50 text-primary-900 ring-2 ring-primary-200'
                      : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300'
                  }`}
                >
                  <span className="text-lg font-bold leading-none">{d}</span>
                  <span className="text-[10px] text-gray-500 mt-0.5">{t('contracts.monthShort')}</span>
                </button>
              );
            })}
          </div>
          <div className="mt-4 pt-4 border-t border-gray-100">
            <p className="text-xs font-medium text-gray-600 mb-2">{t('products.durationAddCustom')}</p>
            <div className="flex flex-wrap gap-2 items-end">
              <div className="flex-1 min-w-[140px]">
                <label className="sr-only" htmlFor="product-duration-custom">{t('contracts.durationManualLabel')}</label>
                <input
                  id="product-duration-custom"
                  type="number"
                  min={1}
                  max={INSTALLMENT_DURATION_MAX_MONTHS}
                  step={1}
                  className="input"
                  placeholder={t('contracts.durationManualPlaceholder')}
                  value={durationDraft}
                  onChange={(e) => {
                    const v = e.target.value;
                    if (v === '' || /^\d{1,3}$/.test(v)) setDurationDraft(v);
                  }}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      addCustomDuration();
                    }
                  }}
                />
              </div>
              <button type="button" onClick={addCustomDuration} className="btn-secondary btn">{t('common.add')}</button>
            </div>
          </div>
          {extraDurations.length > 0 && (
            <div className="mt-3">
              <p className="text-xs font-medium text-gray-600 mb-2">{t('products.durationOtherList')}</p>
              <div className="flex flex-wrap gap-2">
                {extraDurations.map((d) => (
                  <button
                    key={d}
                    type="button"
                    onClick={() => removeDuration(d)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-sm hover:bg-amber-100"
                  >
                    {d} {t('contracts.months')}
                    <span className="text-amber-600">×</span>
                  </button>
                ))}
              </div>
            </div>
          )}
        </FormField>

        <FormField label={t('products.description')}>
          <Textarea value={form.description} onChange={set('description')} />
        </FormField>

        <div className="flex items-center gap-4">
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={form.is_active} onChange={set('is_active')} className="rounded" />
            <span className="text-sm text-gray-700">{t('common.active')}</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={form.track_serial} onChange={set('track_serial')} className="rounded" />
            <span className="text-sm text-gray-700">{t('products.trackSerial')}</span>
          </label>
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" disabled={loading} className="btn-primary btn">
            {loading ? t('common.loading') : t('common.save')}
          </button>
        </div>
      </form>
    </div>
  );
}
