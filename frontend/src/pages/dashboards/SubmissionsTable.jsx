import { Fragment, useState } from 'react';
import StatusBadge from '../../components/StatusBadge';
import Pager from '../../components/Pager';
import usePagination from '../../lib/usePagination';
import { downloadDocumentFile, downloadReviewResponseFile } from '../../lib/download';

/** The metadata the /dashboard/submissions response already carries. */
function SubmissionDetail({ s, onDownloadResponse }) {
  const rows =
    s.kind === 'document'
      ? [
          ['Title', s.title],
          ['Category', s.category],
          ['Document type', s.document_type],
          ['Document date', s.document_date],
          ['Reporting period', s.reporting_period],
          ['Proposed access level', s.access_level],
          ['Version', s.version_number ? `v${s.version_number}` : null],
          ['Keywords', s.keywords],
          ['Description', s.description],
          ["Reviewer's note", s.remarks],
        ]
      : [
          ['Title', s.title],
          ['Request type', s.type],
          ['Needed by', s.needed_by],
          // Only money-bearing request types carry an amount.
          ['Amount', s.amount],
          ['Access level', s.access_level],
          ['Details', s.description],
          // Collected only for types "subject to approval" (urgent,
          // sensitive). When such a type has none, say so — that's a gap
          // worth seeing, not a blank row that quietly disappears.
          ['Reason given', s.uploader_remarks, { always: Boolean(s.requires_justification) }],
          ["Reviewer's note", s.remarks],
        ];

  return (
    <dl className="sub-detail-grid">
      {/* Empty rows are dropped: a dash next to "Amount" on a request
          that never had one reads as missing data rather than as a
          field that does not apply here. The exception is `always` — a
          field whose absence is itself worth seeing. */}
      {rows
        .filter(([, value, opts]) => value || opts?.always)
        .map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value || <span className="cell-muted">Not provided</span>}</dd>
          </div>
        ))}
      {s.response_file && (
        <div>
          <dt>Response file</dt>
          <dd>
            <button className="btn btn--outline btn-sm" onClick={onDownloadResponse}>
              Download — {s.response_file.name}
            </button>
          </dd>
        </div>
      )}
    </dl>
  );
}

/**
 * Renders the submissions table + expandable detail rows. Used by the
 * dashboard overview and each status-filtered page so the row markup and
 * download/resubmit behaviour stay in one place.
 */
export default function SubmissionsTable({ submissions, emptyMessage, onResubmit, onError }) {
  const [detailKey, setDetailKey] = useState(null);
  const { pageItems, page, setPage, meta } = usePagination(submissions);

  async function handleDownload(s) {
    try {
      await downloadDocumentFile(s.id, s.type);
    } catch (err) {
      onError?.(err?.response?.data?.message || 'Could not download that file.');
    }
  }

  async function handleResponseDownload(s) {
    try {
      await downloadReviewResponseFile(s.response_file.review_id, s.response_file.name);
    } catch (err) {
      onError?.(err?.response?.data?.message || 'Could not download the response file.');
    }
  }

  return (
    <>
      <div className="table-scroll">
      <table className="data-table">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Type</th>
            <th>Submitted</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {submissions.length === 0 && (
            <tr>
              <td colSpan={5} className="empty-row">{emptyMessage || 'Nothing here.'}</td>
            </tr>
          )}
          {pageItems.map((s) => {
            const rowKey = `${s.kind}-${s.id}`;
            return (
              <Fragment key={rowKey}>
                <tr>
                  <td className="cell-mono">{s.ref}</td>
                  <td>{s.type}{s.kind === 'document' ? ' (doc)' : ''}</td>
                  <td className="cell-muted">{new Date(s.submitted_at).toLocaleDateString()}</td>
                  <td><StatusBadge status={s.review_stage || s.status} /></td>
                  <td>
                    <div className="btn-row">
                      <button
                        className="btn btn--outline btn-sm"
                        onClick={() => setDetailKey((k) => (k === rowKey ? null : rowKey))}
                      >
                        {detailKey === rowKey ? 'Hide' : 'Details'}
                      </button>
                      {s.kind === 'document' && (
                        <button className="btn btn--outline btn-sm" onClick={() => handleDownload(s)}>
                          Download
                        </button>
                      )}
                      {s.status === 'revision' && onResubmit && (
                        <button className="btn btn--outline btn-sm" onClick={() => onResubmit(s)}>
                          Resubmit
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
                {detailKey === rowKey && (
                  <tr>
                    <td colSpan={5} className="detail-row-cell">
                      <SubmissionDetail s={s} onDownloadResponse={() => handleResponseDownload(s)} />
                    </td>
                  </tr>
                )}
              </Fragment>
            );
          })}
        </tbody>
      </table>
      </div>
      <Pager meta={meta} page={page} onPage={setPage} />
    </>
  );
}
