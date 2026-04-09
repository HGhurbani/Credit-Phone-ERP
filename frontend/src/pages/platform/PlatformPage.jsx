import { Navigate, useParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import SuperAdminDashboardPage from '../dashboard/SuperAdminDashboardPage';

const allowedSections = new Set(['overview', 'tenants', 'plans', 'subscriptions']);

export default function PlatformPage() {
  const { user } = useAuth();
  const { section } = useParams();

  if (!user?.is_super_admin) {
    return <Navigate to="/" replace />;
  }

  const active = section || 'overview';
  if (!allowedSections.has(active)) {
    return <Navigate to="/platform/overview" replace />;
  }

  return <SuperAdminDashboardPage activeTab={active} showTabs={false} />;
}

