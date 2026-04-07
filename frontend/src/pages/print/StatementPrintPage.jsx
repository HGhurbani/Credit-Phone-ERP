import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { customersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import PrintChrome from '../../components/print/PrintChrome';

export default function StatementPrintPage() {
  const { id } = useParams();
  const { t } = useLang();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    customersApi.statement(id)
      .then((r) => setData(r.data.data))
      .catch(() => setError('load'));
  }, [id]);

  if (error) {
    return <div className="p-8 text-center text-gray-600">{t('common.error')}</div>;
  }
  if (!data) {
    return <div className="p-8 text-center text-gray-500">{t('common.loading')}</div>;
  }

  const c = data.customer || {};
  const sum = data.summary || {};

  return (
    <PrintChrome
      documentTitle={t('print.documentStatement')}
      subtitle={c.name}
      fallbackPath={`/customers/${id}`}
    >
      <p className="text-xs text-gray-500 mb-4">
        {t('print.statementDate')}: {data.generated_at ? formatDate(data.generated_at) : formatDate(new Date().toISOString())}
      </p>

      <div className="border border-gray-100 rounded p-3 mb-4 text-[13px]">
        <p className="font-medium">{c.name}</p>
        <p className="text-gray-600 text-xs">{c.phone}{c.email ? ` · ${c.email}` : ''}</p>
        {c.branch?.name && <p className="text-xs text-gray-500 mt-1">{t('common.branch')}: {c.branch.name}</p>}
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-6 text-[12px]">
        <div className="bg-gray-50 border border-gray-100 rounded p-2">
          <p className="text-[10px] text-gray-500">{t('customers.totalOutstanding')}</p>
          <p className="font-semibold">{formatCurrency(sum.total_outstanding)}</p>
        </div>
        <div className="bg-gray-50 border border-gray-100 rounded p-2">
          <p className="text-[10px] text-gray-500">{t('customers.installmentsOutstanding')}</p>
          <p className="font-semibold">{formatCurrency(sum.installments_outstanding)}</p>
        </div>
        <div className="bg-gray-50 border border-gray-100 rounded p-2">
          <p className="text-[10px] text-gray-500">{t('customers.invoiceBalance')}</p>
          <p className="font-semibold">{formatCurrency(sum.invoice_balance)}</p>
        </div>
        <div className="bg-gray-50 border border-gray-100 rounded p-2">
          <p className="text-[10px] text-gray-500">{t('customers.totalPaid')}</p>
          <p className="font-semibold">{formatCurrency(sum.total_paid)}</p>
        </div>
      </div>

      <h3 className="text-xs font-semibold uppercase text-gray-700 mb-1">{t('customers.activeContracts')}</h3>
      <table className="w-full text-[11px] border border-gray-200 mb-4">
        <thead><tr className="bg-gray-50"><th className="text-start p-1.5">{t('contracts.contractNumber')}</th><th className="text-end p-1.5">{t('contracts.remainingAmount')}</th><th className="text-start p-1.5">{t('common.status')}</th></tr></thead>
        <tbody>
          {(data.active_contracts || []).length === 0 ? (
            <tr><td colSpan={3} className="p-2 text-center text-gray-400">{t('common.noData')}</td></tr>
          ) : (
            (data.active_contracts || []).map((row) => (
              <tr key={row.id} className="border-t border-gray-100">
                <td className="p-1.5 font-mono">{row.contract_number}</td>
                <td className="p-1.5 text-end">{formatCurrency(row.remaining_amount)}</td>
                <td className="p-1.5">{row.status}</td>
              </tr>
            ))
          )}
        </tbody>
      </table>

      <h3 className="text-xs font-semibold uppercase text-gray-700 mb-1">{t('customers.overdueInstallments')}</h3>
      <table className="w-full text-[11px] border border-gray-200 mb-4">
        <thead><tr className="bg-red-50"><th className="text-start p-1.5">{t('contracts.contractNumber')}</th><th className="text-start p-1.5">{t('collections.dueDate')}</th><th className="text-end p-1.5">{t('contracts.remainingAmount')}</th></tr></thead>
        <tbody>
          {(data.overdue_installments || []).length === 0 ? (
            <tr><td colSpan={3} className="p-2 text-center text-gray-400">{t('common.noData')}</td></tr>
          ) : (
            (data.overdue_installments || []).map((row) => (
              <tr key={row.id} className="border-t border-gray-100">
                <td className="p-1.5 font-mono">{row.contract_number}</td>
                <td className="p-1.5">{formatDate(row.due_date)}</td>
                <td className="p-1.5 text-end font-medium text-red-800">{formatCurrency(row.remaining_amount)}</td>
              </tr>
            ))
          )}
        </tbody>
      </table>

      <h3 className="text-xs font-semibold uppercase text-gray-700 mb-1">{t('customers.latestPayments')}</h3>
      <ul className="text-[11px] space-y-1 mb-4">
        {(data.latest_payments || []).length === 0 ? (
          <li className="text-gray-400">{t('common.noData')}</li>
        ) : (
          (data.latest_payments || []).map((p) => (
            <li key={p.id} className="flex justify-between gap-2 border-b border-gray-50 pb-1">
              <span>{formatDate(p.payment_date)} · {p.payment_method}</span>
              <span>{formatCurrency(p.amount)}</span>
            </li>
          ))
        )}
      </ul>
    </PrintChrome>
  );
}
