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
import DashboardShell from './DashboardShell';
import StatCard from '../../components/StatCard';
import api from '../../lib/api';
import { CHART_COLORS, ChartTooltip, monthLabel } from '../../lib/chartTheme';
import '../Reports.css';
import './dashboards.css';
import './OfficeAdminDashboard.css';

export default function OfficeAdminDashboard() {
  const [stats, setStats] = useState(null);
  const [reportData, setReportData] = useState(null);

  useEffect(() => {
    api.get('/office-admin/stats').then(({ data }) => setStats(data)).catch(() => {});
    api.get('/reports/documents').then(({ data }) => setReportData(data)).catch(() => {});
  }, []);

  const queueData = useMemo(() => {
    const d = stats?.documents;
    if (!d) return [];
    return [
      { name: 'Pending', value: d.pending, color: CHART_COLORS.inkSoft },
      { name: 'Overdue', value: d.overdue, color: CHART_COLORS.danger },
      { name: 'Approved', value: d.approved, color: CHART_COLORS.seal },
      { name: 'Needs revision', value: d.revision, color: CHART_COLORS.ledger },
      { name: 'Rejected', value: d.rejected, color: '#c97a6a' },
    ].filter((s) => s.value > 0);
  }, [stats]);

  const monthData = useMemo(
    () => (reportData?.by_month || []).map((m) => ({ month: monthLabel(m.month), total: m.total })),
    [reportData],
  );

  const categoryData = useMemo(() => reportData?.by_category || [], [reportData]);

  return (
    <DashboardShell eyebrow="Office admin" title="Overview">
      <div className="stat-grid">
        <StatCard label="Awaiting review" value={stats?.awaiting_review} />
        <StatCard label="Overdue" value={stats?.documents?.overdue} tone="danger" />
        <StatCard label="Approved" value={stats?.documents?.approved} tone="success" />
        <StatCard label="Needs revision" value={stats?.documents?.revision} tone="warning" />
        <StatCard label="Rejected" value={stats?.documents?.rejected} />
        <StatCard label="This week" value={stats?.documents?.submitted_last_7_days} />
      </div>

      <div className="chart-grid u-mb-5">
        <section className="panel chart-panel">
          <div className="panel-header">
            <div>
              <h2 className="panel-title">Submissions over time</h2>
              <p className="panel-subtitle">Documents received per month.</p>
            </div>
          </div>
          {monthData.length === 0 ? (
            <div className="chart-empty">No submission data yet.</div>
          ) : (
            <ResponsiveContainer width="100%" height={240}>
              <AreaChart data={monthData} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
                <defs>
                  <linearGradient id="osmVolumeFill" x1="0" y1="0" x2="0" y2="1">
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
                  fill="url(#osmVolumeFill)"
                />
              </AreaChart>
            </ResponsiveContainer>
          )}
        </section>

        <section className="panel chart-panel">
          <div className="panel-header">
            <div>
              <h2 className="panel-title">Queue breakdown</h2>
              <p className="panel-subtitle">Documents by current status.</p>
            </div>
          </div>
          {queueData.length === 0 ? (
            <div className="chart-empty">No data yet.</div>
          ) : (
            <div className="donut-row">
              <ResponsiveContainer width={140} height={140}>
                <PieChart>
                  <Pie
                    data={queueData}
                    dataKey="value"
                    nameKey="name"
                    innerRadius={42}
                    outerRadius={64}
                    paddingAngle={queueData.length > 1 ? 3 : 0}
                    stroke="none"
                  >
                    {queueData.map((s) => (
                      <Cell key={s.name} fill={s.color} />
                    ))}
                  </Pie>
                  <Tooltip content={<ChartTooltip />} />
                </PieChart>
              </ResponsiveContainer>
              <ul className="donut-legend">
                {queueData.map((s) => (
                  <li key={s.name}>
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

      <div className="chart-grid-even u-mb-5">
        <section className="panel chart-panel">
          <div className="panel-header">
            <div>
              <h2 className="panel-title">By category</h2>
              <p className="panel-subtitle">Volume per document category.</p>
            </div>
          </div>
          {categoryData.length === 0 ? (
            <div className="chart-empty">No category data yet.</div>
          ) : (
            <ResponsiveContainer width="100%" height={Math.max(160, categoryData.length * 34)}>
              <BarChart
                data={categoryData}
                layout="vertical"
                margin={{ top: 4, right: 20, left: 8, bottom: 4 }}
              >
                <CartesianGrid stroke={CHART_COLORS.line} horizontal={false} />
                <XAxis
                  type="number"
                  allowDecimals={false}
                  tickLine={false}
                  axisLine={{ stroke: CHART_COLORS.line }}
                />
                <YAxis
                  type="category"
                  dataKey="category"
                  width={130}
                  tickLine={false}
                  axisLine={false}
                />
                <Tooltip content={<ChartTooltip />} cursor={{ fill: CHART_COLORS.sealSoft }} />
                <Bar
                  dataKey="total"
                  name="Documents"
                  fill={CHART_COLORS.seal}
                  radius={[0, 4, 4, 0]}
                  barSize={14}
                />
              </BarChart>
            </ResponsiveContainer>
          )}
        </section>

        <section className="panel">
          <div className="panel-header">
            <div>
              <h2 className="panel-title">Lead-time</h2>
              <p className="panel-subtitle">
                Avg. days from submission to decision (last 30 days).
              </p>
            </div>
          </div>
          <div className="u-py-3">
            <div className="stat-card stat-card--narrow">
              <div className="stat-value">{stats?.avg_lead_days ?? '—'}</div>
              <div className="stat-label">Avg days to resolve</div>
            </div>
          </div>
        </section>
      </div>
    </DashboardShell>
  );
}
