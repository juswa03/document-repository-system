import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ResponsiveContainer,
  AreaChart,
  Area,
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
import api from '../../lib/api';
import { CHART_COLORS, ChartTooltip, monthLabel } from '../../lib/chartTheme';
import '../Reports.css';
import './dashboards.css';
import './AdminOverview.css';

const ROLE_LABELS = {
  system_admin: 'System admin',
  office_admin: 'Office admin',
  user: 'User / office',
};

const ROLES = [
  {
    key: 'system_admin',
    name: 'System admin',
    desc: 'Full system access — manages accounts, roles, and configuration.',
    permissions: ['Manage users', 'Manage roles', 'System settings', 'Audit log'],
  },
  {
    key: 'office_admin',
    name: 'Office admin',
    desc: 'Reviews incoming submissions and decides approve or reject.',
    permissions: ['Review queue', 'Approve / reject', 'Document repository'],
  },
  {
    key: 'user',
    name: 'User / office',
    desc: 'Submits documents or requests and tracks their status.',
    permissions: ['Upload / request', 'Track submission'],
  },
];

export default function SystemAdminDashboard() {
  const [users, setUsers] = useState([]);
  const [settings, setSettings] = useState(null);
  const [aiSettings, setAiSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);

  async function loadUsers() {
    setLoading(true);
    setError('');
    try {
      const [usersRes, settingsRes, aiRes] = await Promise.allSettled([
        api.get('/admin/users', { params: { all: 1 } }),
        api.get('/admin/settings'),
        api.get('/admin/ai-settings'),
      ]);
      if (usersRes.status === 'fulfilled') setUsers(usersRes.value.data);
      if (settingsRes.status === 'fulfilled') setSettings(settingsRes.value.data);
      if (aiRes.status === 'fulfilled') setAiSettings(aiRes.value.data);
      if (usersRes.status === 'rejected') {
        setError(usersRes.reason?.response?.data?.message || 'Could not load users.');
      }
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadUsers();
  }, []);

  const stats = useMemo(() => {
    const active = users.filter((u) => u.is_active);
    return {
      total: users.length,
      systemAdmins: users.filter((u) => u.role === 'system_admin').length,
      osmAdmins: users.filter((u) => u.role === 'office_admin').length,
      officeUsers: users.filter((u) => u.role === 'user').length,
      inactive: users.length - active.length,
    };
  }, [users]);

  const roleData = useMemo(() => [
    { name: 'System admin', value: stats.systemAdmins, color: CHART_COLORS.seal },
    { name: 'Office admin', value: stats.osmAdmins, color: CHART_COLORS.ledger },
    { name: 'User / office', value: stats.officeUsers, color: CHART_COLORS.inkSoft },
  ].filter((r) => r.value > 0), [stats]);

  const registrationTrend = useMemo(() => {
    const byMonth = {};
    users.forEach((u) => {
      if (!u.created_at) return;
      const key = u.created_at.slice(0, 7);
      byMonth[key] = (byMonth[key] || 0) + 1;
    });
    return Object.entries(byMonth)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([month, count]) => ({ month: monthLabel(month), count }));
  }, [users]);

  async function toggleActive(user) {
    setBusyId(user.id);
    try {
      const { data } = await api.patch(`/admin/users/${user.id}`, {
        is_active: !user.is_active,
      });
      setUsers((prev) => prev.map((u) => (u.id === user.id ? data : u)));
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not update that user.');
    } finally {
      setBusyId(null);
    }
  }

  return (
    <DashboardShell eyebrow="System / super admin" title="Admin panel — system overview">
      {error && <Banner tone="error">{error}</Banner>}

      <div className="btn-row u-mb-5">
        <Link to="/repository" className="btn btn--outline btn-sm">Browse document repository →</Link>
        <Link to="/reports" className="btn btn--outline btn-sm">View reports →</Link>
      </div>

      <div className="stat-grid">
        <StatCard label="Total users" value={stats.total} />
        <StatCard label="System admins" value={stats.systemAdmins} />
        <StatCard label="Office admins" value={stats.osmAdmins} />
        <StatCard label="Office users" value={stats.officeUsers} />
        <StatCard label="Deactivated" value={stats.inactive} tone={stats.inactive > 0 ? "warning" : "default"} />
      </div>

      <div className="chart-grid-even u-mb-5">
        <section className="panel">
          <div className="panel-header">
            <div>
              <h2 className="panel-title">System health</h2>
              <p className="panel-subtitle">Current configuration state.</p>
            </div>
          </div>
          <div className="ov-list">
            <div className="ov-list-row">
              <span className="ov-list-label">Maintenance mode</span>
              <span className={`badge ${settings?.maintenance_mode ? 'badge--rejected' : 'badge--approved'}`}>
                {settings === null ? '—' : settings.maintenance_mode ? 'On' : 'Off'}
              </span>
            </div>
            <div className="ov-list-row">
              <span className="ov-list-label">Audit logging</span>
              <span className={`badge ${settings?.audit_logging === false ? 'badge--rejected' : 'badge--approved'}`}>
                {settings === null ? '—' : settings.audit_logging === false ? 'Off' : 'On'}
              </span>
            </div>
            <div className="ov-list-row">
              <span className="ov-list-label">AI layer</span>
              <span className={`badge ${aiSettings?.ai_enabled ? 'badge--approved' : 'badge--revision'}`}>
                {aiSettings === null ? '—' : aiSettings.ai_enabled ? 'Enabled' : 'Disabled'}
              </span>
            </div>
            <div className="ov-list-row">
              <span className="ov-list-label">AI operational</span>
              <span className={`badge ${aiSettings?.operational ? 'badge--approved' : 'badge--rejected'}`}>
                {aiSettings === null ? '—' : aiSettings.operational ? 'Yes' : 'No'}
              </span>
            </div>
          </div>
        </section>

        <section className="panel chart-panel">
          <div className="panel-header">
            <div>
              <h2 className="panel-title">Role distribution</h2>
              <p className="panel-subtitle">Users by assigned role.</p>
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
                      <Cell key={r.name} fill={r.color} />
                    ))}
                  </Pie>
                  <Tooltip content={<ChartTooltip />} />
                </PieChart>
              </ResponsiveContainer>
              <ul className="donut-legend">
                {roleData.map((r) => (
                  <li key={r.name}>
                    <span className="legend-dot" style={{ background: r.color }} />
                    <span className="legend-label">{r.name}</span>
                    <span className="legend-value">{r.value}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      </div>

      <section className="panel chart-panel u-mb-5">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">User registration trend</h2>
            <p className="panel-subtitle">New accounts created per month.</p>
          </div>
        </div>
        {registrationTrend.length === 0 ? (
          <div className="chart-empty">No registration data yet.</div>
        ) : (
          <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={registrationTrend} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
              <defs>
                <linearGradient id="regFill" x1="0" y1="0" x2="0" y2="1">
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
                dataKey="count"
                name="New users"
                stroke={CHART_COLORS.seal}
                strokeWidth={2}
                fill="url(#regFill)"
              />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Manage users</h2>
            <p className="panel-subtitle">Create, edit, or deactivate accounts.</p>
          </div>
          <button className="btn btn--primary btn-sm">+ New user</button>
        </div>

        {loading ? (
          <p className="loading-text">Loading users…</p>
        ) : (
          <div className="table-scroll">
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Office</th>
                <th>Role</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>{u.full_name}</td>
                  <td className="cell-muted">{u.email}</td>
                  <td className="cell-muted">{u.office?.office_name || '—'}</td>
                  <td>{ROLE_LABELS[u.role] || u.role}</td>
                  <td>
                    <span className={`badge ${u.is_active ? 'badge--approved' : 'badge--rejected'}`}>
                      {u.is_active ? 'Active' : 'Deactivated'}
                    </span>
                  </td>
                  <td>
                    <div className="btn-row">
                      <button className="btn btn--outline btn-sm">Edit</button>
                      <button
                        className="btn btn--danger-outline btn-sm"
                        disabled={busyId === u.id}
                        onClick={() => toggleActive(u)}
                      >
                        {u.is_active ? 'Deactivate' : 'Reactivate'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        )}
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Manage roles</h2>
            <p className="panel-subtitle">What each role can see and do.</p>
          </div>
        </div>

        {ROLES.map((role) => (
          <div className="role-card" key={role.key}>
            <p className="role-name">{role.name}</p>
            <p className="role-desc">{role.desc}</p>
            <div className="chip-row">
              {role.permissions.map((p) => (
                <span className="chip" key={p}>{p}</span>
              ))}
            </div>
          </div>
        ))}
      </section>

      <div className="btn-row u-mt-4">
        <Link to="/admin/users" className="btn btn--outline btn-sm">Manage users →</Link>
        <Link to="/admin/roles" className="btn btn--outline btn-sm">Manage roles →</Link>
        <Link to="/admin/settings" className="btn btn--outline btn-sm">System settings →</Link>
        <Link to="/admin/audit-log" className="btn btn--outline btn-sm">Audit log →</Link>
      </div>
    </DashboardShell>
  );
}
