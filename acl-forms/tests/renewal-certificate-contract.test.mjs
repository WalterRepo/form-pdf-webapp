import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const plugin = await readFile(new URL('../acl-forms.php', import.meta.url), 'utf8');
const api = await readFile(new URL('../src/api.js', import.meta.url), 'utf8');
const renewalForm = await readFile(new URL('../src/renewals/RenewalForm.jsx', import.meta.url), 'utf8');
const certificateForm = await readFile(new URL('../src/certificates/CertificateForm.jsx', import.meta.url), 'utf8');
const renewalController = await readFile(new URL('../includes/class-acl-renewals-controller.php', import.meta.url), 'utf8');
const certificateController = await readFile(new URL('../includes/class-acl-certificates-controller.php', import.meta.url), 'utf8');
const google = await readFile(new URL('../includes/class-acl-google.php', import.meta.url), 'utf8');
const storage = await readFile(new URL('../includes/class-acl-storage.php', import.meta.url), 'utf8');

test('il plugin espone shortcode e route per rinnovi e certificati', () => {
  assert.match(plugin, /acl_renewal_form/);
  assert.match(plugin, /acl_certificate_form/);
  assert.match(renewalController, /\/renewals\/verify/);
  assert.match(renewalController, /\/renewals'/);
  assert.match(certificateController, /\/certificates\/verify/);
  assert.match(certificateController, /\/certificates'/);
});

test('i form pubblici usano verifica CF ed email di conferma', () => {
  assert.match(renewalForm, /name="emailConfirm"/);
  assert.match(renewalForm, /SignaturePad/);
  assert.match(certificateForm, /name="emailConfirm"/);
  assert.match(certificateForm, /name="certificate"/);
  assert.match(api, /options\.body instanceof FormData/);
});

test('il backend consente più rinnovi tessera nello stesso anno e usa il foglio Rinnovi', () => {
  assert.doesNotMatch(renewalController, /current_year_renewal|acl_already_renewed/);
  assert.doesNotMatch(google, /function current_year_renewal/);
  assert.match(google, /append_renewal/);
  assert.match(google, /'Rinnovi!A:S'/);
});

test('i certificati sono validati per contenuto e sincronizzati', () => {
  assert.match(certificateController, /str_starts_with\(\$bytes, '%PDF-'\)/);
  assert.match(certificateController, /10 \* 1024 \* 1024/);
  assert.doesNotMatch(google, /upload_certificate/);
  assert.match(certificateController, /ACL_Forms_Storage::relative_path/);
  assert.match(certificateController, /'wordpress-private'/);
  assert.match(google, /medicalCertificateExpiry/);
  assert.match(google, /'Soci!AP'/);
  assert.doesNotMatch(certificateController, /already|già caricato|non ancora scaduto/i);
});

test('l archivio usa anno, mese e cartella nominativo', () => {
  assert.match(storage, /wp_date\('Y'\)/);
  assert.match(storage, /wp_date\('m'\)/);
  assert.match(storage, /person_directory\(\$person\)/);
  assert.match(storage, /codiceFiscale/);
});
