<?php
/**
 * Plugin Name: Praxis-Portal
 * Plugin URI:  https://praxis-portal.de
 * Description: DSGVO-konformes Patientenportal für medizinische Praxen – Anamnese, Service-Widget, Multi-Standort
 * Version:     4.2.909
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * Author:      Praxis-Portal
 * License:     GPL v2 or later
 * Text Domain: praxis-portal
 */

// Direkten Zugriff verhindern
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// PLUGIN-KONSTANTEN
// ============================================================================
define('PP_VERSION',         '4.2.909');
define('PP_MIN_PHP',         '8.0');
define('PP_MIN_WP',          '5.8');
define('PP_PLUGIN_FILE',     __FILE__);
define('PP_PLUGIN_DIR',      plugin_dir_path(__FILE__));
define('PP_PLUGIN_URL',      plugin_dir_url(__FILE__));
define('PP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// PP4_-Aliase für Abwärtskompatibilität (v4.2.903)
if (!defined('PP4_VERSION'))    define('PP4_VERSION',    PP_VERSION);
if (!defined('PP4_PLUGIN_DIR')) define('PP4_PLUGIN_DIR', PP_PLUGIN_DIR);
if (!defined('PP4_PLUGIN_URL')) define('PP4_PLUGIN_URL', PP_PLUGIN_URL);

// ============================================================================
// PHP-VERSION PRÜFEN
// ============================================================================
if (version_compare(PHP_VERSION, PP_MIN_PHP, '<')) {
    add_action('admin_notices', function () {
        $message = sprintf(
            'Praxis-Portal benötigt mindestens PHP %s. Installiert: PHP %s.',
            PP_MIN_PHP,
            PHP_VERSION
        );
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    });
    return;
}

// ============================================================================
// AUTOLOADER
// ============================================================================
spl_autoload_register(function (string $class): void {
    $prefix = 'PraxisPortal\\';
    
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    
    // PraxisPortal\Core\Plugin → src/Core/Plugin.php
    $relative = substr($class, strlen($prefix));
    $file     = PP_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('PP Autoloader: Klasse nicht gefunden: ' . $class . ' (erwartet: ' . $file . ')');
    }
});

// ============================================================================
// PLUGIN STARTEN
// ============================================================================
use PraxisPortal\Core\Plugin;
use PraxisPortal\Core\Config;

// Sichere Pfade ermitteln (muss vor Plugin-Start geschehen)
Config::initSecurePaths();

// Plugin initialisieren
Plugin::getInstance();

// Diagnose-Tool (nur für Administratoren auf PP-Seiten)
if (is_admin() && file_exists(PP_PLUGIN_DIR . 'tests/pp-diagnostic.php')) {
    add_action('admin_init', function () {
        if (current_user_can('manage_options') && isset($_GET['page']) && str_starts_with($_GET['page'], 'pp-')) {
            require_once PP_PLUGIN_DIR . 'tests/pp-diagnostic.php';
        }
    });
}

// Integration-Test (nur für Administratoren)
if (is_admin() && file_exists(PP_PLUGIN_DIR . 'tests/pp-integration-test.php')) {
    add_action('admin_menu', function () {
        if (current_user_can('manage_options')) {
            require_once PP_PLUGIN_DIR . 'tests/pp-integration-test.php';
        }
    }, 998);
}
