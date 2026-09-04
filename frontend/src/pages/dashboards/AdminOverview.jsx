import { useEffect, useMemo, useState } from 'react';
import { ResponsiveContainer, PieChart, Pie, Cell, Tooltip } from 'recharts';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import { CHART_COLORS, ChartTooltip } from '../../lib/chartTheme';
import '../Reports.css';
import './dashboards.css';

const ROLE_META = {
  system_admin: { label: 'System admin', color: CHART_COLORS.seal },
  osm_admin: { label: 'OSM admin', color: CHART_COLORS.ledger },
  user: { label: 'User / office', color: CHART_COLORS.inkSoft },
};

export default function AdminOverview() {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .get('/admin/users')
      .then(({ data }) => setUsers(data))
      .catch((err) => setError(err?.response?.data?.message || 'Could not load overview data.'))
      .finally(() => setLoading(false));
  }, []);

  const stats = useMemo(() => {
    const active = users.filter((u) => u.is_active);
    return {
      total: users.length,
      systemAdmins: users.filter((u) => u.role === 'system_admin').length,
      osmAdmins: users.filter((u) => u.role === 'osm_admin').length,
      officeUsers: users.filter((u) => u.role === 'user').length,
      inactive: users.length - active.length,
    };
  }, [users]);

  const roleData = useMemo(
    () =>
      Object.entries(ROLE_META)
        .map(([key, meta]) => ({
          key,
          name: meta.label,
          color: meta.color,
          value: users.filter((u) => u.role === key).length,
        }))
        .filter((r) => r.value > 0),
    [users]
  );

  return (
    <DashboardShell eyebrow="System / super admin" title="System overview">
      {error && <p className="error-banner">{error}</p>}

      {loading ? (
        <p className="loading-text">Loading overview…</p>
      ) : (
        <>
          <div className="stat-grid">
            <div className="stat-card">
              <div className="stat-value">{stats.total}</div>
              <div className="stat-label">Total users</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.systemAdmins}</div>
              <div className="stat-label">System admins</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.osmAdmins}</div>
              <div className="stat-label">OSM admins</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.officeUsers}</div>
              <div className="stat-label">Office users</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.inactive}</div>
              <div className="stat-label">Deactivated</div>
            </div>
          </div>

          <section className="panel chart-panel">
            <div className="panel-header">
              <div>
                <h2 className="panel-title">Users by role</h2>
                <p className="panel-subtitle">How accounts are distributed across roles.</p>
              </div>
            </div>

            {roleData.length === 0 ? (
              <div className="chart-empty">No users yet.</div>
            ) : (
              <div className="donut-row">
                <ResponsiveContainer width={140} height={140}>
                  <PieChart>
                    <Pie
                      data={roleData}
                      dataKey="value"
                      nameKey="name"
                      innerRadius={42}
                      outerRadius={64}
                      paddingAngle={roleData.length > 1 ? 3 : 0}
                      stroke="none"
                    >
                      {roleData.map((r) => (
                        <Cell key={r.key} fill={r.color} />
                      ))}
                    </Pie>
                    <Tooltip content={<ChartTooltip />} />
                  </PieChart>
                </ResponsiveContainer>
                <ul className="donut-legend">
                  {roleData.map((r) => (
                    <li key={r.key}>
                      <span className="legend-dot" style={{ background: r.color }} />
                      <span className="legend-label">{r.name}</span>
                      <span className="legend-value">{r.value}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </section>
        </>
      )}
    </DashboardShell>
  );
}
