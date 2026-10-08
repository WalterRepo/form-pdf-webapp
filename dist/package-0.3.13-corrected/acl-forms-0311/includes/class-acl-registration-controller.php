<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Registration_Controller
{
    public function register_routes(): void
    {
        register_rest_route('acl-forms/v1', '/registration', [
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
        $ip = $this->client_ip();
        $key = 'acl_reg_rate_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= 6) {
            return new WP_Error('acl_rate_limit', 'Troppi tentativi. Attendi qualche minuto.', ['status' => 429]);
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private function client_ip(): string
    {
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    private function anonymized_ip(): string
    {
        $ip = $this->client_ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }
        return 'unknown';
    }

    private static function integration_error(WP_Error|bool $result): string
    {
        return is_wp_error($result) ? $result->get_error_code() : 'email_not_sent';
    }

    private static function integration_detail(WP_Error|bool $result): array
    {
        if (!is_wp_error($result)) {
            return ['code' => 'email_not_sent', 'message' => 'Invio email non riuscito.'];
        }
        $detail = [
            'code' => $result->get_error_code(),
            'message' => sanitize_text_field($result->get_error_message()),
        ];
        $data = $result->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            $detail['status'] = (int) $data['status'];
        }
        return $detail;
    }

    private function clean(array $input): array
    {
        $text_fields = [
            'nome','cognome','natoA','natoIl','residenza','comune','provincia','cap','codiceFiscale','telefono',
            'nomeCane1','sessoCane1','razzaCane1','altezzaCane1','microchipCane1','dataNascitaCane1','proprietarioCane1','conduttoreCane1',
            'nomeCane2','sessoCane2','razzaCane2','altezzaCane2','microchipCane2','dataNascitaCane2','proprietarioCane2','conduttoreCane2',
        ];
        $data = [];
        foreach ($text_fields as $field) {
            $data[$field] = sanitize_text_field((string) ($input[$field] ?? ''));
        }
        $data['email'] = sanitize_email((string) ($input['email'] ?? ''));
        $data['codiceFiscale'] = strtoupper($data['codiceFiscale']);
        foreach (['aggiungiSecondoCane','consensoPrivacy','consensoRegolamento','consensoSocial','consensoNewsletter'] as $field) {
            $data[$field] = rest_sanitize_boolean($input[$field] ?? false);
        }
        return $data;
    }

    private function validate(array $data): WP_Error|true
    {
        foreach (['nome','cognome','email','natoA','natoIl','residenza','comune','provincia','cap','codiceFiscale','telefono'] as $field) {
            if ($data[$field] === '') {
                return new WP_Error('acl_required_field', 'Compila tutti i dati personali obbligatori.', ['status' => 400]);
            }
        }
        if (!is_email($data['email'])) {
            return new WP_Error('acl_invalid_email', 'Indirizzo email non valido.', ['status' => 400]);
        }
        if (!preg_match('/^[A-Z0-9]{16}$/', $data['codiceFiscale'])) {
            return new WP_Error('acl_invalid_tax_code', 'Codice fiscale non valido.', ['status' => 400]);
        }
        if (!preg_match('/^\d{5}$/', $data['cap'])) {
            return new WP_Error('acl_invalid_postal_code', 'CAP non valido.', ['status' => 400]);
        }
        if (!$data['consensoPrivacy'] || !$data['consensoRegolamento']) {
            return new WP_Error('acl_missing_consent', 'Privacy e regolamento devono essere accettati.', ['status' => 400]);
        }
        foreach ([1, 2] as $number) {
            if ($number === 2 && !$data['aggiungiSecondoCane']) {
                continue;
            }
            $dog_fields = ['nomeCane', 'razzaCane', 'sessoCane', 'altezzaCane', 'microchipCane', 'dataNascitaCane', 'proprietarioCane', 'conduttoreCane'];
            $has_dog_data = false;
            foreach ($dog_fields as $field) {
                $has_dog_data = $has_dog_data || $data[$field . $number] !== '';
            }
            if ($has_dog_data && $data['nomeCane' . $number] === '') {
                return new WP_Error('acl_dog_name_missing', 'Indica il nome del cane ' . $number . ' oppure lascia vuota la sezione.', ['status' => 400]);
            }
            $birth = $data['dataNascitaCane' . $number];
            if ($birth !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
                if (!$date || $date->format('Y-m-d') !== $birth) {
                    return new WP_Error('acl_dog_birth_invalid', 'Data di nascita del cane ' . $number . ' non valida.', ['status' => 400]);
                }
            }
            if ($data['sessoCane' . $number] !== '' && !in_array($data['sessoCane' . $number], ['M', 'F'], true)) {
                return new WP_Error('acl_dog_sex_invalid', 'Sesso del cane ' . $number . ' non valido.', ['status' => 400]);
            }
        }
        return true;
    }

    public function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = $request->get_json_params();
        if (!is_array($input) || !empty($input['website'])) {
            return new WP_Error('acl_invalid_submission', 'Richiesta non valida.', ['status' => 400]);
        }
        $data = $this->clean($input);
        $valid = $this->validate($data);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $submission_id = sanitize_text_field((string) ($input['submissionId'] ?? ''));
        if (!preg_match('/^[a-f0-9-]{36}$/i', $submission_id)) {
            $submission_id = wp_generate_uuid4();
        }
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
        $pdf = ACL_Forms_Pdf::registration(
            $data,
            $signature_url,
            $signature_hash !== '' ? wp_date('d/m/Y H:i:s') : ''
        );
        if (is_wp_error($pdf)) {
            return $pdf;
        }
        $stored = ACL_Forms_Storage::save_pdf_bytes('registrations', $submission_id, $pdf);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $audit = [
            'documentId' => $submission_id,
            'documentHash' => $stored['hash'],
            'documentSize' => $stored['size'],
            'signatureHash' => $signature_hash,
            'signatureTimestamp' => $signature_timestamp,
            'signatureMethod' => $signature_hash !== '' ? 'html5-canvas' : 'none',
            'ipAddress' => $this->anonymized_ip(),
            'userAgent' => substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500),
            'createdAt' => gmdate('c'),
            'integrations' => [],
        ];
        $audit_path = ACL_Forms_Storage::save_audit($stored['path'], $audit);
        if (is_wp_error($audit_path)) {
            return $audit_path;
        }

        $google = new ACL_Forms_Google();
        $sheet = $google->append_registration($data, $audit);
        $firestore = $google->upsert_handler($data);
        $dogs = is_wp_error($firestore) ? $firestore : $google->upsert_dogs($data, $firestore);
        $audit['integrations']['googleSheets'] = !is_wp_error($sheet);
        $audit['integrations']['firestore'] = !is_wp_error($firestore);
        $audit['integrations']['firestoreDogs'] = !is_wp_error($dogs);
        $audit['dogIds'] = is_wp_error($dogs) ? [] : $dogs['ids'];

        $mail = ACL_Forms_Mailer::registration($data, $stored['path'], $audit_path, $audit);
        $audit['integrations']['emailUser'] = $mail['user'];
        $audit['integrations']['emailAdmin'] = $mail['admin'];
        $audit['email'] = [
            'transport' => $mail['transport'],
            'userMessageId' => $mail['userMessageId'],
            'adminMessageId' => $mail['adminMessageId'],
        ];
        $failures = [];
        $failure_details = [];
        foreach ([
            'Google Sheets' => $sheet,
            'Firestore iscritti' => $firestore,
            'Firestore cani/binomi' => $dogs,
            'email iscritto' => $mail['user'],
            'email segreteria' => $mail['admin'],
        ] as $name => $result) {
            if (is_wp_error($result) || $result === false) {
                $failures[$name] = self::integration_error($result);
                $failure_details[$name] = self::integration_detail($result);
            }
        }
        $audit['integrationErrors'] = $failures;
        $audit['integrationDetails'] = $failure_details;
        $diagnostics = [
            'documentId' => $submission_id,
            'googleSheets' => !is_wp_error($sheet),
            'firestoreHandler' => !is_wp_error($firestore),
            'firestoreDogs' => !is_wp_error($dogs),
            'dogsSaved' => is_wp_error($dogs) ? 0 : (int) $dogs['count'],
            'emailTransport' => $mail['transport'],
            'emailRegistrant' => $mail['user'],
            'emailOffice' => $mail['admin'],
            'emailRegistrantMessageId' => $mail['userMessageId'],
            'emailOfficeMessageId' => $mail['adminMessageId'],
            'signed' => $signature_hash !== '',
        ];
        ACL_Forms_Storage::save_audit($stored['path'], $audit);

        if ($failures) {
            return new WP_Error(
                'acl_partial_failure',
                'Documento archiviato. Non completate: ' . implode(', ', array_keys($failures)) . '. Apri i dettagli tecnici per il registro completo.',
                [
                    'status' => 502,
                    'documentId' => $submission_id,
                    'failedIntegrations' => $failures,
                    'failedIntegrationDetails' => $failure_details,
                    'diagnostics' => $diagnostics,
                ]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'documentId' => $submission_id,
            'documentHash' => $stored['hash'],
            'signed' => $signature_hash !== '',
            'dogsSaved' => $dogs['count'],
            'diagnostics' => $diagnostics,
        ], 201);
    }
}
