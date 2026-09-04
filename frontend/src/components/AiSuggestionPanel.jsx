import { useEffect, useState } from 'react';
import api from '../lib/api';

const KIND_LABEL = {
  classification: 'Category & type',
  completeness: 'Completeness',
  metadata: 'Metadata clean-up',
  confidentiality: 'Access level',
  summary: 'Summary',
  near_duplicate: 'Possible duplicate',
};

// Kinds where "accept" is an acknowledgement, not a field change.
const ACK_KINDS = new Set(['completeness', 'near_duplicate']);

function SuggestionBody({ row }) {
  const d = row.data || {};

  if (row.kind === 'classification') {
    return (
      <p className="ai-sugg-detail">
        Suggested category <strong>{d.category || '—'}</strong>, type <strong>{d.document_type || '—'}</strong>.
      </p>
    );
  }
  if (row.kind === 'confidentiality') {
    return (
      <p className="ai-sugg-detail">
        Suggested access level <strong>{d.access_level || '—'}</strong>.
      </p>
    );
  }
  if (row.kind === 'completeness') {
    const concerns = Array.isArray(d.concerns) ? d.concerns : [];
    return concerns.length ? (
      <ul className="ai-sugg-detail">
        {concerns.map((c, i) => <li key={i}>{c}</li>)}
      </ul>
    ) : (
      <p className="ai-sugg-detail">No concerns raised.</p>
    );
  }
  if (row.kind === 'metadata') {
    const fields = d.fields || {};
    const entries = Object.entries(fields);
    return entries.length ? (
      <ul className="ai-sugg-detail">
        {entries.map(([k, v]) => (
          <li key={k}><strong>{k.replace(/_/g, ' ')}:</strong> {v}</li>
        ))}
      </ul>
    ) : (
      <p className="ai-sugg-detail">No changes suggested.</p>
    );
  }
  if (row.kind === 'summary') {
    const points = Array.isArray(d.key_points) ? d.key_points : [];
    return (
      <div className="ai-sugg-detail">
        <p>{d.summary || '—'}</p>
        {points.length > 0 && (
          <ul>
            {points.map((p, i) => <li key={i}>{p}</li>)}
          </ul>
        )}
      </div>
    );
  }
  if (row.kind === 'near_duplicate') {
    return (
      <p className="ai-sugg-detail">
        Looks similar to <strong>{d.duplicate_of || 'an existing document'}</strong>
        {d.similarity != null && <> — {Math.round(d.similarity * 100)}% text overlap</>}.
      </p>
    );
  }
  return null;
}

export default function AiSuggestionPanel({ documentId }) {
  const [rows, setRows] = useState(null);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);

  useEffect(() => {
    let alive = true;
    api
      .get(`/osm-admin/documents/${documentId}/ai-suggestions`)
      .then(({ data }) => alive && setRows(data))
      .catch((err) =>
        alive && setError(err?.response?.data?.message || 'Could not load AI suggestions.')
      );
    return () => {
      alive = false;
    };
  }, [documentId]);

  async function resolve(id, action) {
    setBusyId(id);
    setError('');
    try {
      const { data } = await api.post(`/osm-admin/ai-suggestions/${id}/${action}`);
      setRows((prev) => prev.map((r) => (r.id === id ? data : r)));
    } catch (err) {
      setError(err?.response?.data?.message || `Could not ${action} that suggestion.`);
    } finally {
      setBusyId(null);
    }
  }

  if (error) return <p className="error-banner">{error}</p>;
  if (rows === null) return <p className="loading-text">Loading AI suggestions…</p>;
  if (rows.length === 0)
    return (
      <p className="panel-subtitle">
        No AI suggestions — the layer may be switched off, or analysis is still running.
      </p>
    );

  return (
    <div className="ai-sugg-list">
      {rows.map((row) => (
        <div className="ai-sugg-card" key={row.id}>
          <div className="ai-sugg-head">
            <span className="ai-sugg-kind">{KIND_LABEL[row.kind] || row.kind}</span>
            <span className="ai-sugg-meta">
              {Math.round((row.confidence || 0) * 100)}% confident · {row.model}
              {row.status !== 'pending' && <> · <strong>{row.status}</strong></>}
            </span>
          </div>

          <SuggestionBody row={row} />
          {row.rationale && <p className="ai-sugg-why">{row.rationale}</p>}

          {row.status === 'pending' && (
            <div className="btn-row">
              <button
                className="btn btn--primary btn-sm"
                disabled={busyId === row.id}
                onClick={() => resolve(row.id, 'accept')}
              >
                {ACK_KINDS.has(row.kind)
                  ? 'Acknowledge'
                  : row.kind === 'summary'
                    ? 'Accept & save'
                    : 'Accept & apply'}
              </button>
              <button
                className="btn btn--outline btn-sm"
                disabled={busyId === row.id}
                onClick={() => resolve(row.id, 'dismiss')}
              >
                Dismiss
              </button>
            </div>
          )}
        </div>
      ))}
    </div>
  );
}
