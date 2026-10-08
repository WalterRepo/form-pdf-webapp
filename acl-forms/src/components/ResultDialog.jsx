import React, { useEffect, useRef } from 'react';

export function ResultDialog({ state, onClose, successTitle = 'Iscrizione completata', errorTitle = 'Iscrizione non completata' }) {
  const closeRef = useRef(null);
  const dialogRef = useRef(null);

  useEffect(() => {
    if (!state?.message) return undefined;
    const previousFocus = document.activeElement;
    closeRef.current?.focus();
    const onKeyDown = (event) => {
      if (event.key === 'Escape') onClose();
      if (event.key === 'Tab') {
        event.preventDefault();
        const focusable = [...(dialogRef.current?.querySelectorAll('summary, button') || [])];
        const current = Math.max(0, focusable.indexOf(document.activeElement));
        const next = event.shiftKey
          ? (current - 1 + focusable.length) % focusable.length
          : (current + 1) % focusable.length;
        focusable[next]?.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      previousFocus?.focus?.();
    };
  }, [state?.message, onClose]);

  if (!state?.message) return null;
  const success = state.type === 'success';
  const title = success ? successTitle : errorTitle;

  return (
    <div className="acl-dialog-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div
        ref={dialogRef}
        className={`acl-dialog acl-dialog--${success ? 'success' : 'error'}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="acl-registration-dialog-title"
        aria-describedby="acl-registration-dialog-message"
      >
        <div className="acl-dialog__icon" aria-hidden="true">{success ? '✓' : '!'}</div>
        <h3 id="acl-registration-dialog-title">{title}</h3>
        <p id="acl-registration-dialog-message">{state.message}</p>
        {state.details?.length > 0 && (
          <details className="acl-dialog__details">
            <summary>Dettagli tecnici e log</summary>
            <dl>
              {state.details.map(({ label, value, status }) => (
                <div key={label}>
                  <dt>{label}</dt>
                  <dd className={status ? `is-${status}` : undefined}>{value}</dd>
                </div>
              ))}
            </dl>
          </details>
        )}
        <button ref={closeRef} className="acl-button acl-button--primary" type="button" onClick={onClose}>
          Chiudi
        </button>
      </div>
    </div>
  );
}
