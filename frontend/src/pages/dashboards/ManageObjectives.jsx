import { useCallback, useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import ObjectiveFormModal from './ObjectiveFormModal';
import ConfirmDialog from '../../components/ConfirmDialog';
import api from '../../lib/api';
import './dashboards.css';
import Banner from '../../components/Banner';

/**
 * Manage the strategic-objective tree (Phase 11 / DR objective linkage).
 * A system admin enters the goals and sub-objectives from the OSM's
 * strategic plan; reviewers then link each document to the objectives it
 * supports, and those links drive the repository's objective filter and
 * the compliance reports.
 */
export default function ManageObjectives() {
  const [data, setData] = useState({ summary: null, tree: [], flat: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modal, setModal] = useState(null); // { mode, item?, parentId? }
  const [busyId, setBusyId] = useState(null);
  const [confirmDelete, setConfirmDelete] = useState(null); // node

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

  async function toggleActive(node) {
    setBusyId(node.id);
    setError('');
    try {
      await api.patch(`/admin/strategic-objectives/${node.id}`, { is_active: !node.is_active });
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not update that objective.');
    } finally {
      setBusyId(null);
    }
  }

  async function remove(node) {
    setBusyId(node.id);
    setError('');
    try {
      await api.delete(`/admin/strategic-objectives/${node.id}`);
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not delete that objective.');
      throw err; // let ConfirmDialog surface it and stay open
    } finally {
      setBusyId(null);
    }
  }

  const flatFor = (node) => data.flat.find((f) => f.id === node.id) || node;

  function Row({ node, depth }) {
    return (
      <>
        <tr>
          <td className="cell-mono tree-cell" style={{ '--depth': depth }}>
            {depth > 0 && <span className="cell-muted">└ </span>}
            {node.code}
          </td>
          <td>
            {node.title}
            {!node.is_active && (
              <span className="badge badge--revision u-ml-1">
                inactive
              </span>
            )}
          </td>
          <td className="cell-muted col-right">
            {node.document_count || 0}
          </td>
          <td>
            <div className="btn-row">
              <button
                className="btn btn--outline btn-sm"
                onClick={() => setModal({ mode: 'create', parentId: node.id })}
              >
                Add sub-objective
              </button>
              <button
                className="btn btn--outline btn-sm"
                onClick={() => setModal({ mode: 'edit', item: flatFor(node) })}
              >
                Edit
              </button>
              <button
                className="btn btn--outline btn-sm"
                disabled={busyId === node.id}
                onClick={() => toggleActive(node)}
              >
                {node.is_active ? 'Deactivate' : 'Reactivate'}
              </button>
              <button
                className="btn btn--danger-outline btn-sm"
                disabled={busyId === node.id}
                onClick={() => setConfirmDelete(node)}
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

  const s = data.summary;
  const tiles = [
    { label: 'Goals', value: s?.goals },
    { label: 'Sub-objectives', value: s?.sub_objectives },
    { label: 'Active', value: s?.active },
    { label: 'Documents linked', value: s?.linked_documents },
  ];

  return (
    <DashboardShell eyebrow="System / super admin" title="Strategic objectives">
      {error && <Banner tone="error">{error}</Banner>}


      <div className="stat-grid">
        {tiles.map((t) => (
          <div className="stat-card" key={t.label}>
            <div className="stat-value">{t.value ?? '—'}</div>
            <div className="stat-label">{t.label}</div>
          </div>
        ))}
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Objective tree</h2>
            <p className="panel-subtitle">
              Goals are top level; nest sub-objectives beneath any node to whatever depth the plan
              needs. Deactivate an objective to keep it on existing documents but hide it from the
              reviewer's picker. Removing a node unlinks its documents and re-parents its children.
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
          <div className="u-scroll-x">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Title</th>
                  <th className="col-right">Documents</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {data.tree.length === 0 && (
                  <tr>
                    <td colSpan={4} className="empty-row">
                      No objectives yet. Add the goals from the strategic plan with “Add goal”, then
                      add sub-objectives beneath each one.
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

      {confirmDelete && (
        <ConfirmDialog
          title={`Delete ${confirmDelete.code} — ${confirmDelete.title}?`}
          body={
            confirmDelete.document_count > 0
              ? `${confirmDelete.document_count} document(s) will be unlinked from it, and any sub-objectives re-parent to its parent.`
              : 'Any sub-objectives re-parent to its parent. This cannot be undone.'
          }
          confirmLabel="Delete objective"
          onConfirm={() => remove(confirmDelete)}
          onClose={() => setConfirmDelete(null)}
        />
      )}
    </DashboardShell>
  );
}
