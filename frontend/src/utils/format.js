/** كود ISO للعملة الافتراضية: ريال قطري */
export const DEFAULT_CURRENCY_CODE = 'QAR';

/** أرقام لاتينية (0–9) حتى مع واجهة عربية — لا تستخدم الأرقام العربية الهندية */
const LATN = { numberingSystem: 'latn' };

/** لاحقة العرض للعربية (رموز شائعة) */
const CURRENCY_DISPLAY_AR = {
  QAR: 'ر.ق',
  SAR: 'ر.س',
};

/**
 * @param {string} [currencyCode] — كود ISO (مثل QAR) أو تمرير لاحقة جاهزة للعرض
 */
export function formatCurrency(amount, currencyCode = DEFAULT_CURRENCY_CODE) {
  if (amount === null || amount === undefined) return '—';
  const formatted = new Intl.NumberFormat('ar-QA', {
    style: 'decimal',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
    ...LATN,
  }).format(amount);
  const suffix = CURRENCY_DISPLAY_AR[currencyCode] ?? currencyCode;
  return `${formatted} ${suffix}`;
}

export function formatDate(date, locale = 'ar-QA') {
  if (!date) return '—';
  const d = typeof date === 'string' ? new Date(date) : date;
  if (isNaN(d)) return '—';
  return new Intl.DateTimeFormat(locale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    ...LATN,
  }).format(d);
}

export function formatDateTime(date, locale = 'ar-QA') {
  if (!date) return '—';
  const d = typeof date === 'string' ? new Date(date) : date;
  if (isNaN(d)) return '—';
  return new Intl.DateTimeFormat(locale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    ...LATN,
  }).format(d);
}

export function formatNumber(n) {
  if (n === null || n === undefined) return '—';
  return new Intl.NumberFormat('ar-QA', { ...LATN }).format(n);
}
