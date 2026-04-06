import { clsx } from 'clsx';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useLang } from '../../context/LangContext';

export function DataTable({ columns, data, loading, emptyMessage }) {
  const { t } = useLang();

  if (loading) {
    return (
      <div className="flex items-center justify-center h-40">
        <div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <div className="table-wrapper">
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key} style={col.width ? { width: col.width } : {}}>
                {col.title}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="text-center py-12 text-gray-400">
                {emptyMessage || t('common.noData')}
              </td>
            </tr>
          ) : (
            data.map((row, idx) => (
              <tr key={row.id ?? idx}>
                {columns.map((col) => (
                  <td key={col.key}>
                    {col.render ? col.render(row) : row[col.key] ?? '—'}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

export function Pagination({ meta, onPageChange }) {
  const { t, isRTL } = useLang();
  if (!meta || meta.last_page <= 1) return null;

  const PrevIcon = isRTL ? ChevronRight : ChevronLeft;
  const NextIcon = isRTL ? ChevronLeft : ChevronRight;

  return (
    <div className="flex items-center justify-between mt-4 px-1">
      <p className="text-sm text-gray-500">
        {t('common.showing')} {((meta.current_page - 1) * meta.per_page) + 1}-{Math.min(meta.current_page * meta.per_page, meta.total)} {t('common.of')} {meta.total}
      </p>
      <div className="flex items-center gap-1">
        <button
          onClick={() => onPageChange(meta.current_page - 1)}
          disabled={meta.current_page === 1}
          className="p-1.5 rounded-lg hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <PrevIcon size={16} />
        </button>
        {Array.from({ length: Math.min(5, meta.last_page) }, (_, i) => {
          let page;
          if (meta.last_page <= 5) {
            page = i + 1;
          } else if (meta.current_page <= 3) {
            page = i + 1;
          } else if (meta.current_page >= meta.last_page - 2) {
            page = meta.last_page - 4 + i;
          } else {
            page = meta.current_page - 2 + i;
          }
          return (
            <button
              key={page}
              onClick={() => onPageChange(page)}
              className={clsx(
                'w-8 h-8 rounded-lg text-sm font-medium transition-colors',
                meta.current_page === page
                  ? 'bg-primary-600 text-white'
                  : 'hover:bg-gray-100 text-gray-600'
              )}
            >
              {page}
            </button>
          );
        })}
        <button
          onClick={() => onPageChange(meta.current_page + 1)}
          disabled={meta.current_page === meta.last_page}
          className="p-1.5 rounded-lg hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <NextIcon size={16} />
        </button>
      </div>
    </div>
  );
}
