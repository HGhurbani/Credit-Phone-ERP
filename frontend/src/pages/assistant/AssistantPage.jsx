import { useEffect, useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { Download, ExternalLink, FileText } from 'lucide-react';
import { assistantApi } from '../../api/client';
import { useLang } from '../../context/LangContext';
import { useAuth } from '../../context/AuthContext';

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function humanizeKey(key) {
  if (!key) return '';

  return String(key)
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatValue(value) {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (Array.isArray(value)) return `${value.length} item${value.length === 1 ? '' : 's'}`;
  if (isPlainObject(value)) {
    return value.name || value.label || value.title || value.summary || `#${value.id ?? '...'}`;
  }

  return String(value);
}

function buildPreviewEntries(source, options = {}) {
  if (!isPlainObject(source)) return [];

  const { exclude = [], limit = 8 } = options;

  return Object.entries(source)
    .filter(([key, value]) => !exclude.includes(key) && value !== null && value !== undefined && value !== '' && !Array.isArray(value) && !isPlainObject(value))
    .slice(0, limit)
    .map(([key, value]) => ({
      label: humanizeKey(key),
      value: formatValue(value),
    }));
}

function summarizeListItem(item) {
  if (!isPlainObject(item)) {
    return {
      title: formatValue(item),
      meta: '',
    };
  }

  const title = item.customer || item.name || item.contract_number || item.label || item.title || item.code || `#${item.id ?? '...'}`;
  const meta = [
    item.branch,
    item.status,
    item.phone,
    item.due_date,
    item.remaining_amount !== undefined ? `Remaining: ${item.remaining_amount}` : null,
    item.amount !== undefined ? `Amount: ${item.amount}` : null,
    item.risk_score !== undefined ? `Risk: ${item.risk_score}` : null,
    item.recommended_action ? humanizeKey(item.recommended_action) : null,
  ].filter(Boolean).join(' • ');

  return { title, meta };
}

function pickTopItems(data) {
  if (!isPlainObject(data)) return [];

  if (Array.isArray(data.items) && data.items.length) return data.items;
  if (Array.isArray(data.matches) && data.matches.length) return data.matches;

  const snapshot = data.statement_snapshot;
  if (isPlainObject(snapshot)) {
    if (Array.isArray(snapshot.overdue_installments) && snapshot.overdue_installments.length) return snapshot.overdue_installments;
    if (Array.isArray(snapshot.active_contracts) && snapshot.active_contracts.length) return snapshot.active_contracts;
    if (Array.isArray(snapshot.active_promises_to_pay) && snapshot.active_promises_to_pay.length) return snapshot.active_promises_to_pay;
    if (Array.isArray(snapshot.pending_reschedule_requests) && snapshot.pending_reschedule_requests.length) return snapshot.pending_reschedule_requests;
  }

  return [];
}

function JsonBlock({ value }) {
  if (!value) return null;

  return (
    <pre className="mt-2 overflow-x-auto rounded-lg bg-slate-950/95 p-3 text-xs text-slate-100">
      {JSON.stringify(value, null, 2)}
    </pre>
  );
}

function StatusBadge({ status }) {
  const styles = {
    completed: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    error: 'bg-rose-50 text-rose-700 border-rose-200',
    rejected: 'bg-rose-50 text-rose-700 border-rose-200',
    pending_confirmation: 'bg-amber-50 text-amber-700 border-amber-200',
    needs_clarification: 'bg-amber-50 text-amber-700 border-amber-200',
  };

  return (
    <span className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold ${styles[status] || 'bg-slate-100 text-slate-700 border-slate-200'}`}>
      {humanizeKey(status || 'unknown')}
    </span>
  );
}

function PreviewGrid({ entries }) {
  if (!entries.length) return null;

  return (
    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
      {entries.map((entry) => (
        <div key={`${entry.label}-${entry.value}`} className="rounded-xl border border-slate-200 bg-white p-3">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{entry.label}</div>
          <div className="mt-1 text-sm font-medium text-slate-900">{entry.value}</div>
        </div>
      ))}
    </div>
  );
}

function CompactList({ title, items, emptyLabel, limit = 4 }) {
  const visibleItems = (items || []).slice(0, limit);
  if (!visibleItems.length) return null;

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3">
      <div className="flex items-center justify-between gap-3">
        <div className="text-sm font-semibold text-slate-900">{title}</div>
        {items.length > limit && (
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">
            +{items.length - limit}
          </span>
        )}
      </div>
      <div className="mt-3 space-y-2">
        {visibleItems.map((item, index) => {
          const summary = summarizeListItem(item);

          return (
            <div key={`${summary.title}-${index}`} className="rounded-lg bg-slate-50 px-3 py-2">
              <div className="text-sm font-medium text-slate-900">{summary.title || emptyLabel}</div>
              {summary.meta && (
                <div className="mt-0.5 text-xs text-slate-500">{summary.meta}</div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

function ActionSummaryCard({ action, t }) {
  if (!action) return null;

  const primaryEntries = [
    { label: t('assistant.moduleLabel'), value: formatValue(action.module) },
    { label: t('assistant.operationLabel'), value: formatValue(action.operation) },
    { label: t('assistant.targetLabel'), value: formatValue(action.target) },
  ].filter((entry) => entry.value !== '—');

  const argumentEntries = buildPreviewEntries(action.arguments, {
    exclude: ['notes', 'description', 'resource'],
    limit: 6,
  });

  return (
    <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="text-sm font-semibold text-slate-900">{t('assistant.planSummary')}</div>
          <p className="mt-1 text-sm text-slate-600">{t('assistant.planSummaryHint')}</p>
        </div>
        <StatusBadge status={action.needs_clarification ? 'needs_clarification' : 'completed'} />
      </div>

      <PreviewGrid entries={primaryEntries} />

      {argumentEntries.length > 0 && (
        <div className="space-y-2">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('assistant.keyInputs')}</div>
          <PreviewGrid entries={argumentEntries} />
        </div>
      )}

      {action.arguments?.resource && (
        <div className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800">
          {t('assistant.resourceLabel')}: {formatValue(action.arguments.resource)}
        </div>
      )}

      {action.clarification_question && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
          {action.clarification_question}
        </div>
      )}

      {action.requires_delete_confirmation && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
          {t('assistant.deleteConfirmationHint')}
        </div>
      )}

      <details className="rounded-xl border border-slate-200 bg-white p-3">
        <summary className="cursor-pointer text-sm font-medium text-slate-700">{t('assistant.rawJson')}</summary>
        <JsonBlock value={action} />
      </details>
    </div>
  );
}

function ExecutionSummaryCard({ result, t }) {
  if (!result) return null;

  const data = result.data || {};
  const summaryEntries = buildPreviewEntries(data.summary, { limit: 6 });
  const customerEntries = buildPreviewEntries(data.customer, { limit: 4 });
  const copilotEntries = buildPreviewEntries(data.copilot_summary, { limit: 4 });
  const recommendations = Array.isArray(data.recommendations) ? data.recommendations : [];
  const topItems = pickTopItems(data);
  const nextBestAction = data.next_best_action || data.clarification?.question || null;

  return (
    <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="text-sm font-semibold text-slate-900">{t('assistant.resultSummary')}</div>
          <p className="mt-1 text-sm text-slate-700">{result.summary || t('assistant.waiting')}</p>
        </div>
        <StatusBadge status={result.status} />
      </div>

      {(summaryEntries.length > 0 || customerEntries.length > 0 || copilotEntries.length > 0) && (
        <div className="space-y-2">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('assistant.mainDetails')}</div>
          <PreviewGrid entries={[...summaryEntries, ...customerEntries, ...copilotEntries]} />
        </div>
      )}

      {nextBestAction && (
        <div className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">{t('assistant.nextBestAction')}</div>
          <p className="mt-1 text-sm text-sky-900">{nextBestAction}</p>
        </div>
      )}

      {recommendations.length > 0 && (
        <div className="rounded-xl border border-slate-200 bg-white p-3">
          <div className="text-sm font-semibold text-slate-900">{t('assistant.recommendations')}</div>
          <div className="mt-3 space-y-2">
            {recommendations.slice(0, 4).map((item, index) => (
              <div key={`${item}-${index}`} className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                {item}
              </div>
            ))}
          </div>
        </div>
      )}

      <CompactList
        title={t('assistant.topItems')}
        items={topItems}
        emptyLabel={t('assistant.noItems')}
      />

      <details className="rounded-xl border border-slate-200 bg-white p-3">
        <summary className="cursor-pointer text-sm font-medium text-slate-700">{t('assistant.rawJson')}</summary>
        <JsonBlock value={result} />
      </details>
    </div>
  );
}

function PrintDocumentCard({ document, t }) {
  if (!document) return null;

  return (
    <div className="rounded-xl border border-sky-200 bg-sky-50 p-4">
      <div className="flex items-start gap-3">
        <div className="rounded-lg bg-white p-2 text-sky-700 shadow-sm">
          <FileText size={16} />
        </div>
        <div>
          <div className="text-sm font-semibold text-sky-900">{t('assistant.printReady')}</div>
          <p className="mt-1 text-xs text-sky-800">
            {document.filename || document.type}
          </p>
        </div>
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        {document.url && (
          <button type="button" className="btn btn-sm" onClick={() => window.open(document.url, '_blank', 'noopener,noreferrer')}>
            <ExternalLink size={14} /> {t('assistant.openDocument')}
          </button>
        )}
        {document.download_url && (
          <button type="button" className="btn btn-primary btn-sm" onClick={() => window.open(document.download_url, '_blank', 'noopener,noreferrer')}>
            <Download size={14} /> {t('assistant.downloadPdf')}
          </button>
        )}
      </div>
    </div>
  );
}

function ClarificationOptions({ clarification, onSelect, t }) {
  const options = clarification?.options || [];

  if (!options.length) return null;

  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50 p-3">
      <div className="text-xs font-semibold uppercase tracking-wide text-amber-700">{t('assistant.quickChoices')}</div>
      <div className="mt-3 flex flex-wrap gap-2">
        {options.map((option) => (
          <button
            key={`${option.number}-${option.value}`}
            type="button"
            className="rounded-full border border-amber-300 bg-white px-3 py-1.5 text-sm text-amber-900 transition hover:border-amber-400 hover:bg-amber-100"
            onClick={() => onSelect(String(option.number))}
          >
            {option.number}. {option.label}
          </button>
        ))}
      </div>
    </div>
  );
}

export default function AssistantPage() {
  const { t } = useLang();
  const { hasPermission } = useAuth();
  const [threads, setThreads] = useState([]);
  const [telegram, setTelegram] = useState({ linked: false });
  const [selectedThreadId, setSelectedThreadId] = useState(null);
  const [thread, setThread] = useState(null);
  const [message, setMessage] = useState('');
  const [linkCode, setLinkCode] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [confirmingId, setConfirmingId] = useState(null);

  const canUseAssistant = hasPermission('assistant.use');
  const canLinkTelegram = hasPermission('assistant.telegram.link');

  const selectedMessages = thread?.messages ?? [];
  const isNewConversation = selectedThreadId === null;

  const loadThreads = async (nextThreadId = null) => {
    const response = await assistantApi.threads();
    const nextThreads = response.data.data || [];
    setThreads(nextThreads);
    setTelegram(response.data.telegram || { linked: false });

    const targetId = nextThreadId ?? selectedThreadId ?? null;
    if (targetId) {
      setSelectedThreadId(targetId);
      const threadResponse = await assistantApi.thread(targetId);
      setThread(threadResponse.data.data);
    } else {
      setThread(null);
      setSelectedThreadId(null);
    }
  };

  useEffect(() => {
    if (!canUseAssistant) {
      setLoading(false);
      return;
    }

    loadThreads().finally(() => setLoading(false));
  }, [canUseAssistant]);

  const threadTitle = useMemo(() => {
    return thread?.title || t('assistant.emptyTitle');
  }, [thread?.title, t]);

  const handleStartNewConversation = () => {
    setSelectedThreadId(null);
    setThread(null);
    setMessage('');
  };

  const sendAssistantMessage = async (nextMessage) => {
    if (!nextMessage.trim()) return;
    setSending(true);
    try {
      const response = await assistantApi.sendMessage({
        message: nextMessage.trim(),
        thread_id: selectedThreadId,
      });

      const nextThreadId = response.data.data.thread?.id;
      setMessage('');
      await loadThreads(nextThreadId);
    } catch (error) {
      toast.error(error.response?.data?.message || t('common.error'));
    } finally {
      setSending(false);
    }
  };

  const handleSend = async (event) => {
    event.preventDefault();
    await sendAssistantMessage(message);
  };

  const handleConfirmDelete = async (messageId) => {
    setConfirmingId(messageId);
    try {
      await assistantApi.confirmDelete(messageId);
      await loadThreads(selectedThreadId);
    } catch (error) {
      toast.error(error.response?.data?.message || t('common.error'));
    } finally {
      setConfirmingId(null);
    }
  };

  const handleGenerateLinkCode = async () => {
    try {
      const response = await assistantApi.generateLinkCode();
      setLinkCode(response.data.data);
      toast.success(t('assistant.linkCodeReady'));
    } catch {
      toast.error(t('common.error'));
    }
  };

  const handleUnlinkTelegram = async () => {
    try {
      await assistantApi.unlinkTelegram();
      setLinkCode(null);
      await loadThreads(selectedThreadId);
      toast.success(t('assistant.telegramUnlinked'));
    } catch {
      toast.error(t('common.error'));
    }
  };

  if (!canUseAssistant) {
    return (
      <div className="card p-6 text-sm text-gray-600">
        {t('assistant.noAccess')}
      </div>
    );
  }

  if (loading) {
    return <div className="flex h-40 items-center justify-center"><div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-600 border-t-transparent" /></div>;
  }

  return (
    <div className="space-y-4">
      <div className="page-header">
        <h1 className="page-title">{t('assistant.title')}</h1>
        <p className="text-sm text-gray-500">{t('assistant.subtitle')}</p>
      </div>

      <div className="grid gap-4 lg:grid-cols-[280px,1fr]">
        <div className="space-y-4">
          <div className="card">
            <div className="card-header flex items-center justify-between gap-3">
              <h2 className="font-semibold text-gray-900">{t('assistant.threads')}</h2>
              <button
                type="button"
                onClick={handleStartNewConversation}
                className={`btn btn-sm ${isNewConversation ? 'btn-primary' : ''}`}
              >
                {t('assistant.newConversation')}
              </button>
            </div>
            <div className="card-body space-y-2">
              {threads.length === 0 && (
                <div className="rounded-lg border border-dashed border-gray-200 p-4 text-sm text-gray-500">
                  {t('assistant.noThreads')}
                </div>
              )}
              {threads.length > 0 && (
                <button
                  type="button"
                  onClick={handleStartNewConversation}
                  className={`w-full rounded-xl border border-dashed p-3 text-start transition ${isNewConversation ? 'border-primary-300 bg-primary-50' : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50'}`}
                >
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-gray-900">{t('assistant.newConversation')}</span>
                    <span className="rounded-full bg-white px-2 py-0.5 text-[11px] text-gray-600">{t('assistant.newRequest')}</span>
                  </div>
                  <p className="mt-1 text-xs text-gray-500">
                    {t('assistant.newConversationHint')}
                  </p>
                </button>
              )}
              {threads.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={async () => {
                    setSelectedThreadId(item.id);
                    const response = await assistantApi.thread(item.id);
                    setThread(response.data.data);
                  }}
                  className={`w-full rounded-xl border p-3 text-start transition ${selectedThreadId === item.id ? 'border-primary-300 bg-primary-50' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'}`}
                >
                  <div className="flex items-center justify-between gap-3">
                    <span className="truncate font-medium text-gray-900">{item.title || t('assistant.emptyTitle')}</span>
                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">{item.channel}</span>
                  </div>
                  <p className="mt-1 line-clamp-2 text-xs text-gray-500">
                    {item.latest_message?.assistant_message || item.latest_message?.user_message || t('assistant.noMessages')}
                  </p>
                </button>
              ))}
            </div>
          </div>

          {canLinkTelegram && (
            <div className="card">
              <div className="card-header">
                <h2 className="font-semibold text-gray-900">{t('assistant.telegramCard')}</h2>
              </div>
              <div className="card-body space-y-3 text-sm">
                <div className={`rounded-lg px-3 py-2 ${telegram.linked ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                  {telegram.linked ? t('assistant.telegramLinked') : t('assistant.telegramNotLinked')}
                </div>
                {telegram.username && (
                  <div className="text-gray-600">@{telegram.username}</div>
                )}
                <button type="button" className="btn btn-primary w-full" onClick={handleGenerateLinkCode}>
                  {t('assistant.generateLinkCode')}
                </button>
                {telegram.linked && (
                  <button type="button" className="btn w-full" onClick={handleUnlinkTelegram}>
                    {t('assistant.unlinkTelegram')}
                  </button>
                )}
                {linkCode && (
                  <div className="rounded-xl border border-gray-200 bg-gray-50 p-3">
                    <div className="text-xs text-gray-500">{t('assistant.linkCode')}</div>
                    <div className="mt-1 font-mono text-lg font-semibold tracking-[0.2em] text-gray-900">{linkCode.code}</div>
                    <p className="mt-2 text-xs text-gray-500">
                      {t('assistant.linkCodeHint')}
                    </p>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>

        <div className="card flex min-h-[620px] flex-col">
          <div className="card-header flex items-center justify-between">
            <div>
              <h2 className="font-semibold text-gray-900">{threadTitle}</h2>
              <p className="text-xs text-gray-500">{t('assistant.executionTimeline')}</p>
            </div>
          </div>

          <div className="card-body flex-1 space-y-4 overflow-y-auto bg-slate-50/60">
            <div className={`rounded-xl border px-4 py-3 ${isNewConversation ? 'border-sky-200 bg-sky-50' : 'border-primary-200 bg-primary-50'}`}>
              <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {isNewConversation ? t('assistant.newRequest') : t('assistant.currentConversation')}
              </div>
              <div className="mt-1 font-medium text-gray-900">
                {isNewConversation ? t('assistant.emptyTitle') : threadTitle}
              </div>
              <p className="mt-1 text-sm text-gray-600">
                {isNewConversation ? t('assistant.newConversationHint') : t('assistant.continueConversationHint')}
              </p>
            </div>

            {selectedMessages.length === 0 && (
              <div className="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-500">
                {t('assistant.emptyState')}
              </div>
            )}

            {selectedMessages.map((item) => (
              <div key={item.id} className="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div>
                  <div className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('assistant.userRequest')}</div>
                  <p className="mt-1 whitespace-pre-wrap break-words [overflow-wrap:anywhere] text-sm text-gray-800">{item.user_message}</p>
                </div>

                <div>
                  <div className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('assistant.assistantReply')}</div>
                  <p className="mt-1 whitespace-pre-wrap break-words [overflow-wrap:anywhere] text-sm text-gray-800">{item.assistant_message || t('assistant.waiting')}</p>
                </div>

                {item.execution_result?.data?.print_document && (
                  <PrintDocumentCard document={item.execution_result.data.print_document} t={t} />
                )}

                {item.status === 'needs_clarification' && (
                  <ClarificationOptions
                    clarification={item.execution_result?.data?.clarification}
                    onSelect={sendAssistantMessage}
                    t={t}
                  />
                )}

                {item.planned_action && (
                  <div>
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{t('assistant.understoodAction')}</div>
                    <ActionSummaryCard action={item.planned_action} t={t} />
                  </div>
                )}

                {item.execution_result && (
                  <div>
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{t('assistant.executionResult')}</div>
                    <ExecutionSummaryCard result={item.execution_result} t={t} />
                  </div>
                )}

                {item.requires_delete_confirmation && item.status === 'pending_confirmation' && (
                  <div className="flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 p-3">
                    <span className="text-sm text-amber-800">{t('assistant.deleteNeedsConfirmation')}</span>
                    <button
                      type="button"
                      className="btn btn-primary"
                      disabled={confirmingId === item.id}
                      onClick={() => handleConfirmDelete(item.id)}
                    >
                      {confirmingId === item.id ? t('common.loading') : t('assistant.confirmDelete')}
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>

          <form onSubmit={handleSend} className="border-t border-gray-100 p-4">
            <label className="label">{t('assistant.inputLabel')}</label>
            <textarea
              value={message}
              onChange={(event) => setMessage(event.target.value)}
              rows={4}
              className="input min-h-[120px]"
              placeholder={t('assistant.inputPlaceholder')}
            />
            <div className="mt-3 flex justify-end">
              <button type="submit" disabled={sending} className="btn btn-primary">
                {sending ? t('common.loading') : t('assistant.send')}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
