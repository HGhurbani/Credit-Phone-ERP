import { useState, useEffect, useCallback } from 'react';
import { Plus, Edit, Trash2, Star } from 'lucide-react';
import Badge from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { ConfirmModal } from '../../components/ui/Modal';
import { branchesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import toast from 'react-hot-toast';

const emptyForm = { name: '', code: '', phone: '', email: '', address: '', city: '', is_active: true };

export default function BranchesPage() {
  const { t } = useLang();
  const [branches, setBranches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [formModal, setFormModal] = useState(false);
  const [editBranch, setEditBranch] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = await branchesApi.list();
      setBranches(res.data.data);
    } catch { toast.error(t('common.error')); }
    finally { setLoading(false); }
  }, [t]);

  useEffect(() => { fetch(); }, [fetch]);

  const openCreate = () => { setEditBranch(null); setForm(emptyForm); setFormModal(true); };
  const openEdit = (b) => {
    setEditBranch(b);
    setForm({ name: b.name, code: b.code || '', phone: b.phone || '', email: b.email || '', address: b.address || '', city: b.city || '', is_active: b.is_active });
    setFormModal(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (editBranch) await branchesApi.update(editBranch.id, form);
      else await branchesApi.create(form);
      toast.success(t('common.success'));
      setFormModal(false);
      fetch();
    } catch { toast.error(t('common.error')); }
    finally { setSaving(false); }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await branchesApi.delete(deleteTarget.id);
      toast.success(t('common.success'));
      setDeleteTarget(null);
      fetch();
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally { setDeleting(false); }
  };

  const set = f => e => setForm(p => ({ ...p, [f]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('branches.title')}</h1>
          <p className="page-subtitle">{branches.length} {t('common.results')}</p>
        </div>
        <button onClick={openCreate} className="btn-primary btn"><Plus size={16} /> {t('branches.add')}</button>
      </div>

      {loading ? (
        <div className="flex items-center justify-center h-40"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {branches.map(branch => (
            <div key={branch.id} className="card p-5">
              <div className="flex items-start justify-between mb-3">
                <div>
                  <div className="flex items-center gap-2">
                    <h3 className="font-semibold text-gray-900">{branch.name}</h3>
                    {branch.is_main && <Star size={14} className="text-yellow-500 fill-yellow-500" />}
                  </div>
                  <p className="text-xs text-gray-400 mt-0.5">{branch.code}</p>
                </div>
                <Badge label={branch.is_active ? t('common.active') : t('common.inactive')} variant={branch.is_active ? 'green' : 'gray'} />
              </div>

              {[branch.phone, branch.email, branch.city].filter(Boolean).map((v, i) => (
                <p key={i} className="text-sm text-gray-500 mt-1">{v}</p>
              ))}

              <div className="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100">
                <button onClick={() => openEdit(branch)} className="btn-secondary btn btn-sm flex-1"><Edit size={13} /> {t('common.edit')}</button>
                {!branch.is_main && (
                  <button onClick={() => setDeleteTarget(branch)} className="btn-ghost btn btn-sm text-red-500"><Trash2 size={13} /></button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Form Modal */}
      <Modal open={formModal} onClose={() => setFormModal(false)} title={editBranch ? t('branches.edit') : t('branches.add')} size="md"
        footer={<>
          <button onClick={() => setFormModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="branch-form" disabled={saving} className="btn-primary btn">{saving ? '...' : t('common.save')}</button>
        </>}
      >
        <form id="branch-form" onSubmit={handleSave} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">{t('common.name')} *</label><input required value={form.name} onChange={set('name')} className="input" /></div>
            <div><label className="label">{t('branches.code')}</label><input value={form.code} onChange={set('code')} className="input" /></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">{t('common.phone')}</label><input value={form.phone} onChange={set('phone')} className="input" /></div>
            <div><label className="label">{t('common.email')}</label><input type="email" value={form.email} onChange={set('email')} className="input" /></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">{t('common.city')}</label><input value={form.city} onChange={set('city')} className="input" /></div>
            <div><label className="label">{t('common.address')}</label><input value={form.address} onChange={set('address')} className="input" /></div>
          </div>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={form.is_active} onChange={set('is_active')} className="rounded" />
            <span className="text-sm">{t('branches.isActive')}</span>
          </label>
        </form>
      </Modal>

      <ConfirmModal open={!!deleteTarget} onClose={() => setDeleteTarget(null)} onConfirm={handleDelete}
        title={t('common.delete')} message={t('ui.deleteConfirmNamed', { name: deleteTarget?.name ?? '' })} confirmText={t('common.delete')} loading={deleting} />
    </div>
  );
}
