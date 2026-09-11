import { useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import StatusBadge from '../../components/StatusBadge';
import Pager from '../../components/Pager';
import api from '../../lib/api';
import './dashboards.css';
import './OfficeAdminDashboard.css';
import Banner from '../../components/Banner';

/**
 * Review history for this office — every request/document already
 * approved, rejected, or sent back for revision. Split out of the queue
 * page so "what's still pending" and "what did we already decide" each
 * get their own screen.
 */
export default function DecidedSubmissions() {
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [categories, setCategories] = useState([]);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  async function load() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/office-admin/decided', {
        params: { page, category_id: categoryFilter || undefined },
      });
      setItems(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load the review history.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    api
      .get('/categories')
      .then(({ data }) => setCategories(data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, categoryFilter]);

  return (
    <DashboardShell eyebrow="Office admin" title="Decided submissions">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Decided submissions</h2>
            <p className="panel-subtitle">Requests and documents this office has already approved, rejected, or returned for revision.</p>
          </div>
          <div className="btn-row">
            {categories.length > 0 && (
              <select
                className="dash-select u-w-auto"
                value={categoryFilter}
                onChange={(e) => {
                  setPage(1);
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

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <div className="table-scroll">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Submitter</th>
                  <th>Type</th>
                  <th>Decision</th>
                  <th>Decided</th>
                  <th>Response sent</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody>
                {items.length === 0 && (
                  <tr>
                    <td colSpan={7} className="empty-row">
                      {categoryFilter ? 'No items match that category.' : 'No decisions recorded yet.'}
                    </td>
                  </tr>
                )}
                {items.map((item) => (
                  <tr key={`${item.kind}-${item.id}`}>
                    <td className="cell-mono">{item.ref}</td>
                    <td>{item.submitter}</td>
                    <td>
                      {item.kind === 'document' ? <>{item.type} (doc)</> : item.title || item.type}
                    </td>
                    <td>
                      <StatusBadge status={item.status} />
                    </td>
                    <td className="cell-muted">
                      {item.decided_at ? new Date(item.decided_at).toLocaleDateString() : '—'}
                    </td>
                    {/* Whether the submitter actually received something
                        back — the question a reviewer most often returns
                        to this page to answer. */}
                    <td className="cell-muted">
                      {item.response_file ? (
                        <span title={item.response_file.name}>
                          {item.response_file.from_repository
                            ? `Document ${item.response_file.ref || ''}`.trim()
                            : 'File attached'}
                        </span>
                      ) : (
                        ''
                      )}
                    </td>
                    {/* Blank rather than a dash: an approval needs no
                        remark, so an em-dash here would read as missing
                        data on most rows. */}
                    <td className="cell-muted">{item.remarks || ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <Pager meta={meta} page={page} onPage={setPage} />
      </section>
    </DashboardShell>
  );
}
