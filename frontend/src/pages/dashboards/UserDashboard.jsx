import { useMemo, useState } from 'react';
import {
  ResponsiveContainer,
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
import Banner from '../../components/Banner';
import DashboardShell from './DashboardShell';
import StatCard from '../../components/StatCard';
import ResubmitModal from './ResubmitModal';
import NewRequestModal from './NewRequestModal';
import NewDocumentModal from './NewDocumentModal';
import useSubmissions from './useSubmissions';
import { CHART_COLORS, STATUS_META, ChartTooltip, monthLabel } from '../../lib/chartTheme';
import '../Reports.css';
import './dashboards.css';
import './UserDashboard.css';

export default function UserDashboard() {
  const { submissions, requestTypes, categories, offices, loading, error, reload } = useSubmissions();

  const [resubmitTarget, setResubmitTarget] = useState(null);
  const [requestModalOpen, setRequestModalOpen] = useState(false);
  const [documentModalOpen, setDocumentModalOpen] = useState(false);

  const notices = submissions.filter((s) => s.status === 'revision' || s.status === 'rejected');

  const quickStats = useMemo(() => ({
    total: submissions.length,
    approved: submissions.filter((s) => s.status === 'approved').length,
    pending: submissions.filter((s) => s.status === 'pending').length,
    rejected: submissions.filter((s) => s.status === 'rejected').length,
  }), [submissions]);

  const myStatusData = useMemo(() => {
    const keys = ['pending', 'approved', 'rejected', 'revision'];
    return keys
      .map((k) => ({
        name: STATUS_META[k]?.label || k,
        value: submissions.filter((s) => s.status === k).length,
        color: STATUS_META[k]?.color || CHART_COLORS.inkSoft,
      }))
      .filter((s) => s.value > 0);
  }, [submissions]);

  const myMonthData = useMemo(() => {
    const byMonth = {};
    submissions.forEach((s) => {
      if (!s.submitted_at) return;
      const key = s.submitted_at.slice(0, 7);
      byMonth[key] = (byMonth[key] || 0) + 1;
    });
    return Object.entries(byMonth)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([month, total]) => ({ month: monthLabel(month), total }));
  }, [submissions]);

  return (
    <DashboardShell eyebrow="User / office" title="Dashboard">
      {error && <Banner tone="error">{error}</Banner>}

      {notices.length > 0 && (
        <div className="u-mb-5">
          {notices.map((n) => (
            <div
              key={`${n.kind}-${n.id}`}
              className={`notice ${n.status === 'revision' ? 'notice--revision' : 'notice--rejected'}`}
            >
              <div className="u-flex-1">
                <strong>{n.ref}</strong> — {n.status === 'revision' ? 'needs revision' : 'was rejected'}
                {n.remarks ? `: ${n.remarks}` : ''}
              </div>
              {n.status === 'revision' && (
                <button className="btn btn--outline btn-sm" onClick={() => setResubmitTarget(n)}>
                  Resubmit
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      <section className="panel u-mb-5">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Submit a document or request</h2>
            <p className="panel-subtitle">Starts a new tracking number once sent.</p>
          </div>
          <div className="btn-row">
            <button className="btn btn--outline btn-sm" onClick={() => setRequestModalOpen(true)}>
              + Add new request
            </button>
            <button className="btn btn--primary btn-sm" onClick={() => setDocumentModalOpen(true)}>
              + Upload document
            </button>
          </div>
        </div>
      </section>

      {!loading && submissions.length > 0 && (
        <>
          <div className="stat-grid">
            <StatCard label="Total submitted" value={quickStats.total} />
            <StatCard label="Approved" value={quickStats.approved} tone="success" />
            <StatCard label="Pending" value={quickStats.pending} />
            <StatCard label="Rejected" value={quickStats.rejected} tone="danger" />
          </div>

          <div className="chart-grid u-mb-5">
            <section className="panel chart-panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">My submissions over time</h2>
                  <p className="panel-subtitle">Documents and requests per month.</p>
                </div>
              </div>
              {myMonthData.length === 0 ? (
                <div className="chart-empty">No data yet.</div>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={myMonthData} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
                    <CartesianGrid stroke={CHART_COLORS.line} vertical={false} />
                    <XAxis dataKey="month" tickLine={false} axisLine={{ stroke: CHART_COLORS.line }} />
                    <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={32} />
                    <Tooltip content={<ChartTooltip />} cursor={{ fill: CHART_COLORS.sealSoft }} />
                    <Bar dataKey="total" name="Submissions" fill={CHART_COLORS.seal} radius={[4, 4, 0, 0]} barSize={20} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </section>

            <section className="panel chart-panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">Status breakdown</h2>
                  <p className="panel-subtitle">Current state of my submissions.</p>
                </div>
              </div>
              {myStatusData.length === 0 ? (
                <div className="chart-empty">No data yet.</div>
              ) : (
                <div className="donut-row">
                  <ResponsiveContainer width={140} height={140}>
                    <PieChart>
                      <Pie
                        data={myStatusData}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={42}
                        outerRadius={64}
                        paddingAngle={myStatusData.length > 1 ? 3 : 0}
                        stroke="none"
                      >
                        {myStatusData.map((s) => (
                          <Cell key={s.name} fill={s.color} />
                        ))}
                      </Pie>
                      <Tooltip content={<ChartTooltip />} />
                    </PieChart>
                  </ResponsiveContainer>
                  <ul className="donut-legend">
                    {myStatusData.map((s) => (
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
        </>
      )}

      {requestModalOpen && (
        <NewRequestModal
          requestTypes={requestTypes}
          offices={offices}
          onClose={() => setRequestModalOpen(false)}
          onSaved={reload}
        />
      )}

      {documentModalOpen && (
        <NewDocumentModal
          categories={categories}
          offices={offices}
          onClose={() => setDocumentModalOpen(false)}
          onSaved={reload}
        />
      )}

      {resubmitTarget && (
        <ResubmitModal
          submission={resubmitTarget}
          requestTypes={requestTypes}
          categories={categories}
          onClose={() => setResubmitTarget(null)}
          onSaved={reload}
        />
      )}
    </DashboardShell>
  );
}
