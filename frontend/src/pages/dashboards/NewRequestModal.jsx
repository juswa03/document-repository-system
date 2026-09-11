import { useEffect, useMemo, useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../lib/api';
import Banner from '../../components/Banner';
import './UserDashboard.css';

const ACCESS_LEVELS = ['public', 'internal', 'restricted', 'confidential'];

/** Whole working days from today, skipping weekends. Mirrors the
 *  server's App\LeadTime\Target::workingDaysBetween closely enough to
 *  set an expectation; the server figure is authoritative. */
function addWorkingDays(days) {
  const date = new Date();
  let remaining = days;
  while (remaining > 0) {
    date.setDate(date.getDate() + 1);
    const day = date.getDay();
    if (day !== 0 && day !== 6) remaining--;
  }
  return date;
}

const fmtDate = (d) =>
  d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });

export default function NewRequestModal({ requestTypes, offices, onClose, onSaved }) {
  const [requestTypeId, setRequestTypeId] = useState(String(requestTypes[0]?.id || ''));
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [remarks, setRemarks] = useState('');
  const [neededBy, setNeededBy] = useState('');
  const [accessLevel, setAccessLevel] = useState('internal');
  const [targetOfficeId, setTargetOfficeId] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const reqType = requestTypes.find((t) => String(t.id) === String(requestTypeId));
  const needsReason = Boolean(reqType?.requires_justification);

  // The date the office is expected to have it back by, at the slow end
  // of the published range — the honest number to plan against.
  const expectedBy = useMemo(() => {
    if (!reqType?.lead_max_days) return null;
    return addWorkingDays(reqType.lead_max_days);
  }, [reqType]);

  // Warn when the requester wants it sooner than the type's own target,
  // before they submit rather than after it slips.
  const neededTooSoon = useMemo(() => {
    if (!neededBy || !expectedBy) return false;
    const wanted = new Date(`${neededBy}T00:00:00`);
    return wanted < new Date(expectedBy.toDateString());
  }, [neededBy, expectedBy]);

  // The list is fetched asynchronously, so it is usually empty on the
  // first render. Adopt the first type as soon as it arrives — otherwise
  // requestTypeId stays '' while the <select> displays the first option,
  // and the form fails validation on a field that looks filled in.
  useEffect(() => {
    if (!requestTypeId && requestTypes.length > 0) {
      setRequestTypeId(String(requestTypes[0].id));
    }
  }, [requestTypes, requestTypeId]);

  // A reason typed for one type shouldn't linger invisibly on another —
  // including switching between two different types that both require
  // one, where needsReason alone never flips to false.
  useEffect(() => {
    setRemarks('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requestTypeId]);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    const missing = [];
    if (!requestTypeId) missing.push('a request type');
    if (!title.trim()) missing.push('a title');
    if (!neededBy) missing.push('a needed-by date');
    if (!description.trim()) {
      missing.push('a description');
    } else if (description.trim().length < 20) {
      missing.push(
        `a longer description (${description.trim().length} of 20 characters)`,
      );
    }
    if (needsReason) {
      if (!remarks.trim()) {
        missing.push('a reason');
      } else if (remarks.trim().length < 20) {
        missing.push(`a longer reason (${remarks.trim().length} of 20 characters)`);
      }
    }

    if (missing.length > 0) {
      setError(`Still needed: ${missing.join(', ')}.`);
      return;
    }

    setSubmitting(true);
    try {
      await api.post('/dashboard/requests', {
        request_type_id: Number(requestTypeId),
        title,
        description,
        remarks: remarks.trim() || null,
        needed_by: neededBy,
        access_level: accessLevel,
        target_office_id: targetOfficeId ? Number(targetOfficeId) : null,
      });
      onSaved();
      onClose();
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
        'Submission failed. Please try again.';
      setError(message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal title="New request" onClose={onClose} width={560}>
      <form onSubmit={handleSubmit}>
        <div className="dash-field">
          <label className="dash-label" htmlFor="requestType">What are you requesting?</label>
          <select
            id="requestType"
            className="dash-select"
            value={requestTypeId}
            onChange={(e) => setRequestTypeId(e.target.value)}
          >
            {requestTypes.map((t) => (
              <option key={t.id} value={t.id}>
                {t.lead_time_label ? `${t.type_name} — ${t.lead_time_label}` : t.type_name}
              </option>
            ))}
          </select>
        </div>

        {/* What this type covers and how long it takes, so the choice is
            informed rather than a guess at five similar-sounding names. */}
        {reqType && (reqType.examples || reqType.lead_time_label) && (
          <div className="req-guide">
            {reqType.examples && (
              <p className="req-guide-examples">
                <strong>Covers:</strong> {reqType.examples}
              </p>
            )}
            {reqType.lead_time_label && (
              <p className="req-guide-lead">
                <strong>Usual turnaround:</strong> {reqType.lead_time_label}
                {expectedBy && <> — normally back by around {fmtDate(expectedBy)}</>}
              </p>
            )}
            {needsReason && (
              <p className="req-guide-note">
                This type is subject to approval. Say why below — the reviewer decides whether
                it stands, and may reclassify it.
              </p>
            )}
          </div>
        )}

        <div className="dash-field">
          <label className="dash-label" htmlFor="reqTitle">Title</label>
          <input
            id="reqTitle"
            className="dash-input"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="e.g. Copy of the approved 2026 operational plan"
          />
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="reqDescription">What exactly do you need?</label>
          <textarea
            id="reqDescription"
            className="dash-textarea"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Describe the document or data, including any period or scope (at least 20 characters)."
          />
        </div>

        {needsReason && (
          <div className="dash-field">
            <label className="dash-label" htmlFor="reqRemarks">
              Reason {reqType.type_code === 'URG' ? 'for urgency' : 'for the request'}
            </label>
            <textarea
              id="reqRemarks"
              className="dash-textarea"
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              placeholder={
                reqType.type_code === 'URG'
                  ? 'e.g. Needed for the Board meeting on 18 Sept; instructed by the VP for Planning.'
                  : 'e.g. Required as supporting evidence for the AACCUP Level III visit.'
              }
            />
          </div>
        )}

        <div className="dash-row">
          <div className="dash-field">
            <label className="dash-label" htmlFor="neededBy">Needed by</label>
            <input
              id="neededBy"
              type="date"
              className="dash-input"
              value={neededBy}
              onChange={(e) => setNeededBy(e.target.value)}
            />
          </div>
          <div className="dash-field">
            <label className="dash-label" htmlFor="reqAccess">Access level</label>
            <select
              id="reqAccess"
              className="dash-select"
              value={accessLevel}
              onChange={(e) => setAccessLevel(e.target.value)}
            >
              {ACCESS_LEVELS.map((a) => (
                <option key={a} value={a}>{a}</option>
              ))}
            </select>
          </div>
        </div>

        {neededTooSoon && (
          <Banner tone="warning">
            You need this sooner than the usual {reqType.lead_time_label} for this type. It may
            not be possible — consider an urgent request, or say why the date matters in the
            description.
          </Banner>
        )}

        <div className="dash-field">
          <label className="dash-label" htmlFor="reqTargetOffice">Which office holds it?</label>
          <select
            id="reqTargetOffice"
            className="dash-select"
            value={targetOfficeId}
            onChange={(e) => setTargetOfficeId(e.target.value)}
          >
            <option value="">— My office (default) —</option>
            {offices.map((o) => (
              <option key={o.id} value={o.id}>{o.office_name}</option>
            ))}
          </select>
        </div>

        {error && <Banner tone="error">{error}</Banner>}

        <div className="btn-row">
          <button type="submit" className="btn btn--primary" disabled={submitting}>
            {submitting ? 'Submitting…' : 'Submit request'}
          </button>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Cancel
          </button>
        </div>
      </form>
    </Modal>
  );
}
