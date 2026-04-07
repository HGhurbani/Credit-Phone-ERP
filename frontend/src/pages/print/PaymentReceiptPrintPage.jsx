import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { paymentsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import PrintChrome from '../../components/print/PrintChrome';

export default function PaymentReceiptPrintPage() {
  const { id } = useParams();
  const { t } = useLang();
  const [payment, setPayment] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    paymentsApi.get(id)
      .then((r) => setPayment(r.data.data))
      .catch(() => setError('load'));
  }, [id]);

  if (error) {
    return <div className="p-8 text-center text-gray-600">{t('common.error')}</div>;
  }
  if (!payment) {
    return <div className="p-8 text-center text-gray-500">{t('common.loading')}</div>;
  }

  const fallback = payment.contract?.id ? `/contracts/${payment.contract.id}` : '/collections';

  return (
    <PrintChrome
      documentTitle={t('print.documentReceipt')}
      subtitle={payment.receipt_number || `#${payment.id}`}
      fallbackPath={fallback}
      hideFooter
    >
      <div className="space-y-4 text-[13px] max-w-md mx-auto">
        <div className="text-center border-b border-gray-200 pb-4">
          <p className="text-2xl font-bold text-gray-900 tracking-tight">{formatCurrency(payment.amount)}</p>
          <p className="text-xs text-gray-500 mt-1">{formatDate(payment.payment_date)}</p>
        </div>
        <dl className="space-y-2">
          <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('customers.name')}</dt><dd className="font-medium text-end">{payment.customer?.name || '—'}</dd></div>
          <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('collections.paymentMethod')}</dt><dd className="text-end">{payment.payment_method}</dd></div>
          {payment.reference_number && (
            <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('collections.referenceNumber')}</dt><dd className="text-end font-mono text-xs">{payment.reference_number}</dd></div>
          )}
          {payment.contract?.contract_number && (
            <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('contracts.contractNumber')}</dt><dd className="text-end font-mono">{payment.contract.contract_number}</dd></div>
          )}
          {payment.schedule && (
            <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('collections.installmentNumber')}</dt><dd className="text-end">#{payment.schedule.installment_number}</dd></div>
          )}
          {payment.invoice?.invoice_number && (
            <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('invoices.invoiceNumber')}</dt><dd className="text-end font-mono">{payment.invoice.invoice_number}</dd></div>
          )}
          <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('common.branch')}</dt><dd className="text-end">{payment.branch?.name || '—'}</dd></div>
          {payment.collected_by?.name && (
            <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('print.collectedBy')}</dt><dd className="text-end">{payment.collected_by.name}</dd></div>
          )}
          {payment.collector_notes && (
            <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('collections.collectorNotes')}</dt><dd className="text-end text-xs max-w-[60%]">{payment.collector_notes}</dd></div>
          )}
        </dl>
      </div>
    </PrintChrome>
  );
}
