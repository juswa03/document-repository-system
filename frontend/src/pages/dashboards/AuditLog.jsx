import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import { downloadAuditLogCsv } from '../../lib/download';
import './dashboards.css';

const EMPTY = { action: '', actor_id: '', date_from: '', date_to: '' };

export default function AuditLog() {
  const [entries, setEntries] = useState([]);
  const [meta, setMeta] = useState(null);
  const [actions, setActions] = useState([]);
  const [users, setUsers] = useState([]);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState(EMPTY);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState('');

  const params = useMemo(
    () => ({
      page,
      action: filters.action || undefined,
      actor_id: filters.actor_id || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
    }),
    [page, filters]
  );

  useEffect(() => {
    api
      .get('/admin/users')
      .then(({ data }) => setUsers(data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/audit-log', { params })
      .then(({ data }) => {
        setEntries(data.data);
        setMeta(data.meta);
        if (data.available_actions) setActions(data.available_actions);
      })
      .catch((err) => setError(err?.response?.data?.message || 'Could not load the audit log.'))
      .finally(() => setLoading(false));
  }, [params]);

  function set(key, value) {
    setPage(1);
    setFilters((f) => ({ ...f, [key]: value }));
  }

  async function exportCsv() {
    setExporting(true);
    try {
      await downloadAuditLogCsv(params);
    } catch {
      setError('Could not export the audit log.');
    } finally {
      setExporting(false);
    }
  }

  const filtered = filters.action || filters.actor_id || filters.date_from || filters.date_to;

  return (
    <DashboardShell eyebrow="System / super admin" title="Audit log">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Activity history</h2>
            <p className="panel-subtitle">
              Every upload, download, review decision, access grant, retention action, AI action,
              sign-in and settings change — always recorded.
            </p>
          </div>
          <button className="btn btn--outline btn-sm" disabled={exporting} onClick={exportCsv}>
            {exporting ? 'Exporting…' : 'Download CSV'}
          </button>
        </div>

        <form className="filter-bar" onSubmit={(e) => e.preventDefault()}>
          <div className="filter-field">
            <label htmlFor="f-action" style={{ color: 'var(--text-label)' }}>
              Action
            </label>
            <select id="f-action" value={filters.action} onChange={(e) => set('action', e.target.value)}>
              <option value="">Any action</option>
              {actions.map((a) => (
                <option key={a} value={a}>
                  {a}
                </option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="f-actor" style={{ color: 'var(--text-label)' }}>
              Actor
            </label>
            <select id="f-actor" value={filters.actor_id} onChange={(e) => set('actor_id', e.target.value)}>
              <option value="">Anyone</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name}
                </option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="f-from" style={{ color: 'var(--text-label)' }}>
              From
            </label>
            <input id="f-from" type="date" value={filters.date_from} onChange={(e) => set('date_from', e.target.value)} />
          </div>

          <div className="filter-field">
            <label htmlFor="f-to" style={{ color: 'var(--text-label)' }}>
              To
            </label>
            <input id="f-to" type="date" value={filters.date_to} onChange={(e) => set('date_to', e.target.value)} />
          </div>

          {filtered && (
            <div className="btn-row">
              <button
                type="button"
                className="btn btn--outline btn-sm"
                onClick={() => {
                  setPage(1);
                  setFilters(EMPTY);
                }}
              >
                Clear
              </button>
            </div>
          )}
        </form>

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <>
            <div style={{ overflowX: 'auto' }}>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>IP</th>
                  </tr>
                </thead>
                <tbody>
                  {entries.length === 0 && (
                    <tr>
                      <td colSpan={5} className="empty-row">
                        {filtered ? 'No activity matches these filters.' : 'No activity recorded yet.'}
                      </td>
                    </tr>
                  )}
                  {entries.map((entry) => (
                    <tr key={entry.id}>
                      <td className="cell-muted">{new Date(entry.created_at).toLocaleString()}</td>
                      <td>{entry.actor}</td>
                      <td className="cell-mono">{entry.action}</td>
                      <td>{entry.description}</td>
                      <td className="cell-muted cell-mono">{entry.ip_address || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {meta && meta.last_page > 1 && (
              <div className="pager">
                <span>
                  Page {meta.current_page} of {meta.last_page} — {meta.total} total
                </span>
                <div className="btn-row">
                  <button
                    className="btn btn--outline btn-sm"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => p - 1)}
                  >
                    ← Previous
                  </button>
                  <button
                    className="btn btn--outline btn-sm"
                    disabled={page >= meta.last_page}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    Next →
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </section>
    </DashboardShell>
  );
}
