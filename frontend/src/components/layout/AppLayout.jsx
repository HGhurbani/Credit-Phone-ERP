import { useEffect, useState } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { useLang } from '../../context/LangContext';
import { clsx } from 'clsx';

export default function AppLayout() {
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [isMobile, setIsMobile] = useState(false);
  const { isRTL } = useLang();

  const sidebarWidth = collapsed ? '4rem' : '16rem';

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 768px)');
    const handleChange = () => {
      const mobile = mq.matches;
      setIsMobile(mobile);
      if (!mobile) setMobileOpen(false);
    };

    handleChange();
    mq.addEventListener?.('change', handleChange);
    return () => mq.removeEventListener?.('change', handleChange);
  }, []);

  return (
    <div className={clsx('min-h-screen bg-gray-50', mobileOpen && 'overflow-hidden')}>
      <Sidebar
        collapsed={collapsed}
        onToggle={() => setCollapsed(c => !c)}
        isMobile={isMobile}
        mobileOpen={mobileOpen}
        onMobileClose={() => setMobileOpen(false)}
      />
      <Topbar
        sidebarCollapsed={collapsed}
        isMobile={isMobile}
        onMenuClick={() => setMobileOpen(true)}
      />
      <main
        className="layout-main pt-16 min-h-screen transition-all duration-300 print:!m-0 print:!p-4 print:min-h-0"
        style={isMobile ? undefined : { [isRTL ? 'marginRight' : 'marginLeft']: sidebarWidth }}
      >
        <div className="p-4 sm:p-6">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
