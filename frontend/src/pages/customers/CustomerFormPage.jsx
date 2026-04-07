import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight, ArrowLeft } from 'lucide-react';
import { FormField, Input, Select, Textarea } from '../../components/ui/FormField';
import { customersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import toast from 'react-hot-toast';

export default function CustomerFormPage() {
  const { t, isRTL } = useLang();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [form, setForm] = useState({
    name: '', phone: '', email: '', national_id: '', id_type: 'national',
    address: '', city: '', employer_name: '', monthly_salary: '', credit_score: 'good', notes: '',
  });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  const set = (field) => (e) => setForm(p => ({ ...p, [field]: e.target.value }));

  const validate = () => {
    const errs = {};
    if (!form.name) errs.name = t('validation.required');
    if (!form.phone) errs.phone = t('validation.required');
    return errs;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const errs = validate();
    if (Object.keys(errs).length) { setErrors(errs); return; }

    setLoading(true);
    try {
      await customersApi.create(form);
      toast.success(t('common.success'));
      navigate('/customers');
    } catch (err) {
      if (err.response?.data?.errors) {
        setErrors(err.response.data.errors);
      } else {
        toast.error(t('common.error'));
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="w-full min-w-0">
      <div className="page-header mb-6">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="btn-ghost btn btn-sm">
            <BackIcon size={16} />
          </button>
          <h1 className="page-title">{t('customers.add')}</h1>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="card p-6 space-y-4">
        {/* Basic Info */}
        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('customers.name')} error={errors.name} required>
            <Input value={form.name} onChange={set('name')} error={errors.name} placeholder={t('customers.name')} />
          </FormField>
          <FormField label={t('common.phone')} error={errors.phone} required>
            <Input value={form.phone} onChange={set('phone')} error={errors.phone} type="tel" />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('common.email')} error={errors.email}>
            <Input value={form.email} onChange={set('email')} type="email" />
          </FormField>
          <FormField label={t('customers.nationalId')} error={errors.national_id}>
            <Input value={form.national_id} onChange={set('national_id')} />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('customers.idType')}>
            <Select value={form.id_type} onChange={set('id_type')}>
              <option value="national">{t('customers.idTypeNational')}</option>
              <option value="residency">{t('customers.idTypeResidency')}</option>
              <option value="passport">{t('customers.idTypePassport')}</option>
            </Select>
          </FormField>
          <FormField label={t('common.city')}>
            <Input value={form.city} onChange={set('city')} />
          </FormField>
        </div>

        <FormField label={t('common.address')}>
          <Input value={form.address} onChange={set('address')} />
        </FormField>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('customers.employer')}>
            <Input value={form.employer_name} onChange={set('employer_name')} />
          </FormField>
          <FormField label={t('customers.salary')}>
            <Input value={form.monthly_salary} onChange={set('monthly_salary')} type="number" min="0" step="0.01" />
          </FormField>
        </div>

        <FormField label={t('customers.creditScore')}>
          <Select value={form.credit_score} onChange={set('credit_score')}>
            <option value="excellent">{t('customers.creditExcellent')}</option>
            <option value="good">{t('customers.creditGood')}</option>
            <option value="fair">{t('customers.creditFair')}</option>
            <option value="poor">{t('customers.creditPoor')}</option>
          </Select>
        </FormField>

        <FormField label={t('common.notes')}>
          <Textarea value={form.notes} onChange={set('notes')} />
        </FormField>

        <div className="flex items-center justify-end gap-3 pt-2">
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary btn">
            {t('common.cancel')}
          </button>
          <button type="submit" disabled={loading} className="btn-primary btn">
            {loading ? t('common.loading') : t('common.save')}
          </button>
        </div>
      </form>
    </div>
  );
}
