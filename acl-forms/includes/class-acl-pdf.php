<?php

if (!defined('ABSPATH')) {
    exit;
}

use setasign\Fpdi\Fpdi;

final class ACL_Forms_Pdf
{
    private const PT_TO_MM = 25.4 / 72;
    private const PAGE_HEIGHT_PT = 841.92;

    private static function text(Fpdi $pdf, float $x, float $y, float $width, float $height, string $value, float $font_size = 10): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $encoded = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value) ?: $value;
        $width_mm = $width * self::PT_TO_MM;
        $size = $font_size;
        $pdf->SetFont('Helvetica', '', $size);
        while ($size > 6.5 && $pdf->GetStringWidth($encoded) > $width_mm - 1.5) {
            $size -= 0.5;
            $pdf->SetFont('Helvetica', '', $size);
        }
        $top = (self::PAGE_HEIGHT_PT - $y - $height) * self::PT_TO_MM;
        $pdf->SetXY($x * self::PT_TO_MM, $top);
        $pdf->Cell($width_mm, $height * self::PT_TO_MM, $encoded, 0, 0, 'L');
    }

    private static function import_pages(Fpdi $pdf, string $template, callable $after_import): void
    {
        $count = $pdf->setSourceFile($template);
        for ($page = 1; $page <= $count; $page++) {
            $template_id = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template_id);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template_id);
            $after_import($page, $pdf);
        }
    }

    private static function checkbox(Fpdi $pdf, float $x, float $y, float $width, float $height, bool $checked): void
    {
        if (!$checked) {
            return;
        }
        $left = ($x + 5) * self::PT_TO_MM;
        $top = (self::PAGE_HEIGHT_PT - $y - $height + 5) * self::PT_TO_MM;
        $right = ($x + $width - 5) * self::PT_TO_MM;
        $bottom = (self::PAGE_HEIGHT_PT - $y - 5) * self::PT_TO_MM;
        $pdf->SetLineWidth(0.55);
        $pdf->Line($left, $top, $right, $bottom);
        $pdf->Line($right, $top, $left, $bottom);
    }

    private static function signature(Fpdi $pdf, string $data_url, string $timestamp, int $page): WP_Error|true
    {
        if ($data_url === '') {
            return true;
        }
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data_url, $match)) {
            return new WP_Error('acl_invalid_signature', 'Firma elettronica non valida.');
        }
        $bytes = base64_decode($match[1], true);
        if ($bytes === false || strlen($bytes) < 50 || strlen($bytes) > 2 * 1024 * 1024) {
            return new WP_Error('acl_invalid_signature', 'Firma elettronica non valida.');
        }
        $temporary = wp_tempnam('acl-signature.png');
        if (!$temporary || file_put_contents($temporary, $bytes, LOCK_EX) === false) {
            return new WP_Error('acl_signature_temp_failed', 'Impossibile elaborare la firma.');
        }
        $placements = [
            1 => [255, 440, 250, 24],
            2 => [405, 135, 125, 26],
            3 => [330, 66, 170, 34],
        ];
        try {
            if (!isset($placements[$page])) {
                return true;
            }
            [$x, $y, $width, $height] = $placements[$page];
            $top = (self::PAGE_HEIGHT_PT - $y - $height) * self::PT_TO_MM;
            $pdf->Image($temporary, $x * self::PT_TO_MM, $top, $width * self::PT_TO_MM, $height * self::PT_TO_MM, 'PNG');
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetTextColor(64, 64, 64);
            $pdf->SetXY($x * self::PT_TO_MM, max(0, $top - 3.3));
            $label = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', 'Firma elettronica apposta il ' . $timestamp);
            $pdf->Cell($width * self::PT_TO_MM, 3, $label ?: '', 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        } finally {
            @unlink($temporary);
        }
        return true;
    }

    public static function registration(array $data, string $signature_data_url, string $timestamp): string|WP_Error
    {
        $template = ACL_FORMS_DIR . 'templates/iscrizione_2.pdf';
        if (!is_readable($template)) {
            return new WP_Error('acl_template_missing', 'Template iscrizione non disponibile.');
        }
        try {
            $pdf = new Fpdi('P', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false);
            $full_name = trim($data['nome'] . ' ' . $data['cognome']);
            $date = static function (string $value): string {
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
                    return $value;
                }
                return $parts[3] . '/' . $parts[2] . '/' . $parts[1];
            };
            $fields = [
                ['nome', 156.467, 635.453, 100, 25], ['cognome', 261.133, 636.12, 100, 25], ['natoA', 431.8, 635.453, 100, 25],
                ['natoIl', 65.133, 614.12, 67.334, 19, $date($data['natoIl'])], ['comune', 197.8, 612.787, 105.333, 19], ['provincia', 337.133, 613.453, 71.334, 19],
                ['residenza', 107.8, 592.787, 274, 19], ['cap', 415.8, 592.787, 44, 19], ['codiceFiscale', 143.8, 570.787, 240.667, 19],
                ['telefono', 109.8, 550.12, 95.333, 19], ['email', 251.8, 550.12, 210, 19],
                ['proprietarioCane1', 124.467, 356.12, 106.666, 19, $data['proprietarioCane1'] ?: $full_name],
                ['conduttoreCane1', 303.8, 358.12, 152, 19, $data['conduttoreCane1'] ?: $full_name],
                ['nomeCane1', 125.133, 336.787, 100.667, 19], ['razzaCane1', 279.133, 336.12, 177.334, 19], ['sessoCane1', 85.133, 316.12, 140, 19],
                ['dataNascitaCane1', 287.8, 316.787, 174.667, 19, $date($data['dataNascitaCane1'])], ['microchipCane1', 131.133, 294.787, 326.667, 19],
                ['proprietarioCane2', 125.133, 254.52, 101.334, 19, !empty($data['aggiungiSecondoCane']) ? ($data['proprietarioCane2'] ?: $full_name) : ''],
                ['conduttoreCane2', 301.133, 253.853, 152, 19, !empty($data['aggiungiSecondoCane']) ? ($data['conduttoreCane2'] ?: $full_name) : ''],
                ['nomeCane2', 125.133, 231.853, 99.334, 19], ['razzaCane2', 278.467, 233.853, 180, 19], ['sessoCane2', 83.8, 213.187, 139.333, 19],
                ['dataNascitaCane2', 287.8, 211.853, 172.667, 19, !empty($data['aggiungiSecondoCane']) ? $date($data['dataNascitaCane2']) : ''],
                ['microchipCane2', 131.8, 189.853, 323.333, 19],
            ];
            $signature_error = null;
            self::import_pages($pdf, $template, static function (int $page, Fpdi $current) use ($fields, $data, $signature_data_url, $timestamp, &$signature_error): void {
                if ($page === 1) {
                    foreach ($fields as $field) {
                        [$name, $x, $y, $width, $height] = $field;
                        $value = array_key_exists(5, $field) ? $field[5] : ($data[$name] ?? '');
                        if (str_ends_with($name, '2') && empty($data['aggiungiSecondoCane'])) {
                            $value = '';
                        }
                        self::text($current, $x, $y, $width, $height, (string) $value);
                    }
                }
                if ($page === 2) {
                    self::text($current, 127, 349, 100, 20, $data['nome']);
                    self::text($current, 236, 349, 100, 19, $data['cognome']);
                    self::checkbox($current, 49.8, 312.453, 20, 20, (bool) $data['consensoPrivacy']);
                    self::checkbox($current, 51.133, 279.787, 20, 20, (bool) $data['consensoSocial']);
                }
                $result = self::signature($current, $signature_data_url, $timestamp, $page);
                if (is_wp_error($result)) {
                    $signature_error = $result;
                }
            });
            if (is_wp_error($signature_error)) {
                return $signature_error;
            }
            return $pdf->Output('S');
        } catch (Throwable $error) {
            return new WP_Error('acl_pdf_generation_failed', 'Generazione PDF iscrizione non riuscita: ' . $error->getMessage());
        }
    }

    public static function receipt(array $data): string|WP_Error
    {
        $template = ACL_FORMS_DIR . 'templates/ricevuta.pdf';
        if (!is_readable($template)) {
            return new WP_Error('acl_template_missing', 'Template ricevuta non disponibile.');
        }
        try {
            $pdf = new Fpdi('P', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false);
            $parts = explode('-', $data['dataRicevuta']);
            $date = count($parts) === 3 ? $parts[2] . '/' . $parts[1] . '/' . $parts[0] : $data['dataRicevuta'];
            $amount = '€ ' . number_format((float) $data['denaroRicevuto'], 2, ',', '.');
            self::import_pages($pdf, $template, static function (int $page, Fpdi $current) use ($data, $date, $amount): void {
                if ($page !== 1) {
                    return;
                }
                self::text($current, 373.133, 650.12, 100, 20.333, (string) $data['numeroRicevuta'], 11);
                self::text($current, 347.133, 626.12, 100, 20.333, $date, 11);
                self::text($current, 264.467, 577.453, 270.666, 17, $data['ricevutoDa'], 11);
                self::text($current, 46.467, 494.12, 425.333, 17, $data['ricevutaPer'], 11);
                self::text($current, 433.8, 348.12, 102, 17, $amount, 11);
            });
            return $pdf->Output('S');
        } catch (Throwable $error) {
            return new WP_Error('acl_pdf_generation_failed', 'Generazione PDF ricevuta non riuscita: ' . $error->getMessage());
        }
    }

    public static function renewal(array $data, string $signature_data_url, string $timestamp): string|WP_Error
    {
        $template = ACL_FORMS_DIR . 'templates/rinnovo-iscrizione.pdf';
        if (!is_readable($template)) {
            return new WP_Error('acl_template_missing', 'Template rinnovo non disponibile.');
        }
        try {
            $pdf = new Fpdi('P', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false);
            $today = wp_date('d/m/Y');
            $signature_error = null;
            self::import_pages($pdf, $template, static function (int $page, Fpdi $current) use ($data, $signature_data_url, $timestamp, $today, &$signature_error): void {
                if ($page === 1) {
                    self::text($current, 156.467, 627.598, 100, 25, $data['nome']);
                    self::text($current, 262.442, 627.611, 100, 25, $data['cognome']);
                    self::text($current, 142.491, 589.769, 240.667, 19, $data['codiceFiscale']);
                    self::text($current, 94.709, 548.811, 210, 19, $data['email']);
                }
                if ($page === 2) {
                    self::text($current, 127.336, 342.815, 100, 25, $data['nome']);
                    self::text($current, 247.77, 343.698, 100, 25, $data['cognome']);
                    self::text($current, 51.836, 203.694, 80.46, 19, 'Trieste');
                    self::text($current, 136.219, 200.727, 100.655, 25, $today);
                    self::checkbox($current, 49.8, 312.453, 20, 20, (bool) $data['consensoPrivacy']);
                    self::checkbox($current, 51.133, 279.787, 20, 20, (bool) $data['consensoSocial']);
                }
                if ($page === 3) {
                    self::text($current, 62.861, 71.508, 80.46, 19, 'Trieste');
                    self::text($current, 177.456, 68.508, 123.563, 25, $today);
                }
                $result = self::signature($current, $signature_data_url, $timestamp, $page);
                if (is_wp_error($result)) {
                    $signature_error = $result;
                }
            });
            if (is_wp_error($signature_error)) {
                return $signature_error;
            }
            return $pdf->Output('S');
        } catch (Throwable $error) {
            return new WP_Error('acl_pdf_generation_failed', 'Generazione PDF rinnovo non riuscita: ' . $error->getMessage());
        }
    }
}
