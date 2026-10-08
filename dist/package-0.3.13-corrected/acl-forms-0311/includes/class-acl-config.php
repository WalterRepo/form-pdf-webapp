<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Forms_Config
{
    public static function value(string $constant, string $environment = '', string $default = ''): string
    {
        if (defined($constant)) {
            return trim((string) constant($constant));
        }
        if ($environment !== '') {
            $value = getenv($environment);
            if ($value !== false && trim($value) !== '') {
                return trim($value);
            }
        }
        return $default;
    }

    private static function account_from_path_or_json(string $json_constant, string $path_constant): array|WP_Error|null
    {
        $inline = self::value($json_constant);
        if ($inline !== '') {
            $decoded = json_decode($inline, true);
            return is_array($decoded) ? $decoded : new WP_Error('acl_bad_credentials', 'JSON account di servizio non valido.');
        }
        $path = self::value($path_constant);
        if ($path === '') {
            return null;
        }
        if (!is_readable($path)) {
            return new WP_Error('acl_missing_credentials', 'File account di servizio non leggibile.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : new WP_Error('acl_bad_credentials', 'File account di servizio non valido.');
    }

    public static function sheets_service_account(): array|WP_Error
    {
        $account = self::account_from_path_or_json('ACL_FORMS_SHEETS_SERVICE_ACCOUNT_JSON', 'ACL_FORMS_SHEETS_SERVICE_ACCOUNT_PATH');
        if ($account !== null) {
            return $account;
        }
        $email = self::value('ACL_FORMS_SHEETS_SERVICE_ACCOUNT_EMAIL', 'GOOGLE_SERVICE_ACCOUNT_EMAIL');
        $key = self::value('ACL_FORMS_SHEETS_PRIVATE_KEY', 'GOOGLE_PRIVATE_KEY');
        if ($email !== '' && $key !== '') {
            return [
                'client_email' => $email,
                'private_key' => str_replace('\\n', "\n", $key),
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ];
        }
        return new WP_Error('acl_missing_sheets_credentials', 'Account di servizio Google Sheets non configurato.');
    }

    public static function firebase_service_account(): array|WP_Error
    {
        $account = self::account_from_path_or_json('ACL_FORMS_FIREBASE_SERVICE_ACCOUNT_JSON', 'ACL_FORMS_FIREBASE_SERVICE_ACCOUNT_PATH');
        if ($account !== null) {
            return $account;
        }
        // Backward-compatible generic constants.
        $account = self::account_from_path_or_json('ACL_FORMS_GOOGLE_SERVICE_ACCOUNT_JSON', 'ACL_FORMS_GOOGLE_SERVICE_ACCOUNT_PATH');
        if ($account !== null) {
            return $account;
        }
        $development_path = dirname(ACL_FORMS_DIR) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'serviceAccountKey.json';
        if (is_readable($development_path)) {
            $decoded = json_decode((string) file_get_contents($development_path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return new WP_Error('acl_missing_firebase_credentials', 'Account di servizio Firebase non configurato.');
    }

    public static function registration_sheet_id(): string
    {
        return self::value('ACL_FORMS_REGISTRATION_SHEET_ID', 'GOOGLE_SHEETS_ID');
    }

    public static function receipts_sheet_id(): string
    {
        return self::value('ACL_FORMS_RECEIPTS_SHEET_ID', 'GOOGLE_SHEETS_RICEVUTE_ID');
    }

    public static function instructor_id(): string
    {
        if (defined('ACL_FORMS_INSTRUCTOR_ID')) {
            return trim((string) constant('ACL_FORMS_INSTRUCTOR_ID'));
        }
        $path = ACL_FORMS_DIR . 'config' . DIRECTORY_SEPARATOR . 'instructor.php';
        if (!is_readable($path)) {
            return '';
        }
        $id = require $path;
        return is_string($id) ? trim($id) : '';
    }

    public static function registration_admin_email(): string
    {
        return self::value('ACL_FORMS_REGISTRATION_ADMIN_EMAIL', 'EMAIL_ISCRIZIONI_ADMIN', (string) get_option('admin_email'));
    }

    private static function email_list(string $value): array
    {
        $emails = preg_split('/[,;\r\n]+/', $value) ?: [];
        $emails = array_map(static fn(string $email): string => sanitize_email(trim($email)), $emails);
        return array_values(array_unique(array_filter($emails, 'is_email')));
    }

    public static function registration_admin_emails(): array
    {
        return self::email_list(self::registration_admin_email());
    }

    public static function receipts_admin_email(): string
    {
        return self::value('ACL_FORMS_RECEIPTS_ADMIN_EMAIL', 'EMAIL_RICEVUTE_ADMIN', (string) get_option('admin_email'));
    }

    public static function receipts_admin_emails(): array
    {
        return self::email_list(self::receipts_admin_email());
    }

    public static function mail_from_email(): string
    {
        return sanitize_email(self::value('ACL_FORMS_FROM_EMAIL', 'EMAIL_FROM'));
    }

    public static function mail_from_name(): string
    {
        return sanitize_text_field(self::value('ACL_FORMS_FROM_NAME', '', 'Agility Club La Bora'));
    }

    public static function resend_api_key(): string
    {
        return self::value('ACL_FORMS_RESEND_API_KEY', 'RESEND_API_KEY');
    }
}
