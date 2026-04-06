import { NavLink, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard, Users, Package, ShoppingCart, FileText,
  CreditCard, Receipt, BarChart3, UserCog, Building2,
  Settings, LogOut, ChevronLeft, ChevronRight,
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { useLang } from '../../context/LangContext';
import { clsx } from 'clsx';

/** ربط كل رابط بصلاحية Spatie — إن وُجدت null يظهر لكل المستخدمين المسجلين */
const navItems = [
  { key: 'dashboard', path: '/', icon: LayoutDashboard, labelKey: 'nav.dashboard', exact: true, permission: null },
  { key: 'customers', path: '/customers', icon: Users, labelKey: 'nav.customers', permission: 'customers.view' },
  { key: 'products', path: '/products', icon: Package, labelKey: 'nav.products', permission: 'products.view' },
  { key: 'orders', path: '/orders', icon: ShoppingCart, labelKey: 'nav.orders', permission: 'orders.view' },
  { key: 'contracts', path: '/contracts', icon: FileText, labelKey: 'nav.contracts', permission: 'contracts.view' },
  { key: 'collections', path: '/collections', icon: CreditCard, labelKey: 'nav.collections', permission: 'payments.collections' },
  { key: 'invoices', path: '/invoices', icon: Receipt, labelKey: 'nav.invoices', permission: 'invoices.view' },
  { key: 'reports', path: '/reports', icon: BarChart3, labelKey: 'nav.reports', permission: 'reports.view' },
  { key: 'users', path: '/users', icon: UserCog, labelKey: 'nav.users', permission: 'users.view' },
  { key: 'branches', path: '/branches', icon: Building2, labelKey: 'nav.branches', permission: 'branches.view' },
  { key: 'settings', path: '/settings', icon: Settings, labelKey: 'nav.settings', permission: 'settings.view' },
];

export default function Sidebar({ collapsed, onToggle }) {
  const { user, logout, hasPermission } = useAuth();
  const { t, isRTL } = useLang();
  const navigate = useNavigate();

  const visibleNav = navItems.filter((item) => {
    if (item.permission == null) return true;
    return hasPermission(item.permission);
  });

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  const CollapseIcon = isRTL
    ? (collapsed ? ChevronLeft : ChevronRight)
    : (collapsed ? ChevronRight : ChevronLeft);

  return (
    <aside
      className={clsx(
        'fixed top-0 bottom-0 z-30 flex flex-col bg-white border-e border-gray-200 transition-all duration-300',
        isRTL ? 'right-0' : 'left-0',
        collapsed ? 'w-16' : 'w-64'
      )}
    >
      {/* Logo */}
      <div className="flex items-center gap-3 px-4 h-16 border-b border-gray-100 flex-shrink-0">
        <div className="w-8 h-8 rounded-lg bg-primary-600 flex items-center justify-center flex-shrink-0">
          <span className="text-white font-bold text-sm">CP</span>
        </div>
        {!collapsed && (
          <div className="overflow-hidden">
            <p className="text-sm font-semibold text-gray-900 truncate">{t('auth.systemName')}</p>
            <p className="text-xs text-gray-500 truncate">{user?.tenant?.name}</p>
          </div>
        )}
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-2 py-4 overflow-y-auto space-y-0.5">
        {visibleNav.map((item) => (
          <NavLink
            key={item.key}
            to={item.path}
            end={item.exact}
            title={collapsed ? t(item.labelKey) : undefined}
            className={({ isActive }) =>
              clsx(
                'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 cursor-pointer',
                isActive
                  ? 'bg-primary-50 text-primary-700'
                  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                collapsed && 'justify-center'
              )
            }
          >
            <item.icon size={18} className="flex-shrink-0" />
            {!collapsed && <span>{t(item.labelKey)}</span>}
          </NavLink>
        ))}
      </nav>

      {/* User + Collapse */}
      <div className="border-t border-gray-100 p-2 space-y-1">
        <button
          onClick={handleLogout}
          className={clsx(
            'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-600 hover:bg-red-50 transition-all',
            collapsed && 'justify-center'
          )}
          title={collapsed ? t('nav.logout') : undefined}
        >
          <LogOut size={18} className="flex-shrink-0" />
          {!collapsed && <span>{t('nav.logout')}</span>}
        </button>

        <button
          onClick={onToggle}
          className={clsx(
            'w-full flex items-center gap-3 px-3 py-2 rounded-lg text-xs text-gray-400 hover:bg-gray-50 transition-all',
            collapsed && 'justify-center'
          )}
        >
          <CollapseIcon size={16} />
          {!collapsed && <span className="text-gray-400">{t('ui.collapseSidebar')}</span>}
        </button>
      </div>
    </aside>
  );
}
