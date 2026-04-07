import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { contractsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import PrintChrome from '../../components/print/PrintChrome';

export default function ContractPrintPage() {
  const { id } = useParams();
  const { t } = useLang();
  const [contract, setContract] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    contractsApi.get(id)
      .then((r) => setContract(r.data.data))
      .catch(() => setError('load'));
  }, [id]);

  if (error) {
    return (
      <div className="p-8 text-center text-gray-600">
        <p>{t('common.error')}</p>
      </div>
    );
  }

  if (!contract) {
    return <div className="p-8 text-center text-gray-500">{t('common.loading')}</div>;
  }

  const rows = contract.schedules || [];

  return (
    <PrintChrome
      documentTitle={t('print.documentContract')}
      subtitle={contract.contract_number}
      fallbackPath={`/contracts/${id}`}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6 text-[13px]">
        <div className="border border-gray-100 rounded p-3">
          <p className="text-[10px] uppercase text-gray-500 mb-1">{t('customers.name')}</p>
          <p className="font-medium">{contract.customer?.name || '—'}</p>
          <p className="text-gray-600 text-xs mt-0.5">{contract.customer?.phone || ''}</p>
        </div>
        <div className="border border-gray-100 rounded p-3">
          <p className="text-[10px] uppercase text-gray-500 mb-1">{t('common.branch')}</p>
          <p className="font-medium">{contract.branch?.name || '—'}</p>
          <p className="text-xs text-gray-500 mt-1">{t('common.status')}: {contract.status}</p>
        </div>
      </div>

      <dl className="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2 mb-6 text-[13px]">
        <div><dt className="text-gray-500 text-xs">{t('contracts.financedAmount')}</dt><dd className="font-medium">{formatCurrency(contract.financed_amount)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.downPayment')}</dt><dd className="font-medium">{formatCurrency(contract.down_payment)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.monthlyAmount')}</dt><dd className="font-medium">{formatCurrency(contract.monthly_amount)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.totalAmount')}</dt><dd className="font-medium">{formatCurrency(contract.total_amount)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.paidAmount')}</dt><dd className="font-medium">{formatCurrency(contract.paid_amount)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.remainingAmount')}</dt><dd className="font-medium">{formatCurrency(contract.remaining_amount)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.duration')}</dt><dd>{contract.duration_months} {t('contracts.months')}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.startDate')}</dt><dd>{formatDate(contract.start_date)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('contracts.endDate')}</dt><dd>{formatDate(contract.end_date)}</dd></div>
      </dl>

      <h2 className="text-xs font-semibold text-gray-800 uppercase mb-2">{t('contracts.schedule')}</h2>
      <div className="overflow-x-auto border border-gray-200 rounded">
        <table className="w-full text-[12px] print:text-[11px] border-collapse">
          <thead>
            <tr className="bg-gray-50 border-b border-gray-200">
              <th className="text-start p-2 font-medium">#</th>
              <th className="text-start p-2 font-medium">{t('collections.dueDate')}</th>
              <th className="text-end p-2 font-medium">{t('common.amount')}</th>
              <th className="text-end p-2 font-medium">{t('collections.paidAmount')}</th>
              <th className="text-end p-2 font-medium">{t('contracts.remainingAmount')}</th>
              <th className="text-start p-2 font-medium">{t('common.status')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan={6} className="p-4 text-center text-gray-400">{t('common.noData')}</td></tr>
            ) : (
              rows.map((s) => (
                <tr key={s.id} className="border-b border-gray-100">
                  <td className="p-2">{s.installment_number}</td>
                  <td className="p-2">{formatDate(s.due_date)}</td>
                  <td className="p-2 text-end">{formatCurrency(s.amount)}</td>
                  <td className="p-2 text-end">{formatCurrency(s.paid_amount)}</td>
                  <td className="p-2 text-end">{formatCurrency(s.remaining_amount)}</td>
                  <td className="p-2">{s.status}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="mt-10 grid grid-cols-2 gap-8 print:mt-12">
        <div className="border-t border-gray-300 pt-2 min-h-[72px]">
          <p className="text-[10px] text-gray-500">{t('print.signatureCustomer')}</p>
        </div>
        <div className="border-t border-gray-300 pt-2 min-h-[72px]">
          <p className="text-[10px] text-gray-500">{t('print.signatureCompany')}</p>
        </div>
      </div>
    </PrintChrome>
  );
}
