import { useCallback, useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import ObjectiveFormModal from './ObjectiveFormModal';
import api from '../../lib/api';
import './dashboards.css';

/**
 * Manage the strategic-objective tree (Phase 11 / DR objective linkage).
 * Enter the real goals and sub-objectives from the strategic plan here;
 * documents are then linked to them during review.
 */
export default function ManageObjectives() {
  const [data, setData] = useState({ tree: [], flat: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modal, setModal] = useState(null); // { mode, item?, parentId? }
  const [busyId, setBusyId] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/admin/strategic-objectives');
      setData(data);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load the objectives.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function remove(node) {
    if (!window.confirm(`Delete ${node.code} — ${node.title}? Linked documents will be unlinked.`)) {
      return;
    }
    setBusyId(node.id);
    setError('');
    try {
      await api.delete(`/admin/strategic-objectives/${node.id}`);
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not delete that objective.');
    } finally {
      setBusyId(null);
    }
  }

  function Row({ node, depth }) {
    return (
      <>
        <tr>
          <td className="cell-mono" style={{ paddingLeft: `${0.8 + depth * 1.4}rem` }}>
            {node.code}
          </td>
          <td>
            {node.title}
            {!node.is_active && (
              <span className="badge badge--revision" style={{ marginLeft: '0.5rem' }}>
                inactive
              </span>
            )}
          </td>
          <td>
            <div className="btn-row">
              {depth === 0 && (
                <button
                  className="btn btn--outline btn-sm"
                  onClick={() => setModal({ mode: 'create', parentId: node.id })}
                >
                  Add sub-objective
                </button>
              )}
              <button
                className="btn btn--outline btn-sm"
                onClick={() => setModal({ mode: 'edit', item: flatFor(node) })}
              >
                Edit
              </button>
              <button
                className="btn btn--danger-outline btn-sm"
                disabled={busyId === node.id}
                onClick={() => remove(node)}
              >
                Delete
              </button>
            </div>
          </td>
        </tr>
        {(node.children || []).map((c) => (
          <Row key={c.id} node={c} depth={depth + 1} />
        ))}
      </>
    );
  }

  // The tree nodes don't carry parent_id; look it up from the flat list.
  function flatFor(node) {
    return data.flat.find((f) => f.id === node.id) || node;
  }

  return (
    <DashboardShell eyebrow="System / super admin" title="Strategic objectives">
      {error && <p className="error-banner">{error}</p>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Objective tree</h2>
            <p className="panel-subtitle">
              The goals and sub-objectives from the strategic plan. Documents are linked to these
              during review; the repository can be filtered by objective.
            </p>
          </div>
          <div className="btn-row">
            <button className="btn btn--primary btn-sm" onClick={() => setModal({ mode: 'create' })}>
              Add goal
            </button>
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Title</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {data.tree.length === 0 && (
                  <tr>
                    <td colSpan={3} className="empty-row">
                      No objectives yet — add the goals from the strategic plan.
                    </td>
                  </tr>
                )}
                {data.tree.map((g) => (
                  <Row key={g.id} node={g} depth={0} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {modal && (
        <ObjectiveFormModal
          mode={modal.mode}
          item={modal.item}
          parentId={modal.parentId}
          flat={data.flat}
          onClose={() => setModal(null)}
          onSaved={load}
        />
      )}
    </DashboardShell>
  );
}
