import { useEffect, useMemo, useState } from 'react';
import { Sparkles } from 'lucide-react';
import api from '../lib/api';
import { downloadReportCsv } from '../lib/download';

const STATUS_OPTIONS = ['pending', 'approved', 'rejected', 'revision'];
const KIND_OPTIONS = ['all', 'document', 'request'];

// Keep in sync with ReportController::NARRATABLE_REPORTS — the aggregate
// / scored reports where an AI sentence of context adds something over
// the table. The raw-list reports have nothing extra for it to say.
const NARRATABLE_REPORTS = ['compliance-evidence', 'office-submission-compliance', 'document-aging'];

/**
 * Phase 6.2 — the report picker (PF-16 surface). Lists GET /api/reports,
 * renders each report's declared filters, runs it, shows the summary +
 * table, and exports the same rows as CSV.
 */
export default function ReportRunner() {
  const [reports, setReports] = useState([]);
  const [selectedKey, setSelectedKey] = useState('');
  const [categories, setCategories] = useState([]);
  const [offices, setOffices] = useState([]);
  const [filters, setFilters] = useState({});
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState('');
  const [narrative, setNarrative] = useState(null);
  const [narrativeLoading, setNarrativeLoading] = useState(false);
  const [narrativeError, setNarrativeError] = useState('');

  useEffect(() => {
    Promise.all([
      api.get('/reports'),
      api.get('/categories'),
      api.get('/offices'),
    ])
      .then(([r, c, o]) => {
        setReports(r.data);
        setCategories(c.data);
        setOffices(o.data);
        if (r.data[0]) setSelectedKey(r.data[0].key);
      })
      .catch((err) => setError(err?.response?.data?.message || 'Could not load the report list.'));
  }, []);

  const report = useMemo(
    () => reports.find((r) => r.key === selectedKey) || null,
    [reports, selectedKey]
  );

  useEffect(() => {
    setFilters({});
    setResult(null);
    setError('');
    setNarrative(null);
    setNarrativeError('');
  }, [selectedKey]);

  async function run() {
    if (!report) return;
    setLoading(true);
    setError('');
    setNarrative(null);
    setNarrativeError('');
    try {
      const params = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v != null)
      );
      const { data } = await api.get(`/reports/${report.key}`, { params });
      setResult(data);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not run that report.');
    } finally {
      setLoading(false);
    }
  }

  async function generateNarrative() {
    if (!report) return;
    setNarrativeLoading(true);
    setNarrativeError('');
    try {
      const params = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v != null)
      );
      const { data } = await api.post(`/reports/${report.key}/narrative`, params);
      setNarrative(data);
    } catch (err) {
      setNarrativeError(err?.response?.data?.message || 'Could not draft a narrative right now.');
    } finally {
      setNarrativeLoading(false);
    }
  }

  async function exportCsv() {
    if (!report) return;
    setExporting(true);
    try {
      const params = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v != null)
      );
      await downloadReportCsv(report.key, params);
    } catch {
      setError('Could not export the CSV.');
    } finally {
      setExporting(false);
    }
  }

  function setFilter(key, value) {
    setFilters((f) => ({ ...f, [key]: value }));
  }

  function renderFilter(key) {
    const val = filters[key] ?? '';
    const common = { id: `f-${key}`, value: val, className: 'dash-select' };
    switch (key) {
      case 'date_from':
      case 'date_to':
        return <input {...common} type="date" onChange={(e) => setFilter(key, e.target.value)} />;
      case 'category_id':
        return (
          <select {...common} onChange={(e) => setFilter(key, e.target.value)}>
            <option value="">All categories</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>{c.category_name}</option>
            ))}
          </select>
        );
      case 'office_id':
        return (
          <select {...common} onChange={(e) => setFilter(key, e.target.value)}>
            <option value="">All offices</option>
            {offices.map((o) => (
              <option key={o.id} value={o.id}>{o.office_name}</option>
            ))}
          </select>
        );
      case 'status':
        return (
          <select {...common} onChange={(e) => setFilter(key, e.target.value)}>
            <option value="">Any status</option>
            {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        );
      case 'kind':
        return (
          <select {...common} onChange={(e) => setFilter(key, e.target.value)}>
            {KIND_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        );
      default:
        return (
          <input
            {...common}
            type="text"
            placeholder={key}
            onChange={(e) => setFilter(key, e.target.value)}
          />
        );
    }
  }

  return (
    <section className="panel">
      <div className="panel-header">
        <div>
          <h2 className="panel-title">Reports</h2>
          <p className="panel-subtitle">Run any of the {reports.length} repository reports and export to CSV.</p>
        </div>
      </div>

      {error && <p className="error-banner">{error}</p>}

      <div className="dash-field" style={{ maxWidth: 420 }}>
        <label className="dash-label" htmlFor="report-key">Report</label>
        <select
          id="report-key"
          className="dash-select"
          value={selectedKey}
          onChange={(e) => setSelectedKey(e.target.value)}
        >
          {reports.map((r) => (
            <option key={r.key} value={r.key}>{r.label}</option>
          ))}
        </select>
        {report && <p className="cell-muted" style={{ marginTop: '0.35rem' }}>{report.description}</p>}
      </div>

      {report && (
        <>
          <div className="filter-bar" style={{ flexWrap: 'wrap', gap: '0.75rem', margin: '1rem 0' }}>
            {(report.filters || []).map((key) => (
              <div className="filter-field" key={key}>
                <label htmlFor={`f-${key}`}>
                  {key.replace(/_/g, ' ')}
                </label>
                {renderFilter(key)}
              </div>
            ))}
            <button className="btn btn--primary btn-sm" onClick={run} disabled={loading}>
              {loading ? 'Running…' : 'Run report'}
            </button>
            <button className="btn btn--outline btn-sm" onClick={exportCsv} disabled={exporting || !result}>
              {exporting ? 'Exporting…' : 'Download CSV'}
            </button>
            {report && NARRATABLE_REPORTS.includes(report.key) && result && (
              <button
                className="btn btn--outline btn-sm"
                onClick={generateNarrative}
                disabled={narrativeLoading}
              >
                <Sparkles size={14} style={{ marginRight: '0.35rem' }} />
                {narrativeLoading ? 'Drafting…' : narrative ? 'Regenerate AI summary' : 'Generate AI summary'}
              </button>
            )}
          </div>

          {result && (
            <>
              {result.summary && Object.keys(result.summary).length > 0 && (
                <div className="stat-grid" style={{ marginBottom: '1rem' }}>
                  {Object.entries(result.summary).map(([k, v]) => (
                    <div className="stat-card" key={k}>
                      <div className="stat-value">{v ?? '—'}</div>
                      <div className="stat-label">{k.replace(/_/g, ' ')}</div>
                    </div>
                  ))}
                </div>
              )}

              {narrativeError && <p className="error-banner">{narrativeError}</p>}

              {narrative && (
                <div className="ai-narrative-card">
                  <div className="ai-narrative-head">
                    <Sparkles size={14} />
                    <span>AI-drafted summary — verify against the table below</span>
                  </div>
                  <p className="ai-narrative-text">{narrative.narrative}</p>
                  {narrative.key_points?.length > 0 && (
                    <ul className="ai-narrative-points">
                      {narrative.key_points.map((point, i) => <li key={i}>{point}</li>)}
                    </ul>
                  )}
                  <p className="ai-narrative-meta">
                    {Math.round(narrative.confidence * 100)}% confidence · {narrative.model}
                  </p>
                </div>
              )}

              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      {result.columns.map((c) => (
                        <th key={c.key}>{c.label}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {result.rows.length === 0 && (
                      <tr>
                        <td colSpan={result.columns.length} className="empty-row">
                          No rows for these filters.
                        </td>
                      </tr>
                    )}
                    {result.rows.map((row, i) => (
                      <tr key={i}>
                        {result.columns.map((c) => (
                          <td key={c.key}>{String(row[c.key] ?? '')}</td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="cell-muted" style={{ marginTop: '0.5rem' }}>
                {result.truncated
                  ? `Showing the first ${result.rows.length} of ${result.total_rows} rows — export to CSV for the full set.`
                  : `${result.rows.length} row${result.rows.length === 1 ? '' : 's'}`}{' '}
                · generated {result.generated_at}
              </p>
            </>
          )}
        </>
      )}
    </section>
  );
}
