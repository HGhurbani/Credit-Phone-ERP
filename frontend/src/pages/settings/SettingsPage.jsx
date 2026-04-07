import { useState, useEffect } from 'react';
import { settingsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import toast from 'react-hot-toast';

const SETTING_GROUPS = [
  {
    group: 'company',
    label: 'settings.companyProfile',
    settings: [
      { key: 'company_name', type: 'text' },
      { key: 'company_phone', type: 'text' },
      { key: 'company_email', type: 'email' },
      { key: 'company_address', type: 'text' },
    ],
  },
  {
    group: 'installment',
    label: 'settings.installmentSettings',
    settings: [
      {
        key: 'installment_pricing_mode',
        type: 'select',
        options: [
          { value: 'percentage', labelKey: 'settings.installmentModePercentage' },
          { value: 'fixed', labelKey: 'settings.installmentModeFixed' },
        ],
      },
      { key: 'installment_monthly_percent_of_cash', type: 'number' },
      { key: 'admin_fee_percentage', type: 'number' },
      { key: 'late_fee_percentage', type: 'number' },
      { key: 'grace_days', type: 'number' },
    ],
  },
  {
    group: 'invoice',
    label: 'settings.invoiceSettings',
    settings: [
      { key: 'invoice_prefix', type: 'text' },
      { key: 'invoice_footer', type: 'text' },
      { key: 'show_logo_on_invoice', type: 'boolean' },
    ],
  },
];

export default function SettingsPage() {
  const { t } = useLang();
  const [settings, setSettings] = useState({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    settingsApi.get().then(r => setSettings(r.data.data || {})).finally(() => setLoading(false));
  }, []);

  const handleChange = (key, value) => setSettings(p => ({ ...p, [key]: value }));

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await settingsApi.update(settings);
      toast.success(t('common.success'));
    } catch { toast.error(t('common.error')); }
    finally { setSaving(false); }
  };

  if (loading) return <div className="flex items-center justify-center h-40"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>;

  return (
    <div className="w-full min-w-0 space-y-4">
      <div className="page-header">
        <h1 className="page-title">{t('settings.title')}</h1>
      </div>

      <form onSubmit={handleSave} className="space-y-4">
        {SETTING_GROUPS.map(group => (
          <div key={group.group} className="card">
            <div className="card-header">
              <h2 className="font-semibold text-gray-900">{t(group.label)}</h2>
            </div>
            <div className="card-body space-y-4">
              {group.group === 'installment' && (
                <p className="text-sm text-gray-500 -mt-1">{t('settings.installmentPricingModeHelp')}</p>
              )}
              {group.settings.map(setting => (
                <div key={setting.key}>
                  <label className="label">{t(`settings.fields.${setting.key}`)}</label>
                  {setting.type === 'boolean' ? (
                    <label className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={settings[setting.key] === 'true' || settings[setting.key] === true}
                        onChange={e => handleChange(setting.key, e.target.checked)}
                        className="rounded"
                      />
                      <span className="text-sm text-gray-600">{t('common.active')}</span>
                    </label>
                  ) : setting.type === 'select' ? (
                    <select
                      value={settings[setting.key] ?? 'percentage'}
                      onChange={e => handleChange(setting.key, e.target.value)}
                      className="input"
                    >
                      {setting.options.map(opt => (
                        <option key={opt.value} value={opt.value}>{t(opt.labelKey)}</option>
                      ))}
                    </select>
                  ) : (
                    <input
                      type={setting.type}
                      value={settings[setting.key] ?? ''}
                      onChange={e => handleChange(setting.key, e.target.value)}
                      className="input"
                    />
                  )}
                </div>
              ))}
            </div>
          </div>
        ))}

        <div className="flex justify-end">
          <button type="submit" disabled={saving} className="btn-primary btn">
            {saving ? t('common.loading') : t('settings.saveSettings')}
          </button>
        </div>
      </form>
    </div>
  );
}
