<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Certificates_Controller
{
    public function register_routes(): void
    {
        register_rest_route('acl-forms/v1', '/certificates/verify', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'verify'],
            'permission_callback' => [$this, 'permission'],
        ]);
        register_rest_route('acl-forms/v1', '/certificates', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upload'],
            'permission_callback' => [$this, 'permission'],
        ]);
    }

    public function permission(WP_REST_Request $request): bool|WP_Error
    {
        if (!wp_verify_nonce((string) $request->get_header('X-ACL-Nonce'), 'acl_forms_public')) {
            return new WP_Error('acl_invalid_nonce', 'Sessione scaduta. Ricarica la pagina e riprova.', ['status' => 403]);
        }
        $origin = (string) $request->get_header('Origin');
        if ($origin !== '' && wp_parse_url($origin, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return new WP_Error('acl_invalid_origin', 'Origine della richiesta non consentita.', ['status' => 403]);
        }
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $key = 'acl_cert_rate_' . md5($ip . $request->get_route());
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
        return $local !== '' && $domain !== ''
            ? substr($local, 0, 1) . str_repeat('•', max(2, strlen($local) - 1)) . '@' . $domain
            : '';
    }

    public function verify(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = $request->get_json_params();
        $tax_code = self::tax_code(is_array($input) ? ($input['taxCode'] ?? '') : '');
        if (!preg_match('/^[A-Z0-9]{16}$/', $tax_code)) {
            return new WP_Error('acl_invalid_tax_code', 'Codice fiscale non valido.', ['status' => 400]);
        }
        $handler = (new ACL_Forms_Google())->find_handler_by_tax_code($tax_code);
        if (is_wp_error($handler)) {
            return $handler;
        }
        if ($handler === null) {
            return new WP_Error('acl_member_not_found', 'Socio non trovato. Verifica il codice fiscale inserito.', ['status' => 404]);
        }
        return new WP_REST_Response(['success' => true, 'handler' => [
            'firstName' => $handler['firstName'], 'lastName' => $handler['lastName'],
            'taxCode' => $handler['taxCode'], 'maskedEmail' => self::masked_email($handler['email']),
            'medicalCertificateExpiry' => $handler['medicalCertificateExpiry'],
        ]]);
    }

    private static function valid_expiry(string $value): bool
    {
        $timezone = new DateTimeZone('Europe/Rome');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $today = new DateTimeImmutable('today', $timezone);
        return $date !== false && $date->format('Y-m-d') === $value && $date > $today;
    }

    private static function detect_file(string $bytes): array|WP_Error
    {
        if (str_starts_with($bytes, '%PDF-')) {
            return ['extension' => 'pdf', 'mime' => 'application/pdf'];
        }
        if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return ['extension' => 'png', 'mime' => 'image/png'];
        }
        if (substr($bytes, 0, 3) === "\xff\xd8\xff") {
            return ['extension' => 'jpg', 'mime' => 'image/jpeg'];
        }
        $brand = substr($bytes, 4, 8);
        if (preg_match('/^ftyp(heic|heix|hevc|hevx|mif1|msf1)$/', $brand, $match)) {
            $extension = in_array($match[1], ['mif1', 'msf1'], true) ? 'heif' : 'heic';
            return ['extension' => $extension, 'mime' => 'image/' . $extension];
        }
        return new WP_Error('acl_certificate_type_invalid', 'Tipo di file non supportato. Carica PDF, JPG, PNG, HEIC o HEIF.', ['status' => 400]);
    }

    public function upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_params();
        $files = $request->get_file_params();
        if (!empty($params['website'])) {
            return new WP_Error('acl_invalid_certificate', 'Richiesta non valida.', ['status' => 400]);
        }
        $tax_code = self::tax_code($params['taxCode'] ?? '');
        $email = sanitize_email((string) ($params['emailConfirm'] ?? ''));
        $expiry = sanitize_text_field((string) ($params['expiryDate'] ?? ''));
        if (!preg_match('/^[A-Z0-9]{16}$/', $tax_code) || !is_email($email) || !self::valid_expiry($expiry)) {
            return new WP_Error('acl_invalid_certificate', 'Codice fiscale, email o data di scadenza non validi.', ['status' => 400]);
        }
        $file = $files['certificate'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return new WP_Error('acl_certificate_missing', 'Seleziona un certificato da caricare.', ['status' => 400]);
        }
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 10 * 1024 * 1024) {
            return new WP_Error('acl_certificate_size', 'Il certificato deve avere una dimensione massima di 10 MB.', ['status' => 400]);
        }
        $bytes = file_get_contents((string) $file['tmp_name']);
        if ($bytes === false) {
            return new WP_Error('acl_certificate_read', 'Impossibile leggere il certificato caricato.', ['status' => 400]);
        }
        $type = self::detect_file($bytes);
        if (is_wp_error($type)) {
            return $type;
        }

        $google = new ACL_Forms_Google();
        $handler = $google->find_handler_by_tax_code($tax_code);
        if (is_wp_error($handler)) {
            return $handler;
        }
        if ($handler === null) {
            return new WP_Error('acl_member_not_found', 'Socio non trovato.', ['status' => 404]);
        }
        if (!is_email($handler['email']) || !hash_equals(strtolower($handler['email']), strtolower($email))) {
            return new WP_Error('acl_email_mismatch', 'L’email non corrisponde a quella associata al codice fiscale.', ['status' => 403]);
        }

        $id = 'certificato-scadenza-' . $expiry . '-' . wp_generate_uuid4();
        $stored = ACL_Forms_Storage::save_file_bytes('certificates', $id, $type['extension'], $bytes, [
            'nome' => $handler['firstName'], 'cognome' => $handler['lastName'], 'codiceFiscale' => $tax_code,
        ]);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $original = sanitize_file_name((string) ($file['name'] ?? 'certificato.' . $type['extension']));
        $audit = [
            'documentId' => $id, 'documentHash' => $stored['hash'], 'documentSize' => $stored['size'],
            'originalFilename' => $original, 'mimeType' => $type['mime'], 'expiryDate' => $expiry,
            'createdAt' => gmdate('c'), 'integrations' => [],
        ];
        $audit_path = ACL_Forms_Storage::save_audit($stored['path'], $audit);
        if (is_wp_error($audit_path)) {
            return $audit_path;
        }

        $archive_path = ACL_Forms_Storage::relative_path($stored['path']);
        if (is_wp_error($archive_path)) {
            return $archive_path;
        }
        $audit['storagePath'] = $archive_path;
        $audit['storage'] = 'wordpress-private';
        $firestore = $google->update_medical_certificate($handler['id'], $expiry, $archive_path);
        $sheet = $google->update_certificate_expiry_sheet($tax_code, $expiry);
        $mail = ACL_Forms_Mailer::certificate($handler, $expiry, $stored['path'], $original, $audit);
        $audit['integrations'] = [
            'privateArchive' => true, 'firestore' => !is_wp_error($firestore),
            'googleSheets' => !is_wp_error($sheet), 'emailUser' => $mail['user'], 'emailAdmin' => $mail['admin'],
        ];
        ACL_Forms_Storage::save_audit($stored['path'], $audit);

        $failed = [];
        foreach (['Firestore' => $firestore, 'Google Sheets' => $sheet, 'email socio' => $mail['user'], 'email segreteria' => $mail['admin']] as $name => $result) {
            if (is_wp_error($result) || $result === false) {
                $failed[] = $name;
            }
        }
        if ($failed !== []) {
            return new WP_Error('acl_certificate_partial_failure', 'Certificato archiviato. Non completate: ' . implode(', ', $failed) . '.', ['status' => 502, 'documentId' => $id]);
        }
        return new WP_REST_Response(['success' => true, 'documentId' => $id, 'expiryDate' => $expiry], 201);
    }
}
