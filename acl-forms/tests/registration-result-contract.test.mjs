import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const form = await readFile(new URL('../src/registration/RegistrationForm.jsx', import.meta.url), 'utf8');
const dialog = await readFile(new URL('../src/components/ResultDialog.jsx', import.meta.url), 'utf8');
const controller = await readFile(new URL('../includes/class-acl-registration-controller.php', import.meta.url), 'utf8');

test('il messaggio principale non mostra piu l identificativo tecnico', () => {
  assert.match(form, /message: 'Iscrizione acquisita\. Il PDF è stato inviato via email\.'/);
  assert.doesNotMatch(form, /message: `Iscrizione acquisita\. Identificativo/);
});

test('il popup offre un dettaglio espandibile con il registro integrazioni', () => {
  assert.match(dialog, /<details className="acl-dialog__details">/);
  assert.match(dialog, /Dettagli tecnici e log/);
  assert.match(form, /Firebase iscritto/);
  assert.match(form, /Provider email/);
  assert.match(form, /ID pratica/);
});

test('la firma viene proposta sul PDF, senza presentarla come facoltativa', () => {
  assert.match(form, /Se firmi qui, la firma verrà apposta direttamente sul PDF/);
  assert.doesNotMatch(form, /La firma è facoltativa/);
});

test('il backend restituisce diagnostica e conserva gli ID Resend', () => {
  assert.match(controller, /'emailTransport'\s*=>\s*\$mail\['transport'\]/);
  assert.match(controller, /'emailRegistrantMessageId'\s*=>\s*\$mail\['userMessageId'\]/);
  assert.match(controller, /'diagnostics'\s*=>\s*\$diagnostics/);
});
