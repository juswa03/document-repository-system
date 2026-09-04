import { useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import UserFormModal from './UserFormModal';
import api from '../../lib/api';
import './dashboards.css';

const ROLE_LABELS = {
  system_admin: 'System admin',
  osm_admin: 'OSM admin',
  user: 'User / office',
};

export default function ManageUsers() {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [modal, setModal] = useState(null);

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
    <DashboardShell eyebrow="System / super admin" title="Manage users">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">All users</h2>
            <p className="panel-subtitle">Create, edit, or deactivate accounts.</p>
          </div>
          <button className="btn btn--primary btn-sm" onClick={() => setModal({ mode: 'create' })}>
            + New user
          </button>
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
                <th>Actions</th>
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
                    <span className={`badge ${u.is_active ? 'badge--active' : 'badge--inactive'}`}>
                      {u.is_active ? 'Active' : 'Deactivated'}
                    </span>
                  </td>
                  <td>
                    <div className="btn-row">
                      <button className="btn btn--outline btn-sm" onClick={() => setModal({ mode: 'edit', user: u })}>
                        Edit
                      </button>
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

      {modal && (
        <UserFormModal
          mode={modal.mode}
          user={modal.user}
          onClose={() => setModal(null)}
          onSaved={() => loadUsers()}
        />
      )}
    </DashboardShell>
  );
}