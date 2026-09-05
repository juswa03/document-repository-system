import { useEffect, useMemo, useState } from 'react';
import {
  ResponsiveContainer,
  AreaChart,
  Area,
  BarChart,
  Bar,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
} from 'recharts';
import DashboardShell from './dashboards/DashboardShell';
import ReportRunner from '../components/ReportRunner';
import api from '../lib/api';
import { CHART_COLORS, STATUS_META, monthLabel, ChartTooltip } from '../lib/chartTheme';
import './dashboards/dashboards.css';
import './Reports.css';

export default function Reports() {
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  async function load() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/reports/documents', {
        params: { date_from: dateFrom || undefined, date_to: dateTo || undefined },
      });
      setReport(data);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load the report.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleFilter(e) {
    e.preventDefault();
    load();
  }

  const monthData = useMemo(
    () => (report?.by_month || []).map((m) => ({ month: monthLabel(m.month), total: m.total })),
    [report]
  );

  const statusData = useMemo(
    () =>
      Object.entries(report?.by_status || {}).map(([key, value]) => ({
        key,
        name: STATUS_META[key]?.label || key,
        value,
        color: STATUS_META[key]?.color || CHART_COLORS.inkSoft,
      })),
    [report]
  );

  const categoryData = useMemo(() => report?.by_category || [], [report]);
  const officeData = useMemo(() => report?.by_office || [], [report]);

  return (
    <DashboardShell eyebrow="Reports" title="Repository reporting">
      {error && <p className="error-banner">{error}</p>}

      <ReportRunner />

      <h2 className="panel-title" style={{ margin: '2rem 0 0.75rem' }}>Overview</h2>
      <p className="panel-subtitle" style={{ marginBottom: '1rem' }}>
        Document volume, type &amp; status at a glance.
      </p>

      <section className="panel">
        <form className="filter-bar" onSubmit={handleFilter}>
          <div className="filter-field">
            <label htmlFor="dateFrom">From</label>
            <input id="dateFrom" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          </div>
          <div className="filter-field">
            <label htmlFor="dateTo">To</label>
            <input id="dateTo" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          </div>
          <button type="submit" className="btn btn--primary btn-sm">Apply</button>
        </form>

        {loading || !report ? (
          <p className="loading-text">Loading report…</p>
        ) : (
          <div className="stat-grid" style={{ marginBottom: 0 }}>
            <div className="stat-card">
              <div className="stat-value">{report.total}</div>
              <div className="stat-label">Total documents</div>
            </div>
            {Object.entries(report.by_status).map(([status, count]) => (
              <div className="stat-card" key={status}>
                <div className="stat-value">{count}</div>
                <div className="stat-label">{STATUS_META[status]?.label || status}</div>
              </div>
            ))}
          </div>
        )}
      </section>

      {!loading && report && (
        <>
          <div className="chart-grid" style={{ marginBottom: '1.5rem' }}>
            <section className="panel chart-panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">Submissions over time</h2>
                  <p className="panel-subtitle">Documents received per month.</p>
                </div>
              </div>
              {monthData.length === 0 ? (
                <div className="chart-empty">No data for this range.</div>
              ) : (
                <ResponsiveContainer width="100%" height={240}>
                  <AreaChart data={monthData} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
                    <defs>
                      <linearGradient id="volumeFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={CHART_COLORS.seal} stopOpacity={0.35} />
                        <stop offset="100%" stopColor={CHART_COLORS.seal} stopOpacity={0.02} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid stroke={CHART_COLORS.line} vertical={false} />
                    <XAxis dataKey="month" tickLine={false} axisLine={{ stroke: CHART_COLORS.line }} />
                    <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={32} />
                    <Tooltip content={<ChartTooltip />} />
                    <Area
                      type="monotone"
                      dataKey="total"
                      name="Documents"
                      stroke={CHART_COLORS.seal}
                      strokeWidth={2}
                      fill="url(#volumeFill)"
                    />
                  </AreaChart>
                </ResponsiveContainer>
              )}
            </section>

            <section className="panel chart-panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">Status breakdown</h2>
                  <p className="panel-subtitle">Current state of all matching documents.</p>
                </div>
              </div>
              {statusData.every((s) => s.value === 0) ? (
                <div className="chart-empty">No data for this range.</div>
              ) : (
                <div className="donut-row">
                  <ResponsiveContainer width={140} height={140}>
                    <PieChart>
                      <Pie
                        data={statusData}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={42}
                        outerRadius={64}
                        paddingAngle={statusData.length > 1 ? 3 : 0}
                        stroke="none"
                      >
                        {statusData.map((s) => (
                          <Cell key={s.key} fill={s.color} />
                        ))}
                      </Pie>
                      <Tooltip content={<ChartTooltip />} />
                    </PieChart>
                  </ResponsiveContainer>
                  <ul className="donut-legend">
                    {statusData.map((s) => (
                      <li key={s.key}>
                        <span className="legend-dot" style={{ background: s.color }} />
                        <span className="legend-label">{s.name}</span>
                        <span className="legend-value">{s.value}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </section>
          </div>

          <div className="chart-grid-even">
            <section className="panel chart-panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">By category</h2>
                  <p className="panel-subtitle">Volume per document category.</p>
                </div>
              </div>
              {categoryData.length === 0 ? (
                <div className="chart-empty">No data for this range.</div>
              ) : (
                <ResponsiveContainer width="100%" height={Math.max(160, categoryData.length * 34)}>
                  <BarChart
                    data={categoryData}
                    layout="vertical"
                    margin={{ top: 4, right: 20, left: 8, bottom: 4 }}
                  >
                    <CartesianGrid stroke={CHART_COLORS.line} horizontal={false} />
                    <XAxis type="number" allowDecimals={false} tickLine={false} axisLine={{ stroke: CHART_COLORS.line }} />
                    <YAxis
                      type="category"
                      dataKey="category"
                      width={130}
                      tickLine={false}
                      axisLine={false}
                    />
                    <Tooltip content={<ChartTooltip />} cursor={{ fill: CHART_COLORS.sealSoft }} />
                    <Bar dataKey="total" name="Documents" fill={CHART_COLORS.seal} radius={[0, 4, 4, 0]} barSize={14} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </section>

            <section className="panel chart-panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">By office</h2>
                  <p className="panel-subtitle">Volume per submitting office.</p>
                </div>
              </div>
              {officeData.length === 0 ? (
                <div className="chart-empty">No data for this range.</div>
              ) : (
                <ResponsiveContainer width="100%" height={Math.max(160, officeData.length * 34)}>
                  <BarChart
                    data={officeData}
                    layout="vertical"
                    margin={{ top: 4, right: 20, left: 8, bottom: 4 }}
                  >
                    <CartesianGrid stroke={CHART_COLORS.line} horizontal={false} />
                    <XAxis type="number" allowDecimals={false} tickLine={false} axisLine={{ stroke: CHART_COLORS.line }} />
                    <YAxis
                      type="category"
                      dataKey="office"
                      width={130}
                      tickLine={false}
                      axisLine={false}
                    />
                    <Tooltip content={<ChartTooltip />} cursor={{ fill: CHART_COLORS.sealSoft }} />
                    <Bar dataKey="total" name="Documents" fill={CHART_COLORS.ledger} radius={[0, 4, 4, 0]} barSize={14} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </section>
          </div>
        </>
      )}
    </DashboardShell>
  );
}
