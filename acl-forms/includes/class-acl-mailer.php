<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Forms_Mailer
{
    private const RESEND_API_URL = 'https://api.resend.com/emails';

    private static function send(string|array $to, string $subject, string $body, array $attachments): array
    {
        if (ACL_Forms_Config::resend_api_key() !== '') {
            return self::send_with_resend($to, $subject, $body, $attachments);
        }

        return self::send_with_wordpress($to, $subject, $body, $attachments);
    }

    private static function send_with_wordpress(string|array $to, string $subject, string $body, array $attachments): array
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $from_email = ACL_Forms_Config::mail_from_email();
        if ($from_email !== '' && is_email($from_email)) {
            $headers[] = 'From: ' . ACL_Forms_Config::mail_from_name() . ' <' . $from_email . '>';
        }
        $prepared = self::wordpress_attachments($attachments);
        if ($prepared === false) {
            return self::delivery_result(false, 'wordpress');
        }
        try {
            $sent = wp_mail($to, $subject, $body, $headers, $prepared['paths']);
        } finally {
            self::cleanup_wordpress_attachments($prepared);
        }
        error_log('[ACL Forms] Invio wp_mail ' . ($sent ? 'riuscito' : 'non riuscito'));
        return self::delivery_result($sent, 'wordpress');
    }

    private static function send_with_resend(string|array $to, string $subject, string $body, array $attachments): array
    {
        $from_email = ACL_Forms_Config::mail_from_email();
        if ($from_email === '' || !is_email($from_email)) {
            self::log_resend_error('mittente ACL_FORMS_FROM_EMAIL non configurato o non valido');
            return self::delivery_result(false, 'resend');
        }

        $recipients = is_array($to) ? $to : [$to];
        $recipients = array_values(array_unique(array_filter(array_map(
            static fn(mixed $email): string => sanitize_email(trim((string) $email)),
            $recipients
        ), 'is_email')));
        if ($recipients === []) {
            self::log_resend_error('nessun destinatario valido');
            return self::delivery_result(false, 'resend');
        }

        $encoded_attachments = self::resend_attachments($attachments);
        if ($encoded_attachments === false) {
            return self::delivery_result(false, 'resend');
        }

        $payload = [
            'from' => ACL_Forms_Config::mail_from_name() . ' <' . $from_email . '>',
            'to' => $recipients,
            'subject' => $subject,
            'html' => $body,
        ];
        if ($encoded_attachments !== []) {
            $payload['attachments'] = $encoded_attachments;
        }

        $json = wp_json_encode($payload);
        if ($json === false) {
            self::log_resend_error('codifica JSON del messaggio non riuscita');
            return self::delivery_result(false, 'resend');
        }

        $response = wp_remote_post(self::RESEND_API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . ACL_Forms_Config::resend_api_key(),
                'Content-Type' => 'application/json',
            ],
            'body' => $json,
            'data_format' => 'body',
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            self::log_resend_error($response->get_error_message());
            return self::delivery_result(false, 'resend');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $response_body = json_decode((string) wp_remote_retrieve_body($response), true);
            $message = is_array($response_body) && isset($response_body['message'])
                ? sanitize_text_field((string) $response_body['message'])
                : 'risposta HTTP ' . $status;
            self::log_resend_error($message);
            return self::delivery_result(false, 'resend');
        }

        $response_body = json_decode((string) wp_remote_retrieve_body($response), true);
        $message_id = is_array($response_body) ? sanitize_text_field((string) ($response_body['id'] ?? '')) : '';
        error_log('[ACL Forms] Invio Resend riuscito' . ($message_id !== '' ? ': id=' . $message_id : ''));
        return self::delivery_result(true, 'resend', $message_id);
    }

    private static function delivery_result(bool $success, string $transport, string $message_id = ''): array
    {
        return [
            'success' => $success,
            'transport' => $transport,
            'messageId' => $message_id,
        ];
    }

    private static function resend_attachments(array $attachments): array|false
    {
        $result = [];
        foreach ($attachments as $attachment) {
            ['path' => $path, 'filename' => $filename] = self::attachment_details($attachment);
            if ($path === '' || !is_readable($path)) {
                self::log_resend_error('allegato non leggibile');
                return false;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                self::log_resend_error('lettura allegato non riuscita');
                return false;
            }
            $filetype = wp_check_filetype($filename);
            $result[] = [
                'filename' => $filename,
                'content' => base64_encode($content),
                'content_type' => $filetype['type'] ?: 'application/octet-stream',
            ];
        }
        return $result;
    }

    private static function attachment_details(mixed $attachment): array
    {
        $path = is_array($attachment) ? (string) ($attachment['path'] ?? '') : (string) $attachment;
        $requested_name = is_array($attachment) ? (string) ($attachment['filename'] ?? '') : '';
        $filename = sanitize_file_name($requested_name !== '' ? $requested_name : basename($path));
        return ['path' => $path, 'filename' => $filename !== '' ? $filename : basename($path)];
    }

    private static function wordpress_attachments(array $attachments): array|false
    {
        $paths = [];
        $temporary_directory = '';
        foreach ($attachments as $attachment) {
            ['path' => $path, 'filename' => $filename] = self::attachment_details($attachment);
            if ($path === '' || !is_readable($path)) {
                self::cleanup_wordpress_attachments(['paths' => $paths, 'directory' => $temporary_directory]);
                error_log('[ACL Forms] Allegato email non leggibile');
                return false;
            }
            if ($filename === basename($path)) {
                $paths[] = $path;
                continue;
            }
            if ($temporary_directory === '') {
                $temporary_directory = trailingslashit(get_temp_dir()) . 'acl-forms-mail-' . wp_generate_uuid4();
                if (!wp_mkdir_p($temporary_directory)) {
                    error_log('[ACL Forms] Impossibile creare la cartella temporanea per gli allegati email');
                    return false;
                }
            }
            $temporary_path = trailingslashit($temporary_directory) . $filename;
            if (!copy($path, $temporary_path)) {
                self::cleanup_wordpress_attachments(['paths' => $paths, 'directory' => $temporary_directory]);
                error_log('[ACL Forms] Impossibile preparare un allegato email');
                return false;
            }
            $paths[] = $temporary_path;
        }
        return ['paths' => $paths, 'directory' => $temporary_directory];
    }

    private static function cleanup_wordpress_attachments(array $prepared): void
    {
        $directory = (string) ($prepared['directory'] ?? '');
        if ($directory === '') {
            return;
        }
        foreach ((array) ($prepared['paths'] ?? []) as $path) {
            if (dirname((string) $path) === rtrim($directory, '/\\')) {
                @unlink((string) $path);
            }
        }
        @rmdir($directory);
    }

    private static function filename_part(string $value, string $fallback): string
    {
        $part = sanitize_title($value);
        return $part !== '' ? $part : $fallback;
    }

    private static function log_resend_error(string $message): void
    {
        error_log('[ACL Forms] Invio Resend non riuscito: ' . $message);
    }

    private static function value(mixed $value, string $fallback = ''): string
    {
        $text = trim((string) $value);
        return esc_html($text !== '' ? $text : $fallback);
    }

    private static function date(string $value): string
    {
        if ($value === '') {
            return 'Non specificata';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? self::value($value) : esc_html(wp_date('d/m/Y', $timestamp));
    }

    private static function sex(string $value): string
    {
        return match (strtoupper($value)) {
            'M' => 'Maschio',
            'F' => 'Femmina',
            default => 'Non specificato',
        };
    }

    private static function field(string $label, string $value): string
    {
        return '<tr><td class="label">' . esc_html($label) . '</td><td class="value">' . $value . '</td></tr>';
    }

    private static function dog_section(array $data, int $number, string $title): string
    {
        $suffix = (string) $number;
        if (trim((string) ($data['nomeCane' . $suffix] ?? '')) === '') {
            return '';
        }
        $rows = self::field('Nome', self::value($data['nomeCane' . $suffix]))
            . self::field('Sesso', esc_html(self::sex((string) ($data['sessoCane' . $suffix] ?? ''))))
            . self::field('Razza', self::value($data['razzaCane' . $suffix] ?? '', 'Non specificata'))
            . self::field('Altezza', self::value($data['altezzaCane' . $suffix] ?? '', 'Non specificata'))
            . self::field('Microchip', self::value($data['microchipCane' . $suffix] ?? '', 'Non specificato'))
            . self::field('Data di nascita', self::date((string) ($data['dataNascitaCane' . $suffix] ?? '')))
            . self::field('Proprietario', self::value($data['proprietarioCane' . $suffix] ?? '', 'Non specificato'))
            . self::field('Conduttore', self::value($data['conduttoreCane' . $suffix] ?? '', 'Non specificato'));
        return '<div class="section"><h3>' . esc_html($title) . '</h3><div class="dog"><table role="presentation">' . $rows . '</table></div></div>';
    }

    private static function layout(string $title, string $subtitle, string $content, string $footer, string $accent = '#24599a', string $accent_soft = '#eef5fc'): string
    {
        $accent = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : '#24599a';
        $accent_soft = preg_match('/^#[0-9a-f]{6}$/i', $accent_soft) ? $accent_soft : '#eef5fc';
        return '<!doctype html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html($title) . '</title><style>'
            . 'body{margin:0;padding:0;background:#f3f5f7;color:#30343b;font-family:Arial,sans-serif;line-height:1.55}'
            . '.wrap{max-width:680px;margin:24px auto;background:#fff;border:1px solid #dfe3e8}'
            . '.header{padding:28px 22px;background:' . $accent . ';color:#fff;text-align:center}.header h1{margin:0;font-size:25px}.header p{margin:7px 0 0}'
            . '.content{padding:28px 24px}.welcome{font-size:18px;color:' . $accent . ';font-weight:bold}.box{margin:20px 0;padding:17px;border-left:4px solid ' . $accent . ';background:' . $accent_soft . '}'
            . '.success{border-left-color:#3b8d54;background:#edf8f0}.important{border:1px solid #f0d88a;border-left:4px solid #d7a900;background:#fff8dc}'
            . '.contact{border:1px solid #bfd9f0;background:#edf6fd}.section{margin:0 0 24px;padding:0 0 20px;border-bottom:1px solid #e7e9ec}.section h3,.box h3{margin:0 0 12px;color:' . $accent . ';font-size:17px}'
            . 'table{width:100%;border-collapse:collapse}.label{width:155px;padding:6px 12px 6px 0;font-weight:bold;color:#505762;vertical-align:top}.value{padding:6px 0;vertical-align:top;word-break:break-word}'
            . '.dog{padding:12px 15px;border-left:3px solid #24599a;background:#f7f8fa}.footer{padding:18px;background:#f7f8fa;border-top:1px solid #e3e6e9;text-align:center;color:#69717c;font-size:12px}'
            . 'a{color:' . $accent . '}code{font-size:11px;word-break:break-all}@media(max-width:560px){.wrap{margin:0;border:0}.content{padding:22px 16px}.label,.value{display:block;width:auto;padding:3px 0}}'
            . '</style></head><body><div class="wrap"><div class="header"><h1>' . esc_html($title) . '</h1><p>' . esc_html($subtitle) . '</p></div>'
            . '<div class="content">' . $content . '</div><div class="footer">' . $footer . '</div></div></body></html>';
    }

    private static function registration_user_body(array $data, array $audit): string
    {
        $name = self::value(trim((string) $data['nome'] . ' ' . (string) $data['cognome']));
        $document_id = self::value($audit['documentId'] ?? '');
        $signed = !empty($audit['signatureTimestamp']);
        $signature_time = $signed ? strtotime((string) $audit['signatureTimestamp']) : false;
        $signature = $signed
            ? '<div class="box success"><h3>Documento firmato elettronicamente</h3><p><strong>Data e ora:</strong> ' . ($signature_time === false ? self::value($audit['signatureTimestamp']) : esc_html(wp_date('d/m/Y H:i:s', $signature_time))) . '</p><p><strong>ID documento:</strong> ' . $document_id . '</p><p><strong>Hash documento:</strong> <code>' . esc_html(substr((string) ($audit['documentHash'] ?? ''), 0, 32)) . '…</code></p><p style="margin-bottom:0;font-size:13px">Il PDF allegato contiene la firma elettronica; ID e hash permettono di verificarne l’integrità.</p></div>'
            : '';
        $module_note = $signed
            ? '<p><strong>Modulo di iscrizione:</strong> già firmato elettronicamente; non è necessario stamparlo.</p>'
            : '<p><strong>Modulo di iscrizione:</strong> il PDF allegato potrà essere firmato al campo al primo appuntamento.</p>';
        $content = '<p class="welcome">Gentile ' . $name . ',</p><p>grazie per aver compilato il modulo di iscrizione alla nostra associazione.</p>'
            . '<div class="box"><strong>La richiesta è stata ricevuta correttamente</strong> e sarà elaborata dal nostro staff. In allegato trovi il modulo PDF compilato.</div>'
            . $signature
            . '<div class="box important"><h3>Documenti richiesti per completare l’iscrizione</h3>' . $module_note
            . '<p><strong>Passaporto del cane:</strong> invia una copia con le vaccinazioni aggiornate scegliendo una delle modalità seguenti:</p><ul>'
            . '<li>email a <a href="mailto:laboratrieste@gmail.com">laboratrieste@gmail.com</a>;</li>'
            . '<li>WhatsApp al <a href="https://wa.me/393500693832">+39 350 0693832</a>;</li><li>fotocopia consegnata direttamente al campo.</li></ul>'
            . '<p style="font-size:13px;color:#626a73"><em>L’invio digitale contribuisce a ridurre il consumo di carta.</em></p></div>'
            . '<div class="box contact"><h3>Modalità di pagamento</h3><p>Puoi pagare presso il centro oppure tramite bonifico bancario:</p>'
            . '<p><strong>Intestatario:</strong> A.S.D. Agility Club La Bora<br><strong>IBAN:</strong> IT73V0503402200000000003040<br><strong>BIC/SWIFT:</strong> BAPPIT21703</p>'
            . '<p style="font-size:13px"><em>Nella causale indica nome e cognome dell’iscritto.</em></p></div>'
            . '<div class="box important"><p style="margin:0"><strong>Nota:</strong> questa è un’email automatica. Per informazioni scrivi a <a href="mailto:laboratrieste@gmail.com">laboratrieste@gmail.com</a>.</p></div>'
            . '<p style="margin-top:28px">Cordiali saluti,<br><strong>Lo staff di Agility Club La Bora</strong></p>';
        $footer = '<strong>Agility Club La Bora – A.S.D.</strong><br>Messaggio inviato a ' . self::value($data['email']) . ' in seguito alla compilazione del modulo.<br>Anno ' . esc_html(wp_date('Y')) . ' – Tutti i diritti riservati';
        return self::layout('Agility Club La Bora', 'Associazione Sportiva Dilettantistica', $content, $footer);
    }

    private static function registration_admin_body(array $data, array $audit): string
    {
        $name = self::value(trim((string) $data['nome'] . ' ' . (string) $data['cognome']));
        $summary = '<div class="box"><h3>Riepilogo iscrizione</h3><p><strong>Socio:</strong> ' . $name . '<br><strong>Email:</strong> ' . self::value($data['email']) . '<br><strong>Data:</strong> ' . esc_html(wp_date('d/m/Y H:i')) . '</p></div>';
        $person = self::field('Nome completo', $name)
            . self::field('Nascita', self::date((string) $data['natoIl']) . ' – ' . self::value($data['natoA']))
            . self::field('Residenza', self::value($data['residenza']) . ', ' . self::value($data['comune']) . ' (' . self::value($data['provincia']) . ') ' . self::value($data['cap']))
            . self::field('Codice fiscale', self::value($data['codiceFiscale']))
            . self::field('Email', self::value($data['email']))
            . self::field('Telefono', self::value($data['telefono']));
        $consents = self::field('Privacy', !empty($data['consensoPrivacy']) ? 'Accettata' : 'Non accettata')
            . self::field('Regolamento', !empty($data['consensoRegolamento']) ? 'Accettato' : 'Non accettato')
            . self::field('Social', !empty($data['consensoSocial']) ? 'Accettato' : 'Non accettato')
            . self::field('Newsletter', !empty($data['consensoNewsletter']) ? 'Accettata' : 'Non accettata');
        $signature = self::field('Firma elettronica', !empty($audit['signatureTimestamp']) ? 'Presente' : 'Assente')
            . self::field('Timestamp firma', self::value($audit['signatureTimestamp'] ?? '', 'Non disponibile'))
            . self::field('ID documento', '<code>' . self::value($audit['documentId'] ?? '') . '</code>')
            . self::field('Hash documento', '<code>' . self::value($audit['documentHash'] ?? '') . '</code>')
            . self::field('Hash firma', '<code>' . self::value($audit['signatureHash'] ?? '', 'Non disponibile') . '</code>')
            . self::field('IP anonimizzato', self::value($audit['ipAddress'] ?? '', 'Non disponibile'))
            . self::field('User agent', self::value($audit['userAgent'] ?? '', 'Non disponibile'));
        $content = $summary
            . '<div class="section"><h3>Dati anagrafici</h3><table role="presentation">' . $person . '</table></div>'
            . self::dog_section($data, 1, 'Primo cane')
            . (!empty($data['aggiungiSecondoCane']) ? self::dog_section($data, 2, 'Secondo cane') : '')
            . '<div class="section"><h3>Consensi</h3><table role="presentation">' . $consents . '</table></div>'
            . '<div class="section"><h3>Documento e firma</h3><table role="presentation">' . $signature . '</table></div>'
            . '<p>Il PDF compilato e il registro tecnico JSON sono allegati a questa email.</p>';
        $footer = 'Email generata automaticamente dal sistema il ' . esc_html(wp_date('d/m/Y H:i:s'));
        return self::layout('Nuova iscrizione ricevuta', 'Sistema gestione iscrizioni', $content, $footer);
    }

    public static function registration(array $data, string $pdf_path, string $audit_path, array $audit): array
    {
        $name = sanitize_text_field(trim((string) $data['nome'] . ' ' . (string) $data['cognome']));
        $name_part = self::filename_part($name, 'registrante');
        $code = substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) ($audit['documentId'] ?? '')), 0, 8);
        $attachment_base = 'iscrizione-' . $name_part . ($code !== '' ? '-' . strtolower($code) : '');
        $user_subject = (string) apply_filters('acl_forms_registration_user_subject', 'Conferma iscrizione – Agility Club La Bora', $data, $audit);
        $admin_subject = (string) apply_filters('acl_forms_registration_admin_subject', 'Nuova iscrizione – ' . $name, $data, $audit);
        $user_body = (string) apply_filters('acl_forms_registration_user_body', self::registration_user_body($data, $audit), $data, $audit);
        $admin_body = (string) apply_filters('acl_forms_registration_admin_body', self::registration_admin_body($data, $audit), $data, $audit);
        $pdf_attachment = ['path' => $pdf_path, 'filename' => $attachment_base . '.pdf'];
        $audit_attachment = ['path' => $audit_path, 'filename' => 'registro-' . $attachment_base . '.json'];
        $user = self::send((string) $data['email'], $user_subject, $user_body, [$pdf_attachment]);
        $admin = self::send(ACL_Forms_Config::registration_admin_emails(), $admin_subject, $admin_body, [$pdf_attachment, $audit_attachment]);
        return [
            'user' => $user['success'],
            'admin' => $admin['success'],
            'transport' => $user['transport'],
            'userMessageId' => $user['messageId'],
            'adminMessageId' => $admin['messageId'],
        ];
    }

    private static function payment_method(string $value): string
    {
        return match ($value) {
            'contanti' => 'Contanti',
            'bonifico' => 'Bonifico',
            'pos' => 'POS',
            'paypal' => 'PayPal',
            default => trim($value) !== '' ? $value : 'Non specificata',
        };
    }

    private static function receipt_user_body(array $data, string $hash): string
    {
        $number = (int) $data['numeroRicevuta'];
        $payer = self::value($data['ricevutoDa']);
        $amount = esc_html(number_format((float) $data['denaroRicevuto'], 2, ',', '.'));
        $details = self::field('Data', self::date((string) $data['dataRicevuta']))
            . self::field('Causale', self::value($data['ricevutaPer']))
            . self::field('Modalità', esc_html(self::payment_method((string) $data['modalitaPagamento'])))
            . self::field('Importo', '<strong style="color:#23713f;font-size:18px">€ ' . $amount . '</strong>');
        $content = '<p class="welcome">Gentile ' . $payer . ',</p>'
            . '<p>confermiamo di aver ricevuto il pagamento. In allegato trovi la ricevuta in formato PDF.</p>'
            . '<div class="box success"><h3>Ricevuta n. ' . $number . '</h3><table role="presentation">' . $details . '</table></div>'
            . '<div class="box important"><p style="margin:0"><strong>Nota:</strong> questa è un’email automatica. Per informazioni scrivi a <a href="mailto:laboratrieste@gmail.com">laboratrieste@gmail.com</a>.</p></div>'
            . '<p style="margin-top:28px">Cordiali saluti,<br><strong>Lo staff di Agility Club La Bora</strong></p>'
            . '<p style="font-size:11px;color:#7b838c">Identificativo documento: <code>' . self::value($hash) . '</code></p>';
        $footer = '<strong>Agility Club La Bora – A.S.D.</strong><br>Ricevuta inviata a ' . self::value($data['emailPagante']) . '<br>Anno ' . esc_html(wp_date('Y'));
        return self::layout('Ricevuta di pagamento', 'Agility Club La Bora', $content, $footer);
    }

    private static function receipt_admin_body(array $data, string $hash): string
    {
        $number = (int) $data['numeroRicevuta'];
        $amount = esc_html(number_format((float) $data['denaroRicevuto'], 2, ',', '.'));
        $details = self::field('Data', self::date((string) $data['dataRicevuta']))
            . self::field('Pagante', self::value($data['ricevutoDa']))
            . self::field('Email cliente', self::value($data['emailPagante'], 'Non indicata'))
            . self::field('Causale', self::value($data['ricevutaPer']))
            . self::field('Modalità', esc_html(self::payment_method((string) $data['modalitaPagamento'])))
            . self::field('Educatore/Tecnico', self::value($data['educatoreTecnico']))
            . self::field('Importo', '<strong style="color:#23713f;font-size:18px">€ ' . $amount . '</strong>')
            . self::field('Invio al cliente', !empty($data['inviaEmailCliente']) ? 'Richiesto' : 'Non richiesto')
            . self::field('Hash documento', '<code>' . self::value($hash) . '</code>');
        $content = '<div class="box success"><h3>Ricevuta n. ' . $number . ' emessa</h3><p style="margin:0">Il PDF è stato archiviato ed è allegato a questa comunicazione.</p></div>'
            . '<div class="section"><h3>Riepilogo ricevuta</h3><table role="presentation">' . $details . '</table></div>';
        $footer = 'Email generata automaticamente dal sistema il ' . esc_html(wp_date('d/m/Y H:i:s'));
        return self::layout('Ricevuta emessa', 'Sistema amministrativo', $content, $footer);
    }

    public static function receipt(array $data, string $pdf_path, string $hash): array
    {
        $number = (int) $data['numeroRicevuta'];
        $receipt_filename = 'ricevuta-' . $number . '-' . self::filename_part((string) $data['ricevutoDa'], 'destinatario') . '.pdf';
        $pdf_attachment = ['path' => $pdf_path, 'filename' => $receipt_filename];
        $send_to_customer = !empty($data['inviaEmailCliente']);
        $user = $send_to_customer
            ? self::send((string) $data['emailPagante'], "Ricevuta n. {$number} – Agility Club La Bora", self::receipt_user_body($data, $hash), [$pdf_attachment])
            : self::delivery_result(true, 'skipped');
        $admin = self::send(ACL_Forms_Config::receipts_admin_emails(), "Ricevuta emessa n. {$number}", self::receipt_admin_body($data, $hash), [$pdf_attachment]);
        return [
            'user' => $user['success'],
            'admin' => $admin['success'],
            'transport' => $send_to_customer ? $user['transport'] : $admin['transport'],
            'userMessageId' => $user['messageId'],
            'adminMessageId' => $admin['messageId'],
        ];
    }

    public static function renewal(array $data, string $pdf_path, string $audit_path, array $audit): array
    {
        $name = trim((string) $data['nome'] . ' ' . (string) $data['cognome']);
        $year = wp_date('Y');
        $signed = !empty($audit['signatureTimestamp']);
        $signature_time = $signed ? strtotime((string) $audit['signatureTimestamp']) : false;
        $signature_date = $signature_time === false
            ? self::value($audit['signatureTimestamp'] ?? '', 'Non disponibile')
            : esc_html(wp_date('d/m/Y H:i:s', $signature_time));
        $signature_user = $signed
            ? '<div class="box success"><h3>✓ Documento firmato elettronicamente</h3>'
                . '<p><strong>Data e ora:</strong> ' . $signature_date . '<br><strong>ID documento:</strong> ' . self::value($audit['documentId'] ?? '') . '</p>'
                . '<p><strong>Hash documento:</strong> <code>' . esc_html(substr((string) ($audit['documentHash'] ?? ''), 0, 32)) . '…</code></p>'
                . '<p style="margin-bottom:0;font-size:13px">Il PDF allegato contiene la firma elettronica. ID e hash consentono di verificarne l’integrità.</p></div>'
            : '';
        $next_step = $signed
            ? '<p><strong>✓ Modulo già firmato</strong><br>Non è necessario stamparlo o firmarlo nuovamente.</p>'
            : '<p><strong>Modulo da firmare</strong><br>Non è necessario stamparlo: potrai firmarlo direttamente al campo.</p>';
        $user_content = '<p class="welcome">Gentile ' . self::value($name) . ',</p>'
            . '<p>il rinnovo dell’iscrizione per il ' . esc_html($year) . ' è stato ricevuto correttamente.</p>'
            . '<div class="box success"><h3>✓ Iscrizione rinnovata</h3><p style="margin-bottom:0">La tua richiesta è stata registrata. In allegato trovi il modulo di rinnovo compilato.</p></div>'
            . $signature_user
            . '<div class="box important"><h3>Prossimi passi</h3>' . $next_step
            . '<p><strong>Quota associativa annuale: € 30,00</strong></p><p>Puoi effettuare il pagamento in contanti al campo oppure tramite bonifico:</p>'
            . '<p><strong>Intestatario:</strong> A.S.D. Agility Club La Bora<br><strong>IBAN:</strong> IT73V0503402200000000003040<br><strong>BIC/SWIFT:</strong> BAPPIT21703<br><strong>Causale:</strong> Quota associativa – ' . self::value($name) . '</p>'
            . '<p style="margin-bottom:0"><strong>Ti aspettiamo al campo!</strong></p></div>'
            . '<div class="box contact"><h3>Contatti</h3><p style="margin-bottom:5px"><strong>Email:</strong> <a href="mailto:laboratrieste@gmail.com">laboratrieste@gmail.com</a></p>'
            . '<p style="margin:0"><strong>WhatsApp:</strong> <a href="https://wa.me/393500693832">+39 350 0693832</a></p></div>'
            . '<div class="box important"><p style="margin:0"><strong>Nota:</strong> questa è un’email automatica inviata da un indirizzo non monitorato. Per qualsiasi informazione utilizza i contatti indicati sopra.</p></div>'
            . '<p style="margin-top:28px">Cordiali saluti,<br><strong>Lo staff di Agility Club La Bora</strong></p>';
        $user_body = self::layout(
            '✓ Rinnovo iscrizione',
            'Agility Club La Bora – A.S.D.',
            $user_content,
            '<strong>Agility Club La Bora – A.S.D.</strong><br>Email inviata a ' . self::value($data['email']) . ' in seguito al rinnovo dell’iscrizione.<br>Anno ' . esc_html($year),
            '#28a745',
            '#f0f8f2'
        );

        $details = self::field('Socio', self::value($name))
            . self::field('Codice fiscale', self::value($data['codiceFiscale']))
            . self::field('Email', self::value($data['email']))
            . self::field('Anno rinnovo', esc_html($year))
            . self::field('Data rinnovo', esc_html(wp_date('d/m/Y H:i:s')));
        $consents = self::field('Privacy', !empty($data['consensoPrivacy']) ? '✓ Accettato' : '✗ Non accettato')
            . self::field('Utilizzo social', !empty($data['consensoSocial']) ? '✓ Accettato' : '✗ Non accettato')
            . self::field('Regolamento', !empty($data['consensoRegolamento']) ? '✓ Accettato' : '✗ Non accettato')
            . self::field('Newsletter', !empty($data['consensoNewsletter']) ? '✓ Accettata' : '✗ Non accettata');
        $signature_admin = self::field('Firma elettronica', $signed ? '✓ Presente' : 'Assente')
            . self::field('Timestamp firma', $signed ? $signature_date : 'Non disponibile')
            . self::field('ID documento', '<code>' . self::value($audit['documentId'] ?? '') . '</code>')
            . self::field('Hash documento', '<code>' . self::value($audit['documentHash'] ?? '') . '</code>')
            . self::field('Hash firma', '<code>' . self::value($audit['signatureHash'] ?? '', 'Non disponibile') . '</code>')
            . self::field('IP anonimizzato', self::value($audit['ipAddress'] ?? '', 'Non disponibile'));
        $admin_content = '<div class="box success"><h3>Riepilogo rinnovo</h3><p style="margin:0"><strong>' . self::value($name) . '</strong> ha completato il rinnovo per il ' . esc_html($year) . '.</p></div>'
            . '<div class="section"><h3>Dati del socio</h3><table role="presentation">' . $details . '</table></div>'
            . '<div class="section"><h3>Consensi</h3><table role="presentation">' . $consents . '</table></div>'
            . '<div class="section"><h3>Documento e firma</h3><table role="presentation">' . $signature_admin . '</table>'
            . ($signed ? '<p style="color:#23713f"><strong>✓ Il modulo non necessita di firma cartacea.</strong></p>' : '<p style="color:#9a6900"><strong>Il modulo dovrà essere firmato al campo.</strong></p>') . '</div>'
            . '<div class="section"><h3>Pagamento</h3><p><strong>Quota annuale: € 30,00</strong></p><p>Pagamento previsto in contanti al campo oppure tramite bonifico sull’IBAN IT73V0503402200000000003040.</p></div>'
            . '<div class="section"><h3>Pratica archiviata</h3><p>Il backend ha elaborato il foglio “Rinnovi” e l’aggiornamento dei consensi su Firestore. PDF e registro tecnico JSON sono allegati; gli esiti definitivi restano conservati nel registro dell’archivio privato.</p></div>';
        $admin_body = self::layout(
            'Rinnovo iscrizione ricevuto',
            'Sistema gestione rinnovi',
            $admin_content,
            'Email generata automaticamente dal sistema il ' . esc_html(wp_date('d/m/Y H:i:s')),
            '#28a745',
            '#f0f8f2'
        );
        $base = 'rinnovo-' . self::filename_part($name, 'socio') . '-' . $year;
        $pdf = ['path' => $pdf_path, 'filename' => $base . '.pdf'];
        $audit_file = ['path' => $audit_path, 'filename' => 'registro-' . $base . '.json'];
        $user = self::send((string) $data['email'], 'Conferma rinnovo iscrizione ' . $year . ' – Agility Club La Bora', $user_body, [$pdf]);
        $admin = self::send(ACL_Forms_Config::renewal_admin_emails(), 'Nuovo rinnovo – ' . $name, $admin_body, [$pdf, $audit_file]);
        return [
            'user' => $user['success'], 'admin' => $admin['success'],
            'transport' => $user['transport'], 'userMessageId' => $user['messageId'], 'adminMessageId' => $admin['messageId'],
        ];
    }

    public static function certificate(array $handler, string $expiry_date, string $file_path, string $original_name, array $audit): array
    {
        $name = trim((string) $handler['firstName'] . ' ' . (string) $handler['lastName']);
        $formatted_expiry = self::date($expiry_date);
        $details = self::field('Socio', self::value($name))
            . self::field('Codice fiscale', self::value($handler['taxCode']))
            . self::field('Email', self::value($handler['email']))
            . self::field('Scadenza certificato', $formatted_expiry)
            . self::field('Nome file originale', self::value($original_name))
            . self::field('Tipo file', self::value($audit['mimeType'] ?? '', 'Non disponibile'))
            . self::field('Dimensione', isset($audit['documentSize']) ? esc_html(size_format((int) $audit['documentSize'])) : 'Non disponibile')
            . self::field('Percorso archivio privato', '<code>' . self::value($audit['storagePath'] ?? '', 'Non disponibile') . '</code>')
            . self::field('Hash file', '<code>' . self::value($audit['documentHash'] ?? '') . '</code>');
        $user_body = self::layout(
            '✓ Certificato medico ricevuto',
            'Agility Club La Bora – A.S.D.',
            '<p class="welcome">Gentile ' . self::value($name) . ',</p><p>il tuo certificato medico è stato ricevuto correttamente.</p>'
                . '<div class="box success"><h3>Dettagli del certificato</h3><p><strong>Data di scadenza:</strong> ' . $formatted_expiry . '<br><strong>Nome file:</strong> ' . self::value($original_name) . '</p>'
                . '<p style="margin-bottom:0">La segreteria provvederà alla verifica del documento e ti contatterà qualora fossero necessarie integrazioni.</p></div>'
                . '<div class="box contact"><h3>Contatti</h3><p style="margin-bottom:5px"><strong>Email:</strong> <a href="mailto:laboratrieste@gmail.com">laboratrieste@gmail.com</a></p>'
                . '<p style="margin:0"><strong>WhatsApp:</strong> <a href="https://wa.me/393500693832">+39 350 0693832</a></p></div>'
                . '<div class="box important"><p style="margin:0"><strong>Nota:</strong> questa è un’email automatica inviata da un indirizzo non monitorato.</p></div>'
                . '<p style="margin-top:28px">Cordiali saluti,<br><strong>Lo staff di Agility Club La Bora</strong></p>',
            '<strong>Agility Club La Bora – A.S.D.</strong><br>Email inviata a ' . self::value($handler['email']) . ' in seguito al caricamento del certificato medico.',
            '#28a745',
            '#f0f8f2'
        );
        $admin_body = self::layout(
            'Nuovo certificato medico',
            'Sistema gestione certificati',
            '<div class="box success"><h3>Documento acquisito</h3><p style="margin:0">È stato caricato un nuovo certificato medico per <strong>' . self::value($name) . '</strong>.</p></div>'
                . '<div class="section"><h3>Dati del socio e del certificato</h3><table role="presentation">' . $details . '</table></div>'
                . '<p>Il certificato è allegato a questa email ed è archiviato nella directory privata configurata per il plugin.</p>',
            'Email generata automaticamente dal sistema il ' . esc_html(wp_date('d/m/Y H:i:s'))
        );
        $attachment = ['path' => $file_path, 'filename' => sanitize_file_name($original_name)];
        $user = self::send((string) $handler['email'], 'Certificato medico ricevuto – Agility Club La Bora', $user_body, []);
        $admin = self::send(ACL_Forms_Config::certificates_admin_emails(), 'Nuovo certificato medico – ' . $name, $admin_body, [$attachment]);
        return [
            'user' => $user['success'], 'admin' => $admin['success'],
            'transport' => $user['transport'], 'userMessageId' => $user['messageId'], 'adminMessageId' => $admin['messageId'],
        ];
    }
}
