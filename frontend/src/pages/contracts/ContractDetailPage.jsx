import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Printer } from 'lucide-react';
import Badge, { contractStatusBadge, scheduleStatusBadge } from '../../components/ui/Badge';
import { Pagination, useLocalPagination } from '../../components/ui/Table';
import { contractsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';

export default function ContractDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [contract, setContract] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('details');

  useEffect(() => {
    contractsApi.get(id).then(r => setContract(r.data.data))
      .catch(() => { toast.error(t('common.error')); navigate('/contracts'); })
      .finally(() => setLoading(false));
  }, [id, navigate, t]);

  const schedulesPagination = useLocalPagination(contract?.schedules || []);

  if (loading) return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>;
  if (!contract) return null;

  const statusBadge = contractStatusBadge(contract.status);

  const goPrint = () => navigate(`/print/contract/${id}`);

  return (
    <div className="w-full min-w-0 space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="btn-ghost btn btn-sm no-print"><BackIcon size={16} /></button>
          <div>
            <h1 className="page-title">{contract.contract_number}</h1>
            <p className="page-subtitle">{contract.customer?.name} · {formatDate(contract.start_date)}</p>
          </div>
        </div>
        <div className="flex items-center gap-2 no-print">
          <Badge label={t(statusBadge.labelKey)} variant={statusBadge.variant} />
          <button type="button" onClick={goPrint} className="btn-secondary btn btn-sm">
            <Printer size={14} /> {t('contracts.printContract')}
          </button>
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: t('contracts.financedAmount'), value: formatCurrency(contract.financed_amount) },
          { label: t('contracts.downPayment'), value: formatCurrency(contract.down_payment) },
          { label: t('contracts.paidAmount'), value: formatCurrency(contract.paid_amount) },
          { label: t('contracts.remainingAmount'), value: formatCurrency(contract.remaining_amount) },
        ].map(item => (
          <div key={item.label} className="card p-4">
            <p className="text-xs text-gray-500 mb-1">{item.label}</p>
            <p className="text-lg font-bold text-gray-900">{item.value}</p>
          </div>
        ))}
      </div>

      {/* Progress Bar */}
      <div className="card p-4">
        <div className="flex items-center justify-between mb-2">
          <span className="text-sm text-gray-600">{t('contracts.paymentProgress')}</span>
          <span className="text-sm font-medium">
            {Math.round((contract.paid_amount / contract.total_amount) * 100)}%
          </span>
        </div>
        <div className="w-full bg-gray-200 rounded-full h-2.5">
          <div
            className="bg-primary-600 h-2.5 rounded-full transition-all"
            style={{ width: `${Math.min(100, (contract.paid_amount / contract.total_amount) * 100)}%` }}
          />
        </div>
      </div>

      {/* Tabs */}
      <div className="card">
        <div className="flex border-b border-gray-100 px-4">
          {['details', 'schedule', 'payments'].map(tab => (
            <button key={tab}
              onClick={() => setActiveTab(tab)}
              className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors ${activeTab === tab ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
            >
              {tab === 'details' ? t('contracts.details') : tab === 'schedule' ? t('contracts.schedule') : t('contracts.payments')}
            </button>
          ))}
        </div>

        <div className="p-6">
          {activeTab === 'details' && (
            <div className="grid grid-cols-2 gap-4">
              {[
                { label: t('contracts.duration'), value: `${contract.duration_months} ${t('contracts.months')}` },
                { label: t('contracts.monthlyAmount'), value: formatCurrency(contract.monthly_amount) },
                { label: t('contracts.totalAmount'), value: formatCurrency(contract.total_amount) },
                { label: t('contracts.startDate'), value: formatDate(contract.start_date) },
                { label: t('contracts.firstDueDate'), value: formatDate(contract.first_due_date) },
                { label: t('contracts.endDate'), value: formatDate(contract.end_date) },
                { label: t('contracts.linkedOrder'), value: contract.order?.order_number || '—' },
                { label: t('common.branch'), value: contract.branch?.name || '—' },
              ].map(item => (
                <div key={item.label}>
                  <p className="text-xs text-gray-500 mb-0.5">{item.label}</p>
                  <p className="text-sm font-medium text-gray-900">{item.value}</p>
                </div>
              ))}
            </div>
          )}

          {activeTab === 'schedule' && (
            <>
              <div className="overflow-x-auto">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>{t('collections.dueDate')}</th>
                      <th>{t('common.amount')}</th>
                      <th>{t('collections.paidAmount')}</th>
                      <th>{t('contracts.remainingAmount')}</th>
                      <th>{t('common.status')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {schedulesPagination.rows.map(s => {
                      const sb = scheduleStatusBadge(s.status);
                      return (
                        <tr key={s.id}>
                          <td>{s.installment_number}</td>
                          <td>{formatDate(s.due_date)}</td>
                          <td>{formatCurrency(s.amount)}</td>
                          <td>{formatCurrency(s.paid_amount)}</td>
                          <td>{formatCurrency(s.remaining_amount)}</td>
                          <td><Badge label={t(sb.labelKey)} variant={sb.variant} /></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <Pagination
                total={schedulesPagination.total}
                currentPage={schedulesPagination.page}
                lastPage={schedulesPagination.lastPage}
                perPage={schedulesPagination.perPage}
                pageSize={schedulesPagination.pageSize}
                onPageChange={schedulesPagination.setPage}
                onPageSizeChange={(value) => { schedulesPagination.setPageSize(value); schedulesPagination.setPage(1); }}
              />
            </>
          )}

          {activeTab === 'payments' && (
            <div className="space-y-2">
              {contract.payments?.length === 0 ? (
                <p className="text-gray-400 text-sm text-center py-8">{t('common.noData')}</p>
              ) : (
                contract.payments?.map(p => (
                  <div key={p.id} className="flex items-center justify-between gap-2 p-3 bg-gray-50 rounded-lg">
                    <div>
                      <p className="text-sm font-medium">{p.receipt_number}</p>
                      <p className="text-xs text-gray-400">{formatDate(p.payment_date)} · {p.payment_method} · {p.collected_by}</p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className="font-semibold text-green-600">{formatCurrency(p.amount)}</span>
                      <button
                        type="button"
                        onClick={() => navigate(`/print/payment/${p.id}`)}
                        className="btn-ghost btn btn-xs text-primary-600"
                      >
                        <Printer size={12} /> {t('print.receiptShort')}
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
