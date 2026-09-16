import { useEffect, useMemo, useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../lib/api';
import { checkUploadFile } from '../../lib/uploads';
import Banner from '../../components/Banner';
import ReportingPeriodField from '../../components/ReportingPeriodField';
import './UserDashboard.css';

const DOCUMENT_TYPES = ['report', 'memo', 'minutes', 'plan', 'template', 'evidence', 'dataset'];
const ACCESS_LEVEL_LABELS = {
  internal: 'Internal — any OSM / BiPSU user',
  public: 'Public',
  restricted: 'Restricted — named users, by grant',
  confidential: 'Confidential — need-to-know',
};
const ALL_ACCESS_LEVELS = ['internal', 'public', 'restricted', 'confidential'];
const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

/**
 * Steps 3-6 of the process flow. The uploader fills the form, the system
 * checks the file against what is already on record, and the uploader
 * confirms or overrides those findings before anything is submitted.
 *
 * Pass `draft` to resume an existing draft instead of starting fresh.
 * Pass `lockTargetOffice` to pin the submission to the uploader's own
 * office — the field shows as fixed rather than an editable dropdown
 * (used for an office admin uploading from the repository, who should
 * not be routing documents to an office other than their own).
 */
export default function NewDocumentModal({
  categories,
  offices,
  draft,
  lockTargetOffice = false,
  lockedOfficeName,
  onClose,
  onSaved,
}) {
  const [title, setTitle] = useState(draft?.title || '');
  const [categoryId, setCategoryId] = useState(
    String(draft?.category_id || categories[0]?.id || ''),
  );
  const [documentType, setDocumentType] = useState(draft?.document_type || 'report');
  const [documentDate, setDocumentDate] = useState(draft?.document_date || '');
  const [reportingPeriod, setReportingPeriod] = useState(draft?.reporting_period || '');
  const [accessLevel, setAccessLevel] = useState(draft?.access_level || 'internal');
  const [keywords, setKeywords] = useState(draft?.keywords || '');
  const [description, setDescription] = useState(draft?.description || '');
  const [file, setFile] = useState(null);
  const [targetOfficeId, setTargetOfficeId] = useState(
    draft?.target_office_id ? String(draft.target_office_id) : '',
  );
  const [submitting, setSubmitting] = useState(false);
  const [savingDraft, setSavingDraft] = useState(false);
  const [error, setError] = useState('');

  // The draft this modal is editing — set once saved, so a second
  // "Save as draft" updates rather than creating a duplicate.
  const [draftId, setDraftId] = useState(draft?.id || null);
  // A resumed draft already has a file on the server; the file input is
  // empty but the document is not fileless.
  const hasStoredFile = Boolean(draft?.file_format);

  // Step 5/6 — pre-submission findings and what the uploader decided.
  const [checking, setChecking] = useState(false);
  const [preflight, setPreflight] = useState(null);
  const [fileAsNewVersion, setFileAsNewVersion] = useState(false);
  const [appliedSuggestions, setAppliedSuggestions] = useState([]);

  // categories is fetched asynchronously and is usually empty on the
  // first render, which would strand categoryId at '' while the <select>
  // displays the first option — the form then fails validation on a
  // field that looks filled in. Adopt the first category when it lands.
  useEffect(() => {
    if (!categoryId && categories.length > 0) {
      setCategoryId(String(categories[0].id));
    }
  }, [categories, categoryId]);

  // Any edit after a check invalidates it — the uploader re-checks.
  // file/category/title are the only inputs the duplicate/version verdict
  // itself is computed from (SubmissionPreflight::inspect on the server),
  // so those are what must invalidate `preflight` and the pending
  // supersedes_id choice. The other metadata fields only feed the access
  // policy / AI-suggestion parts of the response — resetting on every
  // keystroke there would also wipe the suggestions list an uploader is
  // actively applying suggestions from.
  useEffect(() => {
    setPreflight(null);
    setFileAsNewVersion(false);
  }, [file, categoryId, title]);

  const allowedLevels = preflight?.access_policy?.allowed || ALL_ACCESS_LEVELS;

  const missingFields = useMemo(() => {
    const missing = [];
    if (!title.trim()) missing.push('title');
    if (!categoryId) missing.push('category');
    if (!file && !hasStoredFile) missing.push('attachment');
    if (!documentDate) missing.push('document date');
    if (!reportingPeriod.trim()) missing.push('reporting period');
    if (!keywords.trim()) missing.push('keywords');
    if (description.trim().length < 20) missing.push('description (min 20 characters)');
    return missing;
  }, [title, categoryId, file, hasStoredFile, documentDate, reportingPeriod, keywords, description]);

  function formValues() {
    return {
      title,
      category_id: categoryId,
      document_type: documentType,
      document_date: documentDate,
      reporting_period: reportingPeriod,
      access_level: accessLevel,
      keywords,
      description,
    };
  }

  /**
   * Save without submitting — "details are being encoded but not yet
   * submitted". Only a title is required; everything else can be filled
   * in later. Nothing is routed and no reviewer sees it.
   */
  async function saveDraft() {
    setError('');

    if (!title.trim()) {
      setError('A draft still needs a title so you can find it again.');
      return;
    }
    if (file) {
      const fileError = checkUploadFile(file);
      if (fileError) {
        setError(fileError);
        return;
      }
    }

    setSavingDraft(true);
    try {
      const form = new FormData();
      // Send only what has been filled in — a draft is allowed to be
      // partial, and empty strings would fail the shape checks.
      Object.entries(formValues()).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) form.append(k, v);
      });
      if (targetOfficeId) form.append('target_office_id', targetOfficeId);
      if (file) form.append('file', file);

      const url = draftId
        ? `/dashboard/documents/${draftId}/draft`
        : '/dashboard/documents/draft';

      const { data } = await api.post(url, form);
      setDraftId(data.id);
      onSaved();
      onClose();
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
          'Could not save the draft.',
      );
    } finally {
      setSavingDraft(false);
    }
  }

  /** Step 5 — ask the system what it makes of this file before submitting. */
  async function runPreflight() {
    setError('');

    if (missingFields.length > 0) {
      setError(`Still needed: ${missingFields.join(', ')}.`);
      return;
    }

    // A resumed draft's file already lives on the server. There is
    // nothing local to re-check, so go straight to submitting.
    if (!file && hasStoredFile) {
      setPreflight({ duplicate_check: null, suggestions: [], access_policy: null, skipped: true });
      return;
    }

    const fileError = checkUploadFile(file);
    if (fileError) {
      setError(fileError);
      return;
    }

    setChecking(true);
    try {
      const form = new FormData();
      Object.entries(formValues()).forEach(([k, v]) => form.append(k, v));
      form.append('file', file);

      const { data } = await api.post('/dashboard/documents/preflight', form);
      setPreflight(data);

      // A category may forbid the level chosen before the policy was known.
      if (data.access_policy && !data.access_policy.chosen_is_allowed) {
        setAccessLevel(data.access_policy.default);
      }
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
          'Could not check this file. You can still submit it.',
      );
      // Let the uploader proceed rather than trapping them behind a
      // check that is advisory by design.
      setPreflight({ duplicate_check: null, suggestions: [], access_policy: null, failed: true });
    } finally {
      setChecking(false);
    }
  }

  /** Step 6 — take an AI suggestion into the form (the uploader can still edit it after). */
  function applySuggestion(s) {
    if (s.kind === 'classification') {
      const match = categories.find((c) => c.category_name === s.data?.category);
      if (match) setCategoryId(String(match.id));
      if (DOCUMENT_TYPES.includes(s.data?.document_type)) setDocumentType(s.data.document_type);
    }
    if (s.kind === 'metadata') {
      const f = s.data?.fields || {};
      if (f.reporting_period) setReportingPeriod(f.reporting_period);
      if (f.keywords) setKeywords(f.keywords);
      if (f.description) setDescription(f.description);
      if (f.document_date) setDocumentDate(String(f.document_date).slice(0, 10));
    }
    if (s.kind === 'confidentiality' && ALL_ACCESS_LEVELS.includes(s.data?.access_level)) {
      setAccessLevel(s.data.access_level);
    }
    setAppliedSuggestions((prev) => [...prev, s.kind]);
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    if (missingFields.length > 0) {
      setError(`Still needed: ${missingFields.join(', ')}.`);
      return;
    }
    if (file) {
      const fileError = checkUploadFile(file);
      if (fileError) {
        setError(fileError);
        return;
      }
    }

    setSubmitting(true);
    try {
      const form = new FormData();
      Object.entries(formValues()).forEach(([k, v]) => form.append(k, v));
      if (file) form.append('file', file);
      if (targetOfficeId) form.append('target_office_id', targetOfficeId);

      // The uploader confirmed this supersedes an existing record.
      if (fileAsNewVersion && preflight?.duplicate_check?.match?.id) {
        form.append('supersedes_id', preflight.duplicate_check.match.id);
      }

      // A draft is promoted in place — same record, tracking number
      // issued now. Anything else is a fresh submission.
      await api.post(
        draftId ? `/dashboard/documents/${draftId}/submit` : '/dashboard/documents',
        form,
      );
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

  const check = preflight?.duplicate_check;
  const suggestions = preflight?.suggestions || [];
  const checked = preflight !== null;

  return (
    <Modal title={draftId ? 'Continue draft' : 'Upload document'} onClose={onClose} width={620}>
      <form onSubmit={handleSubmit}>
        <div className="dash-row">
          <div className="dash-field">
            <label className="dash-label" htmlFor="title">Title</label>
            <input
              id="title"
              className="dash-input"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="e.g. Q3 supply requisition"
            />
          </div>
          <div className="dash-field">
            <label className="dash-label" htmlFor="category">Category</label>
            <select
              id="category"
              className="dash-select"
              value={categoryId}
              onChange={(e) => setCategoryId(e.target.value)}
            >
              {categories.map((c) => (
                <option key={c.id} value={c.id}>{c.category_name}</option>
              ))}
            </select>
          </div>
        </div>

        <div className="dash-row">
          <div className="dash-field">
            <label className="dash-label" htmlFor="documentType">Document type</label>
            <select
              id="documentType"
              className="dash-select"
              value={documentType}
              onChange={(e) => setDocumentType(e.target.value)}
            >
              {DOCUMENT_TYPES.map((t) => (
                <option key={t} value={t}>{cap(t)}</option>
              ))}
            </select>
          </div>
          <div className="dash-field">
            <label className="dash-label" htmlFor="documentDate">Document date</label>
            <input
              id="documentDate"
              type="date"
              className="dash-input"
              value={documentDate}
              onChange={(e) => setDocumentDate(e.target.value)}
            />
            <p className="cell-muted u-mt-1">
              The date printed on the document itself.
            </p>
          </div>
        </div>

        <div className="dash-row">
          <div className="dash-field">
            <label className="dash-label" htmlFor="reportingPeriod">Reporting / coverage period</label>
            <ReportingPeriodField
              id="reportingPeriod"
              value={reportingPeriod}
              onChange={setReportingPeriod}
            />
          </div>
          <div className="dash-field">
            <label className="dash-label" htmlFor="accessLevel">Proposed access level</label>
            <select
              id="accessLevel"
              className="dash-select"
              value={accessLevel}
              onChange={(e) => setAccessLevel(e.target.value)}
            >
              {(checked ? allowedLevels : ALL_ACCESS_LEVELS).map((value) => (
                <option key={value} value={value}>
                  {ACCESS_LEVEL_LABELS[value] || value}
                </option>
              ))}
            </select>
            <p className="cell-muted u-mt-1">
              {checked && allowedLevels.length < ALL_ACCESS_LEVELS.length
                ? 'Narrowed to the levels this category permits.'
                : 'The reviewer confirms this at approval.'}
            </p>
          </div>
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="docTargetOffice">Target office</label>
          {lockTargetOffice ? (
            <>
              <input
                id="docTargetOffice"
                className="dash-input"
                value={lockedOfficeName || 'My office'}
                disabled
                readOnly
              />
              <p className="cell-muted u-mt-1">
                Uploads here are always labeled to your own office.
              </p>
            </>
          ) : (
            <select
              id="docTargetOffice"
              className="dash-select"
              value={targetOfficeId}
              onChange={(e) => setTargetOfficeId(e.target.value)}
            >
              <option value="">— My office (default) —</option>
              {offices.map((o) => (
                <option key={o.id} value={o.id}>{o.office_name}</option>
              ))}
            </select>
          )}
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="keywords">Keywords / tags</label>
          <input
            id="keywords"
            className="dash-input"
            value={keywords}
            onChange={(e) => setKeywords(e.target.value)}
            placeholder="comma-separated, e.g. accreditation, self-study, 2026"
          />
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="description">Brief description / abstract</label>
          <textarea
            id="description"
            className="dash-input"
            rows={3}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="A sentence or two on what this document contains and why it was filed."
          />
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="file">Attachment</label>
          <input
            id="file"
            type="file"
            className="dash-input"
            accept=".pdf,.doc,.docx"
            onChange={(e) => setFile(e.target.files?.[0] || null)}
          />
          <p className="cell-muted u-mt-1">
            {hasStoredFile
              ? `This draft already has a ${String(draft.file_format).toUpperCase()} attached — choose a file only to replace it.`
              : 'PDF or Word document — up to 20 MB.'}
          </p>
        </div>

        {/* Step 5/6 — findings the uploader confirms or overrides. */}
        {check && (
          <div className="preflight">
            {check.verdict === 'duplicate' && (
              <Banner tone="error">
                <strong>Possible duplicate.</strong> {check.rationale} You can still submit it if
                this is intentional — the reviewer will see the flag.
              </Banner>
            )}

            {check.verdict === 'new_version' && (
              <Banner tone="warning">
                <strong>This may be a new version.</strong> {check.rationale}
                <label className="preflight-choice">
                  <input
                    type="checkbox"
                    checked={fileAsNewVersion}
                    onChange={(e) => setFileAsNewVersion(e.target.checked)}
                  />{' '}
                  File this as version {(check.match?.version_number ?? 1) + 1} of{' '}
                  {check.match?.ref} instead of a separate document
                </label>
              </Banner>
            )}

            {check.verdict === 'new' && (
              <Banner tone="success">
                <strong>Nothing similar on file.</strong> {check.rationale}
              </Banner>
            )}
          </div>
        )}

        {suggestions.length > 0 && (
          <section className="preflight-suggestions">
            <h3 className="panel-title preflight-title">Suggestions — apply any that look right</h3>
            {suggestions.map((s) => {
              const used = appliedSuggestions.includes(s.kind);
              return (
                <div key={s.kind} className="preflight-suggestion">
                  <div className="preflight-suggestion-body">
                    <strong>{cap(s.kind)}</strong>
                    <p className="cell-muted">{s.rationale}</p>
                  </div>
                  <button
                    type="button"
                    className="btn btn--outline btn-sm"
                    disabled={used}
                    onClick={() => applySuggestion(s)}
                  >
                    {used ? 'Applied' : 'Apply'}
                  </button>
                </div>
              );
            })}
            <p className="cell-muted u-mt-1">
              Nothing is applied unless you choose it, and you can edit any field afterwards.
            </p>
          </section>
        )}

        {error && <Banner tone="error">{error}</Banner>}

        <div className="btn-row">
          {!checked ? (
            <button
              type="button"
              className="btn btn--primary"
              disabled={checking || savingDraft}
              onClick={runPreflight}
            >
              {checking ? 'Checking…' : 'Check & continue'}
            </button>
          ) : (
            <button type="submit" className="btn btn--primary" disabled={submitting || savingDraft}>
              {submitting
                ? 'Uploading…'
                : fileAsNewVersion
                  ? 'Submit as new version'
                  : 'Submit for review'}
            </button>
          )}

          {/* Draft — save the encoding so far and come back to it. */}
          <button
            type="button"
            className="btn btn--outline"
            disabled={savingDraft || submitting || checking}
            onClick={saveDraft}
          >
            {savingDraft ? 'Saving…' : draftId ? 'Update draft' : 'Save as draft'}
          </button>

          <button type="button" className="btn btn--outline" onClick={onClose}>
            Cancel
          </button>
        </div>

        <p className="cell-muted u-mt-1">
          A draft stays private to you — it is not sent to a reviewer until you submit it.
        </p>
      </form>
    </Modal>
  );
}
