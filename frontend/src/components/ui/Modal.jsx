import { useEffect } from 'react';
import { X } from 'lucide-react';
import { clsx } from 'clsx';
import { useLang } from '../../context/LangContext';

export default function Modal({ open, onClose, title, children, size = 'md', footer }) {
  useEffect(() => {
    if (open) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => { document.body.style.overflow = ''; };
  }, [open]);

  if (!open) return null;

  const sizes = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
    full: 'max-w-6xl',
  };

  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose?.()}>
      <div className={clsx('modal w-full', sizes[size])}>
        <div className="modal-header">
          <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-colors"
          >
            <X size={18} />
          </button>
        </div>
        <div className="modal-body">
          {children}
        </div>
        {footer && (
          <div className="modal-footer">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}

export function ConfirmModal({ open, onClose, onConfirm, title, message, confirmText, confirmClass = 'btn-danger', loading }) {
  const { t } = useLang();
  const confirmLabel = confirmText ?? t('ui.confirm');
  return (
    <Modal open={open} onClose={onClose} title={title} size="sm"
      footer={
        <>
          <button type="button" onClick={onClose} className="btn-secondary btn">{t('ui.cancel')}</button>
          <button type="button" onClick={onConfirm} disabled={loading} className={clsx('btn', confirmClass)}>
            {loading ? '...' : confirmLabel}
          </button>
        </>
      }
    >
      <p className="text-gray-600">{message}</p>
    </Modal>
  );
}
