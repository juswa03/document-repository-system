import { useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

export default function AuditLog() {
  const [entries, setEntries] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/audit-log', { params: { page } })
      .then(({ data }) => {
        setEntries(data.data);
        setMeta(data.meta);
      })
      .catch((err) => setError(err?.response?.data?.message || 'Could not load the audit log.'))
      .finally(() => setLoading(false));
  }, [page]);

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
        </div>

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <>
            <table className="data-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Actor</th>
                  <th>Action</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                {entries.length === 0 && (
                  <tr>
                    <td colSpan={4} className="empty-row">
                      No activity recorded yet.
                    </td>
                  </tr>
                )}
                {entries.map((entry) => (
                  <tr key={entry.id}>
                    <td className="cell-muted">{new Date(entry.created_at).toLocaleString()}</td>
                    <td>{entry.actor}</td>
                    <td className="cell-mono">{entry.action}</td>
                    <td>{entry.description}</td>
                  </tr>
                ))}
              </tbody>
            </table>

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
