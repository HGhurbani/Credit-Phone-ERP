import { ar } from './ar';
import { en } from './en';

export const translations = { ar, en };

export function t(lang, path, params = {}) {
  const keys = path.split('.');
  let value = translations[lang] || translations.ar;
  
  for (const key of keys) {
    value = value?.[key];
    if (value === undefined) return path;
  }

  if (typeof value === 'string' && params) {
    return value.replace(/\{\{(\w+)\}\}/g, (_, key) => params[key] ?? key);
  }

  return value ?? path;
}
