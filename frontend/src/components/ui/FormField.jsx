import { clsx } from 'clsx';

export function FormField({ label, error, required, children, className }) {
  return (
    <div className={clsx('form-group', className)}>
      {label && (
        <label className="label">
          {label}
          {required && <span className="text-red-500 ms-1">*</span>}
        </label>
      )}
      {children}
      {error && <p className="error-text">{error}</p>}
    </div>
  );
}

export function Input({ error, ...props }) {
  return (
    <input
      className={clsx('input', error && 'input-error')}
      {...props}
    />
  );
}

export function Select({ error, children, ...props }) {
  return (
    <select
      className={clsx('input', error && 'input-error')}
      {...props}
    >
      {children}
    </select>
  );
}

export function Textarea({ error, ...props }) {
  return (
    <textarea
      className={clsx('input', error && 'input-error')}
      rows={3}
      {...props}
    />
  );
}
