import { useEffect, useMemo, useState } from 'react';
import { Sparkles } from 'lucide-react';
import api from '../lib/api';
import { downloadReportCsv } from '../lib/download';
import Banner from './Banner';
import Pager from './Pager';
import usePagination from '../lib/usePagination';

const STATUS_OPTIONS = ['pending', 'approved', 'rejected', 'revision'];
const KIND_OPTIONS = ['all', 'document', 'request'];

// Keep in sync with ReportController::NARRATABLE_REPORTS — the aggregate
// / scored reports where an AI sentence of context adds something over
// the table. The raw-list reports have nothing extra for it to say.
const NARRATABLE_REPORTS = ['compliance-evidence', 'office-submission-compliance', 'document-aging'];

// A breakdown tile shows at most this many rows before summarising the
// rest as "+N more" — audit-trail's by_action/most_active_users can carry
// up to 15/10 entries, which is too many lines for a stat tile.
const BREAKDOWN_VISIBLE_ROWS = 5;

/**
 * Most summary values are a plain scalar (a count, a percentage) and
 * render straight into the stat tile. A few reports (document-inventory's
 * by_status, audit-trail's by_action / by_actor) instead return a
 * breakdown keyed by label -> count. Rendering that as one long
 * "label: count, label: count, …" string at display-figure size is what
 * overflowed the tile and overlapped its neighbours — this renders it as
 * a small label/count list instead, capped to a few rows with a button to
 * show the rest (and collapse back) rather than a dead-end "+N more".
 */
function SummaryValue({ value }) {
  const [expanded, setExpanded] = useState(false);

  if (value === null || value === undefined) {
    return <div className="stat-value">—</div>;
  }
  if (typeof value !== 'object') {
    return <div className="stat-value">{value}</div>;
  }

  const entries = Object.entries(value);
  if (entries.length === 0) {
    return <div className="stat-value">—</div>;
  }

  const hiddenCount = entries.length - BREAKDOWN_VISIBLE_ROWS;
  const visible = expanded ? entries : entries.slice(0, BREAKDOWN_VISIBLE_ROWS);

  return (
    <div className="stat-value stat-value--breakdown">
      {visible.map(([label, count]) => (
        <div className="breakdown-row" key={label}>
          <span>{label}</span>
          <span className="breakdown-count">{count}</span>
        </div>
      ))}
      {hiddenCount > 0 && (
        <button
          type="button"
          className="breakdown-toggle"
          onClick={() => setExpanded((v) => !v)}
          aria-expanded={expanded}
        >
          {expanded ? 'Show fewer' : `+${hiddenCount} more`}
        </button>
      )}
    </div>
  );
}

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

  // The API already caps rows at report_row_cap (500) before sending them
  // down — this pages through that already-loaded set in the browser, it
  // does not fetch more from the server.
  const rows = result?.rows ?? [];
  const { pageItems, page, setPage, meta } = usePagination(rows);

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
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not export the CSV.');
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

      {error && <Banner tone="error">{error}</Banner>}

      <div className="dash-field u-maxw-sm">
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
        {report && <p className="cell-muted u-mt-1">{report.description}</p>}
      </div>

      {report && (
        <>
          {(report.filters || []).length > 0 && (
            <div className="filter-bar u-mt-4">
              {report.filters.map((key) => (
                <div className="filter-field" key={key}>
                  <label htmlFor={`f-${key}`}>
                    {key.replace(/_/g, ' ')}
                  </label>
                  {renderFilter(key)}
                </div>
              ))}
            </div>
          )}

          <div className="btn-row u-mb-4">
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
                <Sparkles size={14} />
                {narrativeLoading ? 'Drafting…' : narrative ? 'Regenerate AI summary' : 'Generate AI summary'}
              </button>
            )}
          </div>

          {result && (
            <>
              {result.summary && Object.keys(result.summary).length > 0 && (
                <div className="stat-grid u-mb-4">
                  {Object.entries(result.summary).map(([k, v]) => (
                    <div className="stat-card" key={k}>
                      <SummaryValue value={v} />
                      <div className="stat-label">{k.replace(/_/g, ' ')}</div>
                    </div>
                  ))}
                </div>
              )}

              {narrativeError && <Banner tone="error">{narrativeError}</Banner>}

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
                        <th key={c.key} className={c.wrap ? 'col-wrap' : undefined} title={c.label}>
                          {c.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {rows.length === 0 && (
                      <tr>
                        <td colSpan={result.columns.length} className="empty-row">
                          No rows for these filters.
                        </td>
                      </tr>
                    )}
                    {pageItems.map((row, i) => (
                      <tr key={i}>
                        {result.columns.map((c) => {
                          const value = String(row[c.key] ?? '');
                          return (
                            <td key={c.key} className={c.wrap ? 'col-wrap' : undefined} title={c.wrap ? undefined : value}>
                              {value}
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Pager meta={meta} page={page} onPage={setPage} />

              {result.truncated && (
                <p className="cell-muted u-mt-1">
                  The database has {result.total_rows} matching rows — this report caps the on-screen
                  and CSV output at {result.row_cap}. Narrow the filters to see the rest.
                </p>
              )}
              <p className="cell-muted u-mt-1">generated {result.generated_at}</p>
            </>
          )}
        </>
      )}
    </section>
  );
}
