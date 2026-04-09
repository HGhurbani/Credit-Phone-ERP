import { useCallback, useEffect, useMemo, useState } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { DataTable } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge from '../../components/ui/Badge';
import Modal, { ConfirmModal } from '../../components/ui/Modal';
import { Input } from '../../components/ui/FormField';
import { brandsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import toast from 'react-hot-toast';

const emptyForm = { name: '' };

export default function BrandsPage() {
  const { t } = useLang();
  const { hasPermission } = useAuth();

  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);

  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await brandsApi.list();
      setRows(res.data.data || []);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    fetchList();
  }, [fetchList]);

  const filteredRows = useMemo(() => {
    const q = String(search || '').trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((r) => String(r.name || '').toLowerCase().includes(q));
  }, [rows, search]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setModalOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setForm({ name: row.name || '' });
    setModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    if (!form.name.trim()) {
      toast.error(t('validation.required'));
      return;
    }
    setSaving(true);
    try {
      const payload = { name: form.name.trim() };
      if (editing) await brandsApi.update(editing.id, payload);
      else await brandsApi.create(payload);
      toast.success(t('common.success'));
      setModalOpen(false);
      fetchList();
    } catch (err) {
      const msg = err.response?.data?.message;
      const errs = err.response?.data?.errors;
      toast.error(msg || (errs && Object.values(errs).flat()[0]) || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await brandsApi.delete(deleteTarget.id);
      toast.success(t('common.success'));
      setDeleteTarget(null);
      fetchList();
    } catch (err) {
      const errs = err.response?.data?.errors;
      toast.error(err.response?.data?.message || (errs && Object.values(errs).flat()[0]) || t('common.error'));
    } finally {
      setDeleting(false);
    }
  };

  const showStatus = rows.some((r) => typeof r?.is_active === 'boolean');

  const columns = [
    { key: 'name', title: t('common.name'), render: (r) => <span className="font-medium">{r.name}</span> },
    ...(showStatus
      ? [{
        key: 'is_active',
        title: t('common.status'),
        render: (r) => (
          <Badge
            label={r.is_active ? t('common.active') : t('common.inactive')}
            variant={r.is_active ? 'green' : 'gray'}
          />
        ),
      }]
      : []),
    {
      key: 'actions',
      title: t('common.actions'),
      render: (r) => (
        <div className="flex items-center gap-2">
          {hasPermission('brands.update') && (
            <button type="button" onClick={() => openEdit(r)} className="btn-ghost btn btn-sm">
              <Pencil size={14} />
            </button>
          )}
          {hasPermission('brands.delete') && (
            <button type="button" onClick={() => setDeleteTarget(r)} className="btn-ghost btn btn-sm text-red-500 hover:bg-red-50">
              <Trash2 size={14} />
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('brands.title')}</h1>
          <p className="page-subtitle">{filteredRows.length} {t('common.results')}</p>
        </div>
        {hasPermission('brands.create') && (
          <button type="button" onClick={openCreate} className="btn-primary btn">
            <Plus size={16} /> {t('brands.add')}
          </button>
        )}
      </div>

      <div className="card p-4">
        <SearchInput value={search} onChange={setSearch} placeholder={t('brands.searchPlaceholder')} className="max-w-sm" />
      </div>

      <DataTable columns={columns} data={filteredRows} loading={loading} paginate />

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? t('brands.edit') : t('brands.add')}
        size="md"
        footer={(
          <>
            <button type="button" onClick={() => setModalOpen(false)} className="btn-secondary btn">{t('common.cancel')}</button>
            <button type="submit" form="brand-form" disabled={saving} className="btn-primary btn">{saving ? '…' : t('common.save')}</button>
          </>
        )}
      >
        <form id="brand-form" onSubmit={handleSave} className="space-y-3">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.name')} *</label>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </div>
        </form>
      </Modal>

      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        title={t('common.delete')}
        message={t('ui.deleteConfirmNamed', { name: deleteTarget?.name ?? '' })}
        loading={deleting}
      />
    </div>
  );
}

