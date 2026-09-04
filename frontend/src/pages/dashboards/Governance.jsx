import { useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import Pager from '../../components/Pager';
import api from '../../lib/api';
import './dashboards.css';

const SCOPE_LABELS = {
  categories: 'Document categories',
  access_levels: 'Access levels',
  retention: 'Retention status',
};

/**
 * BR-07 / Phase 7.2 — the periodic OSM review of the controlled
 * vocabularies and retention, with a due/overdue view and a log.
 */
export default function Governance() {
  const [status, setStatus] = useState([]);
  const [history, setHistory] = useState([]);
  const [historyMeta, setHistoryMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [recording, setRecording] = useState(null); // scope
  const [notes, setNotes] = useState('');

  async function load() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/admin/governance-reviews', { params: { page } });
      setStatus(data.status);
      setHistory(data.history.data);
      setHistoryMeta(data.history.meta);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load governance reviews.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page]);

  async function record(scope) {
    try {
      await api.post('/admin/governance-reviews', { scope, notes: notes || undefined });
      setRecording(null);
      setNotes('');
      page === 1 ? load() : setPage(1);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not record the review.');
    }
  }

  return (
    <DashboardShell eyebrow="System / super admin" title="Governance cadence">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Review status</h2>
            <p className="panel-subtitle">Periodic OSM review of categories, access levels and retention (BR-07).</p>
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Scope</th>
                <th>Last reviewed</th>
                <th>By</th>
                <th>Next due</th>
                <th>Cadence</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {status.map((s) => (
                <tr key={s.scope}>
                  <td>{SCOPE_LABELS[s.scope] || s.scope}</td>
                  <td className="cell-muted">{s.last_reviewed_at || 'Never'}</td>
                  <td className="cell-muted">{s.last_reviewed_by || '—'}</td>
                  <td style={{ color: s.overdue ? 'var(--danger, #a1442f)' : undefined }}>
                    {s.next_due_at}{s.overdue ? ' · overdue' : ''}
                  </td>
                  <td className="cell-muted">every {s.cadence_months} mo</td>
                  <td>
                    {recording === s.scope ? (
                      <div className="inline-form">
                        <textarea
                          className="dash-textarea"
                          value={notes}
                          onChange={(e) => setNotes(e.target.value)}
                          placeholder="What was reviewed and any changes made (optional)."
                        />
                        <div className="btn-row">
                          <button className="btn btn--primary btn-sm" onClick={() => record(s.scope)}>Save review</button>
                          <button className="btn btn--outline btn-sm" onClick={() => setRecording(null)}>Cancel</button>
                        </div>
                      </div>
                    ) : (
                      <button className="btn btn--outline btn-sm" onClick={() => { setRecording(s.scope); setNotes(''); }}>
                        Record review
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Review log</h2>
          </div>
        </div>
        <table className="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Scope</th>
              <th>By</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            {history.length === 0 && (
              <tr><td colSpan={4} className="empty-row">No reviews recorded yet.</td></tr>
            )}
            {history.map((h) => (
              <tr key={h.id}>
                <td className="cell-muted">{(h.performed_at || '').slice(0, 10)}</td>
                <td>{SCOPE_LABELS[h.scope] || h.scope}</td>
                <td className="cell-muted">{h.reviewer?.full_name || '—'}</td>
                <td className="cell-muted">{h.notes || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <Pager meta={historyMeta} page={page} onPage={setPage} />
      </section>
    </DashboardShell>
  );
}
