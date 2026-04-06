import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { useLang } from '../../context/LangContext';

export default function AppLayout() {
  const [collapsed, setCollapsed] = useState(false);
  const { isRTL } = useLang();

  const sidebarWidth = collapsed ? '4rem' : '16rem';

  return (
    <div className="min-h-screen bg-gray-50">
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed(c => !c)} />
      <Topbar sidebarCollapsed={collapsed} />
      <main
        className="pt-16 min-h-screen transition-all duration-300"
        style={{ [isRTL ? 'marginRight' : 'marginLeft']: sidebarWidth }}
      >
        <div className="p-6">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
