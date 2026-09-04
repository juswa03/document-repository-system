import { useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../lib/api';
import { checkUploadFile } from '../../lib/uploads';

const DOCUMENT_TYPES = ['report', 'memo', 'minutes', 'plan', 'template', 'evidence', 'dataset'];
const ACCESS_LEVELS = ['internal', 'public', 'restricted', 'confidential'];
const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

export default function ResubmitModal({ submission, requestTypes, categories, onClose, onSaved }) {
  const isDocument = submission.kind === 'document';

  const [requestTypeId, setRequestTypeId] = useState(String(submission.request_type_id || ''));
  const [neededBy, setNeededBy] = useState(submission.needed_by || '');
  const [amount, setAmount] = useState(submission.amount ?? '');
  const [title, setTitle] = useState(submission.title || submission.type || '');
  const [categoryId, setCategoryId] = useState(String(submission.category_id || ''));
  const [documentType, setDocumentType] = useState(submission.document_type || 'report');
  const [documentDate, setDocumentDate] = useState(submission.document_date || '');
  const [reportingPeriod, setReportingPeriod] = useState(submission.reporting_period || '');
  const [accessLevel, setAccessLevel] = useState(submission.access_level || 'internal');
  const [keywords, setKeywords] = useState(submission.keywords || '');
  const [description, setDescription] = useState(submission.description || '');
  const [file, setFile] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    if (isDocument && file) {
      const fileError = checkUploadFile(file);
      if (fileError) {
        setError(fileError);
        return;
      }
    }
    setSaving(true);
    try {
      if (isDocument) {
        const form = new FormData();
        form.append('title', title);
        form.append('category_id', categoryId);
        form.append('document_type', documentType);
        form.append('document_date', documentDate);
        form.append('reporting_period', reportingPeriod);
        form.append('access_level', accessLevel);
        form.append('keywords', keywords);
        form.append('description', description);
        if (file) form.append('file', file);
        await api.post(`/dashboard/documents/${submission.id}/resubmit`, form);
      } else {
        await api.post(`/dashboard/requests/${submission.id}/resubmit`, {
          request_type_id: Number(requestTypeId),
          title,
          description,
          needed_by: neededBy || undefined,
          access_level: accessLevel,
          amount: amount === '' ? null : Number(amount),
        });
      }
      onSaved();
      onClose();
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
        'Could not resubmit. Please try again.';
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={`Resubmit ${submission.ref}`} onClose={onClose}>
      {submission.remarks && (
        <p className="cell-muted" style={{ marginBottom: '1.1rem' }}>
          <strong>Reviewer's note:</strong> {submission.remarks}
        </p>
      )}

      <form onSubmit={handleSubmit}>
        {isDocument ? (
          <>
            <div className="dash-field">
              <label className="dash-label" htmlFor="resubmit-title">Title</label>
              <input
                id="resubmit-title"
                className="dash-input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                required
              />
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="resubmit-category">Category</label>
              <select
                id="resubmit-category"
                className="dash-select"
                value={categoryId}
                onChange={(e) => setCategoryId(e.target.value)}
              >
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>{c.category_name}</option>
                ))}
              </select>
            </div>

            <div className="dash-row">
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-type">Document type</label>
                <select
                  id="resubmit-type"
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
                <label className="dash-label" htmlFor="resubmit-date">Document date</label>
                <input
                  id="resubmit-date"
                  type="date"
                  className="dash-input"
                  value={documentDate}
                  onChange={(e) => setDocumentDate(e.target.value)}
                />
              </div>
            </div>

            <div className="dash-row">
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-period">Reporting / coverage period</label>
                <input
                  id="resubmit-period"
                  className="dash-input"
                  value={reportingPeriod}
                  onChange={(e) => setReportingPeriod(e.target.value)}
                />
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-access">Proposed access level</label>
                <select
                  id="resubmit-access"
                  className="dash-select"
                  value={accessLevel}
                  onChange={(e) => setAccessLevel(e.target.value)}
                >
                  {ACCESS_LEVELS.map((a) => (
                    <option key={a} value={a}>{cap(a)}</option>
                  ))}
                </select>
              </div>
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="resubmit-keywords">Keywords / tags</label>
              <input
                id="resubmit-keywords"
                className="dash-input"
                value={keywords}
                onChange={(e) => setKeywords(e.target.value)}
              />
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="resubmit-description">Brief description / abstract</label>
              <textarea
                id="resubmit-description"
                className="dash-input"
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="resubmit-file">Replace attachment (optional)</label>
              <input
                id="resubmit-file"
                type="file"
                className="dash-input"
                accept=".pdf,.doc,.docx"
                onChange={(e) => setFile(e.target.files?.[0] || null)}
              />
              <p className="cell-muted" style={{ marginTop: '0.3rem' }}>
                PDF or Word. Leave blank to keep the file you already uploaded.
              </p>
            </div>
          </>
        ) : (
          <>
            <div className="dash-row">
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-type">Request type</label>
                <select
                  id="resubmit-type"
                  className="dash-select"
                  value={requestTypeId}
                  onChange={(e) => setRequestTypeId(e.target.value)}
                >
                  {requestTypes.map((t) => (
                    <option key={t.id} value={t.id}>{t.type_name}</option>
                  ))}
                </select>
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-req-title">Title</label>
                <input
                  id="resubmit-req-title"
                  className="dash-input"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                />
              </div>
            </div>

            <div className="dash-field">
              <label className="dash-label" htmlFor="resubmit-req-description">Details &amp; justification</label>
              <textarea
                id="resubmit-req-description"
                className="dash-input"
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </div>

            <div className="dash-row">
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-needed-by">Needed by</label>
                <input
                  id="resubmit-needed-by"
                  type="date"
                  className="dash-input"
                  value={neededBy}
                  onChange={(e) => setNeededBy(e.target.value)}
                />
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-amount">Amount (if applicable)</label>
                <input
                  id="resubmit-amount"
                  type="number"
                  min="0"
                  step="0.01"
                  className="dash-input"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                />
              </div>
              <div className="dash-field">
                <label className="dash-label" htmlFor="resubmit-req-access">Access level</label>
                <select
                  id="resubmit-req-access"
                  className="dash-select"
                  value={accessLevel}
                  onChange={(e) => setAccessLevel(e.target.value)}
                >
                  {ACCESS_LEVELS.map((a) => (
                    <option key={a} value={a}>{cap(a)}</option>
                  ))}
                </select>
              </div>
            </div>
          </>
        )}

        {error && <p className="error-banner">{error}</p>}

        <div className="btn-row">
          <button type="submit" className="btn btn--primary" disabled={saving}>
            {saving ? 'Resubmitting…' : 'Resubmit for review'}
          </button>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Cancel
          </button>
        </div>
      </form>
    </Modal>
  );
}
