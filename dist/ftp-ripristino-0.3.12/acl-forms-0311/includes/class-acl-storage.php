<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Forms_Storage
{
    public static function activate(): void
    {
        $directory = self::base_directory();
        if (is_wp_error($directory)) {
            wp_die(esc_html($directory->get_error_message()));
        }
    }

    public static function base_directory(): string|WP_Error
    {
        $configured = ACL_Forms_Config::value('ACL_FORMS_PRIVATE_DIR');
        $candidates = $configured !== ''
            ? [$configured]
            : [dirname(rtrim(ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'acl-private', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'acl-private'];

        foreach ($candidates as $candidate) {
            $path = wp_normalize_path($candidate);
            if ((is_dir($path) || wp_mkdir_p($path)) && is_writable($path)) {
                self::protect($path);
                return $path;
            }
        }
        return new WP_Error('acl_storage_unavailable', 'Impossibile creare la directory privata ACL Forms.');
    }

    private static function protect(string $directory): void
    {
        $files = [
            '.htaccess' => "Require all denied\nDeny from all\n",
            'web.config' => "<?xml version=\"1.0\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>",
            'index.php' => "<?php\nhttp_response_code(404);\nexit;\n",
        ];
        foreach ($files as $name => $contents) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $contents, LOCK_EX);
            }
        }
    }

    public static function save_pdf(string $area, string $identifier, string $base64): array|WP_Error
    {
        if (strlen($base64) > 10 * 1024 * 1024) {
            return new WP_Error('acl_pdf_too_large', 'Il PDF supera la dimensione consentita.');
        }
        $bytes = base64_decode($base64, true);
        return self::save_pdf_bytes($area, $identifier, $bytes === false ? '' : $bytes);
    }

    public static function save_pdf_bytes(string $area, string $identifier, string $bytes): array|WP_Error
    {
        if ($bytes === false || strlen($bytes) < 100 || !str_starts_with($bytes, '%PDF-')) {
            return new WP_Error('acl_invalid_pdf', 'Il documento PDF non è valido.');
        }

        $base = self::base_directory();
        if (is_wp_error($base)) {
            return $base;
        }
        $directory = $base . DIRECTORY_SEPARATOR . sanitize_key($area) . DIRECTORY_SEPARATOR . gmdate('Y') . DIRECTORY_SEPARATOR . gmdate('m');
        if (!wp_mkdir_p($directory)) {
            return new WP_Error('acl_storage_failed', 'Impossibile creare la directory di archivio.');
        }
        self::protect($directory);
        $filename = sanitize_file_name($identifier) . '.pdf';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            return new WP_Error('acl_storage_failed', 'Impossibile archiviare il PDF.');
        }
        @chmod($path, 0640);
        return ['path' => $path, 'hash' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }

    public static function save_audit(string $pdf_path, array $audit): string|WP_Error
    {
        $path = preg_replace('/\.pdf$/i', '.json', $pdf_path);
        $json = wp_json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!$path || $json === false || file_put_contents($path, $json, LOCK_EX) === false) {
            return new WP_Error('acl_audit_failed', 'Impossibile salvare il registro della pratica.');
        }
        @chmod($path, 0640);
        return $path;
    }
}
