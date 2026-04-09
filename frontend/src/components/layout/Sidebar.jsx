import { NavLink, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard, Users, Package, ShoppingCart, FileText,
  CreditCard, Receipt, BarChart3, UserCog, Building2,
  Settings, LogOut, ChevronLeft, ChevronRight, Truck, Building,
  Wallet, ReceiptText, ListOrdered, Tags, BadgeCheck, Bot, BookText,
  Layers3, Undo2,
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { useLang } from '../../context/LangContext';
import { clsx } from 'clsx';

/** ربط كل رابط بصلاحية Spatie — إن وُجدت null يظهر لكل المستخدمين المسجلين */
const navSections = [
  {
    key: 'main',
    titleKey: 'navSections.main',
    items: [
      { key: 'dashboard', path: '/', icon: LayoutDashboard, labelKey: 'nav.dashboard', exact: true, permission: null },
    ],
  },
  {
    key: 'sales',
    titleKey: 'navSections.sales',
    items: [
      { key: 'customers', path: '/customers', icon: Users, labelKey: 'nav.customers', permission: 'customers.view' },
      { key: 'orders', path: '/orders', icon: ShoppingCart, labelKey: 'nav.orders', permission: 'orders.view' },
      { key: 'contracts', path: '/contracts', icon: FileText, labelKey: 'nav.contracts', permission: 'contracts.view' },
      { key: 'collections', path: '/collections', icon: CreditCard, labelKey: 'nav.collections', permission: 'payments.collections' },
      { key: 'invoices', path: '/invoices', icon: Receipt, labelKey: 'nav.invoices', permission: 'invoices.view' },
    ],
  },
  {
    key: 'inventory',
    titleKey: 'navSections.inventory',
    items: [
      { key: 'products', path: '/products', icon: Package, labelKey: 'nav.products', permission: 'products.view' },
      { key: 'categories', path: '/categories', icon: Tags, labelKey: 'nav.categories', permission: 'categories.view' },
      { key: 'brands', path: '/brands', icon: BadgeCheck, labelKey: 'nav.brands', permission: 'brands.view' },
    ],
  },
  {
    key: 'purchasing',
    titleKey: 'navSections.purchasing',
    items: [
      { key: 'suppliers', path: '/suppliers', icon: Building, labelKey: 'nav.suppliers', permission: 'suppliers.view' },
      { key: 'purchases', path: '/purchases', icon: Truck, labelKey: 'nav.purchases', permission: 'purchases.view' },
    ],
  },
  {
    key: 'finance',
    titleKey: 'navSections.finance',
    items: [
      { key: 'cash', path: '/cash', icon: Wallet, labelKey: 'nav.cash', permission: 'cashboxes.view' },
      { key: 'cashLedger', path: '/cash/transactions', icon: ListOrdered, labelKey: 'nav.cashLedger', permission: 'cash_transactions.view' },
      { key: 'journalEntries', path: '/accounting/journal-entries', icon: BookText, labelKey: 'nav.journalEntries', permission: 'journal_entries.view' },
      { key: 'expenses', path: '/expenses', icon: ReceiptText, labelKey: 'nav.expenses', permission: 'expenses.view' },
    ],
  },
  {
    key: 'insights',
    titleKey: 'navSections.insights',
    items: [
      { key: 'reports', path: '/reports', icon: BarChart3, labelKey: 'nav.reports', permission: 'reports.view' },
      { key: 'assistant', path: '/assistant', icon: Bot, labelKey: 'nav.assistant', permission: 'assistant.use' },
    ],
  },
  {
    key: 'admin',
    titleKey: 'navSections.admin',
    items: [
      { key: 'users', path: '/users', icon: UserCog, labelKey: 'nav.users', permission: 'users.view' },
      { key: 'branches', path: '/branches', icon: Building2, labelKey: 'nav.branches', permission: 'branches.view' },
      { key: 'settings', path: '/settings', icon: Settings, labelKey: 'nav.settings', permission: 'settings.view' },
    ],
  },
];

const platformSections = [
  {
    key: 'platform',
    titleKey: 'navSections.platform',
    items: [
      { key: 'platform-overview', path: '/platform/overview', icon: LayoutDashboard, labelKey: 'platform.tabs.overview', exact: true, permission: null },
      { key: 'platform-tenants', path: '/platform/tenants', icon: Building2, labelKey: 'platform.tabs.tenants', exact: true, permission: null },
      { key: 'platform-plans', path: '/platform/plans', icon: Layers3, labelKey: 'platform.tabs.plans', exact: true, permission: null },
      { key: 'platform-subscriptions', path: '/platform/subscriptions', icon: CreditCard, labelKey: 'platform.tabs.subscriptions', exact: true, permission: null },
    ],
  },
];

export default function Sidebar({ collapsed, onToggle, isMobile, mobileOpen, onMobileClose }) {
  const { user, logout, hasPermission, isImpersonating, stopImpersonation, impersonationOriginUser } = useAuth();
  const { t, isRTL } = useLang();
  const navigate = useNavigate();

  const effectiveCollapsed = isMobile ? false : collapsed;

  const visibleSections = (user?.is_super_admin ? platformSections : navSections)
    .map((section) => {
      const items = section.items.filter((item) => {
        if (item.permission == null) return true;
        return hasPermission(item.permission);
      });
      return { ...section, items };
    })
    .filter((section) => section.items.length > 0);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  const handleStopImpersonation = async () => {
    await stopImpersonation();
    navigate('/platform/overview');
  };

  const CollapseIcon = isRTL
    ? (effectiveCollapsed ? ChevronLeft : ChevronRight)
    : (effectiveCollapsed ? ChevronRight : ChevronLeft);

  return (
    <>
      {isMobile && mobileOpen && (
        <button
          type="button"
          aria-label={t('ui.closeMenu')}
          onClick={onMobileClose}
          className="fixed inset-0 z-30 bg-black/40 print:hidden"
        />
      )}

      <aside
        className={clsx(
          'fixed top-0 bottom-0 z-40 flex flex-col bg-white border-e border-gray-200 transition-all duration-300 print:hidden',
          isRTL ? 'right-0' : 'left-0',
          effectiveCollapsed ? 'w-16' : 'w-64',
          isMobile && [
            'shadow-xl',
            mobileOpen ? 'translate-x-0' : (isRTL ? 'translate-x-full' : '-translate-x-full'),
          ]
        )}
      >
      {/* Logo */}
      <div className="flex items-center gap-3 px-4 h-16 border-b border-gray-100 flex-shrink-0">
        <div className="w-8 h-8 rounded-lg bg-primary-600 flex items-center justify-center flex-shrink-0">
          <span className="text-white font-bold text-sm">CP</span>
        </div>
        {!effectiveCollapsed && (
          <div className="overflow-hidden">
            <p className="text-sm font-semibold text-gray-900 truncate">{t('auth.systemName')}</p>
            <p className="text-xs text-gray-500 truncate">
              {user?.is_super_admin ? t('platform.platformLabel') : user?.tenant?.name}
            </p>
          </div>
        )}
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-2 py-4 overflow-y-auto">
        {visibleSections.map((section, sectionIdx) => (
          <div key={section.key} className={clsx('space-y-0.5', sectionIdx > 0 && (effectiveCollapsed ? 'pt-2' : 'pt-3'))}>
            {!effectiveCollapsed && (
              <div className="px-3 pt-2 pb-1">
                <p className="text-[11px] font-semibold tracking-wide text-gray-400 uppercase">
                  {t(section.titleKey)}
                </p>
              </div>
            )}

            {effectiveCollapsed && sectionIdx > 0 && <div className="mx-2 my-2 border-t border-gray-100" />}

            {section.items.map((item) => (
              <NavLink
                key={item.key}
                to={item.path}
                end={item.exact}
                title={effectiveCollapsed ? t(item.labelKey) : undefined}
                onClick={isMobile ? onMobileClose : undefined}
                className={({ isActive }) =>
                  clsx(
                    'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 cursor-pointer',
                    isActive
                      ? 'bg-primary-50 text-primary-700'
                      : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                    effectiveCollapsed && 'justify-center'
                  )
                }
              >
                <item.icon size={18} className="flex-shrink-0" />
                {!effectiveCollapsed && <span>{t(item.labelKey)}</span>}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>

      {/* User + Collapse */}
      <div className="border-t border-gray-100 p-2 space-y-1">
        {isImpersonating && (
          <button
            onClick={handleStopImpersonation}
            className={clsx(
              'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-primary-700 hover:bg-primary-50 transition-all',
              effectiveCollapsed && 'justify-center'
            )}
            title={effectiveCollapsed ? t('platform.actions.returnToPlatform') : undefined}
          >
            <Undo2 size={18} className="flex-shrink-0" />
            {!effectiveCollapsed && (
              <span>
                {t('platform.actions.returnToPlatform')}
                {impersonationOriginUser?.name ? ` · ${impersonationOriginUser.name}` : ''}
              </span>
            )}
          </button>
        )}

        <button
          onClick={handleLogout}
          className={clsx(
            'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-600 hover:bg-red-50 transition-all',
            effectiveCollapsed && 'justify-center'
          )}
          title={effectiveCollapsed ? t('nav.logout') : undefined}
        >
          <LogOut size={18} className="flex-shrink-0" />
          {!effectiveCollapsed && <span>{t('nav.logout')}</span>}
        </button>

        {!isMobile && (
          <button
            onClick={onToggle}
            className={clsx(
              'w-full flex items-center gap-3 px-3 py-2 rounded-lg text-xs text-gray-400 hover:bg-gray-50 transition-all',
              effectiveCollapsed && 'justify-center'
            )}
          >
            <CollapseIcon size={16} />
            {!effectiveCollapsed && <span className="text-gray-400">{t('ui.collapseSidebar')}</span>}
          </button>
        )}
      </div>
      </aside>
    </>
  );
}
