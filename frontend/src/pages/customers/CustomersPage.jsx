import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Eye, Trash2, Pencil } from 'lucide-react';
import { DataTable, Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge from '../../components/ui/Badge';
import { ConfirmModal } from '../../components/ui/Modal';
import { customersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';
import toast from 'react-hot-toast';
import { useDebounce } from '../../hooks/useDebounce';

const creditScoreVariant = { excellent: 'green', good: 'blue', fair: 'yellow', poor: 'red' };

export default function CustomersPage() {
  const { t } = useLang();
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [customers, setCustomers] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const debouncedSearch = useDebounce(search, 400);

  const fetchCustomers = useCallback(async () => {
    setLoading(true);
    try {
      const res = await customersApi.list({ search: debouncedSearch, page, per_page: getPerPageRequestValue(perPage) });
      setCustomers(res.data.data);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, page, perPage, t]);

  useEffect(() => { fetchCustomers(); }, [fetchCustomers]);

  const handleDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await customersApi.delete(deleteTarget.id);
      toast.success(t('common.success'));
      setDeleteTarget(null);
      fetchCustomers();
    } catch {
      toast.error(t('common.error'));
    } finally {
      setDeleting(false);
    }
  };

  const canUpdate = hasPermission('customers.update');

  const columns = [
    { key: 'name', title: t('customers.name') },
    { key: 'phone', title: t('common.phone') },
    { key: 'national_id', title: t('customers.nationalId') },
    { key: 'employer_name', title: t('customers.employer') },
    {
      key: 'credit_score',
      title: t('customers.creditScore'),
      render: (row) => (
        <Badge
          label={t(`customers.credit${row.credit_score.charAt(0).toUpperCase() + row.credit_score.slice(1)}`)}
          variant={creditScoreVariant[row.credit_score] || 'gray'}
        />
      ),
    },
    {
      key: 'is_active',
      title: t('common.status'),
      render: (row) => (
        <Badge
          label={row.is_active ? t('common.active') : t('common.inactive')}
          variant={row.is_active ? 'green' : 'gray'}
        />
      ),
    },
    {
      key: 'actions',
      title: t('common.actions'),
      render: (row) => (
        <div className="flex items-center gap-2">
          <button
            onClick={() => navigate(`/customers/${row.id}`)}
            className="btn-ghost btn btn-sm"
          >
            <Eye size={14} />
          </button>
          {canUpdate && (
            <button
              onClick={() => navigate(`/customers/${row.id}/edit`)}
              className="btn-ghost btn btn-sm"
            >
              <Pencil size={14} />
            </button>
          )}
          <button
            onClick={() => setDeleteTarget(row)}
            className="btn-ghost btn btn-sm text-red-500 hover:bg-red-50"
          >
            <Trash2 size={14} />
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('customers.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        <button onClick={() => navigate('/customers/new')} className="btn-primary btn">
          <Plus size={16} />
          {t('customers.add')}
        </button>
      </div>

      <div className="card p-4">
        <SearchInput
          value={search}
          onChange={(v) => { setSearch(v); setPage(1); }}
          placeholder={t('customers.searchPlaceholder')}
          className="max-w-sm"
        />
      </div>

      <div>
        <DataTable columns={columns} data={customers} loading={loading} />
        <Pagination
          meta={meta}
          onPageChange={setPage}
          pageSize={perPage}
          onPageSizeChange={(value) => { setPerPage(value); setPage(1); }}
        />
      </div>

      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        title={t('common.delete')}
        message={t('ui.deleteConfirmNamed', { name: deleteTarget?.name ?? '' })}
        confirmText={t('common.delete')}
        loading={deleting}
      />
    </div>
  );
}
