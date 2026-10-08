<?php

define('ABSPATH', __DIR__ . '/');
define('ACL_FORMS_DIR', dirname(__DIR__) . '/');

final class WP_Error
{
    public function __construct(private string $code, private string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function wp_tempnam(string $filename = ''): string|false
{
    return tempnam(sys_get_temp_dir(), 'acl-');
}

function wp_date(string $format, ?int $timestamp = null): string
{
    return date($format, $timestamp ?? time());
}

require ACL_FORMS_DIR . 'vendor/autoload.php';
require ACL_FORMS_DIR . 'includes/class-acl-pdf.php';

$signature = '';
if (!empty($argv[1]) && is_readable($argv[1])) {
    $signature = 'data:image/png;base64,' . base64_encode(file_get_contents($argv[1]));
}

$registration = [
    'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.test', 'telefono' => '3331234567',
    'natoA' => 'Trieste', 'natoIl' => '1985-03-14', 'residenza' => 'Via di Prova 10', 'comune' => 'Trieste', 'provincia' => 'TS', 'cap' => '34100', 'codiceFiscale' => 'RSSMRA85C14L424X',
    'nomeCane1' => 'Lampo', 'razzaCane1' => 'Border Collie', 'sessoCane1' => 'M', 'dataNascitaCane1' => '2021-04-02', 'microchipCane1' => '380260000000001', 'proprietarioCane1' => '', 'conduttoreCane1' => '',
    'aggiungiSecondoCane' => true, 'nomeCane2' => 'Nuvola', 'razzaCane2' => 'Meticcio', 'sessoCane2' => 'F', 'dataNascitaCane2' => '2020-06-12', 'microchipCane2' => '380260000000002', 'proprietarioCane2' => '', 'conduttoreCane2' => '',
    'consensoPrivacy' => true, 'consensoSocial' => true,
];

$registration_pdf = ACL_Forms_Pdf::registration($registration, $signature, '26/09/2026 12:30:00');
if (is_wp_error($registration_pdf)) {
    throw new RuntimeException($registration_pdf->get_error_message());
}
$receipt_pdf = ACL_Forms_Pdf::receipt([
    'numeroRicevuta' => 42,
    'dataRicevuta' => '2026-09-26',
    'ricevutoDa' => 'Mario Rossi',
    'ricevutaPer' => '10 lezioni di agility/hoopers',
    'denaroRicevuto' => 150,
]);
if (is_wp_error($receipt_pdf)) {
    throw new RuntimeException($receipt_pdf->get_error_message());
}
$renewal_pdf = ACL_Forms_Pdf::renewal([
    'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.test',
    'codiceFiscale' => 'RSSMRA85C14L424X', 'consensoPrivacy' => true, 'consensoSocial' => true,
    'consensoRegolamento' => true, 'consensoNewsletter' => false,
], $signature, '05/10/2026 12:30:00');
if (is_wp_error($renewal_pdf)) {
    throw new RuntimeException($renewal_pdf->get_error_message());
}

$output = $argv[2] ?? dirname(__DIR__, 2) . '/tmp/pdfs';
if (!is_dir($output)) {
    mkdir($output, 0770, true);
}
file_put_contents($output . '/qa-php-iscrizione.pdf', $registration_pdf);
file_put_contents($output . '/qa-php-ricevuta.pdf', $receipt_pdf);
file_put_contents($output . '/qa-php-rinnovo.pdf', $renewal_pdf);
echo "PHP PDF generation: OK\n";
