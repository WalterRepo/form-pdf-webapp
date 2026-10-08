# Guida: modificare testi, email e rilasciare ACL Forms

Questa guida spiega dove intervenire per modificare i testi visibili nei moduli, i messaggi email e come creare uno ZIP aggiornato del plugin WordPress.

## Prima di iniziare

La cartella del plugin è `acl-forms/`. Non inserire mai chiavi Resend, file JSON degli account di servizio o contenuti della cartella `private/` nel repository, nello ZIP o nei file JavaScript.

Per lavorare in locale:

```powershell
cd acl-forms
npm install
composer install --no-dev --optimize-autoloader
```

## Dove modificare i testi dei form

I testi dei moduli sono componenti React. Dopo ogni modifica a questi file occorre eseguire `npm run build`.

| Modulo | Shortcode WordPress | File principale |
| --- | --- | --- |
| Iscrizione | `[acl_registration_form]` | `src/registration/RegistrationForm.jsx` |
| Ricevuta | `[acl_receipt_form]` | `src/receipts/ReceiptForm.jsx` |
| Rinnovo tessera | `[acl_renewal_form]` | `src/renewals/RenewalForm.jsx` |
| Certificato medico | `[acl_certificate_form]` | `src/certificates/CertificateForm.jsx` |

Nei file del form si possono modificare direttamente:

- titoli, sottotitoli, descrizioni e testi dei pulsanti;
- etichette dei campi e messaggi di aiuto;
- link a privacy, regolamento e safeguarding;
- testi di conferma visibili dopo l'invio;
- opzioni e causali della ricevuta.

### Attenzione alle causali delle ricevute

Le causali e gli importi fissi sono definiti in due punti e devono restare coerenti:

```text
src/receipts/ReceiptForm.jsx
includes/class-acl-receipts-controller.php
```

Se, ad esempio, si aggiunge o si modifica una causale nel form, aggiornare anche la costante `FIXED_AMOUNTS` nel controller PHP. Il server considera sempre il proprio importo come valore definitivo.

### Validazioni e messaggi di errore

Le validazioni lato server e i relativi messaggi si trovano nei controller PHP:

```text
includes/class-acl-registration-controller.php
includes/class-acl-receipts-controller.php
includes/class-acl-renewals-controller.php
includes/class-acl-certificates-controller.php
```

Modificare i testi è sicuro; modificare o rimuovere una validazione richiede invece di verificare sempre il flusso completo sul sito di staging.

## Dove modificare le email

Tutte le email sono in:

```text
includes/class-acl-mailer.php
```

| Funzione | Destinatario e contenuto |
| --- | --- |
| `registration_user_body()` | Conferma iscrizione al socio |
| `registration_admin_body()` | Riepilogo iscrizione alla segreteria |
| `receipt_user_body()` | Ricevuta al pagante |
| `receipt_admin_body()` | Ricevuta alla segreteria |
| `renewal()` | Email socio e segreteria per rinnovo tessera |
| `certificate()` | Email socio e segreteria per certificato medico |
| `layout()` | Struttura HTML e stile comuni |

### Modificare il testo delle email di rinnovo tessera

Nella funzione `renewal()` cercare i blocchi `$user_content` e `$admin_content`.

- `$user_content` contiene il messaggio per il socio, i prossimi passi, la quota associativa, l'IBAN e i contatti;
- `$admin_content` contiene dati del socio, consensi, firma elettronica, pagamento e dettagli di archivio.

La quota viene riportata in entrambi i messaggi. Cercare `Quota associativa annuale` e modificare entrambi i valori nello stesso intervento.

### Modificare il testo delle email di certificato medico

Nella funzione `certificate()`:

- `user_body` è la conferma inviata al socio;
- `admin_body` è la notifica alla segreteria con file allegato;
- `$details` contiene scadenza, nome file, hash e percorso relativo nell'archivio privato.

Il certificato non viene allegato al socio; viene allegato solo alla segreteria. Questa scelta evita di rimandare per email un documento sanitario sensibile.

### Modificare lo stile comune

La funzione privata `layout()` contiene CSS e struttura HTML condivisi. I rinnovi usano il tema verde passando `#28a745`; iscrizioni e ricevute usano il tema standard blu. Evitare modifiche globali al layout quando serve cambiare soltanto una specifica email.

## Configurazione email e destinatari

I destinatari amministrativi si configurano in `wp-config.php`, mai nel codice del plugin:

```php
define('ACL_FORMS_REGISTRATION_ADMIN_EMAIL', 'segreteria@example.org');
define('ACL_FORMS_RECEIPTS_ADMIN_EMAIL', 'segreteria@example.org');
define('ACL_FORMS_RENEWAL_ADMIN_EMAIL', 'segreteria@example.org');
define('ACL_FORMS_CERTIFICATES_ADMIN_EMAIL', 'segreteria@example.org');
```

Per più destinatari usare una virgola:

```php
define('ACL_FORMS_RENEWAL_ADMIN_EMAIL', 'segreteria@example.org,presidente@example.org');
```

Il mittente e Resend sono configurati separatamente:

```php
define('ACL_FORMS_RESEND_API_KEY', 'CHIAVE_RESEND');
define('ACL_FORMS_FROM_EMAIL', 'info@example.org');
define('ACL_FORMS_FROM_NAME', 'Agility Club La Bora');
```

La chiave Resend deve restare privata. Se viene inviata via chat, email o inserita per errore in Git, revocarla dal pannello Resend e crearne una nuova.

## Archivio privato

La directory è impostata con:

```php
define('ACL_FORMS_PRIVATE_DIR', __DIR__ . '/private');
```

Le nuove pratiche vengono archiviate in questo formato:

```text
area/anno/mese/nome-cognome-codice-fiscale/
```

Esempio:

```text
renewals/2026/10/mario-rossi-RSSMRA80A01H501U/
```

Più ricevute, rinnovi o certificati dello stesso nominativo nello stesso mese finiscono nella stessa cartella, mantenendo file con nomi univoci.

## Procedura di rilascio

### 1. Aggiornare la versione

Per una modifica al plugin aggiornare lo stesso numero in entrambi i file:

```text
acl-forms.php
vite.config.js
```

Esempio, da `0.4.2` a `0.4.3`:

```php
// acl-forms.php
define('ACL_FORMS_VERSION', '0.4.3');
```

```js
// vite.config.js
entryFileNames: 'assets/app-0.4.3.js',
assetFileNames: 'assets/app-0.4.3.[ext]'
```

Aggiornare anche il test `tests/asset-version-contract.test.mjs` sostituendo il numero di versione.

### 2. Eseguire build e test

Da `acl-forms/`:

```powershell
npm run build
npm test
```

Se PHP è disponibile, eseguire anche:

```powershell
php -l includes/class-acl-mailer.php
```

Controllare sempre che la build produca entrambi gli asset con la nuova versione:

```text
build/assets/app-X.Y.Z.js
build/assets/app-X.Y.Z.css
```

### 3. Creare lo ZIP installabile

Dalla radice del repository, sostituendo `X.Y.Z` con la versione:

```powershell
tar -a -cf dist\acl-forms-X.Y.Z.zip `
  acl-forms\acl-forms.php `
  acl-forms\composer.json `
  acl-forms\composer.lock `
  acl-forms\README.md `
  acl-forms\GUIDA_MODIFICA_TESTI_E_RILASCIO.md `
  acl-forms\build `
  acl-forms\config `
  acl-forms\includes `
  acl-forms\templates `
  acl-forms\vendor
```

Lo ZIP deve contenere una cartella iniziale `acl-forms/` e non deve contenere `node_modules`, `tests`, `.env`, `tmp` o la directory privata del server.

### 4. Installare sul sito WordPress

1. Fare un backup di file e database.
2. In WordPress aprire **Plugin → Aggiungi nuovo → Carica plugin**.
3. Selezionare lo ZIP appena creato e confermare la sostituzione del plugin esistente.
4. Attivare il plugin se necessario.
5. Svuotare W3 Total Cache e l'eventuale cache CDN/browser.
6. Provare ogni form modificato con dati di test.

## Controllo finale prima della pubblicazione

- Il form mostra testi, link e importi corretti.
- Il PDF generato contiene i dati attesi.
- Il file è presente nella directory privata e non è raggiungibile tramite URL pubblico.
- Google Sheets e Firestore ricevono gli aggiornamenti previsti.
- Il socio riceve l'email corretta.
- La segreteria riceve l'email corretta con gli allegati previsti.
- La cache è stata svuotata dopo l'aggiornamento.
