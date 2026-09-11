import { useEffect, useState } from 'react';
import Modal from './Modal';
import api from '../lib/api';
import Banner from './Banner';
import StatusBadge from './StatusBadge';

/**
 * Read-only view of a document's version history (FR-11 / FR-12).
 * Consumes GET /documents/{id}/versions.
 */
export default function VersionHistoryModal({ documentId, reference, onClose }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let alive = true;
    api
      .get(`/documents/${documentId}/versions`)
      .then(({ data }) => alive && setData(data))
      .catch(
        (e) => alive && setError(e?.response?.data?.message || 'Could not load the version history.')
      );
    return () => {
      alive = false;
    };
  }, [documentId]);

  return (
    <Modal title={`Version history — ${reference || ''}`} onClose={onClose} width={640}>
      {error && <Banner tone="error">{error}</Banner>}
      {!data && !error && <p className="loading-text">Loading…</p>}

      {data && (
        <>
          <p className="panel-subtitle">
            Current version {data.current_version} · retention status: {data.retention_status}
          </p>
          <div className="u-scroll-x">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Version</th>
                  <th>Title</th>
                  <th>Type</th>
                  <th>Decision</th>
                  <th>Superseded</th>
                  <th>Reviewer remarks</th>
                </tr>
              </thead>
              <tbody>
                {[...data.versions].reverse().map((v) => (
                  <tr key={v.version_number} style={v.is_current ? { fontWeight: 600 } : undefined}>
                    <td className="cell-mono">
                      v{v.version_number}
                      {v.is_current ? ' · current' : ''}
                    </td>
                    <td>{v.title}</td>
                    <td>{v.document_type || '—'}</td>
                    <td>
                      {v.decision ? (
                        <StatusBadge status={v.decision} />
                      ) : (
                        <span className="cell-muted">Awaiting review</span>
                      )}
                    </td>
                    {/* The current version has not been superseded by
                        anything — that blank is structural, not missing
                        data, so it says so rather than showing a dash. */}
                    <td className="cell-muted">
                      {v.superseded_at
                        ? new Date(v.superseded_at).toLocaleDateString()
                        : v.is_current
                          ? 'In force'
                          : '—'}
                    </td>
                    <td className="cell-muted">
                      {v.review_remarks || (
                        <span className="cell-muted">
                          {v.decision === 'approved' ? 'No remarks' : '—'}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Modal>
  );
}
