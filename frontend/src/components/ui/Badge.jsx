import { clsx } from 'clsx';
import {
  orderStatusVariant,
  contractStatusVariant,
  scheduleStatusVariant,
  invoiceStatusVariant,
  orderStatusLabelKey,
  contractStatusLabelKey,
  scheduleStatusLabelKey,
  invoiceStatusLabelKey,
} from '../../i18n/statusLabels';

const variants = {
  green: 'badge-green',
  red: 'badge-red',
  yellow: 'badge-yellow',
  blue: 'badge-blue',
  gray: 'badge-gray',
  purple: 'badge-purple',
};

export default function Badge({ label, variant = 'gray' }) {
  return (
    <span className={clsx('badge', variants[variant] || 'badge-gray')}>
      {label}
    </span>
  );
}

/** للاستخدام مع t(labelKey) — يعيد variant ومسار الترجمة */
export const orderStatusBadge = (status) => ({
  variant: orderStatusVariant(status),
  labelKey: orderStatusLabelKey(status),
});

export const contractStatusBadge = (status) => ({
  variant: contractStatusVariant(status),
  labelKey: contractStatusLabelKey(status),
});

export const scheduleStatusBadge = (status) => ({
  variant: scheduleStatusVariant(status),
  labelKey: scheduleStatusLabelKey(status),
});

export const invoiceStatusBadge = (status) => ({
  variant: invoiceStatusVariant(status),
  labelKey: invoiceStatusLabelKey(status),
});
