import { useEffect, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { ArrowLeft, ArrowRight, FileText, ShoppingCart, CreditCard, MessageSquare, Plus } from 'lucide-react';
import Badge from '../../components/ui/Badge';
import { customersApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { formatCurrency, formatDate } from '../../utils/format';
import toast from 'react-hot-toast';

export default function CustomerDetailPage() {
  const { id } = useParams();
  const { t, isRTL } = useLang();
  const navigate = useNavigate();
  const BackIcon = isRTL ? ArrowRight : ArrowLeft;

  const [customer, setCustomer] = useState(null);
  const [loading, setLoading] = useState(true);
  const [note, setNote] = useState('');
  const [addingNote, setAddingNote] = useState(false);
  const [activeTab, setActiveTab] = useState('info');

  useEffect(() => {
    customersApi.get(id).then(res => {
      setCustomer(res.data.data);
    }).catch(() => {
      toast.error(t('common.error'));
      navigate('/customers');
    }).finally(() => setLoading(false));
  }, [id, navigate, t]);

  const handleAddNote = async (e) => {
    e.preventDefault();
    if (!note.trim()) return;
    setAddingNote(true);
    try {
      await customersApi.addNote(id, note);
      toast.success(t('common.success'));
      setNote('');
      const res = await customersApi.get(id);
      setCustomer(res.data.data);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setAddingNote(false);
    }
  };

  const creditVariant = { excellent: 'green', good: 'blue', fair: 'yellow', poor: 'red' };

  if (loading) {
    return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" /></div>;
  }

  if (!customer) return null;

  const tabs = [
    { key: 'info', label: t('customers.details'), icon: FileText },
    { key: 'orders', label: t('customers.orderHistory'), icon: ShoppingCart },
    { key: 'contracts', label: t('customers.contractHistory'), icon: CreditCard },
    { key: 'notes', label: t('common.notes'), icon: MessageSquare },
  ];

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="btn-ghost btn btn-sm"><BackIcon size={16} /></button>
          <div>
            <h1 className="page-title">{customer.name}</h1>
            <p className="page-subtitle">{customer.phone}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Badge label={customer.is_active ? t('common.active') : t('common.inactive')} variant={customer.is_active ? 'green' : 'gray'} />
          <Badge label={t(`customers.credit${customer.credit_score.charAt(0).toUpperCase() + customer.credit_score.slice(1)}`)} variant={creditVariant[customer.credit_score]} />
          <button onClick={() => navigate(`/orders/new?customer_id=${customer.id}`)} className="btn-primary btn btn-sm">
            <Plus size={14} />
            {t('orders.add')}
          </button>
        </div>
      </div>

      {/* Quick Info */}
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

      {/* Tabs */}
      <div className="card">
        <div className="flex border-b border-gray-100 px-4">
          {tabs.map(tab => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors ${
                activeTab === tab.key
                  ? 'border-primary-600 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              <tab.icon size={15} />
              {tab.label}
            </button>
          ))}
        </div>

        <div className="p-6">
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
    </div>
  );
}
