import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { ListChecks } from 'lucide-react';
import DashboardShell from './DashboardShell';
import AiSuggestionPanel from '../../components/AiSuggestionPanel';
import AccessGrantsPanel from '../../components/AccessGrantsPanel';
import ObjectivePicker from '../../components/ObjectivePicker';
import Pager from '../../components/Pager';
import { useAuth } from '../../context/AuthContext';
import api from '../../lib/api';
import { downloadDocumentFile } from '../../lib/download';
import './dashboards.css';
import './OfficeAdminDashboard.css';
import Banner from '../../components/Banner';
import { TableSkeleton } from '../../components/Skeleton';
import EmptyState from '../../components/EmptyState';

const SCOPES = [
  { key: 'all', label: 'All pending' },
  { key: 'unassigned', label: 'Unassigned' },
  { key: 'mine', label: 'Assigned to me' },
];

const ACCESS_LEVELS = ['public', 'internal', 'restricted', 'confidential'];

function fmtSize(bytes) {
  if (!bytes && bytes !== 0) return null;
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function DetailGrid({ item }) {
  // A row whose value is empty is dropped rather than shown as a dash:
  // most request types have no amount and no stated reason, and an
  // em-dash there reads as missing data instead of "not applicable".
  //
  // The exception is `always` — a field whose absence is itself worth
  // seeing, like a reason on a type that is supposed to carry one.
  const rows =
    item.kind === 'document'
      ? [
          ['Title', item.title],
          ['Category', item.category],
          ['Document type', item.document_type],
          ['Document date', item.document_date],
          ['Reporting period', item.reporting_period],
          ['Access level', item.access_level],
          ['Version', item.version_number ? `v${item.version_number}` : null],
          [
            'File',
            [item.file_format, fmtSize(item.file_size)].filter(Boolean).join(' · ') || null,
          ],
          ['Keywords', item.keywords],
          ['Description', item.description],
          ['Uploader remarks', item.uploader_remarks],
        ]
      : [
          ['Title', item.title],
          ['Request type', item.type],
          ['Needed by', item.needed_by],
          // Only money-bearing request types carry an amount.
          ['Amount', item.amount],
          ['Access level', item.access_level],
          ['Description', item.description],
          // Collected only for types that are "subject to approval"
          // (urgent, sensitive). When such a type has none, say so —
          // that is a gap the reviewer should weigh, not a blank.
          [
            'Reason given',
            item.uploader_remarks,
            { always: Boolean(item.requires_justification) },
          ],
        ];

  return (
    <dl className="detail-grid">
      {rows
        .filter(([, value, opts]) => value || opts?.always)
        .map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>
              {value || <span className="cell-muted">Not provided</span>}
            </dd>
          </div>
        ))}
    </dl>
  );
}

export default function ReviewQueue() {
  const { user } = useAuth();
  const [queue, setQueue] = useState([]);
  const [queueMeta, setQueueMeta] = useState(null);
  const [queuePage, setQueuePage] = useState(1);
  const [categories, setCategories] = useState([]);
  const [config, setConfig] = useState({ checklists: {}, reviewers: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [scope, setScope] = useState('all');
  const [rejectingKey, setRejectingKey] = useState(null);
  const [approvingKey, setApprovingKey] = useState(null);
  const [checklistState, setChecklistState] = useState({});
  const [approveAccessLevel, setApproveAccessLevel] = useState('internal');
  const [responseFile, setResponseFile] = useState(null);
  // Answering with a document already in the repository, instead of
  // uploading a copy. Mutually exclusive with responseFile.
  const [responseDocumentId, setResponseDocumentId] = useState(null);
  const [repoDocs, setRepoDocs] = useState([]);
  const [repoQuery, setRepoQuery] = useState('');
  const [repoLoading, setRepoLoading] = useState(false);
  const [showRepoPicker, setShowRepoPicker] = useState(false);
  const [remarks, setRemarks] = useState('');
  const [busyKey, setBusyKey] = useState(null);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [aiKey, setAiKey] = useState(null);
  const [objKey, setObjKey] = useState(null);
  const [detailKey, setDetailKey] = useState(null);
  const [grantKey, setGrantKey] = useState(null);
  const repoRequestSeq = useRef(0);

  async function loadQueue(nextScope = scope) {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/office-admin/queue', {
        params: {
          scope: nextScope,
          page: queuePage,
          category_id: categoryFilter || undefined,
        },
      });
      setQueue(data.data);
      setQueueMeta(data.meta);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load the review queue.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    api
      .get('/office-admin/review-config')
      .then(({ data }) => setConfig(data))
      .catch(() => {});
    api
      .get('/categories')
      .then(({ data }) => setCategories(data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    loadQueue(scope);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scope, queuePage, categoryFilter]);

  function keyOf(item) {
    return `${item.kind}-${item.id}`;
  }

  function checklistFor(kind) {
    return config.checklists?.[kind] || [];
  }

  async function loadRepoDocs(item, q = '') {
    // A faster-but-older response must never overwrite a slower-but-newer
    // one — every keystroke fires a fresh request, so tag each call with a
    // sequence number and drop any response that isn't the latest.
    const seq = ++repoRequestSeq.current;
    setRepoLoading(true);
    try {
      const { data } = await api.get('/office-admin/response-documents', {
        params: { kind: item.kind, id: item.id, q: q || undefined },
      });
      if (seq !== repoRequestSeq.current) return;
      setRepoDocs(data.data);
    } catch {
      if (seq !== repoRequestSeq.current) return;
      setRepoDocs([]);
    } finally {
      if (seq === repoRequestSeq.current) setRepoLoading(false);
    }
  }

  function openApprove(item) {
    setRejectingKey(null);
    setApprovingKey(keyOf(item));
    setApproveAccessLevel(item.access_level || 'internal');
    setResponseFile(null);
    setResponseDocumentId(null);
    // Shared with the return/reject form — clear it so a half-typed
    // rejection reason never rides along on an approval.
    setRemarks('');
    setRepoDocs([]);
    setRepoQuery('');
    setShowRepoPicker(false);
    const seed = {};
    checklistFor(item.kind).forEach((c) => {
      seed[c.key] = false;
    });
    setChecklistState(seed);
  }

  const requiredMet = useMemo(() => {
    const item = queue.find((i) => keyOf(i) === approvingKey);
    if (!item) return false;
    return checklistFor(item.kind)
      .filter((c) => c.required)
      .every((c) => checklistState[c.key]);
  }, [approvingKey, checklistState, queue, config]); // eslint-disable-line react-hooks/exhaustive-deps

  async function handleDownload(item) {
    try {
      await downloadDocumentFile(item.id, item.type);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not download that file.');
    }
  }

  async function assign(item, assigneeId) {
    const key = keyOf(item);
    setBusyKey(key);
    try {
      const path =
        item.kind === 'document'
          ? `/office-admin/documents/${item.id}/assign`
          : `/office-admin/requests/${item.id}/assign`;
      const { data } = await api.post(path, { assignee_id: assigneeId });
      setQueue((prev) => prev.map((q) => (keyOf(q) === key ? { ...q, ...data } : q)));
      if (scope !== 'all') loadQueue(scope);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not update the assignment.');
    } finally {
      setBusyKey(null);
    }
  }

  async function decide(item, decision, decisionRemarks, checklist, accessLevel, file, documentId) {
    const key = keyOf(item);
    setBusyKey(key);
    try {
      let body;
      if (decision === 'approved' && file) {
        body = new FormData();
        body.append('kind', item.kind);
        body.append('id', item.id);
        body.append('decision', decision);
        body.append('response_file', file);
        if (checklist) {
          Object.entries(checklist).forEach(([k, v]) => body.append(`checklist[${k}]`, v ? '1' : '0'));
        }
        if (item.kind === 'document' && accessLevel && accessLevel !== item.access_level) {
          body.append('access_level', accessLevel);
        }
      } else {
        body = {
          kind: item.kind,
          id: item.id,
          decision,
          remarks: decisionRemarks || undefined,
          checklist: checklist || undefined,
          response_document_id: documentId || undefined,
          access_level:
            item.kind === 'document' && accessLevel && accessLevel !== item.access_level
              ? accessLevel
              : undefined,
        };
      }

      await api.post('/office-admin/reviews', body);
      setRejectingKey(null);
      setApprovingKey(null);
      setRemarks('');
      setResponseFile(null);
      loadQueue();
    } catch (err) {
      const checklistErr = err?.response?.data?.errors?.checklist?.[0];
      setError(checklistErr || err?.response?.data?.message || 'That decision could not be saved.');
    } finally {
      setBusyKey(null);
    }
  }

  return (
    <DashboardShell eyebrow="Office admin" title="Pending review">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Pending review</h2>
            <p className="panel-subtitle">Claim an item, run the completeness check, then decide.</p>
          </div>
          <div className="btn-row">
            {categories.length > 0 && (
              <select
                className="dash-select u-w-auto"
                value={categoryFilter}
                onChange={(e) => {
                  setQueuePage(1);
                  setCategoryFilter(e.target.value);
                }}
              >
                <option value="">All categories</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.category_name}
                  </option>
                ))}
              </select>
            )}
          </div>
        </div>

        <div className="tab-row">
          {SCOPES.map((s) => (
            <button
              key={s.key}
              className={`tab-btn ${scope === s.key ? 'is-active' : ''}`}
              onClick={() => {
                setQueuePage(1);
                setScope(s.key);
              }}
            >
              {s.label}
            </button>
          ))}
        </div>

        {loading ? (
          <TableSkeleton rows={6} label="Loading the review queue" />
        ) : queue.length === 0 ? (
          <EmptyState
            icon={<ListChecks size={22} />}
            title={categoryFilter ? 'No items in that category' : 'The queue is clear'}
            message={
              categoryFilter
                ? 'Nothing is waiting for review under this category. Clear the filter to see the rest of the queue.'
                : 'Every submission has been reviewed. New submissions land here as they arrive.'
            }
          />
        ) : (
          <div className="table-scroll">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Submitter</th>
                  <th>Type</th>
                  <th>Assignee</th>
                  <th>Submitted</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {queue.map((item) => {
                  const key = keyOf(item);
                  const isBusy = busyKey === key;
                  const mine = item.assigned_to === user?.id;
                  return (
                    <Fragment key={key}>
                      <tr>
                        <td className="cell-mono">
                          {item.ref}
                          {item.overdue && (
                            <span
                              className="overdue-inline"
                              title={`Past the ${item.target_days}-working-day target (${item.days_in_stage} days in stage)`}
                            >
                              ⚠ overdue
                            </span>
                          )}
                        </td>
                        <td>{item.submitter}</td>
                        <td>
                          {item.kind === 'document' ? (
                            <>{item.type} (doc)</>
                          ) : (
                            <>
                              {item.title || item.type}
                              <span className="cell-muted cell-sub">
                                {item.type}
                                {item.needed_by ? ` · needed by ${item.needed_by}` : ''}
                                {item.amount ? ` · ${item.amount}` : ''}
                              </span>
                            </>
                          )}
                        </td>
                        <td>
                          <select
                            className="dash-select u-w-auto assignee-select"
                            value={item.assigned_to || ''}
                            disabled={isBusy}
                            onChange={(e) =>
                              assign(item, e.target.value ? Number(e.target.value) : null)
                            }
                          >
                            <option value="">— Unassigned —</option>
                            {config.reviewers?.map((r) => (
                              <option key={r.id} value={r.id}>
                                {r.id === user?.id ? `${r.full_name} (me)` : r.full_name}
                              </option>
                            ))}
                          </select>
                          {!mine && item.assigned_to == null && (
                            <button
                              className="btn btn--outline btn-sm u-ml-1"
                              disabled={isBusy}
                              onClick={() => assign(item, user?.id)}
                            >
                              Claim
                            </button>
                          )}
                        </td>
                        <td className="cell-muted">
                          {new Date(item.submitted_at).toLocaleDateString()}
                        </td>
                        <td>
                          <div className="btn-row">
                            <button
                              className="btn btn--outline btn-sm"
                              onClick={() => setDetailKey((k) => (k === key ? null : key))}
                            >
                              {detailKey === key ? 'Hide details' : 'Details'}
                            </button>
                            {item.kind === 'document' && (
                              <button
                                className="btn btn--outline btn-sm"
                                onClick={() => handleDownload(item)}
                              >
                                Download
                              </button>
                            )}
                            {item.kind === 'document' && (
                              <button
                                className="btn btn--outline btn-sm"
                                onClick={() => setAiKey((k) => (k === key ? null : key))}
                              >
                                {aiKey === key ? 'Hide AI review' : 'AI review'}
                              </button>
                            )}
                            {item.kind === 'document' && (
                              <button
                                className="btn btn--outline btn-sm"
                                onClick={() => setObjKey((k) => (k === key ? null : key))}
                              >
                                {objKey === key ? 'Hide objectives' : 'Objectives'}
                              </button>
                            )}
                            {item.kind === 'document' &&
                              ['restricted', 'confidential'].includes(
                                approvingKey === key ? approveAccessLevel : item.access_level,
                              ) && (
                                <button
                                  className="btn btn--outline btn-sm"
                                  onClick={() => setGrantKey((k) => (k === key ? null : key))}
                                >
                                  {grantKey === key ? 'Hide grants' : 'Access grants'}
                                </button>
                              )}
                            <button
                              className="btn btn--primary btn-sm"
                              disabled={isBusy}
                              onClick={() => openApprove(item)}
                            >
                              Review &amp; approve
                            </button>
                            <button
                              className="btn btn--outline btn-sm"
                              disabled={isBusy}
                              onClick={() => {
                                setApprovingKey(null);
                                setRejectingKey(key);
                                setRemarks('');
                              }}
                            >
                              Return / reject
                            </button>
                          </div>
                        </td>
                      </tr>

                      {detailKey === key && (
                        <tr>
                          <td colSpan={6}>
                            <div className="inline-form">
                              <h3 className="inline-form-title">
                                Submission details
                              </h3>
                              <DetailGrid item={item} />
                            </div>
                          </td>
                        </tr>
                      )}

                      {aiKey === key && item.kind === 'document' && (
                        <tr>
                          <td colSpan={6}>
                            <div className="inline-form">
                              <h3 className="inline-form-title">
                                AI review — suggestions (nothing is applied until you accept)
                              </h3>
                              <AiSuggestionPanel documentId={item.id} />
                            </div>
                          </td>
                        </tr>
                      )}

                      {objKey === key && item.kind === 'document' && (
                        <tr>
                          <td colSpan={6}>
                            <div className="inline-form">
                              <h3 className="inline-form-title">
                                Strategic objectives this document supports
                              </h3>
                              <ObjectivePicker documentId={item.id} />
                            </div>
                          </td>
                        </tr>
                      )}

                      {grantKey === key && item.kind === 'document' && (
                        <tr>
                          <td colSpan={6}>
                            <div className="inline-form">
                              <h3 className="inline-form-title">
                                Access grants — {approvingKey === key ? approveAccessLevel : item.access_level} document
                              </h3>
                              <AccessGrantsPanel documentId={item.id} />
                            </div>
                          </td>
                        </tr>
                      )}

                      {approvingKey === key && (
                        <tr>
                          <td colSpan={6}>
                            <div className="inline-form">
                              <h3 className="inline-form-title">
                                Completeness checklist — confirm before approving
                              </h3>
                              {checklistFor(item.kind).map((c) => (
                                <label key={c.key} className="check-option">
                                  <input
                                    type="checkbox"
                                    checked={!!checklistState[c.key]}
                                    onChange={(e) =>
                                      setChecklistState((s) => ({ ...s, [c.key]: e.target.checked }))
                                    }
                                  />{' '}
                                  {c.label}
                                  {c.required ? (
                                    <span className="req-mark"> *</span>
                                  ) : null}
                                </label>
                              ))}

                              {item.kind === 'document' && (
                                <div
                                  className="dash-field field--narrow u-mt-3"
                                >
                                  <label className="dash-label" htmlFor={`acc-${key}`}>
                                    Access level at approval
                                  </label>
                                  <select
                                    id={`acc-${key}`}
                                    className="dash-select"
                                    value={approveAccessLevel}
                                    onChange={(e) => setApproveAccessLevel(e.target.value)}
                                  >
                                    {ACCESS_LEVELS.map((l) => (
                                      <option key={l} value={l}>
                                        {l}
                                        {l === item.access_level ? ' (as submitted)' : ''}
                                      </option>
                                    ))}
                                  </select>
                                </div>
                              )}

                              <div className="dash-field u-mt-3">
                                <label className="dash-label" htmlFor={`resp-file-${key}`}>
                                  Response{' '}
                                  <span className="cell-muted">(optional)</span>
                                </label>

                                {/* Two ways to answer, and they exclude each
                                    other — the submitter should never have to
                                    work out which of two files is the real
                                    answer. */}
                                <div className="resp-choice">
                                  <button
                                    type="button"
                                    className={`btn btn--outline btn-sm ${!showRepoPicker ? 'is-active' : ''}`}
                                    onClick={() => {
                                      setShowRepoPicker(false);
                                      setResponseDocumentId(null);
                                    }}
                                  >
                                    Upload a file
                                  </button>
                                  <button
                                    type="button"
                                    className={`btn btn--outline btn-sm ${showRepoPicker ? 'is-active' : ''}`}
                                    onClick={() => {
                                      setShowRepoPicker(true);
                                      setResponseFile(null);
                                      if (repoDocs.length === 0) loadRepoDocs(item);
                                    }}
                                  >
                                    Send an existing document
                                  </button>
                                </div>

                                {!showRepoPicker && (
                                  <>
                                    <input
                                      id={`resp-file-${key}`}
                                      type="file"
                                      className="dash-input"
                                      accept=".pdf,.doc,.docx"
                                      onChange={(e) => setResponseFile(e.target.files?.[0] || null)}
                                    />
                                    <p className="cell-muted u-mt-1">
                                      Attach a signed letter or issued document for the submitter to
                                      download.
                                    </p>
                                  </>
                                )}

                                {showRepoPicker && (
                                  <div className="repo-picker">
                                    <input
                                      type="search"
                                      className="dash-input"
                                      placeholder="Search this office's approved documents"
                                      value={repoQuery}
                                      onChange={(e) => {
                                        setRepoQuery(e.target.value);
                                        loadRepoDocs(item, e.target.value);
                                      }}
                                    />

                                    {repoLoading && <p className="loading-text">Searching…</p>}

                                    {!repoLoading && repoDocs.length === 0 && (
                                      <p className="cell-muted u-mt-1">
                                        Nothing from this office matches. Only approved, public or
                                        internal documents can be sent this way.
                                      </p>
                                    )}

                                    {!repoLoading && repoDocs.length > 0 && (
                                      <ul className="repo-picker-list">
                                        {repoDocs.map((doc) => (
                                          <li key={doc.id}>
                                            <label className="repo-picker-option">
                                              <input
                                                type="radio"
                                                name={`repo-doc-${key}`}
                                                checked={responseDocumentId === doc.id}
                                                onChange={() => setResponseDocumentId(doc.id)}
                                              />
                                              <span className="repo-picker-body">
                                                <span className="repo-picker-title">{doc.title}</span>
                                                <span className="cell-muted">
                                                  {doc.ref}
                                                  {doc.category ? ` · ${doc.category}` : ''}
                                                  {doc.reporting_period ? ` · ${doc.reporting_period}` : ''}
                                                  {doc.version_number > 1 ? ` · v${doc.version_number}` : ''}
                                                </span>
                                              </span>
                                            </label>
                                          </li>
                                        ))}
                                      </ul>
                                    )}

                                    <p className="cell-muted u-mt-1">
                                      The submitter gets the current version of whichever document
                                      you pick, not a copy frozen today.
                                    </p>
                                  </div>
                                )}
                              </div>

                              {/* Optional on an approval — a remark is only
                                  demanded when something is refused. But it
                                  was impossible to leave one at all, which
                                  is why every approved row showed a blank. */}
                              <div className="dash-field u-mt-3">
                                <label className="dash-label" htmlFor={`approve-remarks-${key}`}>
                                  Note to the submitter{' '}
                                  <span className="cell-muted">(optional)</span>
                                </label>
                                <textarea
                                  id={`approve-remarks-${key}`}
                                  className="dash-textarea"
                                  value={remarks}
                                  onChange={(e) => setRemarks(e.target.value)}
                                  placeholder="e.g. Approved as submitted. The signed copy is attached."
                                />
                              </div>

                              <div className="btn-row u-mt-2">
                                <button
                                  className="btn btn--primary btn-sm"
                                  disabled={!requiredMet || isBusy}
                                  onClick={() =>
                                    decide(
                                      item,
                                      'approved',
                                      remarks.trim() || null,
                                      checklistState,
                                      approveAccessLevel,
                                      responseFile,
                                      responseDocumentId,
                                    )
                                  }
                                >
                                  Approve
                                </button>
                                <button
                                  className="btn btn--outline btn-sm"
                                  onClick={() => setApprovingKey(null)}
                                >
                                  Cancel
                                </button>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}

                      {rejectingKey === key && (
                        <tr>
                          <td colSpan={6}>
                            <div className="inline-form">
                              <div className="dash-field u-mb-2">
                                <label className="dash-label" htmlFor={`remarks-${key}`}>
                                  Remarks (sent to submitter)
                                </label>
                                <textarea
                                  id={`remarks-${key}`}
                                  className="dash-textarea"
                                  value={remarks}
                                  onChange={(e) => setRemarks(e.target.value)}
                                  placeholder="e.g. Missing supporting receipt for line 3."
                                />
                              </div>
                              <div className="btn-row">
                                <button
                                  className="btn btn--outline btn-sm"
                                  disabled={!remarks.trim() || isBusy}
                                  onClick={() => decide(item, 'revision', remarks)}
                                >
                                  Send for revision
                                </button>
                                <button
                                  className="btn btn--danger-outline btn-sm"
                                  disabled={!remarks.trim() || isBusy}
                                  onClick={() => decide(item, 'rejected', remarks)}
                                >
                                  Confirm rejection
                                </button>
                                <button
                                  className="btn btn--outline btn-sm"
                                  onClick={() => setRejectingKey(null)}
                                >
                                  Cancel
                                </button>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        <Pager meta={queueMeta} page={queuePage} onPage={setQueuePage} />
      </section>
    </DashboardShell>
  );
}
