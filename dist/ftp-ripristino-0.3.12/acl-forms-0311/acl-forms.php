<?php
/**
 * Plugin Name: ACL Moduli React
 * Description: Iscrizioni con firma elettronica e ricevute, PDF, email, Google Sheets e Firestore.
 * Version: 0.3.12
 * Author: A.S.D. Agility Club La Bora
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ACL_FORMS_VERSION', '0.3.12');
define('ACL_FORMS_DIR', plugin_dir_path(__FILE__));
define('ACL_FORMS_URL', plugin_dir_url(__FILE__));

require_once ACL_FORMS_DIR . 'includes/class-acl-config.php';
if (is_readable(ACL_FORMS_DIR . 'vendor/autoload.php')) {
    require_once ACL_FORMS_DIR . 'vendor/autoload.php';
}
require_once ACL_FORMS_DIR . 'includes/class-acl-pdf.php';
require_once ACL_FORMS_DIR . 'includes/class-acl-storage.php';
require_once ACL_FORMS_DIR . 'includes/class-acl-google.php';
require_once ACL_FORMS_DIR . 'includes/class-acl-mailer.php';
require_once ACL_FORMS_DIR . 'includes/class-acl-registration-controller.php';
require_once ACL_FORMS_DIR . 'includes/class-acl-receipts-controller.php';

final class ACL_Forms_Plugin
{
    private static bool $assets_enqueued = false;

    public static function init(): void
    {
        add_shortcode('acl_registration_form', [self::class, 'registration_shortcode']);
        add_shortcode('acl_receipt_form', [self::class, 'receipt_shortcode']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        (new ACL_Registration_Controller())->register_routes();
        (new ACL_Receipts_Controller())->register_routes();
    }

    private static function enqueue_assets(): void
    {
        if (self::$assets_enqueued) {
            return;
        }

        $script_name = 'app-' . ACL_FORMS_VERSION . '.js';
        $style_name = 'app-' . ACL_FORMS_VERSION . '.css';
        $script = ACL_FORMS_DIR . 'build/assets/' . $script_name;
        $style = ACL_FORMS_DIR . 'build/assets/' . $style_name;
        if (!file_exists($script)) {
            return;
        }

        wp_enqueue_script('acl-forms-' . ACL_FORMS_VERSION, ACL_FORMS_URL . 'build/assets/' . $script_name, [], ACL_FORMS_VERSION, true);
        if (file_exists($style)) {
            wp_enqueue_style('acl-forms-' . ACL_FORMS_VERSION, ACL_FORMS_URL . 'build/assets/' . $style_name, [], ACL_FORMS_VERSION);
        }

        wp_localize_script('acl-forms-' . ACL_FORMS_VERSION, 'ACLFormsConfig', [
            'pluginVersion' => ACL_FORMS_VERSION,
            'restUrl' => esc_url_raw(rest_url('acl-forms/v1')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'publicNonce' => wp_create_nonce('acl_forms_public'),
            'templates' => [
                'registration' => ACL_FORMS_URL . 'templates/iscrizione_2.pdf',
                'receipt' => ACL_FORMS_URL . 'templates/ricevuta.pdf',
            ],
        ]);
        self::$assets_enqueued = true;
    }

    public static function registration_shortcode(): string
    {
        self::enqueue_assets();
        if (!file_exists(ACL_FORMS_DIR . 'build/assets/app-' . ACL_FORMS_VERSION . '.js')) {
            return current_user_can('manage_options')
                ? '<p>ACL Forms: eseguire <code>npm install && npm run build</code> nella cartella del plugin.</p>'
                : '';
        }
        return '<div class="acl-forms-root" data-form="registration"></div>';
    }

    public static function receipt_shortcode(): string
    {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return '<p>Devi accedere come membro della segreteria per emettere ricevute.</p>';
        }
        self::enqueue_assets();
        return '<div class="acl-forms-root" data-form="receipt"></div>';
    }
}

register_activation_hook(__FILE__, [ACL_Forms_Storage::class, 'activate']);
ACL_Forms_Plugin::init();
