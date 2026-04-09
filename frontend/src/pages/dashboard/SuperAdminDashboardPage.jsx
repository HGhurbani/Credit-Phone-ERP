import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle, BadgeDollarSign, Building2, CreditCard, Edit, Layers3,
  LogIn, Plus, Trash2, Users, GitBranch,
} from 'lucide-react';
import toast from 'react-hot-toast';
import StatCard from '../../components/ui/StatCard';
import SearchInput from '../../components/ui/SearchInput';
import Badge from '../../components/ui/Badge';
import Modal, { ConfirmModal } from '../../components/ui/Modal';
import { DataTable, Pagination, getPerPageRequestValue } from '../../components/ui/Table';
import { dashboardApi, platformPlansApi, platformSubscriptionsApi, platformTenantsApi } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { useLang } from '../../context/LangContext';
import { useDebounce } from '../../hooks/useDebounce';
import { formatCurrency, formatDate, formatDateTime, formatNumber } from '../../utils/format';

const tabs = ['overview', 'tenants', 'plans', 'subscriptions'];
const emptyTenant = {
  name: '',
  slug: '',
  domain: '',
  email: '',
  phone: '',
  address: '',
  currency: 'QAR',
  timezone: 'Asia/Qatar',
  locale: 'ar',
  status: 'active',
  trial_ends_at: '',
  main_branch_name: '',
  admin_name: '',
  admin_email: '',
  admin_phone: '',
  admin_password: '',
  plan_id: '',
};
const emptyPlan = { name: '', slug: '', price: '', interval: 'monthly', max_branches: 1, max_users: 5, features_input: '', is_active: true };
const emptySubscription = { tenant_id: '', plan_id: '', status: 'active', starts_at: '', ends_at: '', cancelled_at: '', metadata_input: '' };

function slugify(value) {
  return value.toString().trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function firstError(errors, key) {
  const value = errors[key];
  return Array.isArray(value) ? value[0] : value;
}

function statusVariant(status) {
  return ({
    active: 'green',
    trial: 'blue',
    suspended: 'yellow',
    inactive: 'gray',
    cancelled: 'red',
    expired: 'gray',
  })[status] || 'gray';
}

function parseFeatures(value) {
  return value.split(/\r?\n|,/).map((item) => item.trim()).filter(Boolean);
}

function parseMetadata(value) {
  if (!value.trim()) return {};
  return JSON.parse(value);
}

function Panel({ title, action, children }) {
  return (
    <div className="card">
      <div className="card-header flex items-center justify-between gap-4">
        <h2 className="font-semibold text-gray-900">{title}</h2>
        {action}
      </div>
      <div className="p-4 pt-0">{children}</div>
    </div>
  );
}

export default function SuperAdminDashboardPage({ activeTab, showTabs = true }) {
  const { t } = useLang();
  const navigate = useNavigate();
  const { impersonate } = useAuth();
  const [tab, setTab] = useState(activeTab || 'overview');
  const [overview, setOverview] = useState(null);
  const [overviewLoading, setOverviewLoading] = useState(true);

  const [tenants, setTenants] = useState([]);
  const [tenantsMeta, setTenantsMeta] = useState(null);
  const [tenantOptions, setTenantOptions] = useState([]);
  const [tenantSearch, setTenantSearch] = useState('');
  const [tenantStatus, setTenantStatus] = useState('');
  const [tenantPage, setTenantPage] = useState(1);
  const [tenantPerPage, setTenantPerPage] = useState(10);
  const [tenantsLoading, setTenantsLoading] = useState(true);
  const [tenantModal, setTenantModal] = useState(false);
  const [tenantForm, setTenantForm] = useState(emptyTenant);
  const [tenantErrors, setTenantErrors] = useState({});
  const [tenantSaving, setTenantSaving] = useState(false);
  const [editingTenant, setEditingTenant] = useState(null);
  const [tenantDeleteTarget, setTenantDeleteTarget] = useState(null);
  const [tenantDeleting, setTenantDeleting] = useState(false);
  const [tenantImpersonatingId, setTenantImpersonatingId] = useState(null);

  const [plans, setPlans] = useState([]);
  const [planSearch, setPlanSearch] = useState('');
  const [plansLoading, setPlansLoading] = useState(true);
  const [planModal, setPlanModal] = useState(false);
  const [planForm, setPlanForm] = useState(emptyPlan);
  const [planErrors, setPlanErrors] = useState({});
  const [planSaving, setPlanSaving] = useState(false);
  const [editingPlan, setEditingPlan] = useState(null);
  const [planDeleteTarget, setPlanDeleteTarget] = useState(null);
  const [planDeleting, setPlanDeleting] = useState(false);

  const [subscriptions, setSubscriptions] = useState([]);
  const [subscriptionsMeta, setSubscriptionsMeta] = useState(null);
  const [subscriptionSearch, setSubscriptionSearch] = useState('');
  const [subscriptionStatus, setSubscriptionStatus] = useState('');
  const [subscriptionPage, setSubscriptionPage] = useState(1);
  const [subscriptionPerPage, setSubscriptionPerPage] = useState(10);
  const [subscriptionsLoading, setSubscriptionsLoading] = useState(true);
  const [subscriptionModal, setSubscriptionModal] = useState(false);
  const [subscriptionForm, setSubscriptionForm] = useState(emptySubscription);
  const [subscriptionErrors, setSubscriptionErrors] = useState({});
  const [subscriptionSaving, setSubscriptionSaving] = useState(false);
  const [editingSubscription, setEditingSubscription] = useState(null);
  const [subscriptionDeleteTarget, setSubscriptionDeleteTarget] = useState(null);
  const [subscriptionDeleting, setSubscriptionDeleting] = useState(false);

  const debouncedTenantSearch = useDebounce(tenantSearch, 400);
  const debouncedPlanSearch = useDebounce(planSearch, 400);
  const debouncedSubscriptionSearch = useDebounce(subscriptionSearch, 400);

  const fetchOverview = useCallback(async () => {
    setOverviewLoading(true);
    try {
      const res = await dashboardApi.get();
      setOverview(res.data);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setOverviewLoading(false);
    }
  }, [t]);

  const fetchTenantOptions = useCallback(async () => {
    try {
      const res = await platformTenantsApi.list({ per_page: 200 });
      setTenantOptions(res.data.data || []);
    } catch {}
  }, []);

  const fetchTenants = useCallback(async () => {
    setTenantsLoading(true);
    try {
      const res = await platformTenantsApi.list({ search: debouncedTenantSearch, status: tenantStatus || undefined, page: tenantPage, per_page: getPerPageRequestValue(tenantPerPage) });
      setTenants(res.data.data || []);
      setTenantsMeta(res.data.meta || null);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setTenantsLoading(false);
    }
  }, [debouncedTenantSearch, tenantPage, tenantPerPage, tenantStatus, t]);

  const fetchPlans = useCallback(async () => {
    setPlansLoading(true);
    try {
      const res = await platformPlansApi.list({ search: debouncedPlanSearch });
      setPlans(res.data.data || []);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setPlansLoading(false);
    }
  }, [debouncedPlanSearch, t]);

  const fetchSubscriptions = useCallback(async () => {
    setSubscriptionsLoading(true);
    try {
      const res = await platformSubscriptionsApi.list({ search: debouncedSubscriptionSearch, status: subscriptionStatus || undefined, page: subscriptionPage, per_page: getPerPageRequestValue(subscriptionPerPage) });
      setSubscriptions(res.data.data || []);
      setSubscriptionsMeta(res.data.meta || null);
    } catch {
      toast.error(t('common.error'));
    } finally {
      setSubscriptionsLoading(false);
    }
  }, [debouncedSubscriptionSearch, subscriptionPage, subscriptionPerPage, subscriptionStatus, t]);

  useEffect(() => {
    fetchOverview();
    fetchTenantOptions();
  }, [fetchOverview, fetchTenantOptions]);

  useEffect(() => {
    if (activeTab && activeTab !== tab) setTab(activeTab);
  }, [activeTab, tab]);

  useEffect(() => { fetchTenants(); }, [fetchTenants]);
  useEffect(() => { fetchPlans(); }, [fetchPlans]);
  useEffect(() => { fetchSubscriptions(); }, [fetchSubscriptions]);

  const refreshTenants = () => { fetchTenants(); fetchTenantOptions(); fetchOverview(); };
  const refreshPlans = () => { fetchPlans(); fetchOverview(); };
  const refreshSubscriptions = () => { fetchSubscriptions(); fetchTenants(); fetchOverview(); };

  const openTenantCreate = () => { setEditingTenant(null); setTenantForm(emptyTenant); setTenantErrors({}); setTenantModal(true); };
  const openTenantEdit = (tenant) => {
    setEditingTenant(tenant);
    setTenantForm({
      name: tenant.name || '',
      slug: tenant.slug || '',
      domain: tenant.domain || '',
      email: tenant.email || '',
      phone: tenant.phone || '',
      address: tenant.address || '',
      currency: tenant.currency || 'QAR',
      timezone: tenant.timezone || 'Asia/Qatar',
      locale: tenant.locale || 'ar',
      status: tenant.status || 'active',
      trial_ends_at: tenant.trial_ends_at || '',
      main_branch_name: '',
      admin_name: '',
      admin_email: '',
      admin_phone: '',
      admin_password: '',
      plan_id: '',
    });
    setTenantErrors({});
    setTenantModal(true);
  };

  const openPlanCreate = () => { setEditingPlan(null); setPlanForm(emptyPlan); setPlanErrors({}); setPlanModal(true); };
  const openPlanEdit = (plan) => {
    setEditingPlan(plan);
    setPlanForm({
      name: plan.name || '',
      slug: plan.slug || '',
      price: plan.price ?? '',
      interval: plan.interval || 'monthly',
      max_branches: plan.max_branches || 1,
      max_users: plan.max_users || 5,
      features_input: (plan.features || []).join('\n'),
      is_active: !!plan.is_active,
    });
    setPlanErrors({});
    setPlanModal(true);
  };

  const openSubscriptionCreate = () => { setEditingSubscription(null); setSubscriptionForm(emptySubscription); setSubscriptionErrors({}); setSubscriptionModal(true); };
  const openSubscriptionEdit = (subscription) => {
    setEditingSubscription(subscription);
    setSubscriptionForm({
      tenant_id: subscription.tenant_id || '',
      plan_id: subscription.plan_id || '',
      status: subscription.status || 'active',
      starts_at: subscription.starts_at || '',
      ends_at: subscription.ends_at || '',
      cancelled_at: subscription.cancelled_at || '',
      metadata_input: subscription.metadata ? JSON.stringify(subscription.metadata, null, 2) : '',
    });
    setSubscriptionErrors({});
    setSubscriptionModal(true);
  };

  const saveTenant = async (e) => {
    e.preventDefault();
    setTenantSaving(true);
    try {
      const payload = {
        ...tenantForm,
        slug: tenantForm.slug || slugify(tenantForm.name),
        domain: tenantForm.domain || null,
        phone: tenantForm.phone || null,
        address: tenantForm.address || null,
        trial_ends_at: tenantForm.trial_ends_at || null,
      };
      if (!editingTenant) {
        payload.main_branch_name = tenantForm.main_branch_name || null;
        payload.admin_name = tenantForm.admin_name;
        payload.admin_email = tenantForm.admin_email;
        payload.admin_phone = tenantForm.admin_phone || null;
        payload.admin_password = tenantForm.admin_password;
        payload.plan_id = tenantForm.plan_id ? Number(tenantForm.plan_id) : null;
      }
      if (editingTenant) await platformTenantsApi.update(editingTenant.id, payload);
      else await platformTenantsApi.create(payload);
      toast.success(t('common.success'));
      setTenantModal(false);
      refreshTenants();
    } catch (err) {
      if (err.response?.data?.errors) setTenantErrors(err.response.data.errors);
      else toast.error(t('common.error'));
    } finally {
      setTenantSaving(false);
    }
  };

  const savePlan = async (e) => {
    e.preventDefault();
    setPlanSaving(true);
    try {
      const payload = {
        ...planForm,
        slug: planForm.slug || slugify(planForm.name),
        price: Number(planForm.price || 0),
        max_branches: Number(planForm.max_branches || 1),
        max_users: Number(planForm.max_users || 1),
        features: parseFeatures(planForm.features_input),
      };
      if (editingPlan) await platformPlansApi.update(editingPlan.id, payload);
      else await platformPlansApi.create(payload);
      toast.success(t('common.success'));
      setPlanModal(false);
      refreshPlans();
      fetchSubscriptions();
    } catch (err) {
      if (err.response?.data?.errors) setPlanErrors(err.response.data.errors);
      else toast.error(t('common.error'));
    } finally {
      setPlanSaving(false);
    }
  };

  const saveSubscription = async (e) => {
    e.preventDefault();
    setSubscriptionSaving(true);
    try {
      const payload = {
        tenant_id: Number(subscriptionForm.tenant_id),
        plan_id: subscriptionForm.plan_id ? Number(subscriptionForm.plan_id) : null,
        status: subscriptionForm.status,
        starts_at: subscriptionForm.starts_at || null,
        ends_at: subscriptionForm.ends_at || null,
        cancelled_at: subscriptionForm.cancelled_at || null,
        metadata: parseMetadata(subscriptionForm.metadata_input),
      };
      if (editingSubscription) await platformSubscriptionsApi.update(editingSubscription.id, payload);
      else await platformSubscriptionsApi.create(payload);
      toast.success(t('common.success'));
      setSubscriptionModal(false);
      refreshSubscriptions();
      fetchTenantOptions();
    } catch (err) {
      if (err instanceof SyntaxError) setSubscriptionErrors({ metadata_input: t('platform.form.metadataInvalid') });
      else if (err.response?.data?.errors) setSubscriptionErrors(err.response.data.errors);
      else toast.error(t('common.error'));
    } finally {
      setSubscriptionSaving(false);
    }
  };

  const deleteTenant = async () => {
    setTenantDeleting(true);
    try {
      await platformTenantsApi.delete(tenantDeleteTarget.id);
      toast.success(t('common.success'));
      setTenantDeleteTarget(null);
      refreshTenants();
    } catch {
      toast.error(t('common.error'));
    } finally {
      setTenantDeleting(false);
    }
  };

  const deletePlan = async () => {
    setPlanDeleting(true);
    try {
      await platformPlansApi.delete(planDeleteTarget.id);
      toast.success(t('common.success'));
      setPlanDeleteTarget(null);
      refreshPlans();
      fetchSubscriptions();
    } catch {
      toast.error(t('common.error'));
    } finally {
      setPlanDeleting(false);
    }
  };

  const deleteSubscription = async () => {
    setSubscriptionDeleting(true);
    try {
      await platformSubscriptionsApi.delete(subscriptionDeleteTarget.id);
      toast.success(t('common.success'));
      setSubscriptionDeleteTarget(null);
      refreshSubscriptions();
    } catch {
      toast.error(t('common.error'));
    } finally {
      setSubscriptionDeleting(false);
    }
  };

  const handleTenantImpersonation = async (tenant) => {
    setTenantImpersonatingId(tenant.id);
    try {
      const res = await platformTenantsApi.impersonate(tenant.id);
      await impersonate(res.data);
      toast.success(t('platform.actions.enterTenantSuccess', { name: tenant.name }));
      navigate('/');
    } catch (err) {
      toast.error(err.response?.data?.message || t('common.error'));
    } finally {
      setTenantImpersonatingId(null);
    }
  };

  const tenantColumns = [
    { key: 'name', title: t('common.name'), render: (row) => <div><p className="font-medium text-gray-900">{row.name}</p><p className="text-xs text-gray-400">{row.slug}</p></div> },
    { key: 'email', title: t('common.email') },
    { key: 'status', title: t('common.status'), render: (row) => <Badge label={t(`platform.tenantStatuses.${row.status}`)} variant={statusVariant(row.status)} /> },
    { key: 'plan', title: t('platform.fields.latestPlan'), render: (row) => row.latest_subscription?.plan?.name || t('platform.form.noSubscription') },
    { key: 'counts', title: t('platform.fields.users'), render: (row) => <div className="text-sm text-gray-600">{t('platform.fields.users')}: {formatNumber(row.users_count || 0)}<br />{t('platform.fields.branches')}: {formatNumber(row.branches_count || 0)}</div> },
    { key: 'created_at', title: t('common.createdAt'), render: (row) => formatDate(row.created_at) },
    {
      key: 'actions',
      title: '',
      render: (row) => (
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => handleTenantImpersonation(row)}
            disabled={tenantImpersonatingId === row.id || !['active', 'trial'].includes(row.status)}
            className="btn btn-sm btn-ghost text-primary-700 disabled:opacity-50"
            title={t('platform.actions.enterTenant')}
          >
            <LogIn size={14} />
          </button>
          <button type="button" onClick={() => openTenantEdit(row)} className="btn btn-sm btn-ghost"><Edit size={14} /></button>
          <button type="button" onClick={() => setTenantDeleteTarget(row)} className="btn btn-sm btn-ghost text-red-500"><Trash2 size={14} /></button>
        </div>
      ),
    },
  ];

  const planColumns = [
    { key: 'name', title: t('common.name'), render: (row) => <div><p className="font-medium text-gray-900">{row.name}</p><p className="text-xs text-gray-400">{row.slug}</p></div> },
    { key: 'price', title: t('platform.fields.price'), render: (row) => `${formatCurrency(row.price)} / ${t(`platform.planIntervals.${row.interval}`)}` },
    { key: 'limits', title: t('platform.fields.limits'), render: (row) => `${formatNumber(row.max_branches)} ${t('platform.fields.branches')} • ${formatNumber(row.max_users)} ${t('platform.fields.users')}` },
    { key: 'subscriptions_count', title: t('platform.actions.manageSubscriptions'), render: (row) => formatNumber(row.subscriptions_count || 0) },
    { key: 'is_active', title: t('common.status'), render: (row) => <Badge label={row.is_active ? t('common.active') : t('common.inactive')} variant={row.is_active ? 'green' : 'gray'} /> },
    { key: 'actions', title: '', render: (row) => <div className="flex gap-2"><button type="button" onClick={() => openPlanEdit(row)} className="btn btn-sm btn-ghost"><Edit size={14} /></button><button type="button" onClick={() => setPlanDeleteTarget(row)} className="btn btn-sm btn-ghost text-red-500"><Trash2 size={14} /></button></div> },
  ];

  const subscriptionColumns = [
    { key: 'tenant', title: t('platform.fields.tenant'), render: (row) => <div><p className="font-medium text-gray-900">{row.tenant?.name || '—'}</p><p className="text-xs text-gray-400">{row.plan?.name || t('platform.form.noPlan')}</p></div> },
    { key: 'status', title: t('common.status'), render: (row) => <Badge label={t(`platform.subscriptionStatuses.${row.status}`)} variant={statusVariant(row.status)} /> },
    { key: 'term', title: t('platform.fields.startsAt'), render: (row) => <div className="text-sm text-gray-600"><div>{t('platform.fields.startsAt')}: {formatDate(row.starts_at)}</div><div>{t('platform.fields.endsAt')}: {formatDate(row.ends_at)}</div></div> },
    { key: 'updated_at', title: t('common.updatedAt'), render: (row) => formatDateTime(row.updated_at) },
    { key: 'actions', title: '', render: (row) => <div className="flex gap-2"><button type="button" onClick={() => openSubscriptionEdit(row)} className="btn btn-sm btn-ghost"><Edit size={14} /></button><button type="button" onClick={() => setSubscriptionDeleteTarget(row)} className="btn btn-sm btn-ghost text-red-500"><Trash2 size={14} /></button></div> },
  ];

  return (
    <div className="space-y-6">
      <div className="page-header">
        <div>
          <h1 className="page-title">{t('platform.title')}</h1>
          <p className="page-subtitle">{t('platform.subtitle')}</p>
        </div>
      </div>

      {showTabs && (
        <div className="card p-2">
          <div className="flex flex-wrap gap-2">
            {tabs.map((item) => (
              <button key={item} type="button" onClick={() => setTab(item)} className={`btn btn-sm ${tab === item ? 'btn-primary' : 'btn-secondary'}`}>
                {t(`platform.tabs.${item}`)}
              </button>
            ))}
          </div>
        </div>
      )}

      {overviewLoading ? (
        <div className="flex items-center justify-center h-40">
          <div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" />
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
            <StatCard icon={Building2} label={t('platform.stats.totalTenants')} value={formatNumber(overview?.stats?.total_tenants)} color="blue" />
            <StatCard icon={Layers3} label={t('platform.stats.activeTenants')} value={formatNumber(overview?.stats?.active_tenants)} color="green" />
            <StatCard icon={CreditCard} label={t('platform.stats.activeSubscriptions')} value={formatNumber(overview?.stats?.active_subscriptions)} color="purple" />
            <StatCard icon={AlertTriangle} label={t('platform.stats.expiringSoon')} value={formatNumber(overview?.stats?.expiring_subscriptions)} color="yellow" />
            <StatCard icon={BadgeDollarSign} label={t('platform.stats.mrr')} value={formatCurrency(overview?.stats?.monthly_recurring_revenue)} color="blue" />
          </div>

          {tab === 'overview' && (
            <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
              <Panel title={t('platform.sections.breakdown')}>
                <div className="space-y-3">
                  <div className="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3">
                    <div className="flex items-center gap-3 text-sm text-gray-600"><Users size={16} /><span>{t('platform.stats.totalUsers')}</span></div>
                    <span className="font-semibold text-gray-900">{formatNumber(overview?.stats?.total_users)}</span>
                  </div>
                  <div className="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3">
                    <div className="flex items-center gap-3 text-sm text-gray-600"><GitBranch size={16} /><span>{t('platform.stats.totalBranches')}</span></div>
                    <span className="font-semibold text-gray-900">{formatNumber(overview?.stats?.total_branches)}</span>
                  </div>
                  {Object.entries(overview?.tenant_status_breakdown || {}).map(([status, total]) => (
                    <div key={status} className="flex items-center justify-between rounded-xl border border-gray-100 px-4 py-3">
                      <Badge label={t(`platform.tenantStatuses.${status}`)} variant={statusVariant(status)} />
                      <span className="font-semibold text-gray-900">{formatNumber(total)}</span>
                    </div>
                  ))}
                </div>
              </Panel>

              <div className="xl:col-span-2 space-y-6">
                <Panel title={t('platform.sections.recentTenants')}>
                  <div className="space-y-3">
                    {(overview?.recent_tenants || []).length === 0 ? (
                      <div className="text-sm text-gray-400 py-6 text-center">{t('common.noData')}</div>
                    ) : (
                      overview.recent_tenants.map((tenant) => (
                        <div key={tenant.id} className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl border border-gray-100 px-4 py-3">
                          <div>
                            <p className="font-medium text-gray-900">{tenant.name}</p>
                            <p className="text-sm text-gray-500">{tenant.email}</p>
                          </div>
                          <div className="flex items-center gap-2">
                            <Badge label={t(`platform.tenantStatuses.${tenant.status}`)} variant={statusVariant(tenant.status)} />
                            <span className="text-sm text-gray-500">{formatDate(tenant.created_at)}</span>
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </Panel>

                <Panel title={t('platform.sections.expiring')}>
                  <div className="space-y-3">
                    {(overview?.expiring_subscriptions || []).length === 0 ? (
                      <div className="text-sm text-gray-400 py-6 text-center">{t('common.noData')}</div>
                    ) : (
                      overview.expiring_subscriptions.map((subscription) => (
                        <div key={subscription.id} className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl border border-gray-100 px-4 py-3">
                          <div>
                            <p className="font-medium text-gray-900">{subscription.tenant?.name}</p>
                            <p className="text-sm text-gray-500">{subscription.plan?.name || t('platform.form.noPlan')}</p>
                          </div>
                          <div className="flex items-center gap-2">
                            <Badge label={t(`platform.subscriptionStatuses.${subscription.status}`)} variant={statusVariant(subscription.status)} />
                            <span className="text-sm text-gray-500">{formatDate(subscription.ends_at)}</span>
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </Panel>
              </div>
            </div>
          )}
        </>
      )}

      {tab === 'tenants' && (
        <Panel title={t('platform.actions.manageTenants')} action={<button type="button" onClick={openTenantCreate} className="btn btn-primary"><Plus size={16} /> {t('platform.actions.addTenant')}</button>}>
          <div className="flex flex-col lg:flex-row gap-3 mb-4">
            <SearchInput value={tenantSearch} onChange={(value) => { setTenantSearch(value); setTenantPage(1); }} placeholder={t('platform.filters.searchTenants')} className="max-w-md" />
            <select value={tenantStatus} onChange={(e) => { setTenantStatus(e.target.value); setTenantPage(1); }} className="input max-w-xs">
              <option value="">{t('platform.filters.allStatuses')}</option>
              {['active', 'trial', 'suspended', 'inactive'].map((status) => <option key={status} value={status}>{t(`platform.tenantStatuses.${status}`)}</option>)}
            </select>
          </div>
          <DataTable columns={tenantColumns} data={tenants} loading={tenantsLoading} />
          <Pagination
            meta={tenantsMeta}
            onPageChange={setTenantPage}
            pageSize={tenantPerPage}
            onPageSizeChange={(value) => { setTenantPerPage(value); setTenantPage(1); }}
          />
        </Panel>
      )}

      {tab === 'plans' && (
        <Panel title={t('platform.actions.managePlans')} action={<button type="button" onClick={openPlanCreate} className="btn btn-primary"><Plus size={16} /> {t('platform.actions.addPlan')}</button>}>
          <div className="mb-4">
            <SearchInput value={planSearch} onChange={setPlanSearch} placeholder={t('platform.filters.searchPlans')} className="max-w-md" />
          </div>
          <DataTable columns={planColumns} data={plans} loading={plansLoading} paginate />
        </Panel>
      )}

      {tab === 'subscriptions' && (
        <Panel title={t('platform.actions.manageSubscriptions')} action={<button type="button" onClick={openSubscriptionCreate} className="btn btn-primary"><Plus size={16} /> {t('platform.actions.addSubscription')}</button>}>
          <div className="flex flex-col lg:flex-row gap-3 mb-4">
            <SearchInput value={subscriptionSearch} onChange={(value) => { setSubscriptionSearch(value); setSubscriptionPage(1); }} placeholder={t('platform.filters.searchSubscriptions')} className="max-w-md" />
            <select value={subscriptionStatus} onChange={(e) => { setSubscriptionStatus(e.target.value); setSubscriptionPage(1); }} className="input max-w-xs">
              <option value="">{t('platform.filters.allStatuses')}</option>
              {['active', 'trial', 'cancelled', 'expired'].map((status) => <option key={status} value={status}>{t(`platform.subscriptionStatuses.${status}`)}</option>)}
            </select>
          </div>
          <DataTable columns={subscriptionColumns} data={subscriptions} loading={subscriptionsLoading} />
          <Pagination
            meta={subscriptionsMeta}
            onPageChange={setSubscriptionPage}
            pageSize={subscriptionPerPage}
            onPageSizeChange={(value) => { setSubscriptionPerPage(value); setSubscriptionPage(1); }}
          />
        </Panel>
      )}

      <Modal open={tenantModal} onClose={() => setTenantModal(false)} title={editingTenant ? t('platform.form.tenantEdit') : t('platform.form.tenantCreate')} size="lg" footer={<><button type="button" onClick={() => setTenantModal(false)} className="btn btn-secondary">{t('common.cancel')}</button><button type="submit" form="tenant-form" disabled={tenantSaving} className="btn btn-primary">{tenantSaving ? '...' : t('common.save')}</button></>}>
        <form id="tenant-form" onSubmit={saveTenant} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div><label className="label">{t('common.name')}</label><input value={tenantForm.name} onChange={(e) => setTenantForm((prev) => ({ ...prev, name: e.target.value, slug: editingTenant ? prev.slug : slugify(e.target.value) }))} className={`input ${firstError(tenantErrors, 'name') ? 'input-error' : ''}`} required />{firstError(tenantErrors, 'name') && <p className="error-text">{firstError(tenantErrors, 'name')}</p>}</div>
            <div><label className="label">{t('platform.fields.slug')}</label><input value={tenantForm.slug} onChange={(e) => setTenantForm((prev) => ({ ...prev, slug: e.target.value }))} className={`input ${firstError(tenantErrors, 'slug') ? 'input-error' : ''}`} required />{firstError(tenantErrors, 'slug') && <p className="error-text">{firstError(tenantErrors, 'slug')}</p>}</div>
            <div><label className="label">{t('common.email')}</label><input type="email" value={tenantForm.email} onChange={(e) => setTenantForm((prev) => ({ ...prev, email: e.target.value }))} className={`input ${firstError(tenantErrors, 'email') ? 'input-error' : ''}`} required />{firstError(tenantErrors, 'email') && <p className="error-text">{firstError(tenantErrors, 'email')}</p>}</div>
            <div><label className="label">{t('common.phone')}</label><input value={tenantForm.phone} onChange={(e) => setTenantForm((prev) => ({ ...prev, phone: e.target.value }))} className="input" /></div>
            <div><label className="label">{t('platform.fields.domain')}</label><input value={tenantForm.domain} onChange={(e) => setTenantForm((prev) => ({ ...prev, domain: e.target.value }))} className={`input ${firstError(tenantErrors, 'domain') ? 'input-error' : ''}`} />{firstError(tenantErrors, 'domain') && <p className="error-text">{firstError(tenantErrors, 'domain')}</p>}</div>
            <div><label className="label">{t('common.status')}</label><select value={tenantForm.status} onChange={(e) => setTenantForm((prev) => ({ ...prev, status: e.target.value }))} className="input">{['active', 'trial', 'suspended', 'inactive'].map((status) => <option key={status} value={status}>{t(`platform.tenantStatuses.${status}`)}</option>)}</select></div>
            <div><label className="label">{t('common.currency')}</label><input value={tenantForm.currency} onChange={(e) => setTenantForm((prev) => ({ ...prev, currency: e.target.value }))} className="input" /></div>
            <div><label className="label">{t('platform.fields.timezone')}</label><input value={tenantForm.timezone} onChange={(e) => setTenantForm((prev) => ({ ...prev, timezone: e.target.value }))} className="input" /></div>
            <div><label className="label">{t('platform.fields.locale')}</label><select value={tenantForm.locale} onChange={(e) => setTenantForm((prev) => ({ ...prev, locale: e.target.value }))} className="input"><option value="ar">{t('ui.arabic')}</option><option value="en">{t('ui.english')}</option></select></div>
            <div><label className="label">{t('platform.fields.trialEndsAt')}</label><input type="date" value={tenantForm.trial_ends_at} onChange={(e) => setTenantForm((prev) => ({ ...prev, trial_ends_at: e.target.value }))} className="input" /></div>
          </div>
          <div><label className="label">{t('common.address')}</label><textarea value={tenantForm.address} onChange={(e) => setTenantForm((prev) => ({ ...prev, address: e.target.value }))} className="input" rows={3} /></div>

          {!editingTenant && (
            <>
              <div className="border-t border-gray-100 pt-4">
                <h3 className="text-sm font-semibold text-gray-900 mb-3">{t('platform.sections.bootstrap')}</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div><label className="label">{t('platform.fields.mainBranchName')}</label><input value={tenantForm.main_branch_name} onChange={(e) => setTenantForm((prev) => ({ ...prev, main_branch_name: e.target.value }))} className="input" placeholder={t('platform.form.defaultMainBranch')} /></div>
                  <div><label className="label">{t('platform.fields.plan')}</label><select value={tenantForm.plan_id} onChange={(e) => setTenantForm((prev) => ({ ...prev, plan_id: e.target.value }))} className="input"><option value="">{t('platform.form.noPlan')}</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}</select></div>
                  <div><label className="label">{t('platform.fields.adminName')}</label><input value={tenantForm.admin_name} onChange={(e) => setTenantForm((prev) => ({ ...prev, admin_name: e.target.value }))} className={`input ${firstError(tenantErrors, 'admin_name') ? 'input-error' : ''}`} required={!editingTenant} />{firstError(tenantErrors, 'admin_name') && <p className="error-text">{firstError(tenantErrors, 'admin_name')}</p>}</div>
                  <div><label className="label">{t('platform.fields.adminEmail')}</label><input type="email" value={tenantForm.admin_email} onChange={(e) => setTenantForm((prev) => ({ ...prev, admin_email: e.target.value }))} className={`input ${firstError(tenantErrors, 'admin_email') ? 'input-error' : ''}`} required={!editingTenant} />{firstError(tenantErrors, 'admin_email') && <p className="error-text">{firstError(tenantErrors, 'admin_email')}</p>}</div>
                  <div><label className="label">{t('platform.fields.adminPhone')}</label><input value={tenantForm.admin_phone} onChange={(e) => setTenantForm((prev) => ({ ...prev, admin_phone: e.target.value }))} className="input" /></div>
                  <div><label className="label">{t('platform.fields.adminPassword')}</label><input type="password" value={tenantForm.admin_password} onChange={(e) => setTenantForm((prev) => ({ ...prev, admin_password: e.target.value }))} className={`input ${firstError(tenantErrors, 'admin_password') ? 'input-error' : ''}`} required={!editingTenant} />{firstError(tenantErrors, 'admin_password') && <p className="error-text">{firstError(tenantErrors, 'admin_password')}</p>}</div>
                </div>
              </div>
            </>
          )}
        </form>
      </Modal>

      <Modal open={planModal} onClose={() => setPlanModal(false)} title={editingPlan ? t('platform.form.planEdit') : t('platform.form.planCreate')} size="lg" footer={<><button type="button" onClick={() => setPlanModal(false)} className="btn btn-secondary">{t('common.cancel')}</button><button type="submit" form="plan-form" disabled={planSaving} className="btn btn-primary">{planSaving ? '...' : t('common.save')}</button></>}>
        <form id="plan-form" onSubmit={savePlan} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div><label className="label">{t('common.name')}</label><input value={planForm.name} onChange={(e) => setPlanForm((prev) => ({ ...prev, name: e.target.value, slug: editingPlan ? prev.slug : slugify(e.target.value) }))} className={`input ${firstError(planErrors, 'name') ? 'input-error' : ''}`} required />{firstError(planErrors, 'name') && <p className="error-text">{firstError(planErrors, 'name')}</p>}</div>
            <div><label className="label">{t('platform.fields.slug')}</label><input value={planForm.slug} onChange={(e) => setPlanForm((prev) => ({ ...prev, slug: e.target.value }))} className={`input ${firstError(planErrors, 'slug') ? 'input-error' : ''}`} required />{firstError(planErrors, 'slug') && <p className="error-text">{firstError(planErrors, 'slug')}</p>}</div>
            <div><label className="label">{t('platform.fields.price')}</label><input type="number" min="0" step="0.01" value={planForm.price} onChange={(e) => setPlanForm((prev) => ({ ...prev, price: e.target.value }))} className={`input ${firstError(planErrors, 'price') ? 'input-error' : ''}`} required />{firstError(planErrors, 'price') && <p className="error-text">{firstError(planErrors, 'price')}</p>}</div>
            <div><label className="label">{t('platform.fields.interval')}</label><select value={planForm.interval} onChange={(e) => setPlanForm((prev) => ({ ...prev, interval: e.target.value }))} className="input">{['monthly', 'yearly', 'lifetime'].map((interval) => <option key={interval} value={interval}>{t(`platform.planIntervals.${interval}`)}</option>)}</select></div>
            <div><label className="label">{t('platform.fields.branches')}</label><input type="number" min="1" value={planForm.max_branches} onChange={(e) => setPlanForm((prev) => ({ ...prev, max_branches: e.target.value }))} className="input" required /></div>
            <div><label className="label">{t('platform.fields.users')}</label><input type="number" min="1" value={planForm.max_users} onChange={(e) => setPlanForm((prev) => ({ ...prev, max_users: e.target.value }))} className="input" required /></div>
          </div>
          <div><label className="label">{t('platform.fields.features')}</label><textarea value={planForm.features_input} onChange={(e) => setPlanForm((prev) => ({ ...prev, features_input: e.target.value }))} className="input" rows={4} /><p className="text-xs text-gray-400 mt-1">{t('platform.form.featuresHint')}</p></div>
          <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={planForm.is_active} onChange={(e) => setPlanForm((prev) => ({ ...prev, is_active: e.target.checked }))} /><span>{t('common.active')}</span></label>
        </form>
      </Modal>

      <Modal open={subscriptionModal} onClose={() => setSubscriptionModal(false)} title={editingSubscription ? t('platform.form.subscriptionEdit') : t('platform.form.subscriptionCreate')} size="lg" footer={<><button type="button" onClick={() => setSubscriptionModal(false)} className="btn btn-secondary">{t('common.cancel')}</button><button type="submit" form="subscription-form" disabled={subscriptionSaving} className="btn btn-primary">{subscriptionSaving ? '...' : t('common.save')}</button></>}>
        <form id="subscription-form" onSubmit={saveSubscription} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div><label className="label">{t('platform.fields.tenant')}</label><select value={subscriptionForm.tenant_id} onChange={(e) => setSubscriptionForm((prev) => ({ ...prev, tenant_id: e.target.value }))} className={`input ${firstError(subscriptionErrors, 'tenant_id') ? 'input-error' : ''}`} required><option value="">{t('platform.fields.tenant')}</option>{tenantOptions.map((tenant) => <option key={tenant.id} value={tenant.id}>{tenant.name}</option>)}</select>{firstError(subscriptionErrors, 'tenant_id') && <p className="error-text">{firstError(subscriptionErrors, 'tenant_id')}</p>}</div>
            <div><label className="label">{t('platform.fields.plan')}</label><select value={subscriptionForm.plan_id} onChange={(e) => setSubscriptionForm((prev) => ({ ...prev, plan_id: e.target.value }))} className="input"><option value="">{t('platform.form.noPlan')}</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}</select></div>
            <div><label className="label">{t('common.status')}</label><select value={subscriptionForm.status} onChange={(e) => setSubscriptionForm((prev) => ({ ...prev, status: e.target.value }))} className="input">{['active', 'trial', 'cancelled', 'expired'].map((status) => <option key={status} value={status}>{t(`platform.subscriptionStatuses.${status}`)}</option>)}</select></div>
            <div><label className="label">{t('platform.fields.startsAt')}</label><input type="date" value={subscriptionForm.starts_at} onChange={(e) => setSubscriptionForm((prev) => ({ ...prev, starts_at: e.target.value }))} className="input" /></div>
            <div><label className="label">{t('platform.fields.endsAt')}</label><input type="date" value={subscriptionForm.ends_at} onChange={(e) => setSubscriptionForm((prev) => ({ ...prev, ends_at: e.target.value }))} className="input" /></div>
            <div><label className="label">{t('platform.fields.cancelledAt')}</label><input type="date" value={subscriptionForm.cancelled_at} onChange={(e) => setSubscriptionForm((prev) => ({ ...prev, cancelled_at: e.target.value }))} className="input" /></div>
          </div>
          <div><label className="label">{t('platform.fields.metadata')}</label><textarea value={subscriptionForm.metadata_input} onChange={(e) => setSubscriptionForm((prev) => ({ ...prev, metadata_input: e.target.value }))} className={`input ${firstError(subscriptionErrors, 'metadata_input') ? 'input-error' : ''}`} rows={5} />{firstError(subscriptionErrors, 'metadata_input') && <p className="error-text">{firstError(subscriptionErrors, 'metadata_input')}</p>}<p className="text-xs text-gray-400 mt-1">{t('platform.form.metadataHint')}</p></div>
        </form>
      </Modal>

      <ConfirmModal open={!!tenantDeleteTarget} onClose={() => setTenantDeleteTarget(null)} onConfirm={deleteTenant} title={t('common.delete')} message={t('platform.form.deletedTenantConfirm', { name: tenantDeleteTarget?.name || '' })} confirmText={t('common.delete')} loading={tenantDeleting} />
      <ConfirmModal open={!!planDeleteTarget} onClose={() => setPlanDeleteTarget(null)} onConfirm={deletePlan} title={t('common.delete')} message={t('platform.form.deletedPlanConfirm', { name: planDeleteTarget?.name || '' })} confirmText={t('common.delete')} loading={planDeleting} />
      <ConfirmModal open={!!subscriptionDeleteTarget} onClose={() => setSubscriptionDeleteTarget(null)} onConfirm={deleteSubscription} title={t('common.delete')} message={t('platform.form.deletedSubscriptionConfirm', { id: subscriptionDeleteTarget?.id || '' })} confirmText={t('common.delete')} loading={subscriptionDeleting} />
    </div>
  );
}
