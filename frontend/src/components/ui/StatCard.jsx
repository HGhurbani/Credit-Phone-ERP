import { clsx } from 'clsx';

export default function StatCard({ icon: Icon, label, value, color = 'blue', trend, suffix }) {
  const colors = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-green-50 text-green-600',
    red: 'bg-red-50 text-red-600',
    yellow: 'bg-yellow-50 text-yellow-600',
    purple: 'bg-purple-50 text-purple-600',
  };

  return (
    <div className="stat-card">
      <div className={clsx('stat-icon', colors[color])}>
        <Icon size={22} />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm text-gray-500 truncate">{label}</p>
        <p className="text-2xl font-bold text-gray-900 mt-0.5">
          {value !== null && value !== undefined ? value : '—'}
          {suffix && <span className="text-base font-normal text-gray-500 ms-1">{suffix}</span>}
        </p>
        {trend !== undefined && (
          <p className={clsx('text-xs mt-1', trend >= 0 ? 'text-green-600' : 'text-red-600')}>
            {trend >= 0 ? '↑' : '↓'} {Math.abs(trend)}%
          </p>
        )}
      </div>
    </div>
  );
}
