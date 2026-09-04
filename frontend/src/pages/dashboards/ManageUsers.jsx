import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import UserFormModal from './UserFormModal';
import Pager from '../../components/Pager';
import api from '../../lib/api';
import './dashboards.css';

const ROLE_LABELS = {
  system_admin: 'System admin',
  osm_admin: 'OSM admin',
  user: 'User / office',
};

const EMPTY_FILTERS = { q: '', role: '', status: '' };

export default function ManageUsers() {
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [modal, setModal] = useState(null);

  const params = useMemo(
    () => ({
      page,
      q: filters.q.trim() || undefined,
      role: filters.role || undefined,
      status: filters.status || undefined,
    }),
    [page, filters]
  );

  async function loadUsers() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/admin/users', { params });
      setUsers(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load users.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadUsers();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params]);

  function setFilter(key, value) {
    setPage(1);
    setFilters((f) => ({ ...f, [key]: value }));
  }

  const anyFilter = filters.q || filters.role || filters.status;

  async function toggleActive(user) {
    setBusyId(user.id);
    setError('');
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
            <p className="panel-subtitle">Create, edit, reset a password, or deactivate accounts.</p>
          </div>
          <button className="btn btn--primary btn-sm" onClick={() => setModal({ mode: 'create' })}>
            + New user
          </button>
        </div>

        <form className="filter-bar" onSubmit={(e) => e.preventDefault()}>
          <div className="filter-field filter-field--grow">
            <label htmlFor="f-q" style={{ color: 'var(--text-label)' }}>
              Search
            </label>
            <input
              id="f-q"
              type="search"
              placeholder="Name or email"
              value={filters.q}
              onChange={(e) => setFilter('q', e.target.value)}
            />
          </div>

          <div className="filter-field">
            <label htmlFor="f-role" style={{ color: 'var(--text-label)' }}>
              Role
            </label>
            <select id="f-role" value={filters.role} onChange={(e) => setFilter('role', e.target.value)}>
              <option value="">Any role</option>
              {Object.entries(ROLE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>

          <div className="filter-field">
            <label htmlFor="f-status" style={{ color: 'var(--text-label)' }}>
              Status
            </label>
            <select id="f-status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
              <option value="">Any status</option>
              <option value="active">Active</option>
              <option value="inactive">Deactivated</option>
            </select>
          </div>

          {anyFilter && (
            <div className="btn-row">
              <button
                type="button"
                className="btn btn--outline btn-sm"
                onClick={() => {
                  setPage(1);
                  setFilters(EMPTY_FILTERS);
                }}
              >
                Clear
              </button>
            </div>
          )}
        </form>

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
              {users.length === 0 && (
                <tr>
                  <td colSpan={6} className="empty-row">
                    {anyFilter ? 'No users match these filters.' : 'No users yet.'}
                  </td>
                </tr>
              )}
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

        <Pager meta={meta} page={page} onPage={setPage} />
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
