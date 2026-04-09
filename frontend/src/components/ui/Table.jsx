import { useEffect, useMemo, useState } from 'react';
import { clsx } from 'clsx';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useLang } from '../../context/LangContext';

export const PAGE_SIZE_OPTIONS = [10, 25, 50, 100, 'all'];

export function getPerPageRequestValue(pageSize, total, fallback = 100000) {
  if (pageSize === 'all') return Math.max(Number(total) || 0, fallback);
  const numeric = Number(pageSize);
  return Number.isFinite(numeric) && numeric > 0 ? numeric : 10;
}

function resolvePerPage(pageSize, total) {
  if (pageSize === 'all') return Math.max(total, 1);
  const numeric = Number(pageSize);
  return Number.isFinite(numeric) && numeric > 0 ? numeric : 10;
}

export function useLocalPagination(rows, initialPageSize = 10) {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(initialPageSize);

  useEffect(() => {
    setPage(1);
  }, [rows]);

  const total = rows.length;
  const perPage = resolvePerPage(pageSize, total);
  const lastPage = Math.max(1, Math.ceil(total / perPage));

  useEffect(() => {
    if (page > lastPage) setPage(lastPage);
  }, [page, lastPage]);

  const paginatedRows = useMemo(() => {
    const start = (page - 1) * perPage;
    return rows.slice(start, start + perPage);
  }, [rows, page, perPage]);

  return {
    page,
    setPage,
    pageSize,
    setPageSize,
    perPage,
    lastPage,
    total,
    rows: paginatedRows,
  };
}

function RowsPerPageSelect({ value, onChange, options, t }) {
  if (typeof onChange !== 'function') return null;

  return (
    <label className="flex items-center gap-2 text-sm text-gray-500">
      <span>{t('common.rowsPerPage')}</span>
      <select
        value={String(value)}
        onChange={(e) => onChange(e.target.value === 'all' ? 'all' : Number(e.target.value))}
        className="input w-auto min-w-[96px] py-1.5"
      >
        {options.map((option) => (
          <option key={option} value={option}>
            {option === 'all' ? t('common.all') : option}
          </option>
        ))}
      </select>
    </label>
  );
}

export function DataTable({
  columns,
  data,
  loading,
  emptyMessage,
  paginate = false,
  initialPageSize = 10,
  pageSizeOptions = PAGE_SIZE_OPTIONS,
}) {
  const { t } = useLang();
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(initialPageSize);

  useEffect(() => {
    setPage(1);
  }, [data]);

  const total = data.length;
  const perPage = resolvePerPage(pageSize, total);
  const lastPage = Math.max(1, Math.ceil(total / perPage));

  useEffect(() => {
    if (page > lastPage) setPage(lastPage);
  }, [page, lastPage]);

  const visibleData = useMemo(() => {
    if (!paginate) return data;
    const start = (page - 1) * perPage;
    return data.slice(start, start + perPage);
  }, [data, paginate, page, perPage]);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-40">
        <div className="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <>
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
            {visibleData.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="text-center py-12 text-gray-400">
                  {emptyMessage || t('common.noData')}
                </td>
              </tr>
            ) : (
              visibleData.map((row, idx) => (
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
      {paginate && (
        <Pagination
          total={total}
          currentPage={page}
          lastPage={lastPage}
          perPage={perPage}
          pageSize={pageSize}
          onPageChange={setPage}
          onPageSizeChange={(nextPageSize) => {
            setPageSize(nextPageSize);
            setPage(1);
          }}
          pageSizeOptions={pageSizeOptions}
        />
      )}
    </>
  );
}

export function Pagination({
  meta,
  onPageChange,
  pageSize,
  onPageSizeChange,
  total,
  currentPage,
  lastPage,
  perPage,
  pageSizeOptions = PAGE_SIZE_OPTIONS,
}) {
  const { t, isRTL } = useLang();

  const resolvedTotal = meta?.total ?? total ?? 0;
  const resolvedPageSize = pageSize ?? meta?.per_page ?? perPage ?? 10;
  const resolvedPerPage = meta?.per_page ?? perPage ?? resolvePerPage(resolvedPageSize, resolvedTotal);
  const resolvedCurrentPage = meta?.current_page ?? currentPage ?? 1;
  const resolvedLastPage = meta?.last_page ?? lastPage ?? Math.max(1, Math.ceil(resolvedTotal / resolvedPerPage));
  const showSizeSelect = typeof onPageSizeChange === 'function' && resolvedTotal > 0;
  const showPager = resolvedLastPage > 1;

  if (!showSizeSelect && !showPager) return null;

  const PrevIcon = isRTL ? ChevronRight : ChevronLeft;
  const NextIcon = isRTL ? ChevronLeft : ChevronRight;
  const startItem = resolvedTotal === 0 ? 0 : ((resolvedCurrentPage - 1) * resolvedPerPage) + 1;
  const endItem = resolvedTotal === 0 ? 0 : Math.min(resolvedCurrentPage * resolvedPerPage, resolvedTotal);

  return (
    <div className="flex flex-col gap-3 mt-4 px-1 md:flex-row md:items-center md:justify-between">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
        <p className="text-sm text-gray-500">
          {t('common.showing')} {startItem}-{endItem} {t('common.of')} {resolvedTotal}
        </p>
        <RowsPerPageSelect value={resolvedPageSize} onChange={onPageSizeChange} options={pageSizeOptions} t={t} />
      </div>
      {showPager && (
        <div className="flex items-center gap-1">
          <button
            onClick={() => onPageChange(resolvedCurrentPage - 1)}
            disabled={resolvedCurrentPage === 1}
            className="p-1.5 rounded-lg hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            <PrevIcon size={16} />
          </button>
          {Array.from({ length: Math.min(5, resolvedLastPage) }, (_, i) => {
            let page;
            if (resolvedLastPage <= 5) {
              page = i + 1;
            } else if (resolvedCurrentPage <= 3) {
              page = i + 1;
            } else if (resolvedCurrentPage >= resolvedLastPage - 2) {
              page = resolvedLastPage - 4 + i;
            } else {
              page = resolvedCurrentPage - 2 + i;
            }
            return (
              <button
                key={page}
                onClick={() => onPageChange(page)}
                className={clsx(
                  'w-8 h-8 rounded-lg text-sm font-medium transition-colors',
                  resolvedCurrentPage === page
                    ? 'bg-primary-600 text-white'
                    : 'hover:bg-gray-100 text-gray-600'
                )}
              >
                {page}
              </button>
            );
          })}
          <button
            onClick={() => onPageChange(resolvedCurrentPage + 1)}
            disabled={resolvedCurrentPage === resolvedLastPage}
            className="p-1.5 rounded-lg hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            <NextIcon size={16} />
          </button>
        </div>
      )}
    </div>
  );
}
