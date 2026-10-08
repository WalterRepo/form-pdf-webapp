import React, { useCallback, useRef, useState } from 'react';
import { api } from '../api.js';
import { SignaturePad } from '../components/SignaturePad.jsx';
import { Status } from '../components/Status.jsx';
import { ResultDialog } from '../components/ResultDialog.jsx';

const provinces = [
  'AG','AL','AN','AO','AR','AP','AT','AV','BA','BT','BL','BN','BG','BI','BO','BZ','BS','BR','CA','CL','CB','CE','CT','CZ','CH','CO','CS','CR','KR','CN','EN','FM','FE','FI','FG','FC','FR','GE','GO','GR','IM','IS','AQ','SP','LT','LE','LC','LI','LO','LU','MC','MN','MS','MT','ME','MI','MO','MB','NA','NO','NU','OR','PD','PA','PR','PV','PG','PU','PE','PC','PI','PT','PN','PZ','PO','RG','RA','RC','RE','RI','RN','RM','RO','SA','SS','SV','SI','SR','SO','SU','TA','TE','TR','TO','TP','TN','TV','TS','UD','VA','VE','VB','VC','VR','VV','VI','VT','ES'
];

const dogFields = (suffix) => [
  ['nomeCane', 'Nome del cane', 'text'],
  ['razzaCane', 'Razza', 'text'],
  ['sessoCane', 'Sesso', 'select'],
  ['altezzaCane', 'Altezza (cm)', 'number'],
  ['dataNascitaCane', 'Data di nascita', 'date'],
  ['microchipCane', 'Microchip', 'text']
].map(([name, label, type]) => ({ name: `${name}${suffix}`, label, type }));

function Field({ name, label, type = 'text', required = false, autoComplete, children, ...props }) {
  return (
    <label className="acl-field">
      <span>{label}{required && <b aria-hidden="true"> *</b>}</span>
      {children || <input name={name} type={type} required={required} autoComplete={autoComplete} {...props} />}
    </label>
  );
}

function DogSection({ number }) {
  return (
    <div className="acl-grid">
      {dogFields(number).map((field) => field.type === 'select' ? (
        <Field key={field.name} {...field}>
          <select name={field.name} defaultValue="">
            <option value="">Seleziona</option>
            <option value="M">Maschio</option>
            <option value="F">Femmina</option>
          </select>
        </Field>
      ) : <Field
        key={field.name}
        {...field}
        min={field.name.startsWith('altezza') ? 1 : undefined}
        max={field.name.startsWith('altezza') ? 200 : undefined}
        maxLength={field.name.startsWith('microchip') ? 15 : undefined}
      />)}
    </div>
  );
}

function formValues(form) {
  const data = Object.fromEntries(new FormData(form).entries());
  for (const name of ['aggiungiSecondoCane','consensoPrivacy','consensoRegolamento','consensoSocial','consensoNewsletter']) {
    data[name] = form.elements[name]?.checked || false;
  }
  data.codiceFiscale = String(data.codiceFiscale || '').trim().toUpperCase();
  data.email = String(data.email || '').trim().toLowerCase();
  return data;
}

function technicalDetails(diagnostics) {
  if (!diagnostics) return [];
  const outcome = (value) => ({ value: value ? 'OK' : 'Errore', status: value ? 'ok' : 'error' });
  const transport = diagnostics.emailTransport === 'resend' ? 'Resend' : 'WordPress (wp_mail)';
  const details = [
    { label: 'Google Sheets', ...outcome(diagnostics.googleSheets) },
    { label: 'Firebase iscritto', ...outcome(diagnostics.firestoreHandler) },
    { label: 'Firebase cane/binomio', ...outcome(diagnostics.firestoreDogs) },
    { label: 'Cani salvati', value: String(diagnostics.dogsSaved ?? 0) },
    { label: 'Provider email', value: transport },
    { label: 'Email al registrante', ...outcome(diagnostics.emailRegistrant) },
    { label: 'Email alla segreteria', ...outcome(diagnostics.emailOffice) },
    { label: 'Firma elettronica', value: diagnostics.signed ? 'Presente' : 'Non presente' },
  ];
  if (diagnostics.emailRegistrantMessageId) {
    details.push({ label: 'ID Resend registrante', value: diagnostics.emailRegistrantMessageId });
  }
  if (diagnostics.emailOfficeMessageId) {
    details.push({ label: 'ID Resend segreteria', value: diagnostics.emailOfficeMessageId });
  }
  details.push({ label: 'ID pratica', value: diagnostics.documentId });
  return details;
}

export function RegistrationForm() {
  const signatureRef = useRef(null);
  const [secondDog, setSecondDog] = useState(false);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState(null);
  const [resultDialog, setResultDialog] = useState(null);
  const closeResultDialog = useCallback(() => setResultDialog(null), []);

  const submit = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    setBusy(true);
    setResultDialog(null);
    setStatus({ type: 'info', message: 'Preparazione del modulo PDF…' });

    try {
      const data = formValues(form);
      const signatureDataUrl = signatureRef.current?.toDataURL() || '';
      const submissionId = crypto.randomUUID();
      const result = await api.submitRegistration({
        ...data,
        submissionId,
        signatureDataUrl,
        website: ''
      });

      setStatus(null);
      setResultDialog({
        type: 'success',
        message: 'Iscrizione acquisita. Il PDF è stato inviato via email.',
        details: technicalDetails(result.diagnostics)
      });
      form.reset();
      signatureRef.current?.clear();
      setSecondDog(false);
    } catch (error) {
      setStatus(null);
      setResultDialog({ type: 'error', message: error.message, details: technicalDetails(error.diagnostics) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="acl-card">
      <header className="acl-hero">
        <p className="acl-eyebrow">A.S.D. Agility Club La Bora</p>
        <h2>Modulo di iscrizione</h2>
        <p>Compila i dati richiesti. Al termine riceverai via email il modulo PDF completo.</p>
      </header>

      <form className="acl-form" onSubmit={submit} noValidate>
        <input className="acl-honeypot" name="website" tabIndex="-1" autoComplete="off" />

        <section>
          <h3><span>1</span>Dati personali</h3>
          <div className="acl-grid">
            <Field name="nome" label="Nome" required autoComplete="given-name" />
            <Field name="cognome" label="Cognome" required autoComplete="family-name" />
            <Field name="email" label="Email" type="email" required autoComplete="email" />
            <Field name="telefono" label="Telefono" type="tel" required autoComplete="tel" />
            <Field name="natoA" label="Nato/a a" required />
            <Field name="natoIl" label="Nato/a il" type="date" required />
            <Field name="residenza" label="Indirizzo di residenza" required autoComplete="street-address" />
            <Field name="comune" label="Comune" required autoComplete="address-level2" />
            <Field name="provincia" label="Provincia" required>
              <select name="provincia" required defaultValue="">
                <option value="">Seleziona</option>
                {provinces.map((province) => <option key={province}>{province}</option>)}
              </select>
            </Field>
            <Field name="cap" label="CAP" required inputMode="numeric" pattern="[0-9]{5}" maxLength="5" autoComplete="postal-code" />
            <Field name="codiceFiscale" label="Codice fiscale" required minLength="16" maxLength="16" />
          </div>
        </section>

        <section>
          <h3><span>2</span>Dati del cane</h3>
          <DogSection number="1" />
          <label className="acl-check acl-check--standalone">
            <input name="aggiungiSecondoCane" type="checkbox" checked={secondDog} onChange={(e) => setSecondDog(e.target.checked)} />
            <span>Desidero iscrivere un secondo cane</span>
          </label>
          {secondDog && <div className="acl-subsection"><h4>Secondo cane</h4><DogSection number="2" /></div>}
        </section>

        <section>
          <h3><span>3</span>Consensi</h3>
          <div className="acl-checks">
            <label className="acl-check">
              <input name="consensoPrivacy" type="checkbox" required />
              <span>Ho letto l’<a href="https://www.agilityclublabora.com/privacyiscrizione" target="_blank" rel="noreferrer">informativa privacy</a> e acconsento al trattamento necessario all’iscrizione. *</span>
            </label>
            <label className="acl-check">
              <input name="consensoRegolamento" type="checkbox" required />
              <span>Accetto il <a href="https://www.agilityclublabora.com/regolamento/" target="_blank" rel="noreferrer">Regolamento</a> e dichiaro di aver preso visione del <a href="https://www.agilityclublabora.com/safeguarding/" target="_blank" rel="noreferrer">safeguarding</a>. *</span>
            </label>
            <label className="acl-check">
              <input name="consensoSocial" type="checkbox" />
              <span>Autorizzo la pubblicazione di foto e video sui canali del club.</span>
            </label>
            <label className="acl-check">
              <input name="consensoNewsletter" type="checkbox" />
              <span>Desidero ricevere comunicazioni informative e newsletter.</span>
            </label>
          </div>
        </section>

        <section>
          <h3><span>4</span>Firma elettronica</h3>
          <p className="acl-help">Puoi firmare con il dito su touchscreen oppure con il mouse. Se firmi qui, la firma verrà apposta direttamente sul PDF; altrimenti dovrai firmare al centro cinofilo. Ti suggeriamo di farlo qui :-)</p>
          <SignaturePad ref={signatureRef} />
        </section>

        <Status state={status} />
        <button className="acl-button acl-button--primary" type="submit" disabled={busy}>
          {busy ? 'Invio in corso…' : 'Invia iscrizione'}
        </button>
      </form>
      <ResultDialog state={resultDialog} onClose={closeResultDialog} />
    </div>
  );
}
