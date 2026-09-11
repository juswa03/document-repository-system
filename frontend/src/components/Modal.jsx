import { useEffect, useRef } from 'react';

const FOCUSABLE =
  'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';

export default function Modal({ title, onClose, children, width = 440 }) {
  const cardRef = useRef(null);
  const triggerRef = useRef(null);

  useEffect(() => {
    triggerRef.current = document.activeElement;
    const card = cardRef.current;

    // Focus the first focusable element (fall back to the card itself).
    const first = card?.querySelector(FOCUSABLE);
    (first ?? card)?.focus();

    function onKeyDown(e) {
      if (e.key === 'Escape') {
        e.stopPropagation();
        onClose();
        return;
      }
      if (e.key !== 'Tab') return;

      const items = card?.querySelectorAll(FOCUSABLE);
      if (!items || items.length === 0) {
        e.preventDefault();
        return;
      }
      const list = Array.from(items);
      const firstEl = list[0];
      const lastEl = list[list.length - 1];
      if (e.shiftKey && document.activeElement === firstEl) {
        e.preventDefault();
        lastEl.focus();
      } else if (!e.shiftKey && document.activeElement === lastEl) {
        e.preventDefault();
        firstEl.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown, true);
    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      // Restore focus to whatever opened the modal.
      if (triggerRef.current instanceof HTMLElement) triggerRef.current.focus();
    };
  }, [onClose]);

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div
        ref={cardRef}
        className="modal-card"
        style={{ maxWidth: width }}
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
      >
        <div className="modal-header">
          <h2>{title}</h2>
          <button
            type="button"
            className="icon-btn modal-close"
            onClick={onClose}
            aria-label="Close"
          >
            ×
          </button>
        </div>
        <div className="modal-body">{children}</div>
      </div>
    </div>
  );
}
