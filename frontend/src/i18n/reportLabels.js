/**
 * تسميات أعمدة ومفاتيح ملخص التقارير — ترتبط بـ reports.fields.* في en.js / ar.js
 */

export function reportFieldLabel(key, t) {
  const path = `reports.fields.${key}`;
  const label = t(path);
  if (label !== path) return label;
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** مفتاح `total` في الملخص يختلف حسب نوع التقرير */
export function reportSummaryLabel(key, activeReport, t) {
  if (key === 'total') {
    if (activeReport === 'active_contracts') return t('reports.fields.totalContractsCount');
    if (activeReport === 'overdue') return t('reports.fields.totalOverdueCount');
  }
  return reportFieldLabel(key, t);
}

export function formatReportCellValue(val) {
  if (val == null || val === '') return null;
  if (typeof val === 'object' && !Array.isArray(val)) {
    if (typeof val.name === 'string') return val.name;
    if (typeof val.contract_number === 'string') return val.contract_number;
    if (typeof val.order_number === 'string') return val.order_number;
    if (val.customer?.name) return val.customer.name;
    if (val.branch?.name) return val.branch.name;
    return null;
  }
  return val;
}

export function isReportCurrencyKey(key) {
  if (!key || typeof key !== 'string') return false;
  if (key === 'total_orders' || key === 'total_payments' || key === 'installment_number' || key === 'duration_months') return false;
  if ([
    'avg_order_value',
    'avg_payment_value',
    'portfolio_value',
    'avg_paid_per_contract',
    'outstanding_gap',
  ].includes(key)) {
    return true;
  }
  return (
    key.includes('revenue')
    || key.includes('collected')
    || key.includes('remaining')
    || key.includes('amount')
    || key.includes('value')
    || key.includes('sales')
    || key.includes('gap')
    || key.endsWith('_paid')
    || key.includes('overdue')
    || key.includes('financed')
    || key.includes('down_payment')
    || key.includes('monthly')
    || key.includes('total_amount')
  );
}

export function isReportPercentageKey(key) {
  if (!key || typeof key !== 'string') return false;
  return key.includes('percentage') || key.includes('percent') || key.includes('ratio');
}
