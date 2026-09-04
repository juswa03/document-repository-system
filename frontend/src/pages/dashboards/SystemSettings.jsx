import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

export default function SystemSettings() {
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .get('/admin/settings')
      .then(({ data }) => setSettings(data))
      .catch((err) => setError(err?.response?.data?.message || 'Could not load settings.'))
      .finally(() => setLoading(false));
  }, []);

  async function toggle(key) {
    const previous = settings;
    const next = { ...settings, [key]: !settings[key] };
    setSettings(next); // optimistic
    setSaving(true);
    setError('');
    try {
      const { data } = await api.patch('/admin/settings', { [key]: next[key] });
      setSettings(data);
    } catch (err) {
      setSettings(previous); // rollback
      setError(err?.response?.data?.message || 'Could not save that setting.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <DashboardShell eyebrow="System / super admin" title="System settings">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Configuration &amp; audit log</h2>
            <p className="panel-subtitle">Applies system-wide, effective immediately.</p>
          </div>
          <Link to="/admin/audit-log" className="btn btn--outline btn-sm">
            View audit log →
          </Link>
        </div>

        {loading || !settings ? (
          <p className="loading-text">Loading settings…</p>
        ) : (
          <>
            <div className="toggle-row">
              <div className="toggle-copy">
                <p style={{ color: 'var(--text-label)' }}>Maintenance mode</p>
                <span style={{ color: 'var(--text-value)' }}>Blocks non-admin roles while enabled — new sign-ins and live sessions.</span>
              </div>
              <label className="toggle-switch">
                <input
                  type="checkbox"
                  checked={settings.maintenance_mode}
                  disabled={saving}
                  onChange={() => toggle('maintenance_mode')}
                />
                <span className="toggle-slider" />
              </label>
            </div>

            {/*
              The "Audit logging" toggle was removed: the audit trail is
              always on (backend Phase 1.2 — every upload, download,
              review, sign-in and settings change is recorded and this
              cannot be disabled).
            */}
          </>
        )}
      </section>
    </DashboardShell>
  );
}
