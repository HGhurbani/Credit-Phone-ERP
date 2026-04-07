import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { invoicesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import PrintChrome from '../../components/print/PrintChrome';

export default function InvoicePrintPage() {
  const { id } = useParams();
  const { t } = useLang();
  const [invoice, setInvoice] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    invoicesApi.get(id)
      .then((r) => setInvoice(r.data.data))
      .catch(() => setError('load'));
  }, [id]);

  if (error) {
    return <div className="p-8 text-center text-gray-600">{t('common.error')}</div>;
  }
  if (!invoice) {
    return <div className="p-8 text-center text-gray-500">{t('common.loading')}</div>;
  }

  const items = invoice.items || [];

  return (
    <PrintChrome
      documentTitle={t('print.documentInvoice')}
      subtitle={invoice.invoice_number}
      fallbackPath={`/invoices/${id}`}
    >
      <div className="flex justify-between gap-4 mb-6 text-[13px]">
        <div>
          <p className="text-[10px] uppercase text-gray-500">{t('customers.name')}</p>
          <p className="font-medium">{invoice.customer?.name || '—'}</p>
          <p className="text-xs text-gray-600">{invoice.customer?.phone || ''}</p>
        </div>
        <div className="text-end">
          <p className="text-xs text-gray-500">{t('invoices.issueDate')}</p>
          <p className="font-medium">{formatDate(invoice.issue_date)}</p>
          {invoice.due_date && (
            <>
              <p className="text-xs text-gray-500 mt-2">{t('invoices.dueDate')}</p>
              <p>{formatDate(invoice.due_date)}</p>
            </>
          )}
        </div>
      </div>

      <p className="text-xs text-gray-500 mb-1">{t('common.branch')}: {invoice.branch?.name || '—'}</p>
      {invoice.contract?.contract_number && (
        <p className="text-xs text-gray-500 mb-4">{t('contracts.contractNumber')}: {invoice.contract.contract_number}</p>
      )}

      <table className="w-full text-[12px] border-collapse border border-gray-200 mb-4">
        <thead>
          <tr className="bg-gray-50">
            <th className="text-start p-2 border-b border-gray-200">{t('products.name')}</th>
            <th className="text-end p-2 border-b border-gray-200">{t('products.quantity')}</th>
            <th className="text-end p-2 border-b border-gray-200">{t('common.amount')}</th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 ? (
            <tr><td colSpan={3} className="p-3 text-center text-gray-400">{t('common.noData')}</td></tr>
          ) : (
            items.map((line) => (
              <tr key={line.id} className="border-b border-gray-100">
                <td className="p-2">{line.description}</td>
                <td className="p-2 text-end">{line.quantity}</td>
                <td className="p-2 text-end">{formatCurrency(line.total)}</td>
              </tr>
            ))
          )}
        </tbody>
      </table>

      <div className="max-w-xs ms-auto [dir=rtl]:ms-0 [dir=rtl]:me-auto space-y-1 text-[13px]">
        <div className="flex justify-between gap-4"><span className="text-gray-500">{t('orders.subtotal')}</span><span>{formatCurrency(invoice.subtotal)}</span></div>
        <div className="flex justify-between gap-4"><span className="text-gray-500">{t('orders.discount')}</span><span>{formatCurrency(invoice.discount_amount)}</span></div>
        <div className="flex justify-between gap-4 font-semibold border-t border-gray-200 pt-2"><span>{t('common.total')}</span><span>{formatCurrency(invoice.total)}</span></div>
        <div className="flex justify-between gap-4"><span className="text-gray-500">{t('contracts.paidAmount')}</span><span>{formatCurrency(invoice.paid_amount)}</span></div>
        <div className="flex justify-between gap-4 font-medium"><span className="text-gray-500">{t('invoices.remainingToPay')}</span><span>{formatCurrency(invoice.remaining_amount)}</span></div>
        <p className="text-xs text-gray-500 pt-1">{t('common.status')}: {invoice.status}</p>
      </div>
    </PrintChrome>
  );
}
