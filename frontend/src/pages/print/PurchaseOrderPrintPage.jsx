import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { purchaseOrdersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate, formatDateTime } from '../../utils/format';
import PrintChrome from '../../components/print/PrintChrome';

export default function PurchaseOrderPrintPage() {
  const { id } = useParams();
  const { t } = useLang();
  const [po, setPo] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    purchaseOrdersApi.get(id)
      .then((r) => setPo(r.data.data))
      .catch(() => setError('load'));
  }, [id]);

  if (error) {
    return <div className="p-8 text-center text-gray-600">{t('common.error')}</div>;
  }
  if (!po) {
    return <div className="p-8 text-center text-gray-500">{t('common.loading')}</div>;
  }

  const items = po.items || [];
  const receipts = po.goods_receipts || [];

  return (
    <PrintChrome
      documentTitle={t('print.documentPurchaseOrder')}
      subtitle={po.purchase_number}
      fallbackPath={`/purchases/${id}`}
    >
      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4 text-[13px]">
        <div><dt className="text-gray-500 text-xs">{t('purchases.supplier')}</dt><dd className="font-medium">{po.supplier?.name || '—'}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('common.branch')}</dt><dd>{po.branch?.name || '—'}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('purchases.orderDate')}</dt><dd>{formatDate(po.order_date)}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('purchases.expectedDate')}</dt><dd>{po.expected_date ? formatDate(po.expected_date) : '—'}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('common.status')}</dt><dd>{po.status}</dd></div>
        <div><dt className="text-gray-500 text-xs">{t('common.total')}</dt><dd className="font-semibold">{formatCurrency(po.total)}</dd></div>
      </dl>
      {po.notes && <p className="text-xs text-gray-600 mb-4 border border-gray-100 rounded p-2">{po.notes}</p>}

      <h2 className="text-xs font-semibold uppercase text-gray-800 mb-2">{t('purchases.lines')}</h2>
      <table className="w-full text-[11px] border border-gray-200 mb-6">
        <thead>
          <tr className="bg-gray-50">
            <th className="text-start p-1.5 border-b">{t('products.name')}</th>
            <th className="text-end p-1.5 border-b">{t('products.quantity')}</th>
            <th className="text-end p-1.5 border-b">{t('purchases.received')}</th>
            <th className="text-end p-1.5 border-b">{t('purchases.unitCost')}</th>
            <th className="text-end p-1.5 border-b">{t('common.total')}</th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 ? (
            <tr><td colSpan={5} className="p-2 text-center text-gray-400">{t('common.noData')}</td></tr>
          ) : (
            items.map((line) => (
              <tr key={line.id} className="border-t border-gray-100">
                <td className="p-1.5">{line.product?.name || '—'}</td>
                <td className="p-1.5 text-end">{line.quantity}</td>
                <td className="p-1.5 text-end">{line.quantity_received ?? 0}</td>
                <td className="p-1.5 text-end">{formatCurrency(line.unit_cost)}</td>
                <td className="p-1.5 text-end">{formatCurrency(line.total)}</td>
              </tr>
            ))
          )}
        </tbody>
      </table>

      <h2 className="text-xs font-semibold uppercase text-gray-800 mb-2">{t('print.goodsReceiptsSection')}</h2>
      {receipts.length === 0 ? (
        <p className="text-xs text-gray-400 mb-4">{t('common.noData')}</p>
      ) : (
        <div className="space-y-4">
          {receipts.map((gr) => (
            <div key={gr.id} className="border border-gray-200 rounded p-3 text-[11px]">
              <div className="flex flex-wrap justify-between gap-2 mb-2">
                <span className="font-mono font-medium">{gr.receipt_number}</span>
                <span className="text-gray-500">{gr.received_at ? formatDateTime(gr.received_at) : ''}</span>
              </div>
              <p className="text-xs text-gray-500 mb-1">{gr.branch?.name} · {gr.received_by?.name}</p>
              {gr.items?.length > 0 && (
                <table className="w-full mt-2">
                  <thead>
                    <tr className="text-gray-500">
                      <th className="text-start py-1">{t('products.name')}</th>
                      <th className="text-end py-1">{t('purchases.receiveQty')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {gr.items.map((it) => (
                      <tr key={it.id}>
                        <td className="py-0.5">{it.purchase_order_item?.product?.name || it.purchase_order_item?.product?.name_ar || '—'}</td>
                        <td className="text-end py-0.5">{it.quantity}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {gr.notes && <p className="text-xs text-gray-600 mt-2">{gr.notes}</p>}
            </div>
          ))}
        </div>
      )}
    </PrintChrome>
  );
}
