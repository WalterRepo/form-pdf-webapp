<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Forms_Google
{
    private array|WP_Error $sheets_credentials;
    private array|WP_Error $firebase_credentials;

    public function __construct()
    {
        $this->sheets_credentials = ACL_Forms_Config::sheets_service_account();
        $this->firebase_credentials = ACL_Forms_Config::firebase_service_account();
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function access_token(string $purpose): string|WP_Error
    {
        $credentials = in_array($purpose, ['firebase', 'firestore'], true)
            ? $this->firebase_credentials
            : $this->sheets_credentials;
        if (is_wp_error($credentials)) {
            return $credentials;
        }
        $cache_key = 'acl_google_token_' . md5($purpose . (string) ($credentials['client_email'] ?? ''));
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if (!empty($credentials['private_key_id'])) {
            $header['kid'] = $credentials['private_key_id'];
        }
        $claims = [
            'iss' => $credentials['client_email'] ?? '',
            'scope' => match ($purpose) {
                'firebase', 'firestore' => 'https://www.googleapis.com/auth/datastore',
                default => 'https://www.googleapis.com/auth/spreadsheets',
            },
            'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $unsigned = self::base64url(wp_json_encode($header)) . '.' . self::base64url(wp_json_encode($claims));
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $credentials['private_key'] ?? '', OPENSSL_ALGO_SHA256)) {
            return new WP_Error('acl_google_signing_failed', 'Impossibile firmare la richiesta Google.');
        }
        $assertion = $unsigned . '.' . self::base64url($signature);
        $response = wp_remote_post($claims['aud'], [
            'timeout' => 20,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
        ]);
        $decoded = $this->decode_response($response, 'autenticazione Google');
        if (is_wp_error($decoded)) {
            return $decoded;
        }
        if (empty($decoded['access_token'])) {
            return new WP_Error('acl_google_token_missing', 'Google non ha restituito un token di accesso.');
        }
        set_transient($cache_key, $decoded['access_token'], max(60, ((int) ($decoded['expires_in'] ?? 3600)) - 120));
        return $decoded['access_token'];
    }

    private function decode_response(array|WP_Error $response, string $operation): array|WP_Error
    {
        if (is_wp_error($response)) {
            return new WP_Error('acl_google_network', "Errore di rete durante {$operation}.");
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = $body['error']['message'] ?? $body['error_description'] ?? "Errore Google durante {$operation}.";
            error_log(sprintf(
                '[ACL Forms] Errore API Google durante %s (HTTP %d): %s',
                $operation,
                $status,
                sanitize_text_field((string) $message)
            ));
            return new WP_Error('acl_google_api', sanitize_text_field((string) $message), ['status' => $status]);
        }
        return is_array($body) ? $body : [];
    }

    private function request(string $method, string $url, ?array $body = null, string $purpose = 'sheets'): array|WP_Error
    {
        $token = $this->access_token($purpose);
        if (is_wp_error($token)) {
            return $token;
        }
        $args = [
            'method' => $method,
            'timeout' => 25,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        return $this->decode_response(wp_remote_request($url, $args), $url);
    }

    public function append_row(string $spreadsheet_id, string $range, array $row): array|WP_Error
    {
        if ($spreadsheet_id === '') {
            return new WP_Error('acl_sheet_missing', 'ID del foglio Google non configurato.');
        }
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
            rawurlencode($spreadsheet_id), rawurlencode($range)
        );
        return $this->request('POST', $url, ['majorDimension' => 'ROWS', 'values' => [$row]]);
    }

    public function get_values(string $spreadsheet_id, string $range): array|WP_Error
    {
        if ($spreadsheet_id === '') {
            return new WP_Error('acl_sheet_missing', 'ID del foglio Google non configurato.');
        }
        $url = sprintf('https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s', rawurlencode($spreadsheet_id), rawurlencode($range));
        return $this->request('GET', $url);
    }

    public function update_values(string $spreadsheet_id, string $range, array $rows): array|WP_Error
    {
        if ($spreadsheet_id === '') {
            return new WP_Error('acl_sheet_missing', 'ID del foglio Google non configurato.');
        }
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s?valueInputOption=USER_ENTERED',
            rawurlencode($spreadsheet_id), rawurlencode($range)
        );
        return $this->request('PUT', $url, ['majorDimension' => 'ROWS', 'values' => $rows]);
    }

    public function ensure_sheet(string $spreadsheet_id, string $title, array $headers): true|WP_Error
    {
        if ($spreadsheet_id === '') {
            return new WP_Error('acl_sheet_missing', 'ID del foglio Google non configurato.');
        }
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheet_id);
        $metadata = $this->request('GET', $base . '?fields=sheets.properties.title');
        if (is_wp_error($metadata)) {
            return $metadata;
        }
        foreach (($metadata['sheets'] ?? []) as $sheet) {
            if (($sheet['properties']['title'] ?? '') === $title) {
                return true;
            }
        }
        $created = $this->request('POST', $base . ':batchUpdate', [
            'requests' => [['addSheet' => ['properties' => [
                'title' => $title,
                'gridProperties' => ['rowCount' => 1000, 'columnCount' => max(20, count($headers)), 'frozenRowCount' => 1],
            ]]]],
        ]);
        if (is_wp_error($created)) {
            return $created;
        }
        $range = "'" . str_replace("'", "''", $title) . "'!A1:" . self::column_name(count($headers)) . '1';
        $written = $this->update_values($spreadsheet_id, $range, [$headers]);
        return is_wp_error($written) ? $written : true;
    }

    private static function column_name(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    public function find_sheet_member(string $tax_code): array|WP_Error|null
    {
        $result = $this->get_values(ACL_Forms_Config::registration_sheet_id(), 'Soci!A2:T');
        if (is_wp_error($result)) {
            return $result;
        }
        $tax_code = strtoupper(trim($tax_code));
        foreach (($result['values'] ?? []) as $row) {
            if (strtoupper(trim((string) ($row[10] ?? ''))) === $tax_code) {
                return [
                    'nome' => sanitize_text_field((string) ($row[1] ?? '')),
                    'cognome' => sanitize_text_field((string) ($row[2] ?? '')),
                    'email' => sanitize_email((string) ($row[3] ?? '')),
                    'codiceFiscale' => $tax_code,
                    'telefono' => sanitize_text_field((string) ($row[12] ?? '')),
                ];
            }
        }
        return null;
    }

    public function append_renewal(array $data, array $audit): array|WP_Error
    {
        $headers = [
            'Timestamp Rinnovo', 'Nome', 'Cognome', 'Email', 'Codice Fiscale', 'Consenso Privacy', 'Consenso Social',
            'Consenso Regolamento', 'Consenso Newsletter', 'Timestamp Firma', 'Hash Firma', 'Hash Documento',
            'IP Firma', 'User Agent', 'Stato Verifica', 'Firma Elettronica', 'Account Creato', 'Account UID', 'Anno Rinnovo',
        ];
        $sheet = ACL_Forms_Config::registration_sheet_id();
        $ready = $this->ensure_sheet($sheet, 'Rinnovi', $headers);
        if (is_wp_error($ready)) {
            return $ready;
        }
        return $this->append_row($sheet, 'Rinnovi!A:S', [
            current_time('mysql'), $data['nome'], $data['cognome'], $data['email'], $data['codiceFiscale'],
            $data['consensoPrivacy'] ? 'Sì' : 'No', $data['consensoSocial'] ? 'Sì' : 'No',
            $data['consensoRegolamento'] ? 'Sì' : 'No', $data['consensoNewsletter'] ? 'Sì' : 'No',
            $audit['signatureTimestamp'] ?: 'N/A', $audit['signatureHash'] ?: 'N/A', $audit['documentHash'],
            $audit['ipAddress'], $audit['userAgent'], 'VERIFIED', $audit['signatureHash'] !== '' ? 'Sì' : 'No',
            'No', 'N/A', (int) wp_date('Y'),
        ]);
    }

    public function append_registration(array $data, array $audit): array|WP_Error
    {
        $row = [
            current_time('mysql'),
            $data['nome'], $data['cognome'], $data['email'], $data['natoA'], $data['natoIl'],
            $data['residenza'], $data['comune'], $data['provincia'], $data['cap'], $data['codiceFiscale'], $data['telefono'],
            $data['nomeCane1'] ?? '', $data['sessoCane1'] ?? '', $data['razzaCane1'] ?? '', $data['altezzaCane1'] ?? '', $data['microchipCane1'] ?? '', $data['dataNascitaCane1'] ?? '',
            $data['proprietarioCane1'] ?? '', $data['conduttoreCane1'] ?? '',
            !empty($data['aggiungiSecondoCane']) ? 'Sì' : 'No',
            $data['nomeCane2'] ?? '', $data['sessoCane2'] ?? '', $data['razzaCane2'] ?? '', $data['altezzaCane2'] ?? '', $data['microchipCane2'] ?? '', $data['dataNascitaCane2'] ?? '',
            $data['proprietarioCane2'] ?? '', $data['conduttoreCane2'] ?? '',
            !empty($data['consensoPrivacy']) ? 'Checked' : 'Not checked',
            !empty($data['consensoSocial']) ? 'Checked' : 'Not checked',
            !empty($data['consensoRegolamento']) ? 'Checked' : 'Not checked',
            !empty($data['consensoNewsletter']) ? 'Checked' : 'Not checked',
            $audit['signatureTimestamp'] ?? '', $audit['signatureHash'] ?? '', $audit['documentHash'],
            $audit['ipAddress'], $audit['userAgent'], 'VERIFIED', '',
        ];
        return $this->append_row(ACL_Forms_Config::registration_sheet_id(), 'Iscrizioni!A:AU', $row);
    }

    private static function firestore_value(mixed $value, string $type = ''): array
    {
        return match ($type) {
            'boolean' => ['booleanValue' => (bool) $value],
            'integer' => ['integerValue' => (string) (int) $value],
            'timestamp' => ['timestampValue' => (string) $value],
            default => ['stringValue' => (string) $value],
        };
    }

    private function project_id(): string|WP_Error
    {
        if (is_wp_error($this->firebase_credentials)) {
            return $this->firebase_credentials;
        }
        $project = (string) ($this->firebase_credentials['project_id'] ?? '');
        return $project !== '' ? $project : new WP_Error('acl_firebase_project_missing', 'Project ID Firebase mancante.');
    }

    private function find_handler(string $tax_code): array|WP_Error|null
    {
        $project = $this->project_id();
        if (is_wp_error($project)) {
            return $project;
        }
        $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) . '/databases/(default)/documents:runQuery';
        $result = $this->request('POST', $url, [
            'structuredQuery' => [
                'from' => [['collectionId' => 'handlers']],
                'where' => ['fieldFilter' => [
                    'field' => ['fieldPath' => 'taxCode'],
                    'op' => 'EQUAL',
                    'value' => ['stringValue' => $tax_code],
                ]],
                'limit' => 1,
            ],
        ], 'firebase');
        if (is_wp_error($result)) {
            return $result;
        }
        return $result[0]['document'] ?? null;
    }

    public function upsert_handler(array $data): array|WP_Error
    {
        $project = $this->project_id();
        if (is_wp_error($project)) {
            return $project;
        }
        $existing = $this->find_handler($data['codiceFiscale']);
        if (is_wp_error($existing)) {
            return $existing;
        }
        $document_id = $existing ? basename($existing['name']) : wp_generate_password(20, false, false);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $birth = !empty($data['natoIl']) ? $data['natoIl'] . 'T12:00:00Z' : $now;
        $fields = [
            'firstName' => self::firestore_value($data['nome']),
            'lastName' => self::firestore_value($data['cognome']),
            'email' => self::firestore_value($data['email']),
            'phone' => self::firestore_value($data['telefono']),
            'address' => self::firestore_value($data['residenza']),
            'birthPlace' => self::firestore_value($data['natoA']),
            'birthDate' => self::firestore_value($birth, 'timestamp'),
            'province' => self::firestore_value($data['provincia']),
            'postalCode' => self::firestore_value($data['cap']),
            'taxCode' => self::firestore_value($data['codiceFiscale']),
            'city' => self::firestore_value($data['comune']),
            'privacy' => self::firestore_value($data['consensoPrivacy'], 'boolean'),
            'social' => self::firestore_value($data['consensoSocial'], 'boolean'),
            'organizationId' => self::firestore_value('LaBora'),
            'registrationType' => self::firestore_value('web'),
            'registrationRequestDate' => self::firestore_value($now, 'timestamp'),
            'updatedAt' => self::firestore_value($now, 'timestamp'),
        ];
        if (!$existing) {
            $fields += [
                'id' => self::firestore_value($document_id),
                'credits' => self::firestore_value(0, 'integer'),
                'accessLevel' => self::firestore_value('user'),
                'hasAccount' => self::firestore_value(false, 'boolean'),
                'newsletter' => self::firestore_value(true, 'boolean'),
                'appointmentSmsRemindersEnabled' => self::firestore_value(true, 'boolean'),
                'status' => self::firestore_value('active'),
                'createdAt' => self::firestore_value($now, 'timestamp'),
            ];
        }
        $base = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) . '/databases/(default)/documents/handlers/' . rawurlencode($document_id);
        if (!$existing) {
            return $this->request('PATCH', $base, ['fields' => $fields], 'firebase');
        }
        $masks = implode('', array_map(static fn($name) => '&updateMask.fieldPaths=' . rawurlencode($name), array_keys($fields)));
        return $this->request('PATCH', $base . '?' . ltrim($masks, '&'), ['fields' => $fields], 'firebase');
    }

    private static function dog_birthday(string $date): string
    {
        if ($date === '') {
            return '';
        }
        $local = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Rome'));
        if (!$local || $local->format('Y-m-d') !== $date) {
            return '';
        }
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function dog_form_fields(array $data, int $number): array
    {
        $suffix = (string) $number;
        $fields = [
            'name' => self::firestore_value($data['nomeCane' . $suffix]),
            'breed' => self::firestore_value($data['razzaCane' . $suffix]),
        ];
        foreach (['altezzaCane' => 'height', 'microchipCane' => 'microchip', 'sessoCane' => 'sex'] as $input => $field) {
            if ($data[$input . $suffix] !== '') {
                $fields[$field] = self::firestore_value($data[$input . $suffix]);
            }
        }
        $birthday = self::dog_birthday($data['dataNascitaCane' . $suffix]);
        if ($birthday !== '') {
            $fields['birthday'] = self::firestore_value($birthday, 'timestamp');
        }
        return $fields;
    }

    private static function same_dog(array $submitted, array $existing): bool
    {
        $text = static fn(array $fields, string $name): string => strtolower(trim((string) ($fields[$name]['stringValue'] ?? '')));
        $chip = $text($submitted, 'microchip');
        $old_chip = $text($existing, 'microchip');
        if ($chip !== '' && $old_chip !== '') {
            return $chip === $old_chip;
        }
        if ($text($submitted, 'name') === '' || $text($submitted, 'name') !== $text($existing, 'name')) {
            return false;
        }
        $breed = $text($submitted, 'breed');
        $old_breed = $text($existing, 'breed');
        if ($breed !== '' && $old_breed !== '' && $breed !== $old_breed) {
            return false;
        }
        $birthday = (string) ($submitted['birthday']['timestampValue'] ?? '');
        $old_birthday = (string) ($existing['birthday']['timestampValue'] ?? '');
        if ($birthday !== '' && $old_birthday !== '') {
            return substr($birthday, 0, 10) === substr($old_birthday, 0, 10);
        }
        return true;
    }

    private static function pair_fields(string $pair_id, string $handler_id, string $dog_id, string $instructor_id, string $now): array
    {
        $empty = self::firestore_value('');
        return [
            'id' => self::firestore_value($pair_id),
            'handlerId' => self::firestore_value($handler_id),
            'dogId' => self::firestore_value($dog_id),
            'organizationId' => self::firestore_value('LaBora'),
            'instructorId' => self::firestore_value($instructor_id),
            'instructorNote' => $empty,
            'pairReminder' => $empty,
            'isActive' => self::firestore_value(true, 'boolean'),
            'isDeleted' => self::firestore_value(false, 'boolean'),
            'status' => self::firestore_value('active'),
            'createdAt' => self::firestore_value($now, 'timestamp'),
            'updatedAt' => self::firestore_value($now, 'timestamp'),
        ];
    }

    private function handler_dogs(string $project, string $handler_id): array|WP_Error
    {
        $query_url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) . '/databases/(default)/documents:runQuery';
        $pairs = $this->request('POST', $query_url, [
            'structuredQuery' => [
                'from' => [['collectionId' => 'pairs']],
                'where' => ['fieldFilter' => [
                    'field' => ['fieldPath' => 'handlerId'],
                    'op' => 'EQUAL',
                    'value' => ['stringValue' => $handler_id],
                ]],
            ],
        ], 'firebase');
        if (is_wp_error($pairs)) {
            return $pairs;
        }
        $dogs = [];
        foreach ($pairs as $item) {
            $pair_fields = $item['document']['fields'] ?? [];
            $dog_id = (string) ($pair_fields['dogId']['stringValue'] ?? '');
            if ($dog_id === '') {
                continue;
            }
            $active_pair = ($pair_fields['isDeleted']['booleanValue'] ?? false) !== true
                && ($pair_fields['isActive']['booleanValue'] ?? true) !== false
                && ($pair_fields['status']['stringValue'] ?? '') !== 'inactive';
            $pair_id = basename((string) ($item['document']['name'] ?? ''));
            if (isset($dogs[$dog_id])) {
                if ($active_pair && !$dogs[$dog_id]['hasActivePair']) {
                    $dogs[$dog_id]['hasActivePair'] = true;
                    $dogs[$dog_id]['pairId'] = $pair_id;
                    $dogs[$dog_id]['instructorId'] = (string) ($pair_fields['instructorId']['stringValue'] ?? '');
                }
                continue;
            }
            $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) . '/databases/(default)/documents/dogs/' . rawurlencode($dog_id);
            $dog = $this->request('GET', $url, null, 'firebase');
            if (is_wp_error($dog)) {
                if (($dog->get_error_data()['status'] ?? 0) === 404) {
                    continue;
                }
                return $dog;
            }
            $dogs[$dog_id] = [
                'fields' => $dog['fields'] ?? [],
                'pairId' => $pair_id,
                'instructorId' => (string) ($pair_fields['instructorId']['stringValue'] ?? ''),
                'hasActivePair' => $active_pair,
            ];
        }
        return $dogs;
    }

    public function upsert_dogs(array $data, array $handler): array|WP_Error
    {
        $numbers = [];
        if ($data['nomeCane1'] !== '') {
            $numbers[] = 1;
        }
        if ($data['aggiungiSecondoCane'] && $data['nomeCane2'] !== '') {
            $numbers[] = 2;
        }
        if (!$numbers) {
            return ['count' => 0, 'ids' => []];
        }
        $project = $this->project_id();
        if (is_wp_error($project)) {
            return $project;
        }
        $handler_id = basename((string) ($handler['name'] ?? ''));
        if ($handler_id === '' || $handler_id === '.') {
            return new WP_Error('acl_handler_id_missing', 'Identificativo handler Firebase mancante.');
        }
        $known = $this->handler_dogs($project, $handler_id);
        if (is_wp_error($known)) {
            return $known;
        }
        $document_base = 'projects/' . rawurlencode($project) . '/databases/(default)/documents';
        $base = 'https://firestore.googleapis.com/v1/' . $document_base;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $instructor_id = ACL_Forms_Config::instructor_id();
        $ids = [];
        foreach ($numbers as $number) {
            $form_fields = self::dog_form_fields($data, $number);
            $matched_id = '';
            foreach ($known as $id => $entry) {
                if (self::same_dog($form_fields, $entry['fields'])) {
                    $matched_id = $id;
                    break;
                }
            }
            if ($matched_id !== '') {
                $mask = implode('', array_map(static fn($name) => '&updateMask.fieldPaths=' . rawurlencode($name), array_keys($form_fields)));
                $updated = $this->request('PATCH', $base . '/dogs/' . rawurlencode($matched_id) . '?' . ltrim($mask, '&'), ['fields' => $form_fields], 'firebase');
                if (is_wp_error($updated)) {
                    return $updated;
                }
                $known[$matched_id]['fields'] = array_merge($known[$matched_id]['fields'], $form_fields);
                if (!$known[$matched_id]['hasActivePair']) {
                    $pair_id = wp_generate_uuid4();
                    $pair_fields = self::pair_fields($pair_id, $handler_id, $matched_id, $instructor_id, $now);
                    $pair_created = $this->request('POST', $base . ':commit', ['writes' => [
                        ['update' => ['name' => $document_base . '/pairs/' . rawurlencode($pair_id), 'fields' => $pair_fields], 'currentDocument' => ['exists' => false]],
                    ]], 'firebase');
                    if (is_wp_error($pair_created)) {
                        return $pair_created;
                    }
                    $known[$matched_id]['pairId'] = $pair_id;
                    $known[$matched_id]['instructorId'] = $instructor_id;
                    $known[$matched_id]['hasActivePair'] = true;
                } elseif ($instructor_id !== '' && $known[$matched_id]['instructorId'] === '' && $known[$matched_id]['pairId'] !== '') {
                    $pair_id = $known[$matched_id]['pairId'];
                    $pair_fields = [
                        'instructorId' => self::firestore_value($instructor_id),
                        'updatedAt' => self::firestore_value($now, 'timestamp'),
                    ];
                    $pair_url = $base . '/pairs/' . rawurlencode($pair_id) . '?updateMask.fieldPaths=instructorId&updateMask.fieldPaths=updatedAt';
                    $pair_update = $this->request('PATCH', $pair_url, ['fields' => $pair_fields], 'firebase');
                    if (is_wp_error($pair_update)) {
                        return $pair_update;
                    }
                    $known[$matched_id]['instructorId'] = $instructor_id;
                }
                $ids[] = $matched_id;
                continue;
            }

            $dog_id = wp_generate_uuid4();
            $pair_id = wp_generate_uuid4();
            $empty = self::firestore_value('');
            $dog_fields = [
                'id' => self::firestore_value($dog_id),
                'firebaseId' => self::firestore_value($dog_id),
                'organizationId' => self::firestore_value('LaBora'),
                'birthday' => ['nullValue' => null],
                'dailyWalks' => $empty,
                'diseases' => $empty,
                'family' => $empty,
                'food' => $empty,
                'foodDetails' => $empty,
                'foodIntolerance' => $empty,
                'game' => $empty,
                'goals' => $empty,
                'height' => $empty,
                'microchip' => $empty,
                'notes' => $empty,
                'ownerClaims' => $empty,
                'photoUrl' => $empty,
                'preferredReinforcement' => $empty,
                'sleepingLocation' => $empty,
                'suggestions' => $empty,
                'walkingSkills' => $empty,
                'warning' => $empty,
                'weight' => $empty,
                'skills' => ['mapValue' => ['fields' => array_fill_keys(
                    ['kennel', 'museruola', 'piede', 'resta', 'seduto', 'terra'],
                    ['mapValue' => ['fields' => [
                        'comments' => $empty,
                        'value' => self::firestore_value(false, 'boolean'),
                    ]]]
                )]],
                'isActive' => self::firestore_value(true, 'boolean'),
                'createdAt' => self::firestore_value($now, 'timestamp'),
            ];
            $dog_fields = array_merge($dog_fields, $form_fields);
            $pair_fields = self::pair_fields($pair_id, $handler_id, $dog_id, $instructor_id, $now);
            $committed = $this->request('POST', $base . ':commit', ['writes' => [
                ['update' => ['name' => $document_base . '/dogs/' . rawurlencode($dog_id), 'fields' => $dog_fields], 'currentDocument' => ['exists' => false]],
                ['update' => ['name' => $document_base . '/pairs/' . rawurlencode($pair_id), 'fields' => $pair_fields], 'currentDocument' => ['exists' => false]],
            ]], 'firebase');
            if (is_wp_error($committed)) {
                return $committed;
            }
            $known[$dog_id] = ['fields' => $dog_fields, 'pairId' => $pair_id, 'instructorId' => $instructor_id, 'hasActivePair' => true];
            $ids[] = $dog_id;
        }
        return ['count' => count($ids), 'ids' => $ids];
    }

    private static function firestore_scalar(array $field): mixed
    {
        foreach (['stringValue', 'timestampValue', 'booleanValue', 'integerValue', 'doubleValue'] as $key) {
            if (array_key_exists($key, $field)) {
                return $field[$key];
            }
        }
        return null;
    }

    public function find_handler_by_tax_code(string $tax_code): array|WP_Error|null
    {
        $document = $this->find_handler(strtoupper(trim($tax_code)));
        if (is_wp_error($document) || $document === null) {
            return $document;
        }
        $fields = [];
        foreach (($document['fields'] ?? []) as $name => $field) {
            $fields[$name] = self::firestore_scalar((array) $field);
        }
        return [
            'id' => basename((string) ($document['name'] ?? '')),
            'firstName' => sanitize_text_field((string) ($fields['firstName'] ?? '')),
            'lastName' => sanitize_text_field((string) ($fields['lastName'] ?? '')),
            'email' => sanitize_email((string) ($fields['email'] ?? '')),
            'taxCode' => strtoupper(sanitize_text_field((string) ($fields['taxCode'] ?? $tax_code))),
            'medicalCertificateExpiry' => sanitize_text_field((string) ($fields['medicalCertificateExpiry'] ?? '')),
        ];
    }

    private function update_handler_fields(string $handler_id, array $fields): array|WP_Error
    {
        $project = $this->project_id();
        if (is_wp_error($project)) {
            return $project;
        }
        $masks = implode('', array_map(static fn(string $name): string => '&updateMask.fieldPaths=' . rawurlencode($name), array_keys($fields)));
        $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project)
            . '/databases/(default)/documents/handlers/' . rawurlencode($handler_id) . '?' . ltrim($masks, '&');
        return $this->request('PATCH', $url, ['fields' => $fields], 'firebase');
    }

    public function update_handler_consents(string $tax_code, array $data): array|WP_Error
    {
        $handler = $this->find_handler_by_tax_code($tax_code);
        if (is_wp_error($handler)) {
            return $handler;
        }
        if ($handler === null || $handler['id'] === '') {
            return new WP_Error('acl_handler_not_found', 'Socio non trovato in Firestore.');
        }
        return $this->update_handler_fields($handler['id'], [
            'privacy' => self::firestore_value($data['consensoPrivacy'], 'boolean'),
            'social' => self::firestore_value($data['consensoSocial'], 'boolean'),
            'newsletter' => self::firestore_value($data['consensoNewsletter'], 'boolean'),
            'regolamento' => self::firestore_value($data['consensoRegolamento'], 'boolean'),
            'updatedAt' => self::firestore_value(gmdate('Y-m-d\TH:i:s\Z'), 'timestamp'),
        ]);
    }

    public function update_medical_certificate(string $handler_id, string $expiry_date, string $storage_path): array|WP_Error
    {
        return $this->update_handler_fields($handler_id, [
            'medicalCertificateExpiry' => self::firestore_value($expiry_date . 'T12:00:00Z', 'timestamp'),
            'medicalCertificatePath' => self::firestore_value($storage_path),
            'updatedAt' => self::firestore_value(gmdate('Y-m-d\TH:i:s\Z'), 'timestamp'),
        ]);
    }

    public function update_certificate_expiry_sheet(string $tax_code, string $expiry_date): array|WP_Error
    {
        $result = $this->get_values(ACL_Forms_Config::registration_sheet_id(), 'Soci!A2:AP');
        if (is_wp_error($result)) {
            return $result;
        }
        foreach (($result['values'] ?? []) as $index => $row) {
            if (strtoupper(trim((string) ($row[10] ?? ''))) === strtoupper($tax_code)) {
                $parts = explode('-', $expiry_date);
                $formatted = count($parts) === 3 ? $parts[2] . '/' . $parts[1] . '/' . $parts[0] : $expiry_date;
                return $this->update_values(ACL_Forms_Config::registration_sheet_id(), 'Soci!AP' . ($index + 2), [[$formatted]]);
            }
        }
        return new WP_Error('acl_sheet_member_not_found', 'Socio non trovato nel foglio Soci.');
    }
}
