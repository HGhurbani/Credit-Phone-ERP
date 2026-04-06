import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Eye, Trash2, Package } from 'lucide-react';
import { DataTable, Pagination } from '../../components/ui/Table';
import SearchInput from '../../components/ui/SearchInput';
import Badge from '../../components/ui/Badge';
import { ConfirmModal } from '../../components/ui/Modal';
import { productsApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency } from '../../utils/format';
import toast from 'react-hot-toast';
import { useDebounce } from '../../hooks/useDebounce';

export default function ProductsPage() {
  const { t } = useLang();
  const navigate = useNavigate();
  const [products, setProducts] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const debouncedSearch = useDebounce(search, 400);

  const fetchProducts = useCallback(async () => {
    setLoading(true);
    try {
      const res = await productsApi.list({ search: debouncedSearch, page, per_page: 15 });
      setProducts(res.data.data);
      setMeta(res.data.meta);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, page, t]);

  useEffect(() => { fetchProducts(); }, [fetchProducts]);

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await productsApi.delete(deleteTarget.id);
      toast.success(t('common.success'));
      setDeleteTarget(null);
      fetchProducts();
    } catch {
      toast.error(t('common.error'));
    } finally {
      setDeleting(false);
    }
  };

  const columns = [
    {
      key: 'name',
      title: t('products.name'),
      render: (row) => (
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center">
            {row.image ? (
              <img src={row.image} alt={row.name} className="w-8 h-8 rounded-lg object-cover" />
            ) : (
              <Package size={14} className="text-gray-400" />
            )}
          </div>
          <div>
            <p className="font-medium">{row.name}</p>
            <p className="text-xs text-gray-400">{row.sku || '—'}</p>
          </div>
        </div>
      ),
    },
    { key: 'category', title: t('products.category'), render: (row) => row.category?.name || '—' },
    { key: 'brand', title: t('products.brand'), render: (row) => row.brand?.name || '—' },
    { key: 'cash_price', title: t('products.cashPrice'), render: (row) => formatCurrency(row.cash_price) },
    { key: 'installment_price', title: t('products.installmentPrice'), render: (row) => formatCurrency(row.installment_price) },
    {
      key: 'is_active',
      title: t('common.status'),
      render: (row) => <Badge label={row.is_active ? t('common.active') : t('common.inactive')} variant={row.is_active ? 'green' : 'gray'} />,
    },
    {
      key: 'actions',
      title: t('common.actions'),
      render: (row) => (
        <div className="flex items-center gap-2">
          <button onClick={() => navigate(`/products/${row.id}`)} className="btn-ghost btn btn-sm"><Eye size={14} /></button>
          <button onClick={() => setDeleteTarget(row)} className="btn-ghost btn btn-sm text-red-500 hover:bg-red-50"><Trash2 size={14} /></button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('products.title')}</h1>
          <p className="page-subtitle">{meta?.total ?? 0} {t('common.results')}</p>
        </div>
        <button onClick={() => navigate('/products/new')} className="btn-primary btn">
          <Plus size={16} /> {t('products.add')}
        </button>
      </div>

      <div className="card p-4">
        <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t('products.searchPlaceholder')} className="max-w-sm" />
      </div>

      <DataTable columns={columns} data={products} loading={loading} />
      <Pagination meta={meta} onPageChange={setPage} />

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
