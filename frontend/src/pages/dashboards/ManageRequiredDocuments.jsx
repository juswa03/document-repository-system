import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import Modal from '../../components/Modal';
import api from '../../lib/api';
import './dashboards.css';

const CADENCES = ['annual', 'semestral', 'quarterly', 'monthly', 'once'];
const DOC_TYPES = ['', 'report', 'memo', 'minutes', 'plan', 'template', 'evidence', 'dataset'];

const BLANK = {
  name: '',
  office_id: '',
  category_id: '',
  document_type: '',
  reporting_period_label: '',
  cadence: 'annual',
  due_offset_days: 0,
  is_active: true,
  notes: '',
};

/**
 * Phase 6.2 — the compliance checklist that drives RPT-06 / RPT-07.
 * system_admin maintains it; the reports compute expected-vs-actual
 * against these rows.
 */
export default function ManageRequiredDocuments() {
  const [rows, setRows] = useState([]);
  const [offices, setOffices] = useState([]);
  const [categories, setCategories] = useState([]);
  const [form, setForm] = useState(null); // { ...fields, id? }
  const [saving, setSaving] = useState(false);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  async function load() {
    setLoading(true);
    setError('');
    try {
      const [r, o, c] = await Promise.all([
        api.get('/admin/required-documents'),
        // ?all=1 so a rule that targets a since-deactivated office or
        // category still resolves to a name instead of showing blank.
        api.get('/offices', { params: { all: 1 } }),
        api.get('/categories', { params: { all: 1 } }),
      ]);
      setRows(r.data);
      setOffices(o.data);
      setCategories(c.data);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load the checklist.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  const officeName = (id) => offices.find((o) => o.id === id)?.office_name || 'All offices';
  const categoryName = (id) => categories.find((c) => c.id === id)?.category_name || '—';

  const visible = useMemo(() => {
    const term = q.trim().toLowerCase();
    if (!term) return rows;
    return rows.filter(
      (row) =>
        row.name.toLowerCase().includes(term) ||
        officeName(row.office_id).toLowerCase().includes(term) ||
        categoryName(row.category_id).toLowerCase().includes(term)
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows, q, offices, categories]);

  async function save(e) {
    e.preventDefault();
    setError('');
    setSaving(true);
    const payload = {
      ...form,
      office_id: form.office_id || null,
      category_id: form.category_id || null,
      document_type: form.document_type || null,
      reporting_period_label: form.reporting_period_label || null,
      notes: form.notes || null,
      due_offset_days: Number(form.due_offset_days) || 0,
    };
    try {
      if (form.id) {
        await api.patch(`/admin/required-documents/${form.id}`, payload);
      } else {
        await api.post('/admin/required-documents', payload);
      }
      setForm(null);
      load();
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
          'Could not save.'
      );
    } finally {
      setSaving(false);
    }
  }

  async function remove(id) {
    if (!window.confirm('Remove this requirement?')) return;
    try {
      await api.delete(`/admin/required-documents/${id}`);
      load();
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not remove that row.');
    }
  }

  return (
    <DashboardShell eyebrow="System / super admin" title="Required documents">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Compliance checklist</h2>
            <p className="panel-subtitle">
              What each office must submit. Drives the Compliance Evidence and Office Submission Compliance reports.
            </p>
          </div>
          <button className="btn btn--primary btn-sm" onClick={() => setForm({ ...BLANK })}>
            + New requirement
          </button>
        </div>

        <div className="filter-bar">
          <div className="filter-field filter-field--grow">
            <label htmlFor="rd-search" style={{ color: 'var(--text-label)' }}>
              Search
            </label>
            <input
              id="rd-search"
              type="search"
              placeholder="Name, office or category"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Office</th>
                <th>Category</th>
                <th>Type</th>
                <th>Period</th>
                <th>Cadence</th>
                <th>Active</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {visible.length === 0 && (
                <tr>
                  <td colSpan={8} className="empty-row">
                    {q.trim() ? 'No requirements match that search.' : 'No requirements yet.'}
                  </td>
                </tr>
              )}
              {visible.map((row) => (
                <tr key={row.id}>
                  <td>{row.name}</td>
                  <td>{officeName(row.office_id)}</td>
                  <td>{categoryName(row.category_id)}</td>
                  <td>{row.document_type || '—'}</td>
                  <td>{row.reporting_period_label || '—'}</td>
                  <td>{row.cadence}</td>
                  <td>{row.is_active ? 'Yes' : 'No'}</td>
                  <td>
                    <div className="btn-row">
                      <button className="btn btn--outline btn-sm" onClick={() => setForm({ ...BLANK, ...row })}>
                        Edit
                      </button>
                      <button className="btn btn--danger-outline btn-sm" onClick={() => remove(row.id)}>
                        Remove
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      {form && (
        <Modal
          title={form.id ? 'Edit requirement' : 'New requirement'}
          onClose={() => setForm(null)}
          width={620}
        >
          <form onSubmit={save}>
            <div className="dash-row">
              <div className="dash-field">
                <label className="dash-label" htmlFor="rd-name">Name</label>
                <input id="rd-name" className="dash-input" value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })} required />
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="rd-office">Office</label>
                <select id="rd-office" className="dash-select" value={form.office_id || ''}
                  onChange={(e) => setForm({ ...form, office_id: e.target.value })}>
                  <option value="">All offices</option>
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.office_name}{o.is_active === false ? ' (inactive)' : ''}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <div className="dash-row">
              <div className="dash-field">
                <label className="dash-label" htmlFor="rd-cat">Category</label>
                <select id="rd-cat" className="dash-select" value={form.category_id || ''}
                  onChange={(e) => setForm({ ...form, category_id: e.target.value })}>
                  <option value="">Any category</option>
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.category_name}{c.is_active === false ? ' (inactive)' : ''}
                    </option>
                  ))}
                </select>
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="rd-type">Document type</label>
                <select id="rd-type" className="dash-select" value={form.document_type || ''}
                  onChange={(e) => setForm({ ...form, document_type: e.target.value })}>
                  {DOC_TYPES.map((t) => <option key={t} value={t}>{t || 'Any type'}</option>)}
                </select>
              </div>
            </div>
            <div className="dash-row">
              <div className="dash-field">
                <label className="dash-label" htmlFor="rd-period">Reporting period label</label>
                <input id="rd-period" className="dash-input" value={form.reporting_period_label || ''}
                  onChange={(e) => setForm({ ...form, reporting_period_label: e.target.value })}
                  placeholder="e.g. AY 2025-2026" />
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="rd-cadence">Cadence</label>
                <select id="rd-cadence" className="dash-select" value={form.cadence}
                  onChange={(e) => setForm({ ...form, cadence: e.target.value })}>
                  {CADENCES.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="rd-due">Due offset (days)</label>
                <input id="rd-due" type="number" min="0" className="dash-input" value={form.due_offset_days}
                  onChange={(e) => setForm({ ...form, due_offset_days: e.target.value })} />
              </div>
            </div>
            <div className="dash-field">
              <label className="dash-label">
                <input type="checkbox" checked={!!form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Active
              </label>
            </div>
            <div className="dash-field">
              <label className="dash-label" htmlFor="rd-notes">Notes</label>
              <input id="rd-notes" className="dash-input" value={form.notes || ''}
                onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </div>
            <div className="btn-row">
              <button type="submit" className="btn btn--primary" disabled={saving}>
                {saving ? 'Saving…' : 'Save'}
              </button>
              <button type="button" className="btn btn--outline" onClick={() => setForm(null)}>Cancel</button>
            </div>
          </form>
        </Modal>
      )}
    </DashboardShell>
  );
}
