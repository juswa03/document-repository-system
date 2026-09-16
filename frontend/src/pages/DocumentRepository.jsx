import { Fragment, useEffect, useMemo, useState } from 'react';
import { Archive } from 'lucide-react';
import DashboardShell from './dashboards/DashboardShell';
import StatusBadge from '../components/StatusBadge';
import Pager from '../components/Pager';
import VersionHistoryModal from '../components/VersionHistoryModal';
import NewDocumentModal from './dashboards/NewDocumentModal';
import { useAuth } from '../context/AuthContext';
import api from '../lib/api';
import { downloadDocumentFile } from '../lib/download';
import '../pages/dashboards/dashboards.css';
import Banner from '../components/Banner';
import { TableSkeleton } from '../components/Skeleton';
import EmptyState from '../components/EmptyState';

const STATUS_OPTIONS = [
  { value: '', label: 'Any status' },
  { value: 'pending', label: 'Pending review' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'revision', label: 'Needs revision' },
];

export default function DocumentRepository() {
  const { user } = useAuth();
  const canUpload = user?.role === 'office_admin';

  const [categories, setCategories] = useState([]);
  const [offices, setOffices] = useState([]);
  const ownOfficeName = useMemo(
    () => offices.find((o) => String(o.id) === String(user?.office_id))?.office_name,
    [offices, user?.office_id],
  );
  const [objectives, setObjectives] = useState([]);
  const [uploadOpen, setUploadOpen] = useState(false);

  const [q, setQ] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [officeId, setOfficeId] = useState('');
  const [objectiveId, setObjectiveId] = useState('');
  const [status, setStatus] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);

  const [nl, setNl] = useState('');
  const [smartNote, setSmartNote] = useState('');

  const [result, setResult] = useState({ data: [], meta: null });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [historyDoc, setHistoryDoc] = useState(null);
  const [detailId, setDetailId] = useState(null);

  const params = useMemo(
    () => ({
      q: q || undefined,
      category_id: categoryId || undefined,
      office_id: officeId || undefined,
      objective_id: objectiveId || undefined,
      status: status || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
    }),
    [q, categoryId, officeId, objectiveId, status, dateFrom, dateTo, page]
  );

  useEffect(() => {
    Promise.all([
      api.get('/categories'),
      api.get('/offices'),
      api.get('/repository/objectives'),
    ])
      .then(([catRes, officeRes, objRes]) => {
        setCategories(catRes.data);
        setOffices(officeRes.data);
        setObjectives(objRes.data);
      })
      .catch(() => {
        // Dropdowns just stay empty if this fails — search still works.
      });
  }, []);

  async function runSearch(override) {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/repository/documents', { params: override || params });
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
    setSmartNote('');
    setPage(1);
    runSearch();
  }

  function describe(f) {
    const bits = [];
    if (f.q) bits.push(`text "${f.q}"`);
    if (f.category_id) bits.push(`category ${categories.find((c) => c.id === f.category_id)?.category_name || f.category_id}`);
    if (f.office_id) bits.push(`office ${offices.find((o) => o.id === f.office_id)?.office_name || f.office_id}`);
    if (f.status) bits.push(`status ${f.status}`);
    if (f.date_from) bits.push(`from ${f.date_from}`);
    if (f.date_to) bits.push(`to ${f.date_to}`);
    return bits.length ? bits.join(', ') : 'no specific filters';
  }

  async function runSmartSearch(e) {
    e.preventDefault();
    if (!nl.trim()) return;
    setLoading(true);
    setError('');
    try {
      const { data } = await api.post('/repository/search', { query: nl.trim() });
      const f = data.interpreted || {};
      setQ(f.q || '');
      setCategoryId(f.category_id ? String(f.category_id) : '');
      setOfficeId(f.office_id ? String(f.office_id) : '');
      setObjectiveId('');
      setStatus(f.status || '');
      setDateFrom(f.date_from || '');
      setDateTo(f.date_to || '');
      setPage(1);
      setResult({ data: data.results.data, meta: data.results.meta });
      setSmartNote(
        data.ai
          ? `Read as: ${describe(f)}. Tweak the filters below and Search to refine.`
          : `AI is off — ran "${nl.trim()}" as a plain text search.`
      );
      setNl('');
    } catch (err) {
      setError(err?.response?.data?.message || 'Smart search failed.');
    } finally {
      setLoading(false);
    }
  }

  function resetFilters() {
    setQ('');
    setCategoryId('');
    setOfficeId('');
    setObjectiveId('');
    setStatus('');
    setDateFrom('');
    setDateTo('');
    setSmartNote('');
    setPage(1);
  }

  async function handleDownload(doc) {
    try {
      await downloadDocumentFile(doc.id, doc.title);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not download that file.');
    }
  }

  return (
    <DashboardShell eyebrow="Document repository" title="Search &amp; filter documents">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        {canUpload && (
          <div className="panel-header">
            <div>
              <h2 className="panel-title">Repository</h2>
              <p className="panel-subtitle">
                Documents you upload here go through the normal review queue, labeled to your
                office. Another reviewer in your office decides on it.
              </p>
            </div>
            <button className="btn btn--primary btn-sm" onClick={() => setUploadOpen(true)}>
              + Upload document
            </button>
          </div>
        )}

        <form className="filter-bar u-mb-3" onSubmit={runSmartSearch}>
          <div className="filter-field filter-field--grow">
            <label htmlFor="nl">
              Ask in plain language
            </label>
            <input
              id="nl"
              type="text"
              placeholder='e.g. "approved board minutes about the 2027 budget from Head Office"'
              value={nl}
              onChange={(e) => setNl(e.target.value)}
            />
          </div>
          <div className="btn-row">
            <button type="submit" className="btn btn--primary btn-sm" disabled={!nl.trim()}>
              Smart search
            </button>
          </div>
        </form>

        {smartNote && (
          <p className="cell-muted u-my-0 u-mb-3">
            {smartNote}{' '}
            <button
              type="button"
              className="btn btn--outline btn-sm u-ml-1"
              onClick={resetFilters}
            >
              Clear
            </button>
          </p>
        )}

        <form className="filter-bar" onSubmit={handleSearchSubmit}>
          <div className="filter-field filter-field--grow">
            <label htmlFor="q">
              Keyword
            </label>
            <input
              id="q"
              type="text"
              placeholder="Words in the title, tracking number, or document text"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>

          <div className="filter-field">
            <label htmlFor="category">
              Category
            </label>
            <select id="category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
              <option value="">Any category</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>{c.category_name}</option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="office">
              Office
            </label>
            <select id="office" value={officeId} onChange={(e) => setOfficeId(e.target.value)}>
              <option value="">Any office</option>
              {offices.map((o) => (
                <option key={o.id} value={o.id}>{o.office_name}</option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="objective">
              Objective
            </label>
            <select id="objective" value={objectiveId} onChange={(e) => setObjectiveId(e.target.value)}>
              <option value="">Any objective</option>
              {objectives.map((o) => (
                <option key={o.id} value={o.id}>{o.code} — {o.title}</option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="status">
              Status
            </label>
            <select id="status" value={status} onChange={(e) => setStatus(e.target.value)}>
              {STATUS_OPTIONS.map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="dateFrom">
              From
            </label>
            <input id="dateFrom" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          </div>

          <div className="filter-field">
            <label htmlFor="dateTo">
              To
            </label>
            <input id="dateTo" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          </div>

          <div className="btn-row">
            <button type="submit" className="btn btn--primary btn-sm">Search</button>
            <button type="button" className="btn btn--outline btn-sm" onClick={resetFilters}>Clear</button>
          </div>
        </form>

        {loading ? (
          <TableSkeleton rows={6} label="Searching the repository" />
        ) : result.data.length === 0 ? (
          <EmptyState
            icon={<Archive size={22} />}
            title="No documents match"
            message="Nothing in the repository fits these filters. Widen the date range or clear a filter to see more."
          />
        ) : (
          <>
            <div className="table-scroll">
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
                {result.data.map((d) => (
                  <Fragment key={d.id}>
                    <tr>
                      <td className="cell-mono">{d.ref}</td>
                      <td>{d.title}</td>
                      <td className="cell-muted">{d.category}</td>
                      <td className="cell-muted">{d.office}</td>
                      <td className="cell-muted">{d.uploader}</td>
                      <td className="cell-muted">{new Date(d.submitted_at).toLocaleDateString()}</td>
                      <td><StatusBadge status={d.status} /></td>
                      <td>
                        <div className="btn-row">
                          <button
                            className="btn btn--outline btn-sm"
                            onClick={() => setDetailId((id) => (id === d.id ? null : d.id))}
                          >
                            {detailId === d.id ? 'Hide' : 'Details'}
                          </button>
                          <button className="btn btn--outline btn-sm" onClick={() => handleDownload(d)}>
                            Download
                          </button>
                          {d.version_number > 1 && (
                            <button
                              className="btn btn--outline btn-sm"
                              onClick={() => setHistoryDoc({ id: d.id, ref: d.ref })}
                            >
                              History
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                    {detailId === d.id && (
                      <tr>
                        <td colSpan={8} className="detail-row-cell">
                          <div className="detail-row-inner">
                            <div>
                              <strong>AI summary:</strong>{' '}
                              {d.summary ? (
                                d.summary
                              ) : (
                                <span className="cell-muted">none generated</span>
                              )}
                            </div>
                            <div className="cell-muted">
                              Objectives: {d.objectives?.length ? d.objectives.join(', ') : '—'} ·
                              Retention: {d.retention_status} · Version: v{d.version_number}
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
            </div>

            <Pager meta={result.meta} page={page} onPage={setPage} />
          </>
        )}
      </section>

      {historyDoc && (
        <VersionHistoryModal
          documentId={historyDoc.id}
          reference={historyDoc.ref}
          onClose={() => setHistoryDoc(null)}
        />
      )}

      {uploadOpen && (
        <NewDocumentModal
          categories={categories}
          offices={offices}
          lockTargetOffice
          lockedOfficeName={ownOfficeName}
          onClose={() => setUploadOpen(false)}
          onSaved={() => runSearch()}
        />
      )}
    </DashboardShell>
  );
}
