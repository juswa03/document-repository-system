import { useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../lib/api';

export default function LookupFormModal({ config, mode, item, onClose, onSaved }) {
  const isEdit = mode === 'edit';
  const [name, setName] = useState(item?.[config.nameField] || '');
  const [code, setCode] = useState(item?.[config.codeField] || '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSaving(true);
    const payload = { [config.nameField]: name, [config.codeField]: code.toUpperCase() };
    try {
      if (isEdit) {
        await api.patch(config.updateEndpoint(item.id), payload);
      } else {
        await api.post(config.createEndpoint, payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
        'Could not save this entry.';
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={isEdit ? `Edit ${config.singular.toLowerCase()}` : `New ${config.singular.toLowerCase()}`} onClose={onClose}>
      <form onSubmit={handleSubmit}>
        <div className="dash-field">
          <label className="dash-label" htmlFor="lookup-name">{config.nameLabel}</label>
          <input
            id="lookup-name"
            className="dash-input"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
        </div>

        <div className="dash-field">
          <label className="dash-label" htmlFor="lookup-code">{config.codeLabel}</label>
          <input
            id="lookup-code"
            className="dash-input"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            required
            maxLength={20}
          />
          <p className="cell-muted" style={{ marginTop: '0.3rem' }}>
            Short, unique — used in tracking numbers (e.g. FIN, HQ).
          </p>
        </div>

        {error && <p className="error-banner">{error}</p>}

        <div className="btn-row">
          <button type="submit" className="btn btn--primary" disabled={saving}>
            {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Create'}
          </button>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Cancel
          </button>
        </div>
      </form>
    </Modal>
  );
}
