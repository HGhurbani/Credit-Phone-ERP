import { useState, useEffect, useCallback } from 'react';
import { Plus, Ban } from 'lucide-react';
import { DataTable, Pagination } from '../../components/ui/Table';
import Modal from '../../components/ui/Modal';
import { Input, Select, Textarea } from '../../components/ui/FormField';
import { expensesApi, cashboxesApi, branchesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
import Badge from '../../components/ui/Badge';

export default function ExpensesPage() {
  const { t } = useLang();
  const { user, hasPermission, hasRole } = useAuth();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [branches, setBranches] = useState([]);
  const [cashboxes, setCashboxes] = useState([]);
  const [form, setForm] = useState({
    branch_id: user?.branch_id ? String(user.branch_id) : '',
    cashbox_id: '',
    category: '',
    amount: '',
    expense_date: new Date().toISOString().split('T')[0],
    vendor_name: '',
    notes: '',
  });
  const [saving, setSaving] = useState(false);

  const showBranchField = !user?.branch_id && (hasRole('company_admin') || hasPermission('branches.view'));

  useEffect(() => {
    if (showBranchField) {
      branchesApi.list().then((r) => setBranches(r.data.data || [])).catch(() => {});
    }
  }, [showBranchField]);

  useEffect(() => {
    cashboxesApi.list().then((r) => setCashboxes(r.data.data || [])).catch(() => {});
  }, []);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const params = { page, per_page: 20 };
      if (category) params.category = category;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;
      const res = await expensesApi.list(params);
      setRows(res.data.data);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [page, category, dateFrom, dateTo, t]);

  useEffect(() => { fetchList(); }, [fetchList]);

  useEffect(() => {
    if (user?.branch_id) {
      setForm((f) => ({ ...f, branch_id: String(user.branch_id) }));
    }
  }, [user?.branch_id]);

  const submit = async (e) => {
    e.preventDefault();
    const branchId = showBranchField ? parseInt(form.branch_id, 10) : user?.branch_id;
    if (!branchId) {
      toast.error(t('purchases.branchRequired'));
      return;
    }
    const payload = {
      branch_id: branchId,
      category: form.category,
      amount: parseFloat(form.amount),
      expense_date: form.expense_date,
      vendor_name: form.vendor_name || null,
      notes: form.notes || null,
    };
    if (form.cashbox_id) payload.cashbox_id = parseInt(form.cashbox_id, 10);

    setSaving(true);
    try {
      await expensesApi.create(payload);
      toast.success(t('common.success'));
      setModalOpen(false);
      setForm({
        branch_id: user?.branch_id ? String(user.branch_id) : '',
        cashbox_id: '',
        category: '',
        amount: '',
        expense_date: new Date().toISOString().split('T')[0],
        vendor_name: '',
        notes: '',
      });
      fetchList();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const cancelExpense = async (row) => {
    if (!window.confirm(t('expenses.confirmCancel'))) return;
    try {
      await expensesApi.cancel(row.id);
      toast.success(t('common.success'));
      fetchList();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    }
  };

  const filteredCashboxes = cashboxes.filter((c) => !form.branch_id || String(c.branch?.id) === String(form.branch_id));

  const columns = [
    { key: 'n', title: t('expenses.number'), render: (r) => <span className="font-mono text-sm">{r.expense_number}</span> },
    { key: 'c', title: t('expenses.category'), render: (r) => r.category },
    { key: 'b', title: t('common.branch'), render: (r) => r.branch?.name },
    { key: 'd', title: t('common.date'), render: (r) => formatDate(r.expense_date) },
    { key: 'a', title: t('common.amount'), render: (r) => formatCurrency(r.amount) },
    {
      key: 's',
      title: t('common.status'),
      render: (r) => <Badge label={r.status === 'active' ? t('expenses.statusActive') : t('expenses.statusCancelled')} variant={r.status === 'active' ? 'blue' : 'gray'} />,
    },
    {
      key: 'x',
      title: t('common.actions'),
      render: (r) => (
        r.status === 'active' && hasPermission('expenses.update') ? (
          <button type="button" onClick={() => cancelExpense(r)} className="btn-ghost btn btn-sm text-amber-700">
            <Ban size={14} /> {t('expenses.cancel')}
          </button>
        ) : null
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('expenses.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        {hasPermission('expenses.create') && (
          <button type="button" onClick={() => setModalOpen(true)} className="btn-primary btn">
            <Plus size={16} /> {t('expenses.add')}
          </button>
        )}
      </div>

      <div className="card p-4 flex flex-wrap gap-3 items-end">
        <div>
          <label className="block text-xs text-gray-500 mb-1">{t('expenses.category')}</label>
          <Input value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }} placeholder={t('expenses.filterCategory')} className="w-40" />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">{t('common.date')}</label>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">{t('expenses.dateTo')}</label>
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} />
        </div>
      </div>

      <DataTable columns={columns} data={rows} loading={loading} />
      <Pagination meta={meta} onPageChange={setPage} />

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={t('expenses.add')}
        size="lg"
        footer={(
          <>
            <button type="button" onClick={() => setModalOpen(false)} className="btn-secondary btn">{t('common.cancel')}</button>
            <button type="submit" form="expense-form" disabled={saving} className="btn-primary btn">{saving ? '…' : t('common.save')}</button>
          </>
        )}
      >
        <form id="expense-form" onSubmit={submit} className="space-y-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
          {showBranchField && (
            <div className="sm:col-span-2">
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.branch')} *</label>
              <Select required value={form.branch_id} onChange={(e) => setForm({ ...form, branch_id: e.target.value, cashbox_id: '' })}>
                <option value="">{t('ui.selectBranch')}</option>
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </Select>
            </div>
          )}
          <div className="sm:col-span-2">
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('cash.cashbox')} ({t('common.optional')})</label>
            <Select value={form.cashbox_id} onChange={(e) => setForm({ ...form, cashbox_id: e.target.value })}>
              <option value="">{t('expenses.noCashbox')}</option>
              {filteredCashboxes.map((c) => (
                <option key={c.id} value={c.id}>{c.name} — {formatCurrency(c.current_balance)}</option>
              ))}
            </Select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('expenses.category')} *</label>
            <Input required value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.amount')} *</label>
            <Input type="number" min="0.01" step="0.01" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.date')} *</label>
            <Input type="date" required value={form.expense_date} onChange={(e) => setForm({ ...form, expense_date: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('expenses.vendor')}</label>
            <Input value={form.vendor_name} onChange={(e) => setForm({ ...form, vendor_name: e.target.value })} />
          </div>
          <div className="sm:col-span-2">
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.notes')}</label>
            <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </div>
        </form>
      </Modal>
    </div>
  );
}
