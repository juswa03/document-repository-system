import { useEffect, useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../lib/api';

const ROLE_OPTIONS = [
  { value: 'user', label: 'User / office' },
  { value: 'osm_admin', label: 'OSM admin' },
  { value: 'system_admin', label: 'System admin' },
];

export default function UserFormModal({ mode, user, onClose, onSaved }) {
  const isEdit = mode === 'edit';

  const [offices, setOffices] = useState([]);
  const [fullName, setFullName] = useState(user?.full_name || '');
  const [email, setEmail] = useState(user?.email || '');
  const [role, setRole] = useState(user?.role || 'user');
  const [officeId, setOfficeId] = useState(user?.office_id ? String(user.office_id) : '');
  const [password, setPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/offices').then(({ data }) => setOffices(data)).catch(() => {});
  }, []);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSaving(true);
    try {
      if (isEdit) {
        const { data } = await api.patch(`/admin/users/${user.id}`, {
          full_name: fullName,
          role,
          office_id: officeId || null,
        });
        onSaved(data);
      } else {
        const { data } = await api.post('/admin/users', {
          full_name: fullName,
          email,
          role,
          office_id: officeId || null,
          password,
        });
        onSaved(data);
      }
      onClose();
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
        'Could not save this user.';
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={isEdit ? 'Edit user' : 'New user'} onClose={onClose} >
      <form onSubmit={handleSubmit}>
        <div className="dash-field">
          <label className="dash-label" htmlFor="fullName" style={{ color: 'var(--text-label)' }}>Full name</label>
          <input
            style={{ color: 'var(--text-value)' }}
            id="fullName"
            className="dash-input"
            value={fullName}
            onChange={(e) => setFullName(e.target.value)}
            required
          />
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="email" style={{ color: 'var(--text-label)' }}>Email</label>
          <input
            style={{ color: 'var(--text-value)' }}
            id="email"
            type="email"
            className="dash-input"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            disabled={isEdit}
          />
          {isEdit && (
            <p className="cell-muted" style={{ marginTop: '0.3rem', color: 'var(--text-value)' }}>
              Email can't be changed here.
            </p>
          )}
        </div>

        <div className="dash-row">
          <div className="dash-field">
            <label className="dash-label" htmlFor="role" style={{ color: 'var(--text-label)' }}>Role</label>
            <select id="role" className="dash-select" value={role} onChange={(e) => setRole(e.target.value)} style={{ color: 'var(--text-value)' }}>
              {ROLE_OPTIONS.map((r) => (
                <option key={r.value} value={r.value}>{r.label}</option>
              ))}
            </select>
          </div>
          <div className="dash-field">
            <label className="dash-label" htmlFor="office" style={{ color: 'var(--text-label)' }}>Office</label>
            <select id="office" className="dash-select" value={officeId} onChange={(e) => setOfficeId(e.target.value)} style={{ color: 'var(--text-value)' }}>
              <option value="">No office</option>
              {offices.map((o) => (
                <option key={o.id} value={o.id}>{o.office_name}</option>
              ))}
            </select>
          </div>
        </div>

        {!isEdit && (
          <div className="dash-field">
            <label className="dash-label" htmlFor="password" style={{ color: 'var(--text-label)' }}>Temporary password</label>
            <input
              style={{ color: 'var(--text-value)' }}
              id="password"
              type="password"
              className="dash-input"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              minLength={8}
            />
          </div>
        )}

        {error && <p className="error-banner">{error}</p>}

        <div className="btn-row">
          <button type="submit" className="btn btn--primary" disabled={saving}>
            {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Create user'}
          </button>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Cancel
          </button>
        </div>
      </form>
    </Modal>
  );
}
