import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../includes/class-acl-google.php', import.meta.url), 'utf8');

test('i nuovi handler ricevono le preferenze predefinite richieste', () => {
  const newHandlerBlock = source.match(/if \(!\$existing\) \{([\s\S]*?)\n\s*\}/)?.[1] || '';
  assert.match(newHandlerBlock, /'newsletter'\s*=>\s*self::firestore_value\(true, 'boolean'\)/);
  assert.match(newHandlerBlock, /'appointmentSmsRemindersEnabled'\s*=>\s*self::firestore_value\(true, 'boolean'\)/);
});

test('il consenso social continua a provenire dal modulo', () => {
  assert.match(source, /'social'\s*=>\s*self::firestore_value\(\$data\['consensoSocial'\], 'boolean'\)/);
});
