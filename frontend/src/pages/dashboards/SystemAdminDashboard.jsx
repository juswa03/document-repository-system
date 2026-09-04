import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

const ROLE_LABELS = {
  system_admin: 'System admin',
  osm_admin: 'OSM admin',
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
    key: 'osm_admin',
    name: 'OSM admin',
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
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);

  const [maintenanceMode, setMaintenanceMode] = useState(false);
  const [auditLogging, setAuditLogging] = useState(true);

  async function loadUsers() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/admin/users');
      setUsers(data);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load users.');
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
      osmAdmins: users.filter((u) => u.role === 'osm_admin').length,
      officeUsers: users.filter((u) => u.role === 'user').length,
      inactive: users.length - active.length,
    };
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
      {error && <p className="error-banner">{error}</p>}

      <div style={{ marginBottom: '1.5rem' }} className="btn-row">
        <Link to="/repository" className="btn btn--outline btn-sm">
          Browse document repository →
        </Link>
        <Link to="/reports" className="btn btn--outline btn-sm">
          View reports →
        </Link>
      </div>

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

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">System settings</h2>
            <p className="panel-subtitle">Configuration &amp; audit log.</p>
          </div>
          <button className="btn btn--outline btn-sm">View audit log →</button>
        </div>

        <div className="toggle-row">
          <div className="toggle-copy">
            <p>Maintenance mode</p>
            <span>Temporarily blocks sign-in for non-admin roles.</span>
          </div>
          <label className="toggle-switch">
            <input
              type="checkbox"
              checked={maintenanceMode}
              onChange={() => setMaintenanceMode((v) => !v)}
            />
            <span className="toggle-slider" />
          </label>
        </div>

        <div className="toggle-row">
          <div className="toggle-copy">
            <p>Audit logging</p>
            <span>Records every approve, reject, and role change.</span>
          </div>
          <label className="toggle-switch">
            <input
              type="checkbox"
              checked={auditLogging}
              onChange={() => setAuditLogging((v) => !v)}
            />
            <span className="toggle-slider" />
          </label>
        </div>
      </section>
    </DashboardShell>
  );
}
