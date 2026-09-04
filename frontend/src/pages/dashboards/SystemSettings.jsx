import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

const titleCase = (s) => s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export default function SystemSettings() {
  const [settings, setSettings] = useState(null);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [savingMsg, setSavingMsg] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .get('/admin/settings')
      .then(({ data }) => {
        setSettings(data);
        setMessage(data.maintenance_message || '');
      })
      .catch((err) => setError(err?.response?.data?.message || 'Could not load settings.'))
      .finally(() => setLoading(false));
  }, []);

  async function toggle(key) {
    if (key === 'maintenance_mode' && !settings.maintenance_mode) {
      const ok = window.confirm(
        'Enable maintenance mode? Everyone except system admins will be blocked from signing in, and non-admin sessions stop working.'
      );
      if (!ok) return;
    }

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

  async function saveMessage() {
    setSavingMsg(true);
    setError('');
    try {
      const { data } = await api.patch('/admin/settings', { maintenance_message: message.trim() || null });
      setSettings(data);
      setMessage(data.maintenance_message || '');
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not save the message.');
    } finally {
      setSavingMsg(false);
    }
  }

  const platform = settings?.platform;
  const messageDirty = settings && (settings.maintenance_message || '') !== message.trim();

  return (
    <DashboardShell eyebrow="System / super admin" title="System settings">
      {error && <p className="error-banner">{error}</p>}

      {loading || !settings ? (
        <p className="loading-text">Loading settings…</p>
      ) : (
        <>
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

            <div className="toggle-row">
              <div className="toggle-copy">
                <p style={{ color: 'var(--text-label)' }}>Maintenance mode</p>
                <span style={{ color: 'var(--text-value)' }}>
                  Blocks non-admin roles while enabled — new sign-ins and live sessions.
                </span>
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

            <div className="dash-field" style={{ marginTop: '0.75rem' }}>
              <label className="dash-label" htmlFor="maint-msg">
                Message shown to blocked users
              </label>
              <textarea
                id="maint-msg"
                className="dash-textarea"
                rows={2}
                maxLength={500}
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                placeholder="The system is temporarily under maintenance. Please try again later."
              />
              <div className="btn-row" style={{ marginTop: '0.5rem' }}>
                <button
                  className="btn btn--outline btn-sm"
                  disabled={!messageDirty || savingMsg}
                  onClick={saveMessage}
                >
                  {savingMsg ? 'Saving…' : 'Save message'}
                </button>
                {messageDirty && (
                  <button
                    className="btn btn--outline btn-sm"
                    onClick={() => setMessage(settings.maintenance_message || '')}
                  >
                    Reset
                  </button>
                )}
              </div>
            </div>

            <div className="toggle-row">
              <div className="toggle-copy">
                <p style={{ color: 'var(--text-label)' }}>Audit logging</p>
                <span style={{ color: 'var(--text-value)' }}>
                  Always on. Every upload, download, review decision, access grant, retention
                  action, AI action, sign-in and settings change is recorded and cannot be
                  disabled (BR-06 / PF-18).
                </span>
              </div>
              <span className="badge badge--active">Enforced</span>
            </div>

            <div className="toggle-row">
              <div className="toggle-copy">
                <p style={{ color: 'var(--text-label)' }}>AI agent layer</p>
                <span style={{ color: 'var(--text-value)' }}>
                  Provider, model, spend cap and confidence threshold are managed on their own
                  screen.
                </span>
              </div>
              <Link to="/admin/ai-settings" className="btn btn--outline btn-sm">
                AI settings →
              </Link>
            </div>
          </section>

          {platform && (
            <section className="panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">Platform configuration</h2>
                  <p className="panel-subtitle">
                    Set in the server environment / config files — shown here for reference.
                  </p>
                </div>
              </div>

              <table className="data-table">
                <tbody>
                  <tr>
                    <td className="cell-muted">Maximum upload size</td>
                    <td>{platform.max_upload_mb} MB</td>
                  </tr>
                  <tr>
                    <td className="cell-muted">Allowed file types</td>
                    <td className="cell-mono">{platform.allowed_file_types.join(', ')}</td>
                  </tr>
                  <tr>
                    <td className="cell-muted">Document types</td>
                    <td>{platform.document_types.join(', ')}</td>
                  </tr>
                  <tr>
                    <td className="cell-muted">Access levels</td>
                    <td>
                      {platform.access_levels.join(', ')}{' '}
                      <span className="cell-muted">(default: {platform.default_access_level})</span>
                    </td>
                  </tr>
                  <tr>
                    <td className="cell-muted">Retention statuses</td>
                    <td>{platform.retention_statuses.join(', ')}</td>
                  </tr>
                  <tr>
                    <td className="cell-muted">Near-duplicate threshold</td>
                    <td>{platform.near_duplicate_threshold} / 100 similarity</td>
                  </tr>
                  <tr>
                    <td className="cell-muted">Governance review cadence</td>
                    <td>
                      {Object.entries(platform.governance_cadence_months)
                        .map(([scope, months]) => `${titleCase(scope)}: ${months} mo`)
                        .join(' · ')}
                    </td>
                  </tr>
                  <tr>
                    <td className="cell-muted">Access-token lifetime</td>
                    <td>
                      {platform.token_expiration_minutes
                        ? `${platform.token_expiration_minutes} min`
                        : 'No expiry (revoked on sign-out / deactivation)'}
                    </td>
                  </tr>
                </tbody>
              </table>
            </section>
          )}
        </>
      )}
    </DashboardShell>
  );
}
