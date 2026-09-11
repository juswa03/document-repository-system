import { useState } from 'react';
import DashboardShell from './DashboardShell';
import NewDocumentModal from './NewDocumentModal';
import Modal from '../../components/Modal';
import Banner from '../../components/Banner';
import StatusBadge from '../../components/StatusBadge';
import Pager from '../../components/Pager';
import usePagination from '../../lib/usePagination';
import useSubmissions from './useSubmissions';
import api from '../../lib/api';
import './dashboards.css';
import './UserDashboard.css';

/**
 * "Draft — document details are being encoded but not yet submitted."
 *
 * A draft has no tracking number yet and is invisible to reviewers; it
 * becomes a real submission only when the uploader finishes it here.
 */
export default function DraftSubmissions() {
  const { submissions, categories, offices, loading, error, setError, reload } = useSubmissions();
  const drafts = submissions.filter((s) => s.status === 'draft');
  const { pageItems, page, setPage, meta } = usePagination(drafts);

  const [editing, setEditing] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [confirmDiscard, setConfirmDiscard] = useState(null);

  async function discard(draft) {
    setBusyId(draft.id);
    setError('');
    try {
      await api.delete(`/dashboard/documents/${draft.id}/draft`);
      setConfirmDiscard(null);
      await reload();
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not discard that draft.');
    } finally {
      setBusyId(null);
    }
  }

  return (
    <DashboardShell eyebrow="User / office" title="Drafts">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Drafts</h2>
            <p className="panel-subtitle">
              Started but not yet submitted. Only you can see these — no reviewer has them.
            </p>
          </div>
          <button className="btn btn--primary btn-sm" onClick={() => setEditing({})}>
            + New draft
          </button>
        </div>

        {loading ? (
          <p className="loading-text">Loading your drafts…</p>
        ) : (
          <div className="table-scroll">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Category</th>
                  <th>Attachment</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {drafts.length === 0 && (
                  <tr>
                    <td colSpan={5} className="empty-row">
                      No drafts. Anything you start and save without submitting appears here.
                    </td>
                  </tr>
                )}
                {pageItems.map((d) => (
                  <tr key={d.id}>
                    <td>{d.title || <span className="cell-muted">Untitled</span>}</td>
                    <td>{d.category || <span className="cell-muted">Not chosen yet</span>}</td>
                    <td className="cell-muted">
                      {d.file_format ? String(d.file_format).toUpperCase() : 'None yet'}
                    </td>
                    <td><StatusBadge status="draft" /></td>
                    <td>
                      <div className="btn-row">
                        <button
                          className="btn btn--primary btn-sm"
                          onClick={() => setEditing(d)}
                        >
                          Continue
                        </button>
                        <button
                          className="btn btn--danger-outline btn-sm"
                          disabled={busyId === d.id}
                          onClick={() => setConfirmDiscard(d)}
                        >
                          Discard
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={meta} page={page} onPage={setPage} />
      </section>

      {editing && (
        <NewDocumentModal
          categories={categories}
          offices={offices}
          draft={editing.id ? editing : undefined}
          onClose={() => setEditing(null)}
          onSaved={reload}
        />
      )}

      {confirmDiscard && (
        <Modal title="Discard draft" onClose={() => setConfirmDiscard(null)} width={440}>
          <p>
            Discard “{confirmDiscard.title || 'Untitled'}”? This cannot be undone, and any file
            attached to the draft is discarded with it.
          </p>
          <div className="btn-row">
            <button
              className="btn btn--danger-outline"
              disabled={busyId === confirmDiscard.id}
              onClick={() => discard(confirmDiscard)}
            >
              {busyId === confirmDiscard.id ? 'Discarding…' : 'Discard draft'}
            </button>
            <button className="btn btn--outline" onClick={() => setConfirmDiscard(null)}>
              Keep it
            </button>
          </div>
        </Modal>
      )}
    </DashboardShell>
  );
}
