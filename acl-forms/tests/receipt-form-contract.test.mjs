import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const form = await readFile(new URL('../src/receipts/ReceiptForm.jsx', import.meta.url), 'utf8');
const controller = await readFile(new URL('../includes/class-acl-receipts-controller.php', import.meta.url), 'utf8');
const mailer = await readFile(new URL('../includes/class-acl-mailer.php', import.meta.url), 'utf8');

test('la selezione del socio collega nome ed email della stessa riga', () => {
  assert.match(form, /init\.contacts\.find/);
  assert.match(form, /setCustomerEmail\(contact \? contact\.email : ''\)/);
  assert.match(form, /setTaxCode\(contact \? contact\.codiceFiscale : ''\)/);
  assert.match(controller, /'codiceFiscale'\s*=>\s*strtoupper/);
});

test('le causali predefinite impostano gli importi fissi anche sul server', () => {
  for (const [purpose, amount] of [
    ['10 lezioni di agility/hoopers', 150],
    ['10 lezioni di educazione', 250],
    ['5 lezioni di educazione', 150],
    ['Lezione singola', 30],
    ['Quota associativa', 30],
    ['Colloquio e valutazione del cane', 25]
  ]) {
    assert.ok(form.includes(`'${purpose}': ${amount}`));
    assert.ok(controller.includes(`'${purpose}' => ${amount}.0`));
  }
  assert.match(form, /readOnly=\{hasFixedPrice\}/);
});

test('le modalita di pagamento sono pulsanti radio obbligatori', () => {
  assert.match(form, /type="radio" value=\{value\} required/);
  assert.match(form, /acl-radio-buttons/);
});

test('l invio al cliente e opzionale ma quello amministrativo resta sempre attivo', () => {
  assert.match(form, /name="inviaEmailCliente"/);
  assert.match(controller, /\$data\['inviaEmailCliente'\] && !is_email/);
  assert.match(mailer, /self::delivery_result\(true, 'skipped'\)/);
  assert.match(mailer, /self::send\(ACL_Forms_Config::receipts_admin_emails\(\)/);
});

test('le email ricevuta usano il layout HTML condiviso', () => {
  assert.match(mailer, /receipt_user_body[\s\S]*self::layout\('Ricevuta di pagamento'/);
  assert.match(mailer, /receipt_admin_body[\s\S]*self::layout\('Ricevuta emessa'/);
});
