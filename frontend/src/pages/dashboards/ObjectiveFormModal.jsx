import { useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../lib/api';

/**
 * Create / edit a strategic objective (Phase 11 API). `parentId` is
 * preset when adding a sub-objective under a specific goal.
 */
export default function ObjectiveFormModal({ mode, item, parentId, flat, onClose, onSaved }) {
  const isEdit = mode === 'edit';
  const [code, setCode] = useState(item?.code || '');
  const [title, setTitle] = useState(item?.title || '');
  const [parent, setParent] = useState(
    item ? (item.parent_id ?? '') : parentId != null ? String(parentId) : ''
  );
  const [active, setActive] = useState(item ? item.is_active : true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  // A node can't be its own parent or a child of one of its own
  // descendants — drop itself and everything beneath it from the list.
  const nodes = flat || [];
  const depthOf = (o) => {
    let d = 0;
    let cur = o;
    while (cur?.parent_id) {
      cur = nodes.find((n) => n.id === cur.parent_id);
      d += 1;
      if (d > 20) break;
    }
    return d;
  };
  const isUnder = (o, ancestorId) => {
    let cur = o;
    while (cur?.parent_id) {
      if (cur.parent_id === ancestorId) return true;
      cur = nodes.find((n) => n.id === cur.parent_id);
    }
    return false;
  };
  const parentOptions = nodes.filter((o) => o.id !== item?.id && !(item && isUnder(o, item.id)));

  async function submit(e) {
    e.preventDefault();
    setError('');
    setSaving(true);
    const payload = {
      code: code.trim(),
      title: title.trim(),
      parent_id: parent === '' ? null : Number(parent),
      is_active: active,
    };
    try {
      if (isEdit) {
        await api.patch(`/admin/strategic-objectives/${item.id}`, payload);
      } else {
        await api.post('/admin/strategic-objectives', payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
          'Could not save this objective.'
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={isEdit ? 'Edit objective' : 'New objective'} onClose={onClose}>
      <form onSubmit={submit}>
        <div className="dash-field">
          <label className="dash-label" htmlFor="obj-code">
            Code
          </label>
          <input
            id="obj-code"
            className="dash-input"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            required
            maxLength={40}
            placeholder="e.g. G3.4"
          />
          <p className="cell-muted" style={{ marginTop: '0.3rem' }}>
            Unique. Matches the numbering in the strategic plan.
          </p>
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="obj-title">
            Title
          </label>
          <input
            id="obj-title"
            className="dash-input"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
            maxLength={255}
          />
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="obj-parent">
            Parent goal
          </label>
          <select
            id="obj-parent"
            className="dash-input"
            value={parent}
            onChange={(e) => setParent(e.target.value)}
          >
            <option value="">— none (this is a top-level goal) —</option>
            {parentOptions.map((o) => (
              <option key={o.id} value={o.id}>
                {'  '.repeat(depthOf(o))}
                {o.code} — {o.title}
              </option>
            ))}
          </select>
        </div>

        <div className="dash-field">
          <label className="dash-label" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
            Active (available for linking)
          </label>
        </div>

        {error && <p className="error-banner">{error}</p>}

        <div className="btn-row">
          <button type="submit" className="btn btn--primary" disabled={saving}>
            {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Create'}
          </button>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Cancel
          </button>
        </div>
      </form>
    </Modal>
  );
}
