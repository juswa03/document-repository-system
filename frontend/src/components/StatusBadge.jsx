import {
  REVIEW_LABELS,
  RETENTION_LABELS,
  ACCESS_LABELS,
} from '../lib/statusLabels';

const DIMENSIONS = {
  review: REVIEW_LABELS,
  retention: RETENTION_LABELS,
  access: ACCESS_LABELS,
};

/**
 * One status label. `dimension` picks the vocabulary — review (default),
 * retention, or access — because a document carries one value from each
 * at the same time and they must not be conflated.
 *
 *   <StatusBadge status={doc.review_stage} />
 *   <StatusBadge status={doc.retention_status} dimension="retention" />
 *   <StatusBadge status={doc.access_level} dimension="access" />
 *
 * The documented meaning rides along as a tooltip.
 */
export default function StatusBadge({ status, dimension = 'review' }) {
  const vocabulary = DIMENSIONS[dimension] || REVIEW_LABELS;
  const meta = vocabulary[status];

  if (!meta) {
    // An unrecognised value is shown as-is rather than silently
    // mislabelled as something else.
    return <span className="badge badge--draft">{status || '—'}</span>;
  }

  return (
    <span className={`badge ${meta.className}`} title={meta.meaning}>
      {meta.label}
    </span>
  );
}
