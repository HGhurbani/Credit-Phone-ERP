import { Search, X } from 'lucide-react';
import { useState } from 'react';
import { useLang } from '../../context/LangContext';

export default function SearchInput({ value, onChange, placeholder, className = '' }) {
  const { t } = useLang();

  return (
    <div className={`relative ${className}`}>
      <div className="absolute inset-y-0 start-3 flex items-center pointer-events-none">
        <Search size={16} className="text-gray-400" />
      </div>
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder || t('common.search')}
        className="input ps-9 pe-9"
      />
      {value && (
        <button
          onClick={() => onChange('')}
          className="absolute inset-y-0 end-3 flex items-center text-gray-400 hover:text-gray-600"
        >
          <X size={14} />
        </button>
      )}
    </div>
  );
}
