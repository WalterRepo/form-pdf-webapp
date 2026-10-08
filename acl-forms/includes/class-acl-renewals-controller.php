<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Renewals_Controller
{
    public function register_routes(): void
    {
        register_rest_route('acl-forms/v1', '/renewals/verify', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'verify'],
            'permission_callback' => [$this, 'permission'],
        ]);
        register_rest_route('acl-forms/v1', '/renewals', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'submit'],
            'permission_callback' => [$this, 'permission'],
        ]);
    }

    public function permission(WP_REST_Request $request): bool|WP_Error
    {
        $nonce = (string) $request->get_header('X-ACL-Nonce');
        if (!wp_verify_nonce($nonce, 'acl_forms_public')) {
            return new WP_Error('acl_invalid_nonce', 'Sessione scaduta. Ricarica la pagina e riprova.', ['status' => 403]);
        }
        $origin = (string) $request->get_header('Origin');
        if ($origin !== '' && wp_parse_url($origin, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return new WP_Error('acl_invalid_origin', 'Origine della richiesta non consentita.', ['status' => 403]);
        }
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $key = 'acl_renew_rate_' . md5($ip . $request->get_route());
        $count = (int) get_transient($key);
        if ($count >= 10) {
            return new WP_Error('acl_rate_limit', 'Troppi tentativi. Attendi qualche minuto.', ['status' => 429]);
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function tax_code(mixed $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value));
    }

    private static function masked_email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '';
        }
        return substr($local, 0, 1) . str_repeat('•', max(2, strlen($local) - 1)) . '@' . $domain;
    }

    private static function anonymized_ip(): string
    {
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 4)) . '::';
        }
        return 'unknown';
    }

    public function verify(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = $request->get_json_params();
        $tax_code = self::tax_code(is_array($input) ? ($input['codiceFiscale'] ?? '') : '');
        if (!preg_match('/^[A-Z0-9]{16}$/', $tax_code)) {
            return new WP_Error('acl_invalid_tax_code', 'Codice fiscale non valido.', ['status' => 400]);
        }
        $google = new ACL_Forms_Google();
        $member = $google->find_sheet_member($tax_code);
        if (is_wp_error($member)) {
            return $member;
        }
        if ($member === null) {
            return new WP_Error('acl_member_not_found', 'Codice fiscale non trovato. Per una prima iscrizione usa il modulo di iscrizione.', ['status' => 404]);
        }
        return new WP_REST_Response([
            'success' => true,
            'member' => [
                'nome' => $member['nome'],
                'cognome' => $member['cognome'],
                'codiceFiscale' => $member['codiceFiscale'],
                'maskedEmail' => self::masked_email($member['email']),
            ],
        ]);
    }

    private function acquire_lock(string $tax_code): bool
    {
        $key = 'acl_forms_renewal_lock_' . md5($tax_code . wp_date('Y'));
        $existing = (int) get_option($key, 0);
        if ($existing > 0 && $existing < time() - 120) {
            delete_option($key);
        }
        if (!add_option($key, time(), '', false)) {
            return false;
        }
        register_shutdown_function(static function () use ($key): void {
            delete_option($key);
        });
        return true;
    }

    public function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = $request->get_json_params();
        if (!is_array($input) || !empty($input['website'])) {
            return new WP_Error('acl_invalid_renewal', 'Richiesta non valida.', ['status' => 400]);
        }
        $tax_code = self::tax_code($input['codiceFiscale'] ?? '');
        $email_confirm = sanitize_email((string) ($input['emailConfirm'] ?? ''));
        $data = [
            'codiceFiscale' => $tax_code,
            'consensoPrivacy' => rest_sanitize_boolean($input['consensoPrivacy'] ?? false),
            'consensoRegolamento' => rest_sanitize_boolean($input['consensoRegolamento'] ?? false),
            'consensoSocial' => rest_sanitize_boolean($input['consensoSocial'] ?? false),
            'consensoNewsletter' => rest_sanitize_boolean($input['consensoNewsletter'] ?? false),
        ];
        if (!preg_match('/^[A-Z0-9]{16}$/', $tax_code) || !is_email($email_confirm)) {
            return new WP_Error('acl_invalid_renewal', 'Codice fiscale o email non validi.', ['status' => 400]);
        }
        if (!$data['consensoPrivacy'] || !$data['consensoRegolamento']) {
            return new WP_Error('acl_missing_consent', 'Privacy e regolamento devono essere accettati.', ['status' => 400]);
        }
        if (!$this->acquire_lock($tax_code)) {
            return new WP_Error('acl_renewal_busy', 'Un rinnovo per questo socio è già in elaborazione.', ['status' => 409]);
        }

        $google = new ACL_Forms_Google();
        $member = $google->find_sheet_member($tax_code);
        if (is_wp_error($member)) {
            return $member;
        }
        if ($member === null) {
            return new WP_Error('acl_member_not_found', 'Socio non trovato.', ['status' => 404]);
        }
        if (!hash_equals(strtolower($member['email']), strtolower($email_confirm))) {
            return new WP_Error('acl_email_mismatch', 'L’email non corrisponde a quella associata al codice fiscale.', ['status' => 403]);
        }
        $data = array_merge($data, [
            'nome' => $member['nome'], 'cognome' => $member['cognome'], 'email' => $member['email'],
        ]);

        $signature_url = (string) ($input['signatureDataUrl'] ?? '');
        $signature_hash = '';
        if ($signature_url !== '') {
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $signature_url, $match)) {
                return new WP_Error('acl_invalid_signature', 'Firma elettronica non valida.', ['status' => 400]);
            }
            $signature = base64_decode($match[1], true);
            if ($signature === false || strlen($signature) > 2 * 1024 * 1024) {
                return new WP_Error('acl_invalid_signature', 'Firma elettronica non valida.', ['status' => 400]);
            }
            $signature_hash = hash('sha256', $signature);
        }
        $signature_timestamp = $signature_hash !== '' ? gmdate('c') : '';
        $pdf = ACL_Forms_Pdf::renewal($data, $signature_url, $signature_hash !== '' ? wp_date('d/m/Y H:i:s') : '');
        if (is_wp_error($pdf)) {
            return $pdf;
        }
        $submission_id = preg_match('/^[a-f0-9-]{36}$/i', (string) ($input['submissionId'] ?? ''))
            ? (string) $input['submissionId'] : wp_generate_uuid4();
        $stored = ACL_Forms_Storage::save_pdf_bytes('renewals', 'rinnovo-' . $submission_id, $pdf, [
            'nome' => $data['nome'], 'cognome' => $data['cognome'], 'codiceFiscale' => $tax_code,
        ]);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $audit = [
            'documentId' => $submission_id, 'documentHash' => $stored['hash'], 'documentSize' => $stored['size'],
            'signatureHash' => $signature_hash, 'signatureTimestamp' => $signature_timestamp,
            'signatureMethod' => $signature_hash !== '' ? 'html5-canvas' : 'none',
            'ipAddress' => self::anonymized_ip(),
            'userAgent' => substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500),
            'createdAt' => gmdate('c'), 'integrations' => [],
        ];
        $audit_path = ACL_Forms_Storage::save_audit($stored['path'], $audit);
        if (is_wp_error($audit_path)) {
            return $audit_path;
        }
        $sheet = $google->append_renewal($data, $audit);
        $firestore = $google->update_handler_consents($tax_code, $data);
        $mail = ACL_Forms_Mailer::renewal($data, $stored['path'], $audit_path, $audit);
        $audit['integrations'] = [
            'googleSheets' => !is_wp_error($sheet), 'firestore' => !is_wp_error($firestore),
            'emailUser' => $mail['user'], 'emailAdmin' => $mail['admin'],
        ];
        ACL_Forms_Storage::save_audit($stored['path'], $audit);
        $failed = [];
        foreach (['Google Sheets' => $sheet, 'Firestore' => $firestore, 'email socio' => $mail['user'], 'email segreteria' => $mail['admin']] as $name => $result) {
            if (is_wp_error($result) || $result === false) {
                $failed[] = $name;
            }
        }
        if ($failed !== []) {
            return new WP_Error('acl_renewal_partial_failure', 'Rinnovo archiviato. Non completate: ' . implode(', ', $failed) . '.', ['status' => 502, 'documentId' => $submission_id]);
        }
        return new WP_REST_Response(['success' => true, 'documentId' => $submission_id, 'signed' => $signature_hash !== ''], 201);
    }
}
