import { useEffect, useState } from 'react';
import api from '../lib/api';

/**
 * Manage access grants on a restricted / confidential document (FR-06 /
 * BR-04). Grant a specific user or a whole office, with a reason and an
 * optional expiry; revoke an active grant. Confidential documents get a
 * 90-day expiry by default on the server if none is given.
 */
export default function AccessGrantsPanel({ documentId }) {
  const [grants, setGrants] = useState(null);
  const [users, setUsers] = useState([]);
  const [offices, setOffices] = useState([]);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const [granteeType, setGranteeType] = useState('user');
  const [granteeId, setGranteeId] = useState('');
  const [reason, setReason] = useState('');
  const [expiresAt, setExpiresAt] = useState('');

  async function load() {
    setError('');
    try {
      const { data } = await api.get(`/osm-admin/documents/${documentId}/access-grants`);
      setGrants(data);
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load the access grants.');
    }
  }

  useEffect(() => {
    load();
    api.get('/osm-admin/users').then(({ data }) => setUsers(data)).catch(() => {});
    api.get('/offices').then(({ data }) => setOffices(data)).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [documentId]);

  function resetForm() {
    setGranteeId('');
    setReason('');
    setExpiresAt('');
  }

  async function grant(e) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      await api.post(`/osm-admin/documents/${documentId}/access-grants`, {
        grantee_user_id: granteeType === 'user' ? Number(granteeId) : undefined,
        grantee_office_id: granteeType === 'office' ? Number(granteeId) : undefined,
        reason: reason.trim(),
        expires_at: expiresAt || undefined,
      });
      resetForm();
      await load();
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
          'Could not create the grant.'
      );
    } finally {
      setBusy(false);
    }
  }

  async function revoke(id) {
    setBusy(true);
    setError('');
    try {
      await api.delete(`/osm-admin/access-grants/${id}`);
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not revoke that grant.');
    } finally {
      setBusy(false);
    }
  }

  const state = (g) => {
    if (g.revoked_at) return 'revoked';
    if (g.expires_at && new Date(g.expires_at) < new Date()) return 'expired';
    return 'active';
  };

  if (error && grants === null) return <p className="error-banner">{error}</p>;
  if (grants === null) return <p className="loading-text">Loading access grants…</p>;

  return (
    <div>
      {error && <p className="error-banner">{error}</p>}

      <div className="table-scroll" style={{ marginBottom: '0.8rem' }}>
      <table className="data-table">
        <thead>
          <tr>
            <th>Grantee</th>
            <th>Reason</th>
            <th>Expires</th>
            <th>Granted by</th>
            <th>State</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {grants.length === 0 && (
            <tr><td colSpan={6} className="empty-row">No grants on this document.</td></tr>
          )}
          {grants.map((g) => {
            const s = state(g);
            const office = g.grantee_office || g.granteeOffice;
            const grantedBy = g.granted_by || g.grantedBy;
            return (
              <tr key={g.id} style={s !== 'active' ? { opacity: 0.55 } : undefined}>
                <td>
                  {g.grantee?.full_name
                    ? `${g.grantee.full_name} (user)`
                    : office?.office_name
                      ? `${office.office_name} (office)`
                      : '—'}
                </td>
                <td className="cell-muted">{g.reason}</td>
                <td className="cell-muted">{g.expires_at ? String(g.expires_at).slice(0, 10) : 'No expiry'}</td>
                <td className="cell-muted">{grantedBy?.full_name || '—'}</td>
                <td>
                  <span className={`badge ${s === 'active' ? 'badge--active' : 'badge--inactive'}`}>{s}</span>
                </td>
                <td>
                  {s === 'active' && (
                    <button
                      className="btn btn--danger-outline btn-sm"
                      disabled={busy}
                      onClick={() => revoke(g.id)}
                    >
                      Revoke
                    </button>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      </div>

      <form className="filter-bar" onSubmit={grant} style={{ alignItems: 'flex-end' }}>
        <div className="filter-field">
          <label htmlFor="gt">Grant to</label>
          <select
            id="gt"
            value={granteeType}
            onChange={(e) => {
              setGranteeType(e.target.value);
              setGranteeId('');
            }}
          >
            <option value="user">A user</option>
            <option value="office">An office</option>
          </select>
        </div>

        <div className="filter-field filter-field--grow">
          <label htmlFor="gid">
            {granteeType === 'user' ? 'User' : 'Office'}
          </label>
          <select id="gid" value={granteeId} onChange={(e) => setGranteeId(e.target.value)} required>
            <option value="">— select —</option>
            {(granteeType === 'user' ? users : offices).map((o) => (
              <option key={o.id} value={o.id}>
                {granteeType === 'user' ? o.full_name : o.office_name}
              </option>
            ))}
          </select>
        </div>

        <div className="filter-field filter-field--grow">
          <label htmlFor="grsn">Reason</label>
          <input
            id="grsn"
            type="text"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Why this grantee needs access"
            required
          />
        </div>

        <div className="filter-field">
          <label htmlFor="gexp">Expires (optional)</label>
          <input id="gexp" type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
        </div>

        <button className="btn btn--primary btn-sm" disabled={busy || !granteeId || !reason.trim()}>
          {busy ? 'Saving…' : 'Grant access'}
        </button>
      </form>
    </div>
  );
}
