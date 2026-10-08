import React, { useState } from 'react';
import { api } from '../api.js';
import { Status } from '../components/Status.jsx';
import { ResultDialog } from '../components/ResultDialog.jsx';

export function CertificateForm() {
  const [taxCode, setTaxCode] = useState('');
  const [handler, setHandler] = useState(null);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState(null);
  const [dialog, setDialog] = useState(null);

  const verify = async (event) => {
    event.preventDefault();
    setBusy(true);
    setStatus({ type: 'info', message: 'Verifica del socio in corso…' });
    try {
      const result = await api.verifyCertificate(taxCode);
      setHandler(result.handler);
      setStatus({ type: 'success', message: 'Socio trovato. Puoi caricare il certificato.' });
    } catch (error) {
      setHandler(null);
      setStatus({ type: 'error', message: error.message });
    } finally {
      setBusy(false);
    }
  };

  const upload = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const formData = new FormData(form);
    const certificate = formData.get('certificate');
    if (certificate instanceof File && certificate.size > 10 * 1024 * 1024) {
      setStatus({ type: 'error', message: 'Il certificato supera la dimensione massima di 10 MB.' });
      return;
    }
    formData.set('taxCode', handler.taxCode);
    setBusy(true);
    setStatus({ type: 'info', message: 'Caricamento e archiviazione del certificato…' });
    try {
      await api.submitCertificate(formData);
      setStatus(null);
      setDialog({ type: 'success', message: 'Certificato medico caricato e comunicato alla segreteria.' });
      form.reset();
      setHandler(null);
      setTaxCode('');
    } catch (error) {
      setStatus({ type: 'error', message: error.message });
      setDialog({ type: 'error', message: error.message });
    } finally {
      setBusy(false);
    }
  };

  const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);

  return (
    <div className="acl-card">
      <header className="acl-hero acl-hero--certificate">
        <p className="acl-eyebrow">Documenti soci</p>
        <h2>Certificato medico</h2>
        <p>Carica un certificato in PDF o come immagine. Dimensione massima: 10 MB.</p>
      </header>
      {!handler ? (
        <form className="acl-form" onSubmit={verify}>
          <section>
            <h3><span>1</span>Verifica socio</h3>
            <label className="acl-field"><span>Codice fiscale *</span><input value={taxCode} onChange={(event) => setTaxCode(event.target.value.toUpperCase())} minLength="16" maxLength="16" pattern="[A-Za-z0-9]{16}" autoComplete="off" required /></label>
          </section>
          <div className="acl-honeypot" aria-hidden="true"><label>Website<input name="website" tabIndex="-1" autoComplete="off" /></label></div>
          <Status state={status} />
          <button className="acl-button acl-button--primary" type="submit" disabled={busy}>{busy ? 'Verifica…' : 'Verifica e continua'}</button>
        </form>
      ) : (
        <form className="acl-form" onSubmit={upload} encType="multipart/form-data">
          <section>
            <h3><span>1</span>Dati verificati</h3>
            <div className="acl-summary"><strong>{handler.firstName} {handler.lastName}</strong><span>{handler.taxCode}</span><span>Email registrata: {handler.maskedEmail}</span></div>
            <label className="acl-field"><span>Conferma la tua email *</span><input name="emailConfirm" type="email" autoComplete="email" required /></label>
            <button className="acl-button acl-button--secondary acl-button--inline" type="button" onClick={() => { setHandler(null); setStatus(null); }}>Cambia codice fiscale</button>
          </section>
          <section>
            <h3><span>2</span>Certificato</h3>
            <div className="acl-grid">
              <label className="acl-field"><span>Data di scadenza *</span><input name="expiryDate" type="date" min={tomorrow} required /></label>
              <label className="acl-field acl-file"><span>File certificato *</span><input name="certificate" type="file" accept="application/pdf,image/jpeg,image/png,image/heic,image/heif,.heic,.heif" capture="environment" required /><small>PDF, JPG, PNG, HEIC o HEIF; massimo 10 MB.</small></label>
            </div>
          </section>
          <Status state={status} />
          <button className="acl-button acl-button--primary" type="submit" disabled={busy}>{busy ? 'Caricamento in corso…' : 'Carica certificato'}</button>
        </form>
      )}
      <ResultDialog state={dialog} onClose={() => setDialog(null)} successTitle="Certificato caricato" errorTitle="Caricamento non completato" />
    </div>
  );
}
