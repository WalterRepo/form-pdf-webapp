import assert from 'node:assert/strict';
import { access, readFile } from 'node:fs/promises';
import test from 'node:test';

const plugin = await readFile(new URL('../acl-forms.php', import.meta.url), 'utf8');
const vite = await readFile(new URL('../vite.config.js', import.meta.url), 'utf8');

test('il plugin carica asset con nome univoco per la versione', () => {
  assert.match(plugin, /Version: 0\.4\.2/);
  assert.match(plugin, /'app-' \. ACL_FORMS_VERSION \. '\.js'/);
  assert.match(plugin, /'acl-forms-' \. ACL_FORMS_VERSION/);
  assert.match(plugin, /'pluginVersion'\s*=>\s*ACL_FORMS_VERSION/);
  assert.match(vite, /app-0\.4\.2\.js/);
  assert.match(vite, /app-0\.4\.2\.\[ext\]/);
});

test('il build contiene i nuovi asset versionati', async () => {
  await access(new URL('../build/assets/app-0.4.2.js', import.meta.url));
  await access(new URL('../build/assets/app-0.4.2.css', import.meta.url));
});
