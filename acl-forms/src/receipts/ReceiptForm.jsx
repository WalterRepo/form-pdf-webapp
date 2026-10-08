import React, { useEffect, useState } from 'react';
import { api } from '../api.js';
import { Status } from '../components/Status.jsx';

const purposePrices = {
  '10 lezioni di agility/hoopers': 150,
  '10 lezioni di educazione': 250,
  '5 lezioni di educazione': 150,
  'Lezione singola': 30,
  'Quota associativa': 30,
  'Colloquio e valutazione del cane': 25
};

const paymentMethods = [
  ['contanti', 'Contanti'],
  ['bonifico', 'Bonifico'],
  ['pos', 'POS'],
  ['paypal', 'PayPal']
];

const normalizeContact = (value) => value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('it');

export function ReceiptForm() {
  const [init, setInit] = useState({ nextNumber: '', contacts: [] });
  const [customPurpose, setCustomPurpose] = useState(false);
  const [payer, setPayer] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [taxCode, setTaxCode] = useState('');
  const [purpose, setPurpose] = useState('');
  const [amount, setAmount] = useState('');
  const [sendCustomerEmail, setSendCustomerEmail] = useState(true);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState(null);

  useEffect(() => {
    api.getReceiptInit()
      .then(setInit)
      .catch((error) => setStatus({ type: 'error', message: error.message }));
  }, []);

  const changePayer = (value) => {
    setPayer(value);
    const normalized = normalizeContact(value);
    const contact = init.contacts.find(({ nome, cognome }) => normalizeContact(`${nome} ${cognome}`) === normalized);
    setCustomerEmail(contact ? contact.email : '');
    setTaxCode(contact ? contact.codiceFiscale : '');
  };

  const changePurpose = (value) => {
    setPurpose(value);
    setCustomPurpose(value === '__custom');
    setAmount(Object.prototype.hasOwnProperty.call(purposePrices, value) ? String(purposePrices[value]) : '');
  };

  const submit = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const values = Object.fromEntries(new FormData(form).entries());
    if (values.ricevutaPer === '__custom') values.ricevutaPer = values.causalePersonalizzata.trim();
    delete values.causalePersonalizzata;
    setBusy(true);
    setStatus({ type: 'info', message: 'Generazione e invio della ricevuta…' });

    try {
      const result = await api.submitReceipt({ ...values, inviaEmailCliente: sendCustomerEmail, submissionId: crypto.randomUUID() });
      setStatus({
        type: 'success',
        message: result.customerEmailSent
          ? `Ricevuta n. ${result.receiptNumber} emessa e inviata al cliente e alla segreteria.`
          : `Ricevuta n. ${result.receiptNumber} emessa e inviata solo alla segreteria.`
      });
      form.reset();
      setCustomPurpose(false);
      setPayer('');
      setCustomerEmail('');
      setTaxCode('');
      setPurpose('');
      setAmount('');
      setSendCustomerEmail(true);
      setInit((current) => ({ ...current, nextNumber: Number(result.receiptNumber) + 1 }));
    } catch (error) {
      setStatus({ type: 'error', message: error.message });
    } finally {
      setBusy(false);
    }
  };

  const today = new Date().toISOString().slice(0, 10);
  const hasFixedPrice = Object.prototype.hasOwnProperty.call(purposePrices, purpose);

  return (
    <div className="acl-card">
      <header className="acl-hero acl-hero--receipt">
        <p className="acl-eyebrow">Segreteria</p>
        <h2>Emissione ricevuta</h2>
        <p>La ricevuta sarà archiviata e inviata alla segreteria; puoi scegliere se inviarla anche al cliente.</p>
      </header>
      <form className="acl-form" onSubmit={submit}>
        <section>
          <h3><span>1</span>Ricevuta</h3>
          <div className="acl-grid">
            <label className="acl-field"><span>Numero *</span><input name="numeroRicevuta" type="number" min="1" value={init.nextNumber} onChange={(event) => setInit({ ...init, nextNumber: event.target.value })} required /></label>
            <label className="acl-field"><span>Data *</span><input name="dataRicevuta" type="date" defaultValue={today} required /></label>
            <label className="acl-field"><span>Ricevuto da *</span><input name="ricevutoDa" list="acl-contacts" value={payer} onChange={(event) => changePayer(event.target.value)} required /></label>
            <label className="acl-field"><span>Email cliente{sendCustomerEmail ? ' *' : ''}</span><input name="emailPagante" type="email" list="acl-contact-emails" value={customerEmail} onChange={(event) => setCustomerEmail(event.target.value)} required={sendCustomerEmail} /></label>
            <label className="acl-field"><span>Codice fiscale / P. IVA *</span><input name="codiceFiscale" value={taxCode} onChange={(event) => setTaxCode(event.target.value.toUpperCase())} minLength="11" maxLength="16" pattern="[A-Za-z0-9]{11,16}" required /></label>
            <label className="acl-field"><span>Pseudonimo per educatore</span><input name="pseudonimo" /></label>
          </div>
          <datalist id="acl-contacts">{init.contacts.map((contact) => <option key={`${contact.email}-n`} value={`${contact.nome} ${contact.cognome}`} />)}</datalist>
          <datalist id="acl-contact-emails">{init.contacts.map((contact) => <option key={`${contact.email}-e`} value={contact.email} />)}</datalist>
        </section>

        <section>
          <h3><span>2</span>Pagamento</h3>
          <div className="acl-grid">
            <label className="acl-field"><span>Causale *</span>
              <select name="ricevutaPer" required value={purpose} onChange={(event) => changePurpose(event.target.value)}>
                <option value="">Seleziona</option>
                {Object.keys(purposePrices).map((item) => <option key={item}>{item}</option>)}
                <option value="__custom">Altro</option>
              </select>
            </label>
            {customPurpose && <label className="acl-field"><span>Causale personalizzata *</span><input name="causalePersonalizzata" required /></label>}
            <label className="acl-field"><span>Importo (€) *</span><input name="denaroRicevuto" type="number" min="0.01" step="0.01" value={amount} onChange={(event) => setAmount(event.target.value)} readOnly={hasFixedPrice} required /></label>
            <fieldset className="acl-field acl-fieldset">
              <legend>Modalità di pagamento *</legend>
              <div className="acl-radio-buttons">
                {paymentMethods.map(([value, label]) => (
                  <label key={value} className="acl-radio-button">
                    <input name="modalitaPagamento" type="radio" value={value} required />
                    <span>{label}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            <label className="acl-field"><span>Educatore/Tecnico *</span>
              <select name="educatoreTecnico" required defaultValue="">
                <option value="">Seleziona</option><option>Walter</option><option>Luciana</option><option>Erika</option><option>Generico</option>
              </select>
            </label>
          </div>
          <label className="acl-check acl-check--standalone">
            <input name="inviaEmailCliente" type="checkbox" value="1" checked={sendCustomerEmail} onChange={(event) => setSendCustomerEmail(event.target.checked)} />
            <span>Invia al cliente la ricevuta PDF via email</span>
          </label>
        </section>

        <Status state={status} />
        <button className="acl-button acl-button--primary" type="submit" disabled={busy || !init.nextNumber}>{busy ? 'Emissione in corso…' : 'Emetti ricevuta'}</button>
      </form>
    </div>
  );
}
