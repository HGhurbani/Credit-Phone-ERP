/**
 * i18n keys for cash transaction types.
 * Use with: t(cashTxTypeLabelKey(type))
 */
export function cashTxTypeLabelKey(type) {
  const map = {
    customer_payment_in: 'cash.txTypes.customer_payment_in',
    expense_out: 'cash.txTypes.expense_out',
    manual_adjustment: 'cash.txTypes.manual_adjustment',
    purchase_payment_out: 'cash.txTypes.purchase_payment_out',
    other_in: 'cash.txTypes.other_in',
    other_out: 'cash.txTypes.other_out',
  };
  return map[type] || 'common.unknown';
}

