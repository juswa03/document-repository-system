import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './dashboards/DashboardShell';
import StatusBadge from '../components/StatusBadge';
import api from '../lib/api';
import { downloadDocumentFile } from '../lib/download';
import '../pages/dashboards/dashboards.css';

const STATUS_OPTIONS = [
  { value: '', label: 'Any status' },
  { value: 'pending', label: 'Pending review' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'revision', label: 'Needs revision' },
];

export default function DocumentRepository() {
  const [categories, setCategories] = useState([]);
  const [offices, setOffices] = useState([]);

  const [q, setQ] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [officeId, setOfficeId] = useState('');
  const [status, setStatus] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);

  const [result, setResult] = useState({ data: [], meta: null });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const params = useMemo(
    () => ({
      q: q || undefined,
      category_id: categoryId || undefined,
      office_id: officeId || undefined,
      status: status || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
    }),
    [q, categoryId, officeId, status, dateFrom, dateTo, page]
  );

  useEffect(() => {
    Promise.all([api.get('/categories'), api.get('/offices')])
      .then(([catRes, officeRes]) => {
        setCategories(catRes.data);
        setOffices(officeRes.data);
      })
      .catch(() => {
        // Dropdowns just stay empty if this fails — search still works.
      });
  }, []);

  async function runSearch() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/repository/documents', { params });
      setResult({ data: data.data, meta: data.meta });
    } catch (err) {
      setError(err?.response?.data?.message || 'Search failed. Try again.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    runSearch();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page]);

  function handleSearchSubmit(e) {
    e.preventDefault();
    setPage(1);
    runSearch();
  }

  function resetFilters() {
    setQ('');
    setCategoryId('');
    setOfficeId('');
    setStatus('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  }

  async function handleDownload(doc) {
    try {
      await downloadDocumentFile(doc.id, doc.title);
    } catch {
      setError('Could not download that file.');
    }
  }

  return (
    <DashboardShell eyebrow="Document repository" title="Search &amp; filter documents">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <form className="filter-bar" onSubmit={handleSearchSubmit}>
          <div className="filter-field filter-field--grow">
            <label htmlFor="q" style={{ color: 'var(--text-label)' }}>
              Keyword
            </label>
            <input
              id="q"
              type="text"
              placeholder="Title or tracking number"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>

          <div className="filter-field">
            <label htmlFor="category" style={{ color: 'var(--text-label)' }}>
              Category
            </label>
            <select id="category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={{ color: 'var(--text-value)' }}>
              <option value="" style={{ color: 'var(--text-value)' }}>Any category</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>{c.category_name}</option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="office" style={{ color: 'var(--text-label)' }}>
              Office
            </label>
            <select id="office" value={officeId} onChange={(e) => setOfficeId(e.target.value)} style={{ color: 'var(--text-value)' }}>
              <option value="" style={{ color: 'var(--text-value)' }}>Any office</option>
              {offices.map((o) => (
                <option key={o.id} value={o.id}>{o.office_name}</option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="status" style={{ color: 'var(--text-label)' }}>
              Status
            </label>
            <select id="status" value={status} onChange={(e) => setStatus(e.target.value)} style={{ color: 'var(--text-value)' }}>
              {STATUS_OPTIONS.map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="dateFrom" style={{ color: 'var(--text-label)' }}>
              From
            </label>
            <input id="dateFrom" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} style={{ color: 'var(--text-value)' }} />
          </div>

          <div className="filter-field">
            <label htmlFor="dateTo" style={{ color: 'var(--text-label)' }}>
              To
            </label>
            <input id="dateTo" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} style={{ color: 'var(--text-value)' }} />
          </div>

          <div className="btn-row">
            <button type="submit" className="btn btn--primary btn-sm">Search</button>
            <button type="button" className="btn btn--outline btn-sm" onClick={resetFilters}>Clear</button>
          </div>
        </form>

        {loading ? (
          <p className="loading-text">Searching…</p>
        ) : (
          <>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Title</th>
                  <th>Category</th>
                  <th>Office</th>
                  <th>Uploaded by</th>
                  <th>Submitted</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {result.data.length === 0 && (
                  <tr>
                    <td colSpan={8} className="empty-row">No documents match these filters.</td>
                  </tr>
                )}
                {result.data.map((d) => (
                  <tr key={d.id}>
                    <td className="cell-mono">{d.ref}</td>
                    <td>{d.title}</td>
                    <td className="cell-muted">{d.category}</td>
                    <td className="cell-muted">{d.office}</td>
                    <td className="cell-muted">{d.uploader}</td>
                    <td className="cell-muted">{new Date(d.submitted_at).toLocaleDateString()}</td>
                    <td><StatusBadge status={d.status} /></td>
                    <td>
                      <button className="btn btn--outline btn-sm" onClick={() => handleDownload(d)}>
                        Download
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {result.meta && result.meta.last_page > 1 && (
              <div className="pager">
                <span>
                  Page {result.meta.current_page} of {result.meta.last_page} — {result.meta.total} total
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
                    disabled={page >= result.meta.last_page}
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
