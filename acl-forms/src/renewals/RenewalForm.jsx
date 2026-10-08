import React, { useRef, useState } from 'react';
import { api } from '../api.js';
import { SignaturePad } from '../components/SignaturePad.jsx';
import { Status } from '../components/Status.jsx';
import { ResultDialog } from '../components/ResultDialog.jsx';

export function RenewalForm() {
  const [taxCode, setTaxCode] = useState('');
  const [member, setMember] = useState(null);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState(null);
  const [dialog, setDialog] = useState(null);
  const signatureRef = useRef(null);

  const verify = async (event) => {
    event.preventDefault();
    setBusy(true);
    setStatus({ type: 'info', message: 'Verifica del socio in corso…' });
    try {
      const result = await api.verifyRenewal(taxCode);
      setMember(result.member);
      setStatus({ type: 'success', message: 'Socio trovato. Conferma l’email e completa il rinnovo.' });
    } catch (error) {
      setMember(null);
      setStatus({ type: 'error', message: error.message });
    } finally {
      setBusy(false);
    }
  };

  const submit = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const values = Object.fromEntries(new FormData(form).entries());
    setBusy(true);
    setStatus({ type: 'info', message: 'Generazione e archiviazione del rinnovo…' });
    try {
      const result = await api.submitRenewal({
        ...values,
        codiceFiscale: member.codiceFiscale,
        consensoPrivacy: Boolean(values.consensoPrivacy),
        consensoRegolamento: Boolean(values.consensoRegolamento),
        consensoSocial: Boolean(values.consensoSocial),
        consensoNewsletter: Boolean(values.consensoNewsletter),
        signatureDataUrl: signatureRef.current?.toDataURL() || '',
        submissionId: crypto.randomUUID()
      });
      setStatus(null);
      setDialog({ type: 'success', message: `Rinnovo ${new Date().getFullYear()} completato e archiviato.`, documentId: result.documentId });
      form.reset();
      signatureRef.current?.clear();
      setMember(null);
      setTaxCode('');
    } catch (error) {
      setStatus({ type: 'error', message: error.message });
      setDialog({ type: 'error', message: error.message });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="acl-card">
      <header className="acl-hero acl-hero--renewal">
        <p className="acl-eyebrow">Soci</p>
        <h2>Rinnovo iscrizione</h2>
        <p>Verifica la tua iscrizione con il codice fiscale e rinnova i consensi per l’anno corrente.</p>
      </header>
      {!member ? (
        <form className="acl-form" onSubmit={verify}>
          <section>
            <h3><span>1</span>Verifica socio</h3>
            <label className="acl-field"><span>Codice fiscale *</span><input value={taxCode} onChange={(event) => setTaxCode(event.target.value.toUpperCase())} minLength="16" maxLength="16" pattern="[A-Za-z0-9]{16}" autoComplete="off" required /></label>
          </section>
          <Status state={status} />
          <button className="acl-button acl-button--primary" type="submit" disabled={busy}>{busy ? 'Verifica…' : 'Verifica e continua'}</button>
        </form>
      ) : (
        <form className="acl-form" onSubmit={submit}>
          <section>
            <h3><span>1</span>Dati verificati</h3>
            <div className="acl-summary">
              <strong>{member.nome} {member.cognome}</strong>
              <span>{member.codiceFiscale}</span>
              <span>Email registrata: {member.maskedEmail}</span>
            </div>
            <label className="acl-field"><span>Conferma la tua email *</span><input name="emailConfirm" type="email" autoComplete="email" required /></label>
            <button className="acl-button acl-button--secondary acl-button--inline" type="button" onClick={() => { setMember(null); setStatus(null); }}>Cambia codice fiscale</button>
          </section>
          <section>
            <h3><span>2</span>Consensi</h3>
            <div className="acl-checks">
              <label className="acl-check"><input name="consensoPrivacy" type="checkbox" required /><span>Ho letto l’<a href="https://www.agilityclublabora.com/privacyiscrizione" target="_blank" rel="noreferrer">informativa privacy</a> e acconsento al trattamento necessario al rinnovo. *</span></label>
              <label className="acl-check"><input name="consensoRegolamento" type="checkbox" required /><span>Accetto il <a href="https://www.agilityclublabora.com/regolamento/" target="_blank" rel="noreferrer">Regolamento</a> e dichiaro di aver preso visione del <a href="https://www.agilityclublabora.com/safeguarding/" target="_blank" rel="noreferrer">safeguarding</a>. *</span></label>
              <label className="acl-check"><input name="consensoSocial" type="checkbox" /><span>Autorizzo la pubblicazione di foto e video sui canali del club.</span></label>
              <label className="acl-check"><input name="consensoNewsletter" type="checkbox" /><span>Desidero ricevere comunicazioni informative e newsletter.</span></label>
            </div>
          </section>
          <section>
            <h3><span>3</span>Firma elettronica</h3>
            <p className="acl-help">La firma è facoltativa. Se la apponi qui verrà inserita nel PDF; altrimenti potrai firmare al centro cinofilo.</p>
            <SignaturePad ref={signatureRef} />
          </section>
          <div className="acl-honeypot" aria-hidden="true"><label>Website<input name="website" tabIndex="-1" autoComplete="off" /></label></div>
          <Status state={status} />
          <button className="acl-button acl-button--primary" type="submit" disabled={busy}>{busy ? 'Rinnovo in corso…' : 'Completa rinnovo'}</button>
        </form>
      )}
      <ResultDialog state={dialog} onClose={() => setDialog(null)} successTitle="Rinnovo completato" errorTitle="Rinnovo non completato" />
    </div>
  );
}
