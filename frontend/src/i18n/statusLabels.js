/**
 * مفاتيح i18n لحالات الكيانات — استخدم مع t('orders.statusDraft') إلخ
 */

export function orderStatusLabelKey(status) {
  const map = {
    draft: 'orders.statusDraft',
    pending_review: 'orders.statusPending',
    approved: 'orders.statusApproved',
    rejected: 'orders.statusRejected',
    converted_to_contract: 'orders.statusConverted',
    completed: 'orders.statusCompleted',
    cancelled: 'orders.statusCancelled',
  };
  return map[status] || 'common.unknown';
}

export function orderStatusVariant(status) {
  const map = {
    draft: 'gray',
    pending_review: 'yellow',
    approved: 'blue',
    rejected: 'red',
    converted_to_contract: 'purple',
    completed: 'green',
    cancelled: 'gray',
  };
  return map[status] || 'gray';
}

export function contractStatusLabelKey(status) {
  const map = {
    active: 'contracts.statusActive',
    completed: 'contracts.statusCompleted',
    overdue: 'contracts.statusOverdue',
    rescheduled: 'contracts.statusRescheduled',
    cancelled: 'contracts.statusCancelled',
    defaulted: 'contracts.statusDefaulted',
  };
  return map[status] || 'common.unknown';
}

export function contractStatusVariant(status) {
  const map = {
    active: 'green',
    completed: 'blue',
    overdue: 'red',
    rescheduled: 'yellow',
    cancelled: 'gray',
    defaulted: 'red',
  };
  return map[status] || 'gray';
}

export function scheduleStatusLabelKey(status) {
  const map = {
    upcoming: 'collections.scheduleStatus.upcoming',
    due_today: 'collections.scheduleStatus.due_today',
    paid: 'collections.scheduleStatus.paid',
    partial: 'collections.scheduleStatus.partial',
    overdue: 'collections.scheduleStatus.overdue',
    waived: 'collections.scheduleStatus.waived',
  };
  return map[status] || 'common.unknown';
}

export function scheduleStatusVariant(status) {
  const map = {
    upcoming: 'gray',
    due_today: 'yellow',
    paid: 'green',
    partial: 'blue',
    overdue: 'red',
    waived: 'purple',
  };
  return map[status] || 'gray';
}

export function invoiceStatusLabelKey(status) {
  const map = {
    unpaid: 'invoices.statusUnpaid',
    partial: 'invoices.statusPartial',
    paid: 'invoices.statusPaid',
    cancelled: 'invoices.statusCancelled',
  };
  return map[status] || 'common.unknown';
}

export function invoiceStatusVariant(status) {
  const map = {
    unpaid: 'red',
    partial: 'yellow',
    paid: 'green',
    cancelled: 'gray',
  };
  return map[status] || 'gray';
}

/** رسائل تنبيهات لوحة التحكم من الـ API */
export function dashboardAlertMessage(alert, t) {
  if (alert?.type === 'overdue_contracts') {
    return t('dashboard.alertOverdueContracts', { count: alert.count });
  }
  if (alert?.type === 'due_today') {
    return t('dashboard.alertDueToday', { count: alert.count });
  }
  return alert?.message || '';
}
