/**
 * The documented status vocabulary. Three independent dimensions — a
 * document carries one value from each at all times:
 *
 *   review     draft → submitted / for review → revision → approved | rejected
 *   retention  active → superseded → archived → disposed
 *   access     public | internal | restricted | confidential
 *
 * `review_stage` from the API already resolves "Submitted" vs "For
 * review" (both are `pending` in the database, split by whether a
 * reviewer is assigned), so prefer it over `status` for display.
 */

export const REVIEW_LABELS = {
  draft: {
    label: 'Draft',
    meaning: 'Details are being encoded but not yet submitted.',
    className: 'badge--draft',
  },
  submitted: {
    label: 'Submitted',
    meaning: 'Uploaded and submitted for review.',
    className: 'badge--submitted',
  },
  for_review: {
    label: 'For review',
    meaning: 'Awaiting review by OSM staff.',
    className: 'badge--pending',
  },
  // The raw status, for callers that have no review_stage to hand.
  pending: {
    label: 'For review',
    meaning: 'Awaiting review by OSM staff.',
    className: 'badge--pending',
  },
  revision: {
    label: 'For revision',
    meaning: 'Returned due to missing or incorrect details.',
    className: 'badge--revision',
  },
  approved: {
    label: 'Approved',
    meaning: 'Passed review and accepted into the repository.',
    className: 'badge--approved',
  },
  rejected: {
    label: 'Rejected',
    meaning: 'Not accepted — invalid, duplicate, or inappropriate.',
    className: 'badge--rejected',
  },
};

export const RETENTION_LABELS = {
  active: {
    label: 'Active',
    meaning: 'Currently valid and available for use.',
    className: 'badge--approved',
  },
  superseded: {
    label: 'Superseded',
    meaning: 'Replaced by a newer version.',
    className: 'badge--submitted',
  },
  archived: {
    label: 'Archived',
    meaning: 'No longer active but retained for record purposes.',
    className: 'badge--draft',
  },
  disposed: {
    label: 'Disposed',
    meaning: 'Removed from the repository under the retention schedule.',
    className: 'badge--rejected',
  },
};

export const ACCESS_LABELS = {
  public: {
    label: 'Public',
    meaning: 'Open to anyone.',
    className: 'badge--approved',
  },
  internal: {
    label: 'Internal',
    meaning: 'Any authenticated OSM / BiPSU user.',
    className: 'badge--submitted',
  },
  restricted: {
    label: 'Restricted',
    meaning: 'Accessible only to authorized users.',
    className: 'badge--revision',
  },
  confidential: {
    label: 'Confidential',
    meaning: 'Requires higher-level permission before access or release.',
    className: 'badge--rejected',
  },
};

/** Prefer review_stage; fall back to the raw status. */
export function reviewMeta(submission) {
  const key = submission?.review_stage || submission?.status;
  return REVIEW_LABELS[key] || REVIEW_LABELS.pending;
}

export const retentionMeta = (v) => RETENTION_LABELS[v] || null;
export const accessMeta = (v) => ACCESS_LABELS[v] || null;
