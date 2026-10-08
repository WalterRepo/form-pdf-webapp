# ACL Moduli React per WordPress

Plugin WordPress con due applicazioni React e generazione PDF server-side:

- `[acl_registration_form]`: iscrizione pubblica, firma con mouse/touch, PDF da `templates/iscrizione_2.pdf`, archivio privato, email, Google Sheets e Firestore `handlers`, `dogs` e `pairs`.
- `[acl_receipt_form]`: ricevute riservate agli utenti WordPress con capacità `edit_posts`, PDF da `templates/ricevuta.pdf`, email a cliente/segreteria e aggiornamento dei fogli Ricevute, Cassa ed educatore.

## Requisiti

- WordPress 6.2 o successivo
- PHP 8.0 o successivo, con OpenSSL, Zlib e GD
- account Resend e dominio mittente verificato (oppure invio WordPress configurato tramite SMTP come fallback)
- chiamate HTTPS in uscita abilitate
- account di servizio Google con accesso ai fogli e a Firestore

## Build e installazione

```bash
cd acl-forms
npm install
npm test
npm run build
composer install --no-dev --optimize-autoloader
```

La cartella installabile deve includere `acl-forms.php`, `config`, `includes`, `build`, `templates` e `vendor`. Copiarla in `wp-content/plugins/acl-forms`, attivare **ACL Moduli React**, quindi inserire gli shortcode nelle pagine desiderate. Lo ZIP di distribuzione fornito contiene già questi elementi e non richiede Node.js o Composer sul server.

I template inclusi nel plugin sono copie PDF 1.4 normalizzate dei file originali presenti nella cartella `templates` del repository; il contenuto grafico non viene modificato. La normalizzazione consente a FPDI di importarli senza il parser PDF commerciale.

## Configurazione sicura

Inserire in `wp-config.php`, prima della riga finale di stop:

```php
define('ACL_FORMS_FIREBASE_SERVICE_ACCOUNT_PATH', '/percorso-privato/firebase-service-account.json');
define('ACL_FORMS_SHEETS_SERVICE_ACCOUNT_PATH', '/percorso-privato/google-sheets-service-account.json');
define('ACL_FORMS_REGISTRATION_SHEET_ID', 'ID_FOGLIO_ISCRIZIONI');
define('ACL_FORMS_RECEIPTS_SHEET_ID', 'ID_FOGLIO_RICEVUTE');
define('ACL_FORMS_REGISTRATION_ADMIN_EMAIL', 'segreteria@example.org');
define('ACL_FORMS_RECEIPTS_ADMIN_EMAIL', 'segreteria@example.org');
define('ACL_FORMS_RESEND_API_KEY', 're_xxxxxxxxx');
define('ACL_FORMS_FROM_EMAIL', 'noreply@example.org');
define('ACL_FORMS_FROM_NAME', 'Agility Club La Bora');
define('ACL_FORMS_PRIVATE_DIR', '/percorso-fuori-dalla-document-root/acl-private');
```

Per inviare le notifiche amministrative a più indirizzi, separarli con una virgola nella relativa costante, per esempio `define('ACL_FORMS_REGISTRATION_ADMIN_EMAIL', 'segreteria@example.org,presidente@example.org');`. Le costanti `ACL_FORMS_*_ADMIN_EMAIL` indicano i destinatari, non il mittente. La conferma dell'iscrizione viene inviata anche all'indirizzo compilato nel campo email del registrante; la ricevuta viene inviata anche all'indirizzo compilato nel campo email del pagante.

Quando `ACL_FORMS_RESEND_API_KEY` è valorizzata, il plugin invia direttamente tramite l'API HTTPS di Resend, inclusi gli allegati PDF e JSON. `ACL_FORMS_FROM_EMAIL` deve appartenere a un dominio verificato in Resend. Se la chiave Resend non è configurata, il plugin continua a usare `wp_mail()` e quindi l'eventuale plugin SMTP di WordPress. In alternativa alle costanti, gli hosting che supportano variabili d'ambiente possono fornire `RESEND_API_KEY` e `EMAIL_FROM`; le costanti nel `wp-config.php` hanno precedenza. Gli errori restituiti da Resend vengono scritti nel log PHP/WordPress con il prefisso `[ACL Forms]`.

Al termine di un'iscrizione, il popup mostra un pannello espandibile **Dettagli tecnici e log** con l'esito di Google Sheets, Firebase, email, il provider usato e gli eventuali ID restituiti da Resend. Gli stessi dati vengono salvati nel registro JSON privato della pratica. Il pannello non espone mai la chiave API.

Gli asset del frontend includono la versione del plugin nel nome del file. Questo evita che cache del browser, proxy dell'hosting o plugin WordPress continuino a servire JavaScript di una versione precedente dopo un aggiornamento.

I PDF allegati alle email usano nomi leggibili: le iscrizioni seguono il formato `iscrizione-nome-cognome-codice.pdf`, mentre le ricevute usano `ricevuta-numero-destinatario.pdf`. I file archiviati sul server mantengono il nome tecnico originale, così hash e riferimenti delle pratiche non cambiano.

I contenuti HTML delle email di iscrizione si trovano in `includes/class-acl-mailer.php`: `registration_user_body()` genera il messaggio per l'iscritto, `registration_admin_body()` quello per la segreteria e `layout()` contiene colori e stile comuni. Oggetto e corpo possono essere personalizzati senza modificare il plugin tramite i filtri WordPress `acl_forms_registration_user_subject`, `acl_forms_registration_admin_subject`, `acl_forms_registration_user_body` e `acl_forms_registration_admin_body`.

Se il provider consegna email e chiave privata invece di un file JSON, per Google Sheets si possono usare in alternativa:

```php
define('ACL_FORMS_SHEETS_SERVICE_ACCOUNT_EMAIL', 'account-servizio@progetto.iam.gserviceaccount.com');
define('ACL_FORMS_SHEETS_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n");
```

Non collocare mai i JSON degli account di servizio nella cartella del plugin, nella libreria Media o in Git. Per sviluppo locale il plugin riconosce come credenziale Firebase il file già ignorato `../config/serviceAccountKey.json` e per Sheets le variabili `GOOGLE_SERVICE_ACCOUNT_EMAIL` / `GOOGLE_PRIVATE_KEY`; queste scorciatoie non sono destinate alla produzione.

Condividere entrambi i Google Sheet con l’indirizzo email dell’account di servizio assegnando il ruolo Editor. Nel progetto Google devono essere abilitate Google Sheets API e Cloud Firestore API.

## Istruttore dei nuovi binomi

Il binomio handler/cane viene creato nella collection Firestore `pairs`. Il suo `instructorId` predefinito si trova in `config/instructor.php` ed è inizialmente vuoto. Nessun identificativo ricavato dagli esempi viene inserito nel plugin.

Per cambiarlo, in Firebase Console aprire **Firestore Database → handlers → documento dell’istruttore**, copiare **l’ID del documento** mostrato in alto e sostituire la stringa restituita da `config/instructor.php`. Non usare il campo `firebaseId` interno al documento. In alternativa, per mantenere il valore anche dopo gli aggiornamenti del plugin, aggiungere in `wp-config.php`:

```php
define('ACL_FORMS_INSTRUCTOR_ID', 'ID_DEL_DOCUMENTO_HANDLER_ISTRUTTORE');
```

Il valore in `wp-config.php` ha precedenza sul file. I nuovi binomi ricevono l'ID configurato; finché il valore resta vuoto, `instructorId` viene salvato come stringa vuota. Se una nuova iscrizione ritrova un binomio esistente senza istruttore, lo completa; un istruttore già assegnato non viene cambiato.

## Archivio

Il plugin prova prima a creare `acl-private` sopra la directory WordPress. Se l’hosting non lo consente, usa `wp-content/acl-private` e scrive protezioni Apache/IIS. In produzione è raccomandato impostare `ACL_FORMS_PRIVATE_DIR` su una directory realmente esterna alla document root e includerla nei backup.

Ogni pratica contiene:

- PDF statico generato dal backend WordPress a partire dal template originale;
- JSON di audit con hash SHA-256, timestamp e risultato delle integrazioni;
- per l’iscrizione, hash separato della firma quando presente.

## Sicurezza e comportamento

- Il form iscrizione è pubblico, protetto da nonce, controllo origine, honeypot e limite per IP.
- Il form ricevute e i relativi endpoint richiedono un utente WordPress con capacità `edit_posts`.
- Il plugin cerca `handlers` per `taxCode` e aggiorna solo i dati anagrafici/consensi; crediti, tessera, scadenze e ruoli esistenti non vengono sovrascritti.
- Quando il modulo contiene un cane con nome, il plugin crea o aggiorna il documento `dogs` e crea il binomio in `pairs` con `handlerId`, `dogId` e `instructorId`. Un secondo cane viene scritto solo se selezionato e compilato. Il matching sui cani già associati usa il microchip, oppure nome, razza e data di nascita disponibili; i campi gestionali esistenti vengono preservati.
- La firma su canvas è una firma elettronica con traccia tecnica; il plugin non la presenta come firma digitale qualificata.
- Il numero ricevuta viene ricontrollato sul server; in caso di conflitto il modulo chiede di ricaricare invece di creare duplicati.

## Verifica prima della pubblicazione

1. Configurare un ambiente WordPress di staging sul dominio autorizzato Firebase.
2. Eseguire un’iscrizione di prova con uno o due cani e controllare PDF, due email, riga `Iscrizioni`, documento `handlers`, documenti `dogs` e relativi collegamenti `pairs`.
3. Emettere una ricevuta di prova e controllare PDF, due email, `Ricevute`, `Impostazioni`, `Cassa` ed eventuale foglio educatore.
4. Verificare che i file PDF non siano raggiungibili direttamente via URL.
