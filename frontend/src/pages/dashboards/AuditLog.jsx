import { Fragment, useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import Pager from '../../components/Pager';
import api from '../../lib/api';
import { downloadAuditLogCsv } from '../../lib/download';
import './dashboards.css';
import Banner from '../../components/Banner';
import { TableSkeleton } from '../../components/Skeleton';

const EMPTY = { action: '', actor_id: '', date_from: '', date_to: '' };

const fmt = (v) =>
  v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v);

/** Renders the `properties` bag recorded with an entry — a before/after
 *  diff when present, otherwise a plain key/value list. */
function EntryDetail({ subject, properties }) {
  const hasProps = properties && typeof properties === 'object' && Object.keys(properties).length > 0;
  const { before, after } = properties || {};
  const isDiff = before && after && typeof before === 'object' && typeof after === 'object';

  return (
    <div className="entry-detail">
      {subject && (
        <p className="cell-muted u-mb-1">
          Subject: {subject}
        </p>
      )}

      {!hasProps && <span className="cell-muted">No additional detail recorded.</span>}

      {hasProps && isDiff && (
        <table className="data-table data-table--flush">
          <thead>
            <tr>
              <th>Field</th>
              <th>Before</th>
              <th>After</th>
            </tr>
          </thead>
          <tbody>
            {Array.from(new Set([...Object.keys(before), ...Object.keys(after)])).map((k) => (
              <tr key={k}>
                <td className="cell-mono">{k}</td>
                <td className="cell-muted">{fmt(before[k])}</td>
                <td>{fmt(after[k])}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {hasProps && !isDiff && (
        <table className="data-table data-table--flush">
          <tbody>
            {Object.entries(properties).map(([k, v]) => (
              <tr key={k}>
                <td className="cell-mono">{k}</td>
                <td>{fmt(v)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

export default function AuditLog() {
  const [entries, setEntries] = useState([]);
  const [meta, setMeta] = useState(null);
  const [actions, setActions] = useState([]);
  const [users, setUsers] = useState([]);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState(EMPTY);
  const [openId, setOpenId] = useState(null);
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
      .get('/admin/users', { params: { all: 1 } })
      .then(({ data }) => setUsers(data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    setLoading(true);
    setError('');
    setOpenId(null);
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
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not export the audit log.');
    } finally {
      setExporting(false);
    }
  }

  const filtered = filters.action || filters.actor_id || filters.date_from || filters.date_to;

  return (
    <DashboardShell eyebrow="System / super admin" title="Audit log">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Activity history</h2>
            <p className="panel-subtitle">
              Every upload, download, review decision, access grant, retention action, AI action,
              sign-in and settings change — always recorded. Rows with a ▸ expand to show what
              changed.
            </p>
          </div>
          <button className="btn btn--outline btn-sm" disabled={exporting} onClick={exportCsv}>
            {exporting ? 'Exporting…' : 'Download CSV'}
          </button>
        </div>

        <form className="filter-bar" onSubmit={(e) => e.preventDefault()}>
          <div className="filter-field">
            <label htmlFor="f-action">
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
            <label htmlFor="f-actor">
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
            <label htmlFor="f-from">
              From
            </label>
            <input id="f-from" type="date" value={filters.date_from} onChange={(e) => set('date_from', e.target.value)} />
          </div>

          <div className="filter-field">
            <label htmlFor="f-to">
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
          <TableSkeleton rows={6} label="Loading the audit log" />
        ) : (
          <>
            <div className="u-scroll-x">
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
                  {entries.map((entry) => {
                    const expandable = !!entry.properties || !!entry.subject;
                    const open = openId === entry.id;
                    return (
                      <Fragment key={entry.id}>
                        <tr
                          className={expandable ? 'row-expandable' : undefined}
                          onClick={() => expandable && setOpenId(open ? null : entry.id)}
                        >
                          <td className="cell-muted">{new Date(entry.created_at).toLocaleString()}</td>
                          <td>{entry.actor}</td>
                          <td className="cell-mono">{entry.action}</td>
                          <td>
                            {entry.description}
                            {expandable && (
                              <span className="cell-muted disclosure-caret">
                                {open ? '▾' : '▸'}
                              </span>
                            )}
                          </td>
                          <td className="cell-muted cell-mono">{entry.ip_address || '—'}</td>
                        </tr>
                        {open && (
                          <tr className="detail-row">
                            <td colSpan={5}>
                              <EntryDetail subject={entry.subject} properties={entry.properties} />
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <Pager meta={meta} page={page} onPage={setPage} />
          </>
        )}
      </section>
    </DashboardShell>
  );
}
