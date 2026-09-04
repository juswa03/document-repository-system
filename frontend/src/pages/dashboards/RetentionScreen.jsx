import { useCallback, useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

/**
 * Records-retention lifecycle (DR-14). Shows the retention position and
 * the documents that have reached their retention period or their
 * disposal grace period, with the archive / restore / dispose actions.
 */
export default function RetentionScreen() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [disposing, setDisposing] = useState(null); // { id, ref }
  const [reason, setReason] = useState('');

  const load = useCallback(async () => {
    setError('');
    try {
      const res = await api.get('/osm-admin/retention');
      setData(res.data);
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load the retention overview.');
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function act(id, path, body) {
    setBusyId(id);
    setError('');
    try {
      await api.post(`/osm-admin/documents/${id}/${path}`, body);
      setDisposing(null);
      setReason('');
      await load();
    } catch (e) {
      setError(e?.response?.data?.message || 'That action could not be completed.');
    } finally {
      setBusyId(null);
    }
  }

  const counts = data?.counts || {};
  const tiles = [
    { label: 'Active', value: counts.active },
    { label: 'Superseded', value: counts.superseded },
    { label: 'Archived', value: counts.archived },
    { label: 'Disposed', value: counts.disposed },
  ];

  return (
    <DashboardShell eyebrow="OSM admin" title="Records retention">
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
            <h2 className="panel-title">Due for archival</h2>
            <p className="panel-subtitle">
              Approved documents that have reached their retention period. Archiving keeps the
              document retrievable but removes it from the working repository.
            </p>
          </div>
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Title</th>
                <th>Category</th>
                <th>Retention reached</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {!data && (
                <tr>
                  <td colSpan={5} className="loading-text">
                    Loading…
                  </td>
                </tr>
              )}
              {data && data.due_for_archival.length === 0 && (
                <tr>
                  <td colSpan={5} className="empty-row">
                    Nothing is due for archival.
                  </td>
                </tr>
              )}
              {data?.due_for_archival.map((d) => (
                <tr key={d.id}>
                  <td className="cell-mono">{d.ref}</td>
                  <td>{d.title}</td>
                  <td className="cell-muted">{d.category || '—'}</td>
                  <td className="cell-muted">{d.retention_due_at}</td>
                  <td>
                    <button
                      className="btn btn--primary btn-sm"
                      disabled={busyId === d.id}
                      onClick={() => act(d.id, 'archive')}
                    >
                      Archive
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Archived — past the disposal grace period</h2>
            <p className="panel-subtitle">
              Disposal is permanent: the file is deleted and only a tombstone record remains. A
              written reason is required.
            </p>
          </div>
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Title</th>
                <th>Archived</th>
                <th>Eligible since</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {data && data.due_for_disposal.length === 0 && (
                <tr>
                  <td colSpan={5} className="empty-row">
                    Nothing is eligible for disposal.
                  </td>
                </tr>
              )}
              {data?.due_for_disposal.map((d) => (
                <tr key={d.id}>
                  <td className="cell-mono">{d.ref}</td>
                  <td>{d.title}</td>
                  <td className="cell-muted">{d.archived_at}</td>
                  <td className="cell-muted">{d.disposal_due_at}</td>
                  <td>
                    <div className="btn-row">
                      <button
                        className="btn btn--outline btn-sm"
                        disabled={busyId === d.id}
                        onClick={() => act(d.id, 'restore')}
                      >
                        Restore
                      </button>
                      <button
                        className="btn btn--danger-outline btn-sm"
                        disabled={busyId === d.id}
                        onClick={() => setDisposing({ id: d.id, ref: d.ref })}
                      >
                        Dispose
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {disposing && (
          <div className="inline-form">
            <div className="dash-field" style={{ marginBottom: '0.6rem' }}>
              <label className="dash-label" htmlFor="dispose-reason">
                Disposal reason for {disposing.ref} (kept on the record)
              </label>
              <textarea
                id="dispose-reason"
                className="dash-textarea"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. End of the approved retention schedule for FY 2020 minutes."
              />
            </div>
            <div className="btn-row">
              <button
                className="btn btn--danger-outline btn-sm"
                disabled={reason.trim().length < 5 || busyId === disposing.id}
                onClick={() => act(disposing.id, 'dispose', { reason })}
              >
                Confirm disposal
              </button>
              <button
                className="btn btn--outline btn-sm"
                onClick={() => {
                  setDisposing(null);
                  setReason('');
                }}
              >
                Cancel
              </button>
            </div>
          </div>
        )}
      </section>
    </DashboardShell>
  );
}
