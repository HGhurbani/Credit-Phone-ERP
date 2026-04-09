import { useEffect, useState, useMemo, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, FileText, ShoppingCart, CreditCard, MessageSquare, Plus,
  ScrollText, History, Printer, Pencil,
} from 'lucide-react';
import Badge from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { Pagination, useLocalPagination } from '../../components/ui/Table';
import { customersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
import { clsx } from 'clsx';

const OUTCOMES = ['contacted', 'no_answer', 'promise_to_pay', 'wrong_number', 'reschedule_requested', 'visited'];
const PRIORITIES = ['low', 'normal', 'high'];

export default function CustomerDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const showStatement = hasPermission('customer_statement.view');
  const showFollowUp = hasPermission('collections.followup.view');
  const canCreateFollow = hasPermission('collections.followup.create');
  const canUpdateCustomer = hasPermission('customers.update');

  const [customer, setCustomer] = useState(null);
  const [loading, setLoading] = useState(true);
  const [note, setNote] = useState('');
  const [addingNote, setAddingNote] = useState(false);
  const [activeTab, setActiveTab] = useState('info');

  const [stmt, setStmt] = useState(null);
  const [stmtLoading, setStmtLoading] = useState(false);
  const [fuList, setFuList] = useState([]);
  const [fuLoading, setFuLoading] = useState(false);
  const [fuMeta, setFuMeta] = useState(null);

  const [followModal, setFollowModal] = useState(false);
  const [promiseModal, setPromiseModal] = useState(false);
  const [rescheduleModal, setRescheduleModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const [followForm, setFollowForm] = useState({
    outcome: 'contacted', contract_id: '', priority: 'normal', next_follow_up_date: '', note: '',
  });
  const [promiseForm, setPromiseForm] = useState({
    contract_id: '', promised_amount: '', promised_date: '', note: '',
  });
  const [rescheduleForm, setRescheduleForm] = useState({ contract_id: '', note: '' });

  const loadCustomer = useCallback(() => {
    return customersApi.get(id).then(res => {
      setCustomer(res.data.data);
      return res.data.data;
    });
  }, [id]);

  useEffect(() => {
    setLoading(true);
    setStmt(null);
    setFuList([]);
    loadCustomer().catch(() => {
      toast.error(t('common.error'));
      navigate('/customers');
    }).finally(() => setLoading(false));
  }, [id, navigate, t, loadCustomer]);

  useEffect(() => {
    if (activeTab !== 'statement' || !showStatement) return;
    setStmtLoading(true);
    customersApi.statement(id)
      .then(res => setStmt(res.data.data))
      .catch(() => toast.error(t('common.error')))
      .finally(() => setStmtLoading(false));
  }, [activeTab, id, showStatement, t]);

  const refreshFollowUps = useCallback(() => {
    if (!showFollowUp) return;
    setFuLoading(true);
    customersApi.followUps(id, { per_page: 50 })
      .then(res => {
        setFuList(res.data.data || []);
        setFuMeta(res.data.meta);
      })
      .catch(() => toast.error(t('common.error')))
      .finally(() => setFuLoading(false));
  }, [id, showFollowUp, t]);

  useEffect(() => {
    if (activeTab !== 'followUp' || !showFollowUp) return;
    refreshFollowUps();
  }, [activeTab, showFollowUp, refreshFollowUps]);

  const contractOptions = useMemo(() => stmt?.active_contracts || [], [stmt]);

  const ensureStmtForModals = useCallback(() => {
    if (stmt !== null) {
      return Promise.resolve();
    }
    return customersApi.statement(id).then(res => setStmt(res.data.data));
  }, [stmt, id]);

  const handleAddNote = async (e) => {
    e.preventDefault();
    if (!note.trim()) return;
    setAddingNote(true);
    try {
      await customersApi.addNote(id, note);
      toast.success(t('common.success'));
      setNote('');
      await loadCustomer();
    } catch {
      toast.error(t('common.error'));
    } finally {
      setAddingNote(false);
    }
  };

  const goPrintStatement = () => navigate(`/print/statement/${id}`);

  const submitFollowUp = async (e) => {
    e.preventDefault();
    if (!canCreateFollow) return;
    setSubmitting(true);
    try {
      const payload = {
        outcome: followForm.outcome,
        priority: followForm.priority,
        note: followForm.note || undefined,
        next_follow_up_date: followForm.next_follow_up_date || undefined,
        contract_id: followForm.contract_id ? Number(followForm.contract_id) : undefined,
      };
      await customersApi.addFollowUp(id, payload);
      toast.success(t('common.success'));
      setFollowModal(false);
      setFollowForm({ outcome: 'contacted', contract_id: '', priority: 'normal', next_follow_up_date: '', note: '' });
      refreshFollowUps();
      if (activeTab === 'statement') {
        setStmtLoading(true);
        customersApi.statement(id).then(res => setStmt(res.data.data)).finally(() => setStmtLoading(false));
      }
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSubmitting(false);
    }
  };

  const submitPromise = async (e) => {
    e.preventDefault();
    if (!canCreateFollow) return;
    setSubmitting(true);
    try {
      await customersApi.addPromiseToPay(id, {
        promised_amount: promiseForm.promised_amount,
        promised_date: promiseForm.promised_date,
        note: promiseForm.note || undefined,
        contract_id: promiseForm.contract_id ? Number(promiseForm.contract_id) : undefined,
      });
      toast.success(t('common.success'));
      setPromiseModal(false);
      setPromiseForm({ contract_id: '', promised_amount: '', promised_date: '', note: '' });
      refreshFollowUps();
      if (activeTab === 'statement') {
        setStmtLoading(true);
        customersApi.statement(id).then(res => setStmt(res.data.data)).finally(() => setStmtLoading(false));
      }
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSubmitting(false);
    }
  };

  const submitReschedule = async (e) => {
    e.preventDefault();
    if (!canCreateFollow) return;
    if (!rescheduleForm.contract_id) {
      toast.error(t('common.required'));
      return;
    }
    setSubmitting(true);
    try {
      await customersApi.addRescheduleRequest(id, {
        contract_id: Number(rescheduleForm.contract_id),
        note: rescheduleForm.note || undefined,
      });
      toast.success(t('common.success'));
      setRescheduleModal(false);
      setRescheduleForm({ contract_id: '', note: '' });
      if (activeTab === 'statement') {
        setStmtLoading(true);
        customersApi.statement(id).then(res => setStmt(res.data.data)).finally(() => setStmtLoading(false));
      }
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSubmitting(false);
    }
  };

  const creditVariant = { excellent: 'green', good: 'blue', fair: 'yellow', poor: 'red' };
  const activeContractsPagination = useLocalPagination(stmt?.active_contracts || []);
  const overdueInstallmentsPagination = useLocalPagination(stmt?.overdue_installments || []);

  if (loading) {
    return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>;
  }

  if (!customer) return null;

  const creditScoreKey = customer.credit_score || 'fair';

  const tabs = [
    ...(showStatement ? [{ key: 'statement', label: t('customers.statementTab'), icon: ScrollText }] : []),
    ...(showFollowUp ? [{ key: 'followUp', label: t('customers.followUpTab'), icon: History }] : []),
    { key: 'info', label: t('customers.details'), icon: FileText },
    { key: 'orders', label: t('customers.orderHistory'), icon: ShoppingCart },
    { key: 'contracts', label: t('customers.contractHistory'), icon: CreditCard },
    { key: 'notes', label: t('common.notes'), icon: MessageSquare },
  ];

  return (
    <div className="space-y-4">
      <div className="print:hidden">
        <div className="page-header">
          <div className="flex items-center gap-3">
            <button type="button" onClick={() => navigate(-1)} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
            <div>
              <h1 className="page-title">{customer.name}</h1>
              <p className="page-subtitle">{customer.phone}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Badge label={customer.is_active ? t('common.active') : t('common.inactive')} variant={customer.is_active ? 'green' : 'gray'} />
            <Badge label={t(`customers.credit${creditScoreKey.charAt(0).toUpperCase() + creditScoreKey.slice(1)}`)} variant={creditVariant[creditScoreKey] || 'gray'} />
            {canUpdateCustomer && (
              <button type="button" onClick={() => navigate(`/customers/${customer.id}/edit`)} className="btn-secondary btn btn-sm">
                <Pencil size={14} />
                {t('common.edit')}
              </button>
            )}
            <button type="button" onClick={() => navigate(`/orders/new?customer_id=${customer.id}`)} className="btn-primary btn btn-sm">
              <Plus size={14} />
              {t('orders.add')}
            </button>
          </div>
        </div>

        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {[
            { label: t('customers.nationalId'), value: customer.national_id || '—' },
            { label: t('customers.employer'), value: customer.employer_name || '—' },
            { label: t('customers.salary'), value: customer.monthly_salary ? formatCurrency(customer.monthly_salary) : '—' },
            { label: t('common.city'), value: customer.city || '—' },
          ].map(item => (
            <div key={item.label} className="card p-4">
              <p className="text-xs text-gray-500 mb-1">{item.label}</p>
              <p className="text-sm font-medium text-gray-900">{item.value}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="card">
        <div className="flex border-b border-gray-100 px-4 print:hidden flex-wrap">
          {tabs.map(tab => (
            <button
              key={tab.key}
              type="button"
              onClick={() => setActiveTab(tab.key)}
              className={clsx(
                'flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors',
                activeTab === tab.key
                  ? 'border-primary-600 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700',
              )}
            >
              <tab.icon size={15} />
              {tab.label}
            </button>
          ))}
        </div>

        <div className="p-6">
          {activeTab === 'statement' && showStatement && (
            <div className="space-y-6" id="customer-statement-print">
              <div className="flex justify-between items-start gap-4 print:hidden">
                <h2 className="text-lg font-semibold text-gray-900">{t('customers.statementTitle')}</h2>
                <button type="button" onClick={goPrintStatement} className="btn-secondary btn btn-sm">
                  <Printer size={14} /> {t('customers.printStatement')}
                </button>
              </div>
              <div className="hidden print:block mb-4">
                <h1 className="text-xl font-bold">{customer.name}</h1>
                <p className="text-sm text-gray-600">{customer.phone}{customer.email ? ` · ${customer.email}` : ''}</p>
                <p className="text-xs text-gray-500 mt-1">{stmt?.generated_at && formatDate(stmt.generated_at)}</p>
              </div>

              {stmtLoading && <p className="text-sm text-gray-500">{t('common.loading')}</p>}

              {!stmtLoading && stmt && (
                <>
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    {[
                      { label: t('customers.totalOutstanding'), value: formatCurrency(stmt.summary.total_outstanding), emphasize: true },
                      { label: t('customers.installmentsOutstanding'), value: formatCurrency(stmt.summary.installments_outstanding) },
                      { label: t('customers.invoiceBalance'), value: formatCurrency(stmt.summary.invoice_balance) },
                      { label: t('customers.totalPaid'), value: formatCurrency(stmt.summary.total_paid) },
                    ].map(row => (
                      <div key={row.label} className={clsx('rounded-lg p-3 border border-gray-100', row.emphasize && 'bg-amber-50 border-amber-100')}>
                        <p className="text-xs text-gray-500 mb-0.5">{row.label}</p>
                        <p className={clsx('text-sm font-semibold', row.emphasize ? 'text-amber-900' : 'text-gray-900')}>{row.value}</p>
                      </div>
                    ))}
                  </div>

                  <div>
                    <h3 className="text-sm font-semibold text-gray-800 mb-2">{t('customers.activeContracts')}</h3>
                    {stmt.active_contracts?.length === 0 ? (
                      <p className="text-sm text-gray-400">{t('common.noData')}</p>
                    ) : (
                      <>
                        <div className="overflow-x-auto border border-gray-100 rounded-lg">
                          <table className="min-w-full text-sm">
                            <thead className="bg-gray-50">
                              <tr>
                                <th className="text-start p-2">{t('contracts.contractNumber')}</th>
                                <th className="text-start p-2">{t('common.status')}</th>
                                <th className="text-end p-2">{t('contracts.remainingAmount')}</th>
                                <th className="text-end p-2">{t('contracts.paidAmount')}</th>
                              </tr>
                            </thead>
                            <tbody>
                              {activeContractsPagination.rows.map(c => (
                                <tr key={c.id} className="border-t border-gray-100">
                                  <td className="p-2 font-mono">
                                    <Link to={`/contracts/${c.id}`} className="text-primary-600 hover:underline">{c.contract_number}</Link>
                                  </td>
                                  <td className="p-2">{c.status}</td>
                                  <td className="p-2 text-end">{formatCurrency(c.remaining_amount)}</td>
                                  <td className="p-2 text-end">{formatCurrency(c.paid_amount)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                        <Pagination
                          total={activeContractsPagination.total}
                          currentPage={activeContractsPagination.page}
                          lastPage={activeContractsPagination.lastPage}
                          perPage={activeContractsPagination.perPage}
                          pageSize={activeContractsPagination.pageSize}
                          onPageChange={activeContractsPagination.setPage}
                          onPageSizeChange={(value) => { activeContractsPagination.setPageSize(value); activeContractsPagination.setPage(1); }}
                        />
                      </>
                    )}
                  </div>

                  <div>
                    <h3 className="text-sm font-semibold text-red-800 mb-2">{t('customers.overdueInstallments')}</h3>
                    {stmt.overdue_installments?.length === 0 ? (
                      <p className="text-sm text-gray-400">{t('common.noData')}</p>
                    ) : (
                      <>
                        <div className="overflow-x-auto border border-red-100 rounded-lg bg-red-50/30">
                          <table className="min-w-full text-sm">
                            <thead className="bg-red-50">
                              <tr>
                                <th className="text-start p-2">{t('contracts.contractNumber')}</th>
                                <th className="text-start p-2">{t('collections.installmentNumber')}</th>
                                <th className="text-start p-2">{t('collections.dueDate')}</th>
                                <th className="text-end p-2">{t('contracts.remainingAmount')}</th>
                              </tr>
                            </thead>
                            <tbody>
                              {overdueInstallmentsPagination.rows.map(s => (
                                <tr key={s.id} className="border-t border-red-100">
                                  <td className="p-2 font-mono">{s.contract_number}</td>
                                  <td className="p-2">#{s.installment_number}</td>
                                  <td className="p-2">{formatDate(s.due_date)}</td>
                                  <td className="p-2 text-end font-medium text-red-700">{formatCurrency(s.remaining_amount)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                        <Pagination
                          total={overdueInstallmentsPagination.total}
                          currentPage={overdueInstallmentsPagination.page}
                          lastPage={overdueInstallmentsPagination.lastPage}
                          perPage={overdueInstallmentsPagination.perPage}
                          pageSize={overdueInstallmentsPagination.pageSize}
                          onPageChange={overdueInstallmentsPagination.setPage}
                          onPageSizeChange={(value) => { overdueInstallmentsPagination.setPageSize(value); overdueInstallmentsPagination.setPage(1); }}
                        />
                      </>
                    )}
                  </div>

                  <div>
                    <h3 className="text-sm font-semibold text-gray-800 mb-2">{t('customers.openInvoicesLabel')}</h3>
                    {stmt.open_invoices?.length === 0 ? (
                      <p className="text-sm text-gray-400">{t('common.noData')}</p>
                    ) : (
                      <ul className="space-y-1 text-sm">
                        {stmt.open_invoices.map(inv => (
                          <li key={inv.id} className="flex justify-between gap-2">
                            <Link to={`/invoices/${inv.id}`} className="text-primary-600 hover:underline font-mono">{inv.invoice_number}</Link>
                            <span>{formatCurrency(inv.remaining_amount)}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>

                  <div className="grid md:grid-cols-2 gap-6">
                    <div>
                      <h3 className="text-sm font-semibold text-gray-800 mb-2">{t('customers.latestPayments')}</h3>
                      {stmt.latest_payments?.length === 0 ? (
                        <p className="text-sm text-gray-400">{t('common.noData')}</p>
                      ) : (
                        <ul className="space-y-2 text-sm">
                          {stmt.latest_payments.map(p => (
                            <li key={p.id} className="flex justify-between border-b border-gray-50 pb-1">
                              <span>{formatDate(p.payment_date)} · {p.payment_method}</span>
                              <span className="font-medium">{formatCurrency(p.amount)}</span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                    <div>
                      <h3 className="text-sm font-semibold text-gray-800 mb-2">{t('customers.collectionNotes')}</h3>
                      {stmt.latest_customer_notes?.length === 0 ? (
                        <p className="text-sm text-gray-400">{t('common.noData')}</p>
                      ) : (
                        <ul className="space-y-2 text-sm">
                          {stmt.latest_customer_notes.map(n => (
                            <li key={n.id} className="bg-gray-50 rounded p-2">
                              <p>{n.note}</p>
                              <p className="text-xs text-gray-400 mt-1">{n.created_by} · {formatDate(n.created_at)}</p>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </div>

                  <div>
                    <h3 className="text-sm font-semibold text-gray-800 mb-2">{t('customers.collectionFollowUps')}</h3>
                    {stmt.latest_collection_follow_ups?.length === 0 ? (
                      <p className="text-sm text-gray-400">{t('common.noData')}</p>
                    ) : (
                      <ul className="space-y-2 text-sm">
                        {stmt.latest_collection_follow_ups.map(f => (
                          <li key={f.id} className="border border-gray-100 rounded p-2">
                            <span className="font-medium">{t(`customers.outcome_${f.outcome}`)}</span>
                            {f.contract_number && <span className="text-gray-500"> · {f.contract_number}</span>}
                            {f.note && <p className="text-gray-700 mt-1">{f.note}</p>}
                            <p className="text-xs text-gray-400 mt-1">{f.created_by} · {formatDate(f.created_at)}</p>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>

                  {(stmt.active_promises_to_pay?.length > 0 || stmt.pending_reschedule_requests?.length > 0) && (
                    <div className="grid md:grid-cols-2 gap-4 text-sm">
                      {stmt.active_promises_to_pay?.length > 0 && (
                        <div>
                          <h3 className="font-semibold text-gray-800 mb-2">{t('customers.activePromises')}</h3>
                          <ul className="space-y-1">
                            {stmt.active_promises_to_pay.map(p => (
                              <li key={p.id} className="flex justify-between">
                                <span>{formatDate(p.promised_date)}</span>
                                <span>{formatCurrency(p.promised_amount)}</span>
                              </li>
                            ))}
                          </ul>
                        </div>
                      )}
                      {stmt.pending_reschedule_requests?.length > 0 && (
                        <div>
                          <h3 className="font-semibold text-gray-800 mb-2">{t('customers.pendingReschedules')}</h3>
                          <ul className="space-y-1">
                            {stmt.pending_reschedule_requests.map(r => (
                              <li key={r.id}>{r.contract_number} — {r.note || '—'}</li>
                            ))}
                          </ul>
                        </div>
                      )}
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {activeTab === 'followUp' && showFollowUp && (
            <div className="space-y-4">
              {canCreateFollow && (
                <div className="flex flex-wrap gap-2 print:hidden">
                  <button
                    type="button"
                    onClick={() => { ensureStmtForModals().catch(() => toast.error(t('common.error'))).finally(() => setFollowModal(true)); }}
                    className="btn-primary btn btn-sm"
                  >
                    {t('customers.addFollowUp')}
                  </button>
                  <button
                    type="button"
                    onClick={() => { ensureStmtForModals().catch(() => toast.error(t('common.error'))).finally(() => setPromiseModal(true)); }}
                    className="btn-secondary btn btn-sm"
                  >
                    {t('customers.recordPromise')}
                  </button>
                  <button
                    type="button"
                    onClick={() => { ensureStmtForModals().catch(() => toast.error(t('common.error'))).finally(() => setRescheduleModal(true)); }}
                    className="btn-secondary btn btn-sm"
                  >
                    {t('customers.requestReschedule')}
                  </button>
                </div>
              )}
              {fuLoading && <p className="text-sm text-gray-500">{t('common.loading')}</p>}
              {!fuLoading && (
                <div className="space-y-3">
                  <h3 className="text-sm font-semibold text-gray-800">{t('customers.followUpHistory')}</h3>
                  {fuList.length === 0 ? (
                    <p className="text-sm text-gray-400">{t('common.noData')}</p>
                  ) : (
                    <ul className="relative border-s border-gray-200 ms-3 space-y-4 py-1">
                      {fuList.map(f => (
                        <li key={f.id} className="relative ms-4">
                          <span className="absolute w-2 h-2 bg-primary-500 rounded-full -translate-x-[1.15rem] mt-1.5" />
                          <p className="text-sm font-medium text-gray-900">{t(`customers.outcome_${f.outcome}`)}</p>
                          <p className="text-xs text-gray-500">
                            {formatDate(f.created_at)}
                            {f.contract_number ? ` · ${f.contract_number}` : ''}
                            {f.next_follow_up_date ? ` · ${t('customers.nextFollowUpDate')}: ${formatDate(f.next_follow_up_date)}` : ''}
                            {f.priority ? ` · ${t(`customers.priority_${f.priority}`)}` : ''}
                          </p>
                          {f.note && <p className="text-sm text-gray-700 mt-1">{f.note}</p>}
                          {f.created_by && <p className="text-xs text-gray-400">{f.created_by}</p>}
                        </li>
                      ))}
                    </ul>
                  )}
                  {fuMeta && fuMeta.total > fuList.length && (
                    <p className="text-xs text-gray-400">{fuMeta.total} {t('common.results')}</p>
                  )}
                </div>
              )}
            </div>
          )}

          {activeTab === 'info' && (
            <div className="grid grid-cols-2 gap-4">
              {[
                { label: t('common.email'), value: customer.email },
                { label: t('common.address'), value: customer.address },
                { label: t('customers.idType'), value: customer.id_type },
                { label: t('common.createdAt'), value: formatDate(customer.created_at) },
              ].map(item => (
                <div key={item.label}>
                  <p className="text-xs text-gray-500 mb-0.5">{item.label}</p>
                  <p className="text-sm text-gray-800">{item.value || '—'}</p>
                </div>
              ))}
              {customer.notes && (
                <div className="col-span-2">
                  <p className="text-xs text-gray-500 mb-0.5">{t('common.notes')}</p>
                  <p className="text-sm text-gray-800">{customer.notes}</p>
                </div>
              )}
            </div>
          )}

          {activeTab === 'orders' && (
            <div className="space-y-2">
              {customer.orders_summary?.total === 0 ? (
                <p className="text-gray-400 text-sm text-center py-8">{t('common.noData')}</p>
              ) : (
                <Link to={`/orders?customer_id=${customer.id}`} className="text-sm text-primary-600 hover:underline">
                  {t('customers.viewAllOrders', { count: customer.orders_summary?.total })}
                </Link>
              )}
            </div>
          )}

          {activeTab === 'contracts' && (
            <div className="space-y-2">
              {customer.contracts_summary?.total === 0 ? (
                <p className="text-gray-400 text-sm text-center py-8">{t('common.noData')}</p>
              ) : (
                <Link to={`/contracts?customer_id=${customer.id}`} className="text-sm text-primary-600 hover:underline">
                  {t('customers.viewAllContracts', {
                    total: customer.contracts_summary?.total,
                    active: customer.contracts_summary?.active,
                  })}
                </Link>
              )}
            </div>
          )}

          {activeTab === 'notes' && (
            <div className="space-y-4">
              <form onSubmit={handleAddNote} className="flex gap-2">
                <input
                  type="text"
                  value={note}
                  onChange={e => setNote(e.target.value)}
                  className="input flex-1"
                  placeholder={t('customers.addNote')}
                />
                <button type="submit" disabled={addingNote} className="btn-primary btn">
                  {addingNote ? '...' : t('common.add')}
                </button>
              </form>
              <div className="space-y-3">
                {customer.notes_list?.map(n => (
                  <div key={n.id} className="bg-gray-50 rounded-lg p-3">
                    <p className="text-sm text-gray-800">{n.note}</p>
                    <p className="text-xs text-gray-400 mt-1">{n.created_by} · {formatDate(n.created_at)}</p>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      <Modal open={followModal} onClose={() => setFollowModal(false)} title={t('customers.addFollowUp')} size="md"
        footer={<>
          <button type="button" onClick={() => setFollowModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="follow-up-form" disabled={submitting} className="btn-primary btn">{submitting ? '...' : t('common.save')}</button>
        </>}
      >
        <form id="follow-up-form" onSubmit={submitFollowUp} className="space-y-3">
          <div>
            <label className="label">{t('customers.outcome')} *</label>
            <select className="input" required value={followForm.outcome} onChange={e => setFollowForm(f => ({ ...f, outcome: e.target.value }))}>
              {OUTCOMES.map(o => (
                <option key={o} value={o}>{t(`customers.outcome_${o}`)}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">{t('customers.contractOptional')}</label>
            <select className="input" value={followForm.contract_id} onChange={e => setFollowForm(f => ({ ...f, contract_id: e.target.value }))}>
              <option value="">{t('common.emDash')}</option>
              {contractOptions.map(c => (
                <option key={c.id} value={c.id}>{c.contract_number}</option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="label">{t('customers.priority')}</label>
              <select className="input" value={followForm.priority} onChange={e => setFollowForm(f => ({ ...f, priority: e.target.value }))}>
                {PRIORITIES.map(p => (
                  <option key={p} value={p}>{t(`customers.priority_${p}`)}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="label">{t('customers.nextFollowUpDate')}</label>
              <input type="date" className="input" value={followForm.next_follow_up_date} onChange={e => setFollowForm(f => ({ ...f, next_follow_up_date: e.target.value }))} />
            </div>
          </div>
          <div>
            <label className="label">{t('customers.followUpNote')}</label>
            <textarea className="input" rows={3} value={followForm.note} onChange={e => setFollowForm(f => ({ ...f, note: e.target.value }))} />
          </div>
        </form>
      </Modal>

      <Modal open={promiseModal} onClose={() => setPromiseModal(false)} title={t('customers.recordPromise')} size="md"
        footer={<>
          <button type="button" onClick={() => setPromiseModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="promise-form" disabled={submitting} className="btn-primary btn">{submitting ? '...' : t('common.save')}</button>
        </>}
      >
        <form id="promise-form" onSubmit={submitPromise} className="space-y-3">
          <div>
            <label className="label">{t('customers.contractOptional')}</label>
            <select className="input" value={promiseForm.contract_id} onChange={e => setPromiseForm(f => ({ ...f, contract_id: e.target.value }))}>
              <option value="">{t('common.emDash')}</option>
              {contractOptions.map(c => (
                <option key={c.id} value={c.id}>{c.contract_number}</option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="label">{t('customers.promisedAmount')} *</label>
              <input type="number" required min="0.01" step="0.01" className="input" value={promiseForm.promised_amount} onChange={e => setPromiseForm(f => ({ ...f, promised_amount: e.target.value }))} />
            </div>
            <div>
              <label className="label">{t('customers.promisedDate')} *</label>
              <input type="date" required className="input" value={promiseForm.promised_date} onChange={e => setPromiseForm(f => ({ ...f, promised_date: e.target.value }))} />
            </div>
          </div>
          <div>
            <label className="label">{t('customers.followUpNote')}</label>
            <textarea className="input" rows={2} value={promiseForm.note} onChange={e => setPromiseForm(f => ({ ...f, note: e.target.value }))} />
          </div>
        </form>
      </Modal>

      <Modal open={rescheduleModal} onClose={() => setRescheduleModal(false)} title={t('customers.requestReschedule')} size="md"
        footer={<>
          <button type="button" onClick={() => setRescheduleModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="reschedule-form" disabled={submitting} className="btn-primary btn">{submitting ? '...' : t('common.save')}</button>
        </>}
      >
        <form id="reschedule-form" onSubmit={submitReschedule} className="space-y-3">
          <div>
            <label className="label">{t('contracts.contractNumber')} *</label>
            <select required className="input" value={rescheduleForm.contract_id} onChange={e => setRescheduleForm(f => ({ ...f, contract_id: e.target.value }))}>
              <option value="">{t('common.emDash')}</option>
              {contractOptions.map(c => (
                <option key={c.id} value={c.id}>{c.contract_number}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">{t('customers.rescheduleNote')}</label>
            <textarea className="input" rows={3} value={rescheduleForm.note} onChange={e => setRescheduleForm(f => ({ ...f, note: e.target.value }))} />
          </div>
        </form>
      </Modal>
    </div>
  );
}
