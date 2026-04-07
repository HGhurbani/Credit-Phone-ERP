import { Bell, Globe, User } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { useLang } from '../../context/LangContext';

export default function Topbar({ sidebarCollapsed }) {
  const { user } = useAuth();
  const { lang, toggleLang, t, isRTL } = useLang();

  const sidebarWidth = sidebarCollapsed ? '4rem' : '16rem';

  return (
    <header
      className="fixed top-0 right-0 left-0 h-16 bg-white border-b border-gray-200 z-20 flex items-center px-6 print:hidden"
      style={{
        [isRTL ? 'marginRight' : 'marginLeft']: sidebarWidth,
        transition: 'margin 300ms',
      }}
    >
      <div className="flex-1" />

      <div className="flex items-center gap-3">
        {/* Language Toggle */}
        <button
          onClick={toggleLang}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
          title={t('ui.switchLanguage')}
        >
          <Globe size={16} />
          <span>{lang === 'ar' ? t('ui.english') : t('ui.arabic')}</span>
        </button>

        {/* Notifications placeholder */}
        <button className="relative p-2 rounded-lg text-gray-500 hover:bg-gray-100 transition-colors">
          <Bell size={18} />
        </button>

        {/* User Info */}
        <div className="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
          <div className="w-8 h-8 bg-primary-100 rounded-full flex items-center justify-center">
            {user?.avatar ? (
              <img src={user.avatar} alt={user.name} className="w-8 h-8 rounded-full object-cover" />
            ) : (
              <User size={16} className="text-primary-600" />
            )}
          </div>
          <div className="hidden sm:block">
            <p className="text-sm font-medium text-gray-900 leading-tight">{user?.name}</p>
            <p className="text-xs text-gray-500 leading-tight">
              {user?.roles?.[0] ? t(`users.roles.${user.roles[0]}`) : ''}
            </p>
          </div>
        </div>
      </div>
    </header>
  );
}
