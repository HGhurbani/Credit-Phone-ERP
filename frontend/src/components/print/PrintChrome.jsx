import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Download, Printer } from 'lucide-react';
import html2pdf from 'html2pdf.js';
import { settingsApi } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { useLang } from '../../context/LangContext';

/** Resolve tenant logo URL for <img src> (absolute or app-relative). */
export function resolveLogoUrl(logo) {
  if (!logo || typeof logo !== 'string') return null;
  if (logo.startsWith('http://') || logo.startsWith('https://')) return logo;
  const base = window.location.origin.replace(/\/$/, '');
  const path = logo.startsWith('/') ? logo : `/${logo}`;
  return `${base}${path}`;
}

/**
 * A4-oriented shell for dedicated print routes (outside AppLayout).
 * Loads company profile from settings + tenant name/logo from auth user.
 */
export default function PrintChrome({
  documentTitle,
  subtitle,
  fallbackPath = '/',
  children,
  footerText,
  hideFooter = false,
  showLogo: showLogoProp,
}) {
  const { t } = useLang();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [settings, setSettings] = useState({});
  const [downloadingPdf, setDownloadingPdf] = useState(false);
  const articleRef = useRef(null);
  const autoPdfTriggered = useRef(false);

  useEffect(() => {
    settingsApi.get().then((r) => setSettings(r.data.data || {})).catch(() => {});
  }, []);

  const companyName = settings.company_name || user?.tenant?.name || '';
  const fromSettings = settings.show_logo_on_invoice === 'true' || settings.show_logo_on_invoice === true;
  const showLogo = showLogoProp !== undefined ? showLogoProp : fromSettings;
  const logoUrl = resolveLogoUrl(user?.tenant?.logo);
  const footer = hideFooter ? null : (footerText ?? settings.invoice_footer ?? '');
  const legalLines = [
    settings.company_cr_number
      ? `${t('settings.fields.company_cr_number')}: ${settings.company_cr_number}`
      : null,
    settings.company_license_number
      ? `${t('settings.fields.company_license_number')}: ${settings.company_license_number}`
      : null,
    settings.company_tax_card_number
      ? `${t('settings.fields.company_tax_card_number')}: ${settings.company_tax_card_number}`
      : null,
  ].filter(Boolean);
  const searchParams = new URLSearchParams(window.location.search);
  const shouldAutoPdf = searchParams.get('autopdf') === '1';
  const pdfFilename = searchParams.get('filename') || `${documentTitle || 'document'}.pdf`;

  const handleDownloadPdf = async () => {
    if (!articleRef.current || downloadingPdf) {
      return;
    }

    setDownloadingPdf(true);
    try {
      const element = articleRef.current;
      await html2pdf()
        .set({
          filename: pdfFilename,
          margin: [8, 8, 8, 8],
          image: { type: 'jpeg', quality: 0.98 },
          html2canvas: {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff',
          },
          jsPDF: {
            unit: 'mm',
            format: 'a4',
            orientation: 'portrait',
          },
          pagebreak: {
            mode: ['css', 'legacy'],
          },
        })
        .from(element)
        .save();
    } finally {
      setDownloadingPdf(false);
    }
  };

  useEffect(() => {
    if (!shouldAutoPdf || autoPdfTriggered.current || !articleRef.current) {
      return;
    }

    autoPdfTriggered.current = true;
    const timer = window.setTimeout(() => {
      handleDownloadPdf().catch(() => {});
    }, 350);

    return () => window.clearTimeout(timer);
  }, [shouldAutoPdf, pdfFilename, documentTitle]);

  return (
    <div className="min-h-screen bg-gray-100 print:bg-white print:min-h-0 print-doc-outer">
      <div className="no-print max-w-[210mm] mx-auto px-4 pt-4 flex flex-wrap gap-2 justify-end print:hidden">
        <button type="button" className="btn-secondary btn btn-sm" onClick={() => navigate(fallbackPath)}>
          {t('common.back')}
        </button>
        <button type="button" className="btn btn-sm" onClick={handleDownloadPdf} disabled={downloadingPdf}>
          <Download size={14} /> {downloadingPdf ? t('common.loading') : t('assistant.downloadPdf')}
        </button>
        <button type="button" className="btn-primary btn btn-sm" onClick={() => window.print()}>
          <Printer size={14} /> {t('common.print')}
        </button>
      </div>

      <article ref={articleRef} className="print-doc mx-auto bg-white shadow print:shadow-none border border-gray-200 print:border-0 px-8 py-10 mb-8 max-w-[210mm] text-gray-900 [dir=rtl]:text-right">
        <header className="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4 border-b border-gray-200 pb-5 mb-6 print:pb-4 print:mb-4">
          <div className="min-w-0 flex-1">
            {showLogo && logoUrl && (
              <img src={logoUrl} alt="" className="h-11 w-auto max-w-[200px] object-contain object-left [dir=rtl]:object-right mb-2" />
            )}
            <h1 className="text-base font-bold text-gray-900 leading-tight">{companyName || '—'}</h1>
            <div className="text-[11px] text-gray-600 space-y-0.5 mt-1.5 leading-relaxed">
              {settings.company_phone && <p>{settings.company_phone}</p>}
              {settings.company_email && <p>{settings.company_email}</p>}
              {settings.company_address && <p className="whitespace-pre-wrap">{settings.company_address}</p>}
              {legalLines.map((line) => (
                <p key={line}>{line}</p>
              ))}
            </div>
          </div>
          <div className="text-start sm:text-end shrink-0 [dir=rtl]:text-start [dir=rtl]:sm:text-start sm:[dir=rtl]:text-end">
            <p className="text-sm font-semibold text-gray-800 uppercase tracking-wide">{documentTitle}</p>
            {subtitle && <p className="text-xs font-mono text-gray-600 mt-1">{subtitle}</p>}
          </div>
        </header>

        <div className="print-body text-sm">{children}</div>

        {footer && (
          <footer className="mt-10 pt-4 border-t border-gray-100 text-center text-[10px] text-gray-500 whitespace-pre-wrap print:mt-8">
            {footer}
          </footer>
        )}
      </article>
    </div>
  );
}
