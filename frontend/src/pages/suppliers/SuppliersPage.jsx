import { useState, useEffect, useCallback } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { DataTable, Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { ConfirmModal } from '../../components/ui/Modal';
import { Input, Textarea } from '../../components/ui/FormField';
import { suppliersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import toast from 'react-hot-toast';
import { useDebounce } from '../../hooks/useDebounce';

const emptyForm = {
  name: '',
  phone: '',
  email: '',
  contact_person: '',
  tax_number: '',
  address: '',
  notes: '',
  is_active: true,
};

export default function SuppliersPage() {
  const { t } = useLang();
  const { hasPermission } = useAuth();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const debouncedSearch = useDebounce(search, 400);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await suppliersApi.list({ search: debouncedSearch, page, per_page: getPerPageRequestValue(perPage) });
      setRows(res.data.data);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, page, perPage, t]);

  useEffect(() => { fetchList(); }, [fetchList]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setModalOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setForm({
      name: row.name || '',
      phone: row.phone || '',
      email: row.email || '',
      contact_person: row.contact_person || '',
      tax_number: row.tax_number || '',
      address: row.address || '',
      notes: row.notes || '',
      is_active: !!row.is_active,
    });
    setModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    if (!form.name.trim()) {
      toast.error(t('common.required'));
      return;
    }
    setSaving(true);
    try {
      if (editing) {
        await suppliersApi.update(editing.id, form);
      } else {
        await suppliersApi.create(form);
      }
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
      await suppliersApi.delete(deleteTarget.id);
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

  const columns = [
    { key: 'name', title: t('common.name'), render: (r) => <span className="font-medium">{r.name}</span> },
    { key: 'phone', title: t('common.phone'), render: (r) => r.phone || t('common.emDash') },
    { key: 'contact_person', title: t('suppliers.contactPerson'), render: (r) => r.contact_person || t('common.emDash') },
    {
      key: 'is_active',
      title: t('common.status'),
      render: (r) => <Badge label={r.is_active ? t('common.active') : t('common.inactive')} variant={r.is_active ? 'green' : 'gray'} />,
    },
    {
      key: 'actions',
      title: t('common.actions'),
      render: (r) => (
        <div className="flex items-center gap-2">
          {hasPermission('suppliers.update') && (
            <button type="button" onClick={() => openEdit(r)} className="btn-ghost btn btn-sm"><Pencil size={14} /></button>
          )}
          {hasPermission('suppliers.delete') && (
            <button type="button" onClick={() => setDeleteTarget(r)} className="btn-ghost btn btn-sm text-red-500 hover:bg-red-50"><Trash2 size={14} /></button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('suppliers.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        {hasPermission('suppliers.create') && (
          <button type="button" onClick={openCreate} className="btn-primary btn">
            <Plus size={16} /> {t('suppliers.add')}
          </button>
        )}
      </div>

      <div className="card p-4">
        <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t('suppliers.searchPlaceholder')} className="max-w-sm" />
      </div>

      <DataTable columns={columns} data={rows} loading={loading} />
      <Pagination
        meta={meta}
        onPageChange={setPage}
        pageSize={perPage}
        onPageSizeChange={(value) => { setPerPage(value); setPage(1); }}
      />

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? t('suppliers.edit') : t('suppliers.add')}
        size="lg"
        footer={(
          <>
            <button type="button" onClick={() => setModalOpen(false)} className="btn-secondary btn">{t('common.cancel')}</button>
            <button type="submit" form="supplier-form" disabled={saving} className="btn-primary btn">{saving ? '…' : t('common.save')}</button>
          </>
        )}
      >
        <form id="supplier-form" onSubmit={handleSave} className="space-y-3">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.name')} *</label>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.phone')}</label>
              <Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.email')}</label>
              <Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('suppliers.contactPerson')}</label>
              <Input value={form.contact_person} onChange={(e) => setForm({ ...form, contact_person: e.target.value })} />
            </div>
            <div className="sm:col-span-2">
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('suppliers.taxNumber')}</label>
              <Input value={form.tax_number} onChange={(e) => setForm({ ...form, tax_number: e.target.value })} />
            </div>
            <div className="sm:col-span-2">
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.address')}</label>
              <Input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
            </div>
            <div className="sm:col-span-2">
              <label className="block text-xs font-medium text-gray-600 mb-1">{t('common.notes')}</label>
              <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </div>
            <div className="sm:col-span-2 flex items-center gap-2">
              <input
                id="sup_active"
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                className="rounded border-gray-300"
              />
              <label htmlFor="sup_active" className="text-sm text-gray-700">{t('common.active')}</label>
            </div>
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
