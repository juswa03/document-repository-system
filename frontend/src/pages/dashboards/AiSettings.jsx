import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import './dashboards.css';

/**
 * §F / AI-09 — admin control of the AI agent layer: on/off, provider,
 * model, monthly spend cap and confidence threshold. The API key is NOT
 * set here — it lives in the environment; this screen only reports
 * whether one is present.
 */
export default function AiSettings() {
  const [data, setData] = useState(null);
  const [form, setForm] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [testResult, setTestResult] = useState(null);
  const [testing, setTesting] = useState(false);

  async function load() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/admin/ai-settings');
      setData(data);
      setForm({
        ai_enabled: data.ai_enabled,
        ai_provider: data.ai_provider,
        ai_model: data.ai_model,
        ai_monthly_cap_usd: data.ai_monthly_cap_usd,
        ai_confidence_threshold: data.ai_confidence_threshold,
        ai_capabilities: data.ai_capabilities || [],
      });
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load AI settings.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  const models = useMemo(
    () => data?.models_by_provider?.[form?.ai_provider] || [],
    [data, form?.ai_provider]
  );

  function set(key, value) {
    setForm((f) => {
      const next = { ...f, [key]: value };
      // Switching provider: fall back to that provider's first model.
      if (key === 'ai_provider') {
        const list = data?.models_by_provider?.[value] || [];
        if (!list.some((m) => m.id === next.ai_model)) {
          next.ai_model = list[0]?.id || '';
        }
      }
      return next;
    });
  }

  function toggleCapability(key) {
    setForm((f) => {
      const wanted = new Set(f.ai_capabilities);
      wanted.has(key) ? wanted.delete(key) : wanted.add(key);
      // Keep the canonical config order so the dirty check is stable.
      const ordered = (data?.ai_capability_options || [])
        .map((o) => o.key)
        .filter((k) => wanted.has(k));
      return { ...f, ai_capabilities: ordered };
    });
  }

  async function save() {
    setSaving(true);
    setError('');
    setTestResult(null);
    try {
      const { data } = await api.patch('/admin/ai-settings', form);
      setData(data);
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
          'Could not save AI settings.'
      );
    } finally {
      setSaving(false);
    }
  }

  async function test() {
    setTesting(true);
    setTestResult(null);
    try {
      const { data } = await api.post('/admin/ai-settings/test');
      setTestResult(data);
    } catch (err) {
      setTestResult({ ok: false, message: err?.response?.data?.message || 'The test call failed.' });
    } finally {
      setTesting(false);
    }
  }

  const dirty =
    form &&
    data &&
    [
      'ai_enabled',
      'ai_provider',
      'ai_model',
      'ai_monthly_cap_usd',
      'ai_confidence_threshold',
      'ai_capabilities',
    ].some((k) => String(form[k]) !== String(data[k]));

  const statusText = !data
    ? ''
    : !data.ai_enabled
      ? 'Off — no documents are analysed.'
      : data.operational
        ? 'On and operational.'
        : data.key_present
          ? 'On, but the provider is not reachable (check the model / base URL, then Test connection).'
          : 'On, but no API key is configured — the layer stays inert until one is set in the environment.';

  return (
    <DashboardShell eyebrow="System / super admin" title="AI settings">
      {error && <p className="error-banner">{error}</p>}

      {loading || !form ? (
        <p className="loading-text">Loading…</p>
      ) : (
        <>
          <section className="panel">
            <p className="prose" style={{ maxWidth: '68ch', color: 'var(--text-secondary)' }}>
              The AI layer reviews each submitted document and produces <em>suggestions</em> —
              category, completeness, metadata clean-up, access level, a summary and a
              near-duplicate check. Nothing is applied to a document until a reviewer accepts it.
              It stays completely inert until it is switched on <strong>and</strong> an API key is
              present in the server environment.
            </p>
            <div
              className="toggle-row"
              style={{ marginTop: '1rem', borderTop: '1px solid var(--border, #e2e8f0)', paddingTop: '1rem' }}
            >
              <div className="toggle-copy">
                <p style={{ color: 'var(--text-label)' }}>AI analysis</p>
                <span style={{ color: 'var(--text-value)' }}>{statusText}</span>
              </div>
              <label className="toggle-switch">
                <input
                  type="checkbox"
                  checked={form.ai_enabled}
                  onChange={(e) => set('ai_enabled', e.target.checked)}
                />
                <span className="toggle-slider" />
              </label>
            </div>
          </section>

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2 className="panel-title">Provider &amp; model</h2>
                <p className="panel-subtitle">
                  The key is read from the environment ({form.ai_provider === 'anthropic'
                    ? 'ANTHROPIC_API_KEY'
                    : form.ai_provider === 'groq'
                      ? 'GROQ_API_KEY'
                      : 'the provider key'}
                  ) — never entered here.
                </p>
              </div>
              <span
                className={`badge ${data.key_present ? 'badge--active' : 'badge--inactive'}`}
              >
                {data.key_present ? 'Key configured' : 'No key'}
              </span>
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="ai-provider">
                Provider
              </label>
              <select
                id="ai-provider"
                className="dash-input"
                value={form.ai_provider}
                onChange={(e) => set('ai_provider', e.target.value)}
              >
                {data.providers.map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </select>
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="ai-model">
                Model
              </label>
              <select
                id="ai-model"
                className="dash-input"
                value={form.ai_model}
                onChange={(e) => set('ai_model', e.target.value)}
              >
                {models.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.label || m.id}
                    {m.input != null ? ` — $${m.input}/$${m.output} per Mtok` : ''}
                  </option>
                ))}
              </select>
            </div>

            <div className="btn-row" style={{ marginTop: '0.6rem' }}>
              <button className="btn btn--outline btn-sm" disabled={testing} onClick={test}>
                {testing ? 'Testing…' : 'Test connection'}
              </button>
              {testResult && (
                <span className={testResult.ok ? 'cell-muted' : 'error-banner'} style={{ margin: 0 }}>
                  {testResult.ok ? '✓ ' : '✕ '}
                  {testResult.message}
                </span>
              )}
            </div>
          </section>

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2 className="panel-title">Budget &amp; confidence</h2>
                <p className="panel-subtitle">
                  Estimated spend this month: <strong>${(data.spend_this_month_usd ?? 0).toFixed(4)}</strong>.
                  Analysis stops for the rest of the month once the cap is reached.
                </p>
              </div>
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="ai-cap">
                Monthly spend cap (USD)
              </label>
              <input
                id="ai-cap"
                type="number"
                min="0"
                step="1"
                className="dash-input"
                value={form.ai_monthly_cap_usd}
                onChange={(e) => set('ai_monthly_cap_usd', e.target.value)}
              />
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="ai-threshold">
                Confidence threshold ({Number(form.ai_confidence_threshold).toFixed(2)})
              </label>
              <input
                id="ai-threshold"
                type="range"
                min="0"
                max="1"
                step="0.05"
                value={form.ai_confidence_threshold}
                onChange={(e) => set('ai_confidence_threshold', e.target.value)}
              />
              <p className="cell-muted" style={{ marginTop: '0.3rem' }}>
                Suggestions below this confidence are still stored but flagged as low-confidence.
              </p>
            </div>
          </section>

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2 className="panel-title">Capabilities</h2>
                <p className="panel-subtitle">
                  Which analyses run on each submitted document. Turn any off to skip its provider
                  call. Near-duplicate detection is deterministic and free.
                </p>
              </div>
            </div>

            {(data.ai_capability_options || []).map((opt) => (
              <div className="toggle-row" key={opt.key}>
                <div className="toggle-copy">
                  <p style={{ color: 'var(--text-label)' }}>{opt.label}</p>
                  <span className="cell-mono" style={{ color: 'var(--text-value)' }}>
                    {opt.key}
                  </span>
                </div>
                <label className="toggle-switch">
                  <input
                    type="checkbox"
                    checked={form.ai_capabilities.includes(opt.key)}
                    onChange={() => toggleCapability(opt.key)}
                  />
                  <span className="toggle-slider" />
                </label>
              </div>
            ))}
          </section>

          <div className="btn-row">
            <button className="btn btn--primary" disabled={!dirty || saving} onClick={save}>
              {saving ? 'Saving…' : 'Save changes'}
            </button>
            {dirty && (
              <button className="btn btn--outline" onClick={load} disabled={saving}>
                Discard
              </button>
            )}
          </div>
        </>
      )}
    </DashboardShell>
  );
}
