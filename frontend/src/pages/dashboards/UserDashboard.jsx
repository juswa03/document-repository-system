import { useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import StatusBadge from '../../components/StatusBadge';
import ResubmitModal from './ResubmitModal';
import api from '../../lib/api';
import { downloadDocumentFile } from '../../lib/download';
import { checkUploadFile } from '../../lib/uploads';
import { useAuth } from '../../context/AuthContext';
import './dashboards.css';

const DOCUMENT_TYPES = ['report', 'memo', 'minutes', 'plan', 'template', 'evidence', 'dataset'];
const ACCESS_LEVELS = [
  ['internal', 'Internal — any OSM / BiPSU user'],
  ['public', 'Public'],
  ['restricted', 'Restricted — named users, by grant'],
  ['confidential', 'Confidential — need-to-know'],
];
const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

export default function UserDashboard() {
  const { user } = useAuth();
  const isOsmAdmin = user?.role === 'osm_admin';
  const [submissions, setSubmissions] = useState([]);
  const [requestTypes, setRequestTypes] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [kind, setKind] = useState('request'); // 'request' | 'document'
  const [requestTypeId, setRequestTypeId] = useState('');
  const [neededBy, setNeededBy] = useState('');
  const [amount, setAmount] = useState('');
  const [title, setTitle] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [documentType, setDocumentType] = useState('report');
  const [documentDate, setDocumentDate] = useState('');
  const [reportingPeriod, setReportingPeriod] = useState('');
  const [accessLevel, setAccessLevel] = useState('internal');
  const [keywords, setKeywords] = useState('');
  const [description, setDescription] = useState('');
  const [file, setFile] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [resubmitTarget, setResubmitTarget] = useState(null);

  async function loadAll() {
    setLoading(true);
    setError('');
    try {
      const [subsRes, typesRes, catsRes] = await Promise.all([
        api.get('/dashboard/submissions'),
        api.get('/request-types'),
        api.get('/categories'),
      ]);
      setSubmissions(subsRes.data);
      setRequestTypes(typesRes.data);
      setCategories(catsRes.data);
      if (typesRes.data[0]) setRequestTypeId(String(typesRes.data[0].id));
      if (catsRes.data[0]) setCategoryId(String(catsRes.data[0].id));
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load your submissions.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadAll();
  }, []);

  const notices = submissions.filter((s) => s.status === 'revision' || s.status === 'rejected');

  async function handleDownload(s) {
    try {
      await downloadDocumentFile(s.id, s.type);
    } catch {
      setError('Could not download that file.');
    }
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setFormError('');

    const reqType = requestTypes.find((t) => String(t.id) === String(requestTypeId));
    const amountRequired = ['BUD', 'SUP'].includes(reqType?.type_code);
    if (kind === 'request') {
      if (!requestTypeId || !title.trim() || description.trim().length < 20 || !neededBy) {
        setFormError('Fill in the request type, title, a description of at least 20 characters, and a needed-by date.');
        return;
      }
      if (amountRequired && !amount) {
        setFormError('This request type needs an amount.');
        return;
      }
    }
    if (
      kind === 'document' &&
      (!title.trim() || !categoryId || !file || !documentDate || !reportingPeriod.trim() ||
        !keywords.trim() || description.trim().length < 20)
    ) {
      setFormError(
        'Fill in every field. The description needs at least 20 characters, and an attachment is required.',
      );
      return;
    }
    if (kind === 'document' && file) {
      const fileError = checkUploadFile(file);
      if (fileError) {
        setFormError(fileError);
        return;
      }
    }

    setSubmitting(true);
    try {
      if (kind === 'request') {
        await api.post('/dashboard/requests', {
          request_type_id: Number(requestTypeId),
          title,
          description,
          needed_by: neededBy,
          access_level: accessLevel,
          amount: amount === '' ? null : Number(amount),
        });
      } else {
        const form = new FormData();
        form.append('title', title);
        form.append('category_id', categoryId);
        form.append('document_type', documentType);
        form.append('document_date', documentDate);
        form.append('reporting_period', reportingPeriod);
        form.append('access_level', accessLevel);
        form.append('keywords', keywords);
        form.append('description', description);
        form.append('file', file);
        await api.post('/dashboard/documents', form);
      }
      setFormOpen(false);
      setTitle('');
      setDocumentDate('');
      setReportingPeriod('');
      setKeywords('');
      setDescription('');
      setNeededBy('');
      setAmount('');
      setFile(null);
      await loadAll();
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
        'Submission failed. Please try again.';
      setFormError(message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <DashboardShell
      eyebrow={isOsmAdmin ? 'OSM admin' : 'User / office'}
      title="My submissions & notices"
    >
      {error && <p className="error-banner">{error}</p>}

      {notices.length > 0 && (
        <div style={{ marginBottom: '1.75rem' }}>
          {notices.map((n) => (
            <div
              key={`${n.kind}-${n.id}`}
              className={`notice ${n.status === 'revision' ? 'notice--revision' : 'notice--rejected'}`}
            >
              <div>
                <strong>{n.ref}</strong> — {n.status === 'revision' ? 'needs revision' : 'was rejected'}
                {n.remarks ? `: ${n.remarks}` : ''}
              </div>
            </div>
          ))}
        </div>
      )}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">
              {formOpen ? 'New submission' : 'Submit a document or request'}
            </h2>
            <p className="panel-subtitle">
              {formOpen ? 'Choose a type, then fill in the details.' : 'Starts a new tracking number once sent.'}
            </p>
          </div>
          <button className="btn btn--outline btn-sm" onClick={() => setFormOpen((v) => !v)}>
            {formOpen ? 'Cancel' : '+ New submission'}
          </button>
        </div>

        {formOpen && (
          <form onSubmit={handleSubmit}>
            <div className="tab-row">
              <button
                type="button"
                className={`tab-btn ${kind === 'request' ? 'is-active' : ''}`}
                onClick={() => setKind('request')}
              >
                Request
              </button>
              <button
                type="button"
                className={`tab-btn ${kind === 'document' ? 'is-active' : ''}`}
                onClick={() => setKind('document')}
              >
                Document upload
              </button>
            </div>

            {kind === 'request' ? (
              <>
                <div className="dash-row">
                  <div className="dash-field">
                    <label className="dash-label" htmlFor="requestType">Request type</label>
                    <select
                      id="requestType"
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
                    <label className="dash-label" htmlFor="reqTitle">Title</label>
                    <input
                      id="reqTitle"
                      className="dash-input"
                      value={title}
                      onChange={(e) => setTitle(e.target.value)}
                      placeholder="e.g. December annual leave"
                    />
                  </div>
                </div>
                <div className="dash-field">
                  <label className="dash-label" htmlFor="reqDescription">Details &amp; justification</label>
                  <textarea
                    id="reqDescription"
                    className="dash-textarea"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder="What is being requested and why (at least 20 characters)."
                  />
                </div>
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
                  {['BUD', 'SUP'].includes(
                    requestTypes.find((t) => String(t.id) === String(requestTypeId))?.type_code
                  ) && (
                    <div className="dash-field">
                      <label className="dash-label" htmlFor="amount">Amount</label>
                      <input
                        id="amount"
                        type="number"
                        min="0"
                        step="0.01"
                        className="dash-input"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        placeholder="0.00"
                      />
                    </div>
                  )}
                  <div className="dash-field">
                    <label className="dash-label" htmlFor="reqAccess">Access level</label>
                    <select
                      id="reqAccess"
                      className="dash-select"
                      value={accessLevel}
                      onChange={(e) => setAccessLevel(e.target.value)}
                    >
                      {['public', 'internal', 'restricted', 'confidential'].map((a) => (
                        <option key={a} value={a}>{a}</option>
                      ))}
                    </select>
                  </div>
                </div>
              </>
            ) : (
              <>
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
                    <p className="cell-muted" style={{ marginTop: '0.35rem' }}>
                      The date printed on the document itself.
                    </p>
                  </div>
                </div>

                <div className="dash-row">
                  <div className="dash-field">
                    <label className="dash-label" htmlFor="reportingPeriod">Reporting / coverage period</label>
                    <input
                      id="reportingPeriod"
                      className="dash-input"
                      value={reportingPeriod}
                      onChange={(e) => setReportingPeriod(e.target.value)}
                      placeholder="e.g. AY 2025–2026, Q3 2026, Jan–Jun 2026"
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
                      {ACCESS_LEVELS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </select>
                    <p className="cell-muted" style={{ marginTop: '0.35rem' }}>
                      The reviewer confirms this at approval.
                    </p>
                  </div>
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
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png"
                    onChange={(e) => setFile(e.target.files?.[0] || null)}
                  />
                  <p className="cell-muted" style={{ marginTop: '0.35rem' }}>
                    PDF, Word, Excel, PowerPoint, or image — up to 20MB.
                  </p>
                </div>
              </>
            )}

            {formError && <p className="error-banner">{formError}</p>}

            <button type="submit" className="btn btn--primary" disabled={submitting}>
              {submitting ? 'Submitting…' : 'Submit for review'}
            </button>
          </form>
        )}
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Track submission</h2>
            <p className="panel-subtitle">Status and tracking number.</p>
          </div>
        </div>

        <div className="tab-row">
          {['all', 'pending', 'revision', 'rejected', 'approved'].map((s) => (
            <button
              key={s}
              type="button"
              className={`tab-btn ${statusFilter === s ? 'is-active' : ''}`}
              onClick={() => setStatusFilter(s)}
            >
              {s === 'all' ? 'All' : s === 'revision' ? 'Needs revision' : s[0].toUpperCase() + s.slice(1)}
            </button>
          ))}
        </div>

        {loading ? (
          <p className="loading-text">Loading your submissions…</p>
        ) : (
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
              {submissions.filter((s) => statusFilter === 'all' || s.status === statusFilter).length === 0 && (
                <tr>
                  <td colSpan={5} className="empty-row">No submissions match this filter.</td>
                </tr>
              )}
              {submissions
                .filter((s) => statusFilter === 'all' || s.status === statusFilter)
                .map((s) => (
                <tr key={`${s.kind}-${s.id}`}>
                  <td className="cell-mono">{s.ref}</td>
                  <td>{s.type}{s.kind === 'document' ? ' (doc)' : ''}</td>
                  <td className="cell-muted">{new Date(s.submitted_at).toLocaleDateString()}</td>
                  <td><StatusBadge status={s.status} /></td>
                  <td>
                    <div className="btn-row">
                      {s.kind === 'document' && (
                        <button className="btn btn--outline btn-sm" onClick={() => handleDownload(s)}>
                          Download
                        </button>
                      )}
                      {s.status === 'revision' && (
                        <button className="btn btn--outline btn-sm" onClick={() => setResubmitTarget(s)}>
                          Resubmit
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      {resubmitTarget && (
        <ResubmitModal
          submission={resubmitTarget}
          requestTypes={requestTypes}
          categories={categories}
          onClose={() => setResubmitTarget(null)}
          onSaved={loadAll}
        />
      )}
    </DashboardShell>
  );
}
