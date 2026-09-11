import { useEffect, useMemo, useState } from 'react';
import api from '../lib/api';
import Banner from './Banner';

/**
 * Links a document to strategic objectives during review (Phase 11).
 * Reads the tree from GET /office-admin/strategic-objectives and the
 * current links from GET /office-admin/documents/{id}/objectives, then
 * PUTs the whole set.
 */
export default function ObjectivePicker({ documentId }) {
  const [flat, setFlat] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [initial, setInitial] = useState(new Set());
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let alive = true;
    Promise.all([
      api.get('/office-admin/strategic-objectives'),
      api.get(`/office-admin/documents/${documentId}/objectives`),
    ])
      .then(([tree, links]) => {
        if (!alive) return;
        setFlat(tree.data.flat.filter((o) => o.is_active || links.data.some((l) => l.id === o.id)));
        const set = new Set(links.data.map((l) => l.id));
        setSelected(set);
        setInitial(new Set(set));
      })
      .catch(
        (e) => alive && setError(e?.response?.data?.message || 'Could not load objectives.')
      );
    return () => {
      alive = false;
    };
  }, [documentId]);

  const dirty = useMemo(() => {
    if (selected.size !== initial.size) return true;
    for (const id of selected) if (!initial.has(id)) return true;
    return false;
  }, [selected, initial]);

  function toggle(id) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  async function save() {
    setSaving(true);
    setError('');
    try {
      await api.put(`/office-admin/documents/${documentId}/objectives`, {
        objective_ids: [...selected],
      });
      setInitial(new Set(selected));
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not save the objective links.');
    } finally {
      setSaving(false);
    }
  }

  if (error) return <Banner tone="error">{error}</Banner>;
  if (flat === null) return <p className="loading-text">Loading objectives…</p>;
  if (flat.length === 0)
    return (
      <p className="panel-subtitle">
        No strategic objectives defined yet — a system admin adds them under Administration →
        Strategic objectives.
      </p>
    );

  return (
    <div>
      <div className="check-list">
        {flat.map((o) => (
          <label key={o.id} className="check-row">
            <input
              type="checkbox"
              checked={selected.has(o.id)}
              onChange={() => toggle(o.id)}
            />
            <span className="cell-mono">{o.code}</span> {o.title}
          </label>
        ))}
      </div>
      <div className="btn-row u-mt-3">
        <button className="btn btn--primary btn-sm" disabled={!dirty || saving} onClick={save}>
          {saving ? 'Saving…' : 'Save links'}
        </button>
        {!dirty && !saving && <span className="cell-muted">Saved</span>}
      </div>
    </div>
  );
}
