import { Fragment, useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import StatusBadge from '../../components/StatusBadge';
import AiSuggestionPanel from '../../components/AiSuggestionPanel';
import ObjectivePicker from '../../components/ObjectivePicker';
import Pager from '../../components/Pager';
import { useAuth } from '../../context/AuthContext';
import api from '../../lib/api';
import { downloadDocumentFile } from '../../lib/download';
import './dashboards.css';

const SCOPES = [
  { key: 'all', label: 'All pending' },
  { key: 'unassigned', label: 'Unassigned' },
  { key: 'mine', label: 'Assigned to me' },
];

export default function OsmAdminDashboard() {
  const { user } = useAuth();
  const [queue, setQueue] = useState([]);
  const [queueMeta, setQueueMeta] = useState(null);
  const [queuePage, setQueuePage] = useState(1);
  const [categories, setCategories] = useState([]);
  const [stats, setStats] = useState(null);
  const [config, setConfig] = useState({ checklists: {}, reviewers: [] });
  const [decided, setDecided] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [scope, setScope] = useState('all');
  const [rejectingKey, setRejectingKey] = useState(null);
  const [approvingKey, setApprovingKey] = useState(null);
  const [checklistState, setChecklistState] = useState({});
  const [remarks, setRemarks] = useState('');
  const [busyKey, setBusyKey] = useState(null);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [aiKey, setAiKey] = useState(null);
  const [objKey, setObjKey] = useState(null);

  async function loadQueue(nextScope = scope) {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/osm-admin/queue', {
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

  async function loadStats() {
    try {
      const { data } = await api.get('/osm-admin/stats');
      setStats(data);
    } catch {
      /* non-critical — the queue is still usable without the tiles */
    }
  }

  useEffect(() => {
    loadStats();
    api
      .get('/osm-admin/review-config')
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

  const tiles = useMemo(() => {
    const d = stats?.documents;
    return [
      { label: 'Awaiting review', value: stats?.awaiting_review },
      { label: 'Overdue', value: d?.overdue },
      { label: 'Approved', value: d?.approved },
      { label: 'Needs revision', value: d?.revision },
      { label: 'Rejected', value: d?.rejected },
      { label: 'Submitted this week', value: d?.submitted_last_7_days },
    ];
  }, [stats]);

  const visibleQueue = queue;

  function keyOf(item) {
    return `${item.kind}-${item.id}`;
  }

  function checklistFor(kind) {
    return config.checklists?.[kind] || [];
  }

  function openApprove(item) {
    setRejectingKey(null);
    setApprovingKey(keyOf(item));
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
    } catch {
      setError('Could not download that file.');
    }
  }

  async function assign(item, assigneeId) {
    const key = keyOf(item);
    setBusyKey(key);
    try {
      const path = item.kind === 'document'
        ? `/osm-admin/documents/${item.id}/assign`
        : `/osm-admin/requests/${item.id}/assign`;
      const { data } = await api.post(path, { assignee_id: assigneeId });
      setQueue((prev) => prev.map((q) => (keyOf(q) === key ? { ...q, ...data } : q)));
      if (scope !== 'all') loadQueue(scope);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not update the assignment.');
    } finally {
      setBusyKey(null);
    }
  }

  async function decide(item, decision, decisionRemarks, checklist) {
    const key = keyOf(item);
    setBusyKey(key);
    try {
      await api.post('/osm-admin/reviews', {
        kind: item.kind,
        id: item.id,
        decision,
        remarks: decisionRemarks || undefined,
        checklist: checklist || undefined,
      });
      setDecided((prev) => [{ ...item, decision, remarks: decisionRemarks || '' }, ...prev]);
      setRejectingKey(null);
      setApprovingKey(null);
      setRemarks('');
      loadStats();
      loadQueue();
    } catch (err) {
      const checklistErr = err?.response?.data?.errors?.checklist?.[0];
      setError(checklistErr || err?.response?.data?.message || 'That decision could not be saved.');
    } finally {
      setBusyKey(null);
    }
  }

  return (
    <DashboardShell eyebrow="OSM admin" title="Pending review queue">
      {error && <p className="error-banner">{error}</p>}

      <div className="stat-grid">
        {tiles.map((t) => (
          <div className="stat-card" key={t.label}>
            <div className="stat-value">{t.value ?? '—'}</div>
            <div className="stat-label">{t.label}</div>
          </div>
        ))}
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Pending review</h2>
            <p className="panel-subtitle">Claim an item, run the completeness check, then decide.</p>
          </div>
          <div className="btn-row">
            {categories.length > 0 && (
              <select
                className="dash-select"
                style={{ width: 'auto', color: 'var(--text-label)' }}
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
              onClick={() => setScope(s.key)}
            >
              {s.label}
            </button>
          ))}
        </div>

        {loading ? (
          <p className="loading-text">Loading the queue…</p>
        ) : (
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
              {visibleQueue.length === 0 && (
                <tr>
                  <td colSpan={6} className="empty-row">
                    {categoryFilter ? 'No items match that category.' : 'Nothing here.'}
                  </td>
                </tr>
              )}
              {visibleQueue.map((item) => {
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
                            title={`Past the ${item.target_days}-working-day target (${item.days_in_stage} days in stage)`}
                            style={{ color: 'var(--danger, #a1442f)', marginLeft: 6, fontSize: '.8em' }}
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
                            <span className="cell-muted" style={{ display: 'block', fontSize: '.8em' }}>
                              {item.type}
                              {item.needed_by ? ` · needed by ${item.needed_by}` : ''}
                              {item.amount ? ` · ${item.amount}` : ''}
                            </span>
                          </>
                        )}
                      </td>
                      <td>
                        <select
                          className="dash-select"
                          style={{ width: 'auto', minWidth: 130 }}
                          value={item.assigned_to || ''}
                          disabled={isBusy}
                          onChange={(e) => assign(item, e.target.value ? Number(e.target.value) : null)}
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
                            className="btn btn--outline btn-sm"
                            style={{ marginLeft: 6 }}
                            disabled={isBusy}
                            onClick={() => assign(item, user?.id)}
                          >
                            Claim
                          </button>
                        )}
                      </td>
                      <td className="cell-muted">{new Date(item.submitted_at).toLocaleDateString()}</td>
                      <td>
                        <div className="btn-row">
                          {item.kind === 'document' && (
                            <button className="btn btn--outline btn-sm" onClick={() => handleDownload(item)}>
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
                            onClick={() => { setApprovingKey(null); setRejectingKey(key); setRemarks(''); }}
                          >
                            Return / reject
                          </button>
                        </div>
                      </td>
                    </tr>

                    {aiKey === key && item.kind === 'document' && (
                      <tr>
                        <td colSpan={6}>
                          <div className="inline-form">
                            <h3 className="panel-title" style={{ fontSize: '0.95rem', marginBottom: '0.4rem' }}>
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
                            <h3 className="panel-title" style={{ fontSize: '0.95rem', marginBottom: '0.4rem' }}>
                              Strategic objectives this document supports
                            </h3>
                            <ObjectivePicker documentId={item.id} />
                          </div>
                        </td>
                      </tr>
                    )}

                    {approvingKey === key && (
                      <tr>
                        <td colSpan={6}>
                          <div className="inline-form">
                            <h3 className="panel-title" style={{ fontSize: '0.95rem', marginBottom: '0.5rem' }}>
                              Completeness checklist — confirm before approving
                            </h3>
                            {checklistFor(item.kind).map((c) => (
                              <label key={c.key} style={{ display: 'block', marginBottom: '0.35rem' }}>
                                <input
                                  type="checkbox"
                                  checked={!!checklistState[c.key]}
                                  onChange={(e) =>
                                    setChecklistState((s) => ({ ...s, [c.key]: e.target.checked }))
                                  }
                                />{' '}
                                {c.label}
                                {c.required ? <span style={{ color: 'var(--danger, #a1442f)' }}> *</span> : null}
                              </label>
                            ))}
                            <div className="btn-row" style={{ marginTop: '0.6rem' }}>
                              <button
                                className="btn btn--primary btn-sm"
                                disabled={!requiredMet || isBusy}
                                onClick={() => decide(item, 'approved', null, checklistState)}
                              >
                                Approve
                              </button>
                              <button className="btn btn--outline btn-sm" onClick={() => setApprovingKey(null)}>
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
                            <div className="dash-field" style={{ marginBottom: '0.6rem' }}>
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
                              <button className="btn btn--outline btn-sm" onClick={() => setRejectingKey(null)}>
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
        )}

        <Pager meta={queueMeta} page={queuePage} onPage={setQueuePage} />
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Recently decided</h2>
            <p className="panel-subtitle">Your decisions this session.</p>
          </div>
        </div>

        <table className="data-table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Submitter</th>
              <th>Decision</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            {decided.length === 0 && (
              <tr>
                <td colSpan={4} className="empty-row">No decisions yet this session.</td>
              </tr>
            )}
            {decided.map((item) => (
              <tr key={keyOf(item)}>
                <td className="cell-mono">{item.ref}</td>
                <td>{item.submitter}</td>
                <td><StatusBadge status={item.decision} /></td>
                <td className="cell-muted">{item.remarks || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </DashboardShell>
  );
}
