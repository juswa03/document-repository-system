import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

// Fallback copy if the API is unreachable — normally the role name and
// description come straight from GET /api/admin/role-matrix so the screen
// can never drift from what the backend enforces.
const ROLE_FALLBACK = {
  user: { name: 'User / office', description: '' },
  osm_admin: { name: 'OSM admin', description: '' },
  system_admin: { name: 'System admin', description: '' },
};

export default function ManageRoles() {
  const [matrix, setMatrix] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const { data } = await api.get('/admin/role-matrix');
        if (alive) setMatrix(data);
      } catch (err) {
        if (alive) setError(err?.response?.data?.message || 'Could not load the role matrix.');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => {
      alive = false;
    };
  }, []);

  const roles = useMemo(() => matrix?.roles ?? [], [matrix]);
  const meta = (key) => matrix?.meta?.[key] || ROLE_FALLBACK[key] || { name: key, description: '' };

  // Rows grouped by their functional area, groups in first-seen order.
  const grouped = useMemo(() => {
    const out = [];
    for (const row of matrix?.rows ?? []) {
      let bucket = out.find((b) => b.group === row.group);
      if (!bucket) {
        bucket = { group: row.group, rows: [] };
        out.push(bucket);
      }
      bucket.rows.push(row);
    }
    return out;
  }, [matrix]);

  return (
    <DashboardShell eyebrow="System / super admin" title="Manage roles">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Roles</h2>
            <p className="panel-subtitle">
              Three fixed roles (decision 0.2). Assign a role per account in Manage users.
            </p>
          </div>
        </div>

        {roles.map((key) => (
          <div className="role-card" key={key}>
            <p className="role-name">{meta(key).name}</p>
            <p className="role-desc">{meta(key).description}</p>
          </div>
        ))}
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Permission matrix</h2>
            <p className="panel-subtitle">
              Exactly what the API enforces — sourced live from the backend.{' '}
              <span className="cell-muted">✓ allowed · — not allowed</span>
            </p>
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Capability</th>
                  {roles.map((r) => (
                    <th key={r} style={{ textAlign: 'center' }}>
                      {meta(r).name}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {grouped.map((bucket) => (
                  <FragmentGroup key={bucket.group} bucket={bucket} roles={roles} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </DashboardShell>
  );
}

function FragmentGroup({ bucket, roles }) {
  return (
    <>
      <tr>
        <td
          colSpan={roles.length + 1}
          className="cell-muted"
          style={{ textTransform: 'uppercase', letterSpacing: '0.05em', fontSize: '0.72rem', paddingTop: '1rem' }}
        >
          {bucket.group}
        </td>
      </tr>
      {bucket.rows.map((row) => (
        <tr key={row.capability}>
          <td>
            {row.label}
            <span className="cell-mono" style={{ display: 'block', opacity: 0.6, fontSize: '.8em' }}>
              {row.capability}
            </span>
          </td>
          {roles.map((r) => (
            <td key={r} style={{ textAlign: 'center' }}>
              {row.allowed[r] ? (
                <span aria-label="allowed" style={{ color: 'var(--primary)' }}>
                  ✓
                </span>
              ) : (
                <span aria-label="not allowed" style={{ opacity: 0.3 }}>
                  —
                </span>
              )}
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}
