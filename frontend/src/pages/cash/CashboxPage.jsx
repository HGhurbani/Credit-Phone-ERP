import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { Wallet, Plus, ArrowRightLeft } from 'lucide-react';
import Modal from '../../components/ui/Modal';
import { Input, Select, Textarea } from '../../components/ui/FormField';
import { cashboxesApi, cashTransactionsApi, branchesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';
import Badge from '../../components/ui/Badge';
import { cashTxTypeLabelKey } from '../../i18n/cashLabels';

const TX_TYPES_IN = ['other_in'];
const TX_TYPES_OUT = ['other_out', 'purchase_payment_out'];

export default function CashboxPage() {
  const { t } = useLang();
  const { user, hasPermission, hasRole } = useAuth();

  const showCreateBranchPicker =
    !user?.branch_id && (hasRole('company_admin') || hasPermission('branches.view'));
  const [boxes, setBoxes] = useState([]);
  const [recent, setRecent] = useState([]);
  const [branches, setBranches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modal, setModal] = useState(null);
  const [adjModal, setAdjModal] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [createForm, setCreateForm] = useState({
    branch_id: '',
    name: t('cash.defaultBoxName'),
    type: '',
    is_primary: true,
    opening_balance: '0',
  });
  const [txForm, setTxForm] = useState({
    cashbox_id: '', transaction_type: 'other_in', amount: '', transaction_date: new Date().toISOString().split('T')[0], notes: '',
  });
  const [adjForm, setAdjForm] = useState({
    cashbox_id: '', direction: 'in', amount: '', transaction_date: new Date().toISOString().split('T')[0], notes: '',
  });
  const [saving, setSaving] = useState(false);

  const canManage = hasPermission('cashboxes.manage');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [bRes, tRes] = await Promise.all([
        cashboxesApi.list(),
        cashTransactionsApi.list({ per_page: 8 }),
      ]);
      setBoxes(bRes.data.data || []);
      setRecent(tRes.data.data || []);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    if (showCreateBranchPicker) {
      branchesApi.list().then((r) => setBranches(r.data.data || [])).catch(() => {});
    }
  }, [showCreateBranchPicker]);

  useEffect(() => {
    if (user?.branch_id) {
      setCreateForm((f) => ({ ...f, branch_id: String(user.branch_id) }));
    }
  }, [user?.branch_id]);

  const openCreateModal = () => {
    setCreateForm({
      branch_id: user?.branch_id ? String(user.branch_id) : '',
      name: t('cash.defaultBoxName'),
      type: '',
      is_primary: true,
      opening_balance: '0',
    });
    setCreateOpen(true);
  };

  const openTx = (cashbox) => {
    setTxForm({
      cashbox_id: String(cashbox.id),
      transaction_type: 'other_in',
      amount: '',
      transaction_date: new Date().toISOString().split('T')[0],
      notes: '',
    });
    setModal('tx');
  };

  const openAdj = (cashbox) => {
    setAdjForm({
      cashbox_id: String(cashbox.id),
      direction: 'in',
      amount: '',
      transaction_date: new Date().toISOString().split('T')[0],
      notes: '',
    });
    setAdjModal(true);
  };

  const submitTx = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await cashboxesApi.addTransaction(txForm.cashbox_id, {
        transaction_type: txForm.transaction_type,
        amount: parseFloat(txForm.amount),
        transaction_date: txForm.transaction_date,
        notes: txForm.notes || null,
      });
      toast.success(t('common.success'));
      setModal(null);
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const submitAdj = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await cashboxesApi.addAdjustment(adjForm.cashbox_id, {
        direction: adjForm.direction,
        amount: parseFloat(adjForm.amount),
        transaction_date: adjForm.transaction_date,
        notes: adjForm.notes || null,
      });
      toast.success(t('common.success'));
      setAdjModal(false);
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const submitCreate = async (e) => {
    e.preventDefault();
    const branchId = user?.branch_id
      ? Number(user.branch_id)
      : parseInt(createForm.branch_id, 10);
    if (!branchId || Number.isNaN(branchId)) {
      toast.error(t('purchases.branchRequired'));
      return;
    }
    setSaving(true);
    try {
      await cashboxesApi.create({
        branch_id: branchId,
        name: createForm.name,
        type: createForm.type?.trim() ? createForm.type.trim() : null,
        is_primary: !!createForm.is_primary,
        opening_balance: parseFloat(createForm.opening_balance) || 0,
      });
      toast.success(t('common.success'));
      setCreateOpen(false);
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="page-header flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="page-title">{t('cash.title')}</h1>
          <p className="page-subtitle text-gray-500">{t('cash.subtitle')}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {hasPermission('cash_transactions.view') && (
            <Link to="/cash/transactions" className="btn-secondary btn btn-sm">{t('cash.viewLedger')}</Link>
          )}
          {canManage && (
            <button type="button" onClick={openCreateModal} className="btn-primary btn btn-sm">
              <Plus size={16} /> {t('cash.createBox')}
            </button>
          )}
        </div>
      </div>

      {loading ? (
        <p className="text-gray-500">{t('common.loading')}</p>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {boxes.map((cb) => (
            <div key={cb.id} className="card p-5 border border-gray-100 shadow-sm">
              <div className="flex items-start justify-between gap-2 mb-3">
                <div className="flex items-center gap-2">
                  <div className="w-10 h-10 rounded-xl bg-primary-50 flex items-center justify-center">
                    <Wallet className="text-primary-600" size={20} />
                  </div>
                  <div>
                    <p className="font-semibold text-gray-900">{cb.name}</p>
                    <p className="text-xs text-gray-500">
                      {cb.branch?.name}
                      {cb.type ? <span className="text-gray-400"> · {cb.type}</span> : null}
                    </p>
                  </div>
                </div>
                <div className="flex flex-col items-end gap-1">
                  {cb.is_primary ? <Badge label={t('cash.primary')} variant="blue" /> : <Badge label={t('cash.secondary')} variant="gray" />}
                  {cb.is_active ? <Badge label={t('common.active')} variant="green" /> : <Badge label={t('common.inactive')} variant="gray" />}
                </div>
              </div>
              <p className="text-xs text-gray-500 mb-1">{t('cash.currentBalance')}</p>
              <p className="text-2xl font-bold text-primary-700 mb-4">{formatCurrency(cb.current_balance)}</p>
              {canManage && (
                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={() => openTx(cb)} className="btn-secondary btn btn-sm flex-1 min-w-[120px]">
                    <ArrowRightLeft size={14} /> {t('cash.addMovement')}
                  </button>
                  <button type="button" onClick={() => openAdj(cb)} className="btn-ghost btn btn-sm border border-gray-200">
                    {t('cash.adjustment')}
                  </button>
                </div>
              )}
            </div>
          ))}
          {boxes.length === 0 && (
            <div className="card p-8 text-center text-gray-500 col-span-full">
              <p>{t('cash.noBoxes')}</p>
              {canManage && (
                <button type="button" onClick={openCreateModal} className="btn-primary btn mt-4">{t('cash.createBox')}</button>
              )}
            </div>
          )}
        </div>
      )}

      <div className="card p-4">
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">{t('cash.recentMovements')}</h2>
          {hasPermission('cash_transactions.view') && (
            <Link to="/cash/transactions" className="text-sm text-primary-600 hover:underline">{t('cash.viewAll')}</Link>
          )}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-start text-gray-500 border-b">
                <th className="py-2 pe-4">{t('cash.voucher')}</th>
                <th className="py-2 pe-4">{t('common.date')}</th>
                <th className="py-2 pe-4">{t('common.branch')}</th>
                <th className="py-2 pe-4">{t('cash.txType')}</th>
                <th className="py-2 pe-4">{t('common.amount')}</th>
                <th className="py-2">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {recent.map((row) => (
                <tr key={row.id} className="border-b border-gray-50">
                  <td className="py-2 pe-4 font-mono text-xs">{row.voucher_number || '—'}</td>
                  <td className="py-2 pe-4">{formatDate(row.transaction_date)}</td>
                  <td className="py-2 pe-4">{row.branch?.name || '—'}</td>
                  <td className="py-2 pe-4">{t(cashTxTypeLabelKey(row.transaction_type))}</td>
                  <td className={`py-2 pe-4 font-medium ${row.direction === 'in' ? 'text-green-700' : 'text-red-700'}`}>
                    {row.direction === 'in' ? '+' : '−'}{formatCurrency(row.amount)}
                  </td>
                  <td className="py-2">
                    <Link to={`/cash/voucher/${row.id}`} className="text-primary-600 text-xs hover:underline" target="_blank" rel="noreferrer">
                      {t('cash.print')}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {recent.length === 0 && !loading && <p className="text-sm text-gray-500 py-4 text-center">{t('common.noData')}</p>}
        </div>
      </div>

      <Modal
        open={modal === 'tx'}
        onClose={() => setModal(null)}
        title={t('cash.addMovement')}
        footer={(
          <>
            <button type="button" onClick={() => setModal(null)} className="btn-secondary btn">{t('common.cancel')}</button>
            <button type="submit" form="cash-tx-form" disabled={saving} className="btn-primary btn">{saving ? '…' : t('common.save')}</button>
          </>
        )}
      >
        <form id="cash-tx-form" onSubmit={submitTx} className="space-y-3">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('cash.txType')}</label>
            <Select value={txForm.transaction_type} onChange={(e) => setTxForm({ ...txForm, transaction_type: e.target.value })}>
              {[...TX_TYPES_IN, ...TX_TYPES_OUT].map((x) => (
                <option key={x} value={x}>{t(cashTxTypeLabelKey(x))}</option>
              ))}
            </Select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.amount')}</label>
            <Input type="number" min="0.01" step="0.01" required value={txForm.amount} onChange={(e) => setTxForm({ ...txForm, amount: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.date')}</label>
            <Input type="date" required value={txForm.transaction_date} onChange={(e) => setTxForm({ ...txForm, transaction_date: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.notes')}</label>
            <Textarea rows={2} value={txForm.notes} onChange={(e) => setTxForm({ ...txForm, notes: e.target.value })} />
          </div>
        </form>
      </Modal>

      <Modal
        open={adjModal}
        onClose={() => setAdjModal(false)}
        title={t('cash.adjustment')}
        footer={(
          <>
            <button type="button" onClick={() => setAdjModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
            <button type="submit" form="cash-adj-form" disabled={saving} className="btn-primary btn">{saving ? '…' : t('common.save')}</button>
          </>
        )}
      >
        <form id="cash-adj-form" onSubmit={submitAdj} className="space-y-3">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('cash.direction')}</label>
            <Select value={adjForm.direction} onChange={(e) => setAdjForm({ ...adjForm, direction: e.target.value })}>
              <option value="in">{t('cash.directionIn')}</option>
              <option value="out">{t('cash.directionOut')}</option>
            </Select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.amount')}</label>
            <Input type="number" min="0.01" step="0.01" required value={adjForm.amount} onChange={(e) => setAdjForm({ ...adjForm, amount: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.date')}</label>
            <Input type="date" required value={adjForm.transaction_date} onChange={(e) => setAdjForm({ ...adjForm, transaction_date: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.notes')}</label>
            <Textarea rows={2} value={adjForm.notes} onChange={(e) => setAdjForm({ ...adjForm, notes: e.target.value })} />
          </div>
        </form>
      </Modal>

      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={t('cash.createBox')}
        footer={(
          <>
            <button type="button" onClick={() => setCreateOpen(false)} className="btn-secondary btn">{t('common.cancel')}</button>
            <button type="submit" form="cash-create-form" disabled={saving} className="btn-primary btn">{saving ? '…' : t('common.save')}</button>
          </>
        )}
      >
        <form id="cash-create-form" onSubmit={submitCreate} className="space-y-3">
          {showCreateBranchPicker ? (
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.branch')} *</label>
              <Select required value={createForm.branch_id} onChange={(e) => setCreateForm({ ...createForm, branch_id: e.target.value })}>
                <option value="">{t('ui.selectBranch')}</option>
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </Select>
            </div>
          ) : user?.branch_id ? (
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.branch')}</label>
              <p className="text-sm text-gray-800 py-2 px-3 rounded-lg bg-gray-50 border border-gray-100">
                {user.branch?.name ?? `#${user.branch_id}`}
              </p>
            </div>
          ) : null}
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.name')}</label>
            <Input value={createForm.name} onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })} />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('cash.typeOptional')}</label>
            <Input value={createForm.type} onChange={(e) => setCreateForm({ ...createForm, type: e.target.value })} placeholder={t('cash.typePlaceholder')} />
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              className="h-4 w-4"
              checked={!!createForm.is_primary}
              onChange={(e) => setCreateForm({ ...createForm, is_primary: e.target.checked })}
            />
            {t('cash.setPrimary')}
          </label>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('cash.openingBalance')}</label>
            <Input type="number" step="0.01" value={createForm.opening_balance} onChange={(e) => setCreateForm({ ...createForm, opening_balance: e.target.value })} />
          </div>
        </form>
      </Modal>
    </div>
  );
}
