import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { PDFDocument } from 'pdf-lib';

const expectedRegistrationFields = [
  'nome','cognome','natoA','natoIl','comune','provincia','residenza','cap','codiceFiscale','telefono','email',
  'proprietarioCane1','conduttoreCane1','nomeCane1','razzaCane1','sessoCane1','dataNascitaCane1','microchipCane1',
  'proprietarioCane2','conduttoreCane2','nomeCane2','razzaCane2','sessoCane2','dataNascitaCane2','microchipCane2',
  'consensoPrivacy','consensoSocial','text_28qrjn','text_29sebd'
];

test('il template iscrizione conserva pagine e campi attesi', async () => {
  const bytes = await readFile(new URL('../templates/iscrizione_2.pdf', import.meta.url));
  const pdf = await PDFDocument.load(bytes);
  assert.equal(pdf.getPageCount(), 3);
  assert.deepEqual(pdf.getForm().getFields().map((field) => field.getName()), expectedRegistrationFields);
});

test('il template ricevuta conserva i campi attesi', async () => {
  const bytes = await readFile(new URL('../templates/ricevuta.pdf', import.meta.url));
  const pdf = await PDFDocument.load(bytes);
  assert.equal(pdf.getPageCount(), 1);
  assert.deepEqual(pdf.getForm().getFields().map((field) => field.getName()), [
    'numeroRicevuta','dataRicevuta','ricevutoDa','ricevutaPer','denaroRicevuto'
  ]);
});

test('il template rinnovo conserva tre pagine e i campi sorgente', async () => {
  const bytes = await readFile(new URL('../templates/rinnovo-iscrizione.pdf', import.meta.url));
  const pdf = await PDFDocument.load(bytes);
  assert.equal(pdf.getPageCount(), 3);
  assert.deepEqual(pdf.getForm().getFields().map((field) => field.getName()), [
    'codiceFiscale','email','consensoPrivacy','consensoSocial','nome','cognome',
    'cognome_privacy','nome_privacy','dataCompilazione_privacy','comune'
  ]);
});
