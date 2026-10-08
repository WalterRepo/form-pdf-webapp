import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const config = await readFile(new URL('../includes/class-acl-config.php', import.meta.url), 'utf8');
const mailer = await readFile(new URL('../includes/class-acl-mailer.php', import.meta.url), 'utf8');

test('Resend viene selezionato quando la chiave API e configurata', () => {
  assert.match(config, /resend_api_key\(\).*ACL_FORMS_RESEND_API_KEY.*RESEND_API_KEY/s);
  assert.match(mailer, /ACL_Forms_Config::resend_api_key\(\) !== ''[\s\S]*send_with_resend/);
  assert.match(mailer, /https:\/\/api\.resend\.com\/emails/);
});

test('il payload Resend conserva destinatari dinamici, HTML e allegati', () => {
  assert.match(mailer, /'to'\s*=>\s*\$recipients/);
  assert.match(mailer, /'html'\s*=>\s*\$body/);
  assert.match(mailer, /'content'\s*=>\s*base64_encode\(\$content\)/);
  assert.match(mailer, /'content_type'\s*=>/);
});

test('wp_mail resta il fallback quando Resend non e configurato', () => {
  assert.match(mailer, /return self::send_with_wordpress\(/);
  assert.match(mailer, /\$sent\s*=\s*wp_mail\(/);
  assert.match(mailer, /delivery_result\(\$sent, 'wordpress'\)/);
});

test('il log espone provider e identificativi Resend senza esporre la chiave', () => {
  assert.match(mailer, /'transport'\s*=>\s*\$transport/);
  assert.match(mailer, /'messageId'\s*=>\s*\$message_id/);
  assert.match(mailer, /Invio Resend riuscito/);
});

test('iscrizioni e ricevute inviano sia all utente sia ai destinatari configurati', () => {
  assert.match(mailer, /self::send\(\(string\) \$data\['email'\]/);
  assert.match(mailer, /self::send\(ACL_Forms_Config::registration_admin_emails\(\)/);
  assert.match(mailer, /self::send\(\(string\) \$data\['emailPagante'\]/);
  assert.match(mailer, /self::send\(ACL_Forms_Config::receipts_admin_emails\(\)/);
});

test('gli allegati hanno nomi leggibili senza rinominare i file archiviati', () => {
  assert.match(mailer, /'iscrizione-' \. \$name_part/);
  assert.match(mailer, /'filename' => \$attachment_base \. '\.pdf'/);
  assert.match(mailer, /'registro-' \. \$attachment_base \. '\.json'/);
  assert.match(mailer, /'ricevuta-' \. \$number/);
  assert.match(mailer, /wordpress_attachments\(\$attachments\)/);
});

test('le email rinnovo riprendono lo stile e i contenuti operativi del vecchio sistema', () => {
  assert.match(mailer, /'✓ Rinnovo iscrizione'/);
  assert.match(mailer, /Quota associativa annuale: € 30,00/);
  assert.doesNotMatch(mailer, /Quota (?:associativa )?annuale: € 15,00/);
  assert.match(mailer, /IT73V0503402200000000003040/);
  assert.match(mailer, /Documento firmato elettronicamente/);
  assert.match(mailer, /ACL_Forms_Config::renewal_admin_emails\(\)/);
  assert.match(mailer, /'#28a745'/);
});

test('le email certificato hanno riepilogo, contatti e dettagli archivio', () => {
  assert.match(mailer, /'✓ Certificato medico ricevuto'/);
  assert.match(mailer, /La segreteria provvederà alla verifica del documento/);
  assert.match(mailer, /Percorso archivio privato/);
  assert.match(mailer, /ACL_Forms_Config::certificates_admin_emails\(\)/);
  assert.match(mailer, /laboratrieste@gmail\.com/);
});
