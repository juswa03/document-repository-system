import { useEffect, useState } from 'react';
import Modal from './Modal';
import api from '../lib/api';

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
      {error && <p className="error-banner">{error}</p>}
      {!data && !error && <p className="loading-text">Loading…</p>}

      {data && (
        <>
          <p className="panel-subtitle">
            Current version {data.current_version} · retention status: {data.retention_status}
          </p>
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Version</th>
                  <th>Title</th>
                  <th>Type</th>
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
                    <td className="cell-muted">
                      {v.superseded_at ? new Date(v.superseded_at).toLocaleDateString() : '—'}
                    </td>
                    <td className="cell-muted">{v.review_remarks || '—'}</td>
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
