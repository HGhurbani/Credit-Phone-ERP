import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { cashTransactionsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import PrintChrome from '../../components/print/PrintChrome';

export default function VoucherPrintPage() {
  const { id } = useParams();
  const { t } = useLang();
  const [row, setRow] = useState(null);

  useEffect(() => {
    cashTransactionsApi.get(id).then((r) => {
      setRow(r.data.data);
      setTimeout(() => window.print(), 400);
    }).catch(() => {});
  }, [id]);

  if (!row) {
    return <div className="p-8 text-center text-gray-500">{t('common.loading')}</div>;
  }

  const isReceipt = row.direction === 'in';

  return (
    <PrintChrome
      documentTitle={isReceipt ? t('cash.voucherReceipt') : t('cash.voucherPayment')}
      subtitle={row.voucher_number}
      fallbackPath="/cash/transactions"
      hideFooter
    >
      <dl className="space-y-3 text-sm max-w-lg mx-auto">
        <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('common.date')}</dt><dd>{formatDate(row.transaction_date)}</dd></div>
        <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('common.branch')}</dt><dd>{row.branch?.name}</dd></div>
        <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('cash.cashbox')}</dt><dd>{row.cashbox?.name}</dd></div>
        <div className="flex justify-between gap-4"><dt className="text-gray-500">{t('cash.txType')}</dt><dd>{row.transaction_type}</dd></div>
        <div className="flex justify-between text-lg font-semibold pt-2 border-t border-gray-100">
          <dt className="text-gray-700">{t('common.amount')}</dt>
          <dd className={isReceipt ? 'text-green-700' : 'text-red-700'}>{formatCurrency(row.amount)}</dd>
        </div>
        {row.notes && (
          <div className="pt-2"><dt className="text-gray-500 mb-1">{t('common.notes')}</dt><dd className="text-gray-800">{row.notes}</dd></div>
        )}
        {row.created_by && (
          <div className="flex justify-between text-xs text-gray-400 pt-4">
            <span>{t('common.by')}: {row.created_by.name}</span>
          </div>
        )}
      </dl>
    </PrintChrome>
  );
}
