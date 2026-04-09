import { useState, useEffect, useCallback } from 'react';
import { Plus, Trash2, Edit } from 'lucide-react';
import { DataTable, Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge from '../../components/ui/Badge';
import Modal from '../../components/ui/Modal';
import { ConfirmModal } from '../../components/ui/Modal';
import { usersApi, branchesApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatDate } from '../../utils/format';
import { useDebounce } from '../../hooks/useDebounce';
import toast from 'react-hot-toast';

const emptyForm = { name: '', email: '', phone: '', password: '', branch_id: '', role: '', is_active: true, locale: 'ar' };

export default function UsersPage() {
  const { t } = useLang();
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [roles, setRoles] = useState([]);
  const [formModal, setFormModal] = useState(false);
  const [editUser, setEditUser] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [branches, setBranches] = useState([]);

  const debouncedSearch = useDebounce(search, 400);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = await usersApi.list({ search: debouncedSearch, page, per_page: getPerPageRequestValue(perPage) });
      setUsers(res.data.data);
      setMeta(res.data.meta);
    } catch { toast.error(t('common.error')); }
    finally { setLoading(false); }
  }, [debouncedSearch, page, perPage, t]);

  useEffect(() => { fetch(); }, [fetch]);
  useEffect(() => { usersApi.roles().then(r => setRoles(r.data.data)); }, []);
  useEffect(() => {
    branchesApi.list({ per_page: 200 }).then(r => setBranches(r.data.data || [])).catch(() => {});
  }, []);

  const openCreate = () => { setEditUser(null); setForm(emptyForm); setErrors({}); setFormModal(true); };
  const openEdit = (user) => {
    setEditUser(user);
    setForm({ name: user.name, email: user.email, phone: user.phone || '', password: '', branch_id: user.branch_id || '', role: user.roles?.[0] || '', is_active: user.is_active, locale: user.locale || 'ar' });
    setErrors({});
    setFormModal(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (editUser) {
        await usersApi.update(editUser.id, form);
      } else {
        await usersApi.create(form);
      }
      toast.success(t('common.success'));
      setFormModal(false);
      fetch();
    } catch (err) {
      if (err.response?.data?.errors) setErrors(err.response.data.errors);
      else toast.error(t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await usersApi.delete(deleteTarget.id);
      toast.success(t('common.success'));
      setDeleteTarget(null);
      fetch();
    } catch { toast.error(t('common.error')); }
    finally { setDeleting(false); }
  };

  const set = f => e => setForm(p => ({ ...p, [f]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  const columns = [
    { key: 'name', title: t('common.name') },
    { key: 'email', title: t('common.email') },
    { key: 'roles', title: t('users.role'), render: r => r.roles?.[0] ? <Badge label={t(`users.roles.${r.roles[0]}`)} variant="blue" /> : '—' },
    { key: 'branch', title: t('common.branch'), render: r => r.branch?.name || '—' },
    { key: 'is_active', title: t('common.status'), render: r => <Badge label={r.is_active ? t('common.active') : t('common.inactive')} variant={r.is_active ? 'green' : 'gray'} /> },
    { key: 'last_login_at', title: t('users.lastLogin'), render: r => formatDate(r.last_login_at) },
    {
      key: 'actions', title: '',
      render: r => (
        <div className="flex gap-2">
          <button onClick={() => openEdit(r)} className="btn-ghost btn btn-sm"><Edit size={14} /></button>
          <button onClick={() => setDeleteTarget(r)} className="btn-ghost btn btn-sm text-red-500"><Trash2 size={14} /></button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('users.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        <button onClick={openCreate} className="btn-primary btn"><Plus size={16} /> {t('users.add')}</button>
      </div>

      <div className="card p-4">
        <SearchInput value={search} onChange={v => { setSearch(v); setPage(1); }} className="max-w-sm" />
      </div>

      <DataTable columns={columns} data={users} loading={loading} />
      <Pagination
        meta={meta}
        onPageChange={setPage}
        pageSize={perPage}
        onPageSizeChange={(value) => { setPerPage(value); setPage(1); }}
      />

      {/* Form Modal */}
      <Modal open={formModal} onClose={() => setFormModal(false)} title={editUser ? t('users.edit') : t('users.add')} size="md"
        footer={<>
          <button onClick={() => setFormModal(false)} className="btn-secondary btn">{t('common.cancel')}</button>
          <button type="submit" form="user-form" disabled={saving} className="btn-primary btn">{saving ? '...' : t('common.save')}</button>
        </>}
      >
        <form id="user-form" onSubmit={handleSave} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">{t('common.name')} *</label>
              <input value={form.name} onChange={set('name')} required className={`input ${errors.name ? 'input-error' : ''}`} />
              {errors.name && <p className="error-text">{errors.name}</p>}
            </div>
            <div>
              <label className="label">{t('common.email')} *</label>
              <input type="email" value={form.email} onChange={set('email')} required className={`input ${errors.email ? 'input-error' : ''}`} />
              {errors.email && <p className="error-text">{errors.email}</p>}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">{t('common.phone')}</label>
              <input value={form.phone} onChange={set('phone')} className="input" />
            </div>
            <div>
              <label className="label">{t('users.password')} {!editUser && '*'}</label>
              <input type="password" value={form.password} onChange={set('password')} required={!editUser} className="input" />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">{t('users.role')}</label>
              <select value={form.role} onChange={set('role')} className="input">
                <option value="">-- {t('users.role')} --</option>
                {roles.map(r => <option key={r.id} value={r.name}>{t(`users.roles.${r.name}`)}</option>)}
              </select>
            </div>
            <div>
              <label className="label">{t('common.branch')}</label>
              <select value={form.branch_id ?? ''} onChange={set('branch_id')} className="input">
                <option value="">{t('users.branchPlaceholder')}</option>
                {branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
              </select>
              <p className="text-xs text-gray-400 mt-1">{t('users.branchManagerHint')}</p>
            </div>
          </div>
          <div>
            <label className="label">{t('users.fieldLanguage')}</label>
            <select value={form.locale} onChange={set('locale')} className="input max-w-xs">
              <option value="ar">{t('ui.arabic')}</option>
              <option value="en">{t('ui.english')}</option>
            </select>
          </div>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={form.is_active} onChange={set('is_active')} className="rounded" />
            <span className="text-sm">{t('users.isActive')}</span>
          </label>
        </form>
      </Modal>

      <ConfirmModal open={!!deleteTarget} onClose={() => setDeleteTarget(null)} onConfirm={handleDelete}
        title={t('common.delete')} message={t('ui.deleteConfirmNamed', { name: deleteTarget?.name ?? '' })} confirmText={t('common.delete')} loading={deleting} />
    </div>
  );
}
