import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

// Human copy for the three roles (decision 0.2 — Option B, three roles).
// The capability grid itself comes from GET /api/admin/role-matrix so the
// screen can never drift from what the backend actually enforces.
const ROLE_META = {
  user: {
    name: 'User / office',
    desc: 'Submits documents or requests and tracks their own status.',
  },
  osm_admin: {
    name: 'OSM admin',
    desc:
      'The whole OSM review-and-publish function — completeness check, classification, ' +
      'return / reject / approve, access grants, retention and disposal, repository search, reports.',
  },
  system_admin: {
    name: 'System admin',
    desc:
      'Platform only — user accounts, lookups, settings, AI settings, audit log, governance cadence. ' +
      'No document decisions and no document submission.',
  },
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
            <p className="role-name">{ROLE_META[key]?.name || key}</p>
            <p className="role-desc">{ROLE_META[key]?.desc || ''}</p>
          </div>
        ))}
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Permission matrix</h2>
            <p className="panel-subtitle">
              Exactly what the API enforces — sourced live from the backend.
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
                      {ROLE_META[r]?.name || r}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {(matrix?.rows ?? []).map((row) => (
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
                          <span aria-label="allowed" style={{ color: 'var(--ok, #3a7256)' }}>✓</span>
                        ) : (
                          <span aria-label="not allowed" style={{ opacity: 0.3 }}>—</span>
                        )}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </DashboardShell>
  );
}
