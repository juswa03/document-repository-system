import { useState } from 'react';
import Modal from './Modal';

/**
 * Confirmation dialog for destructive / irreversible actions. Replaces
 * window.confirm — same intent, but stylable, keyboard-accessible (inherits
 * Modal's focus trap + Escape), and consistent with the rest of the UI.
 *
 *   const [confirm, setConfirm] = useState(null);
 *   ...
 *   <button onClick={() => setConfirm({
 *     title: 'Remove this requirement?',
 *     body: 'Submitters will no longer be asked for this document.',
 *     confirmLabel: 'Remove',
 *     onConfirm: () => doRemove(id),
 *   })}>Remove</button>
 *   {confirm && (
 *     <ConfirmDialog {...confirm} onClose={() => setConfirm(null)} />
 *   )}
 *
 * onConfirm may return a promise; the confirm button shows a busy state
 * until it settles, then the dialog closes.
 */
export default function ConfirmDialog({
  title = 'Are you sure?',
  body,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  tone = 'danger', // 'danger' | 'primary'
  onConfirm,
  onClose,
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function handleConfirm() {
    setError('');
    setBusy(true);
    try {
      await onConfirm?.();
      onClose();
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'That action failed. Try again.');
      setBusy(false);
    }
  }

  const confirmClass = tone === 'danger' ? 'btn btn--danger' : 'btn btn--primary';

  return (
    <Modal title={title} onClose={busy ? () => {} : onClose} width={420}>
      {body && <p className="prose u-mt-0">{body}</p>}
      {error && (
        <p className="banner banner--error" role="alert">
          {error}
        </p>
      )}
      <div className="btn-row btn-row--end u-mt-4">
        <button type="button" className="btn btn--outline" onClick={onClose} disabled={busy}>
          {cancelLabel}
        </button>
        <button type="button" className={confirmClass} onClick={handleConfirm} disabled={busy}>
          {busy ? 'Working…' : confirmLabel}
        </button>
      </div>
    </Modal>
  );
}
