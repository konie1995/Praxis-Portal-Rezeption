<?php
/**
 * Praxis-Portal – Vollständige Deinstallation
 *
 * Wird ausgeführt wenn das Plugin über WordPress gelöscht wird.
 *
 * Toggle: Einstellungen → Allgemein → „Daten bei Deinstallation behalten"
 *   ☑ aktiv   → pp_keep_data_on_uninstall = '1' → nur Crons entfernt
 *   ☐ inaktiv → ALLES wird gelöscht
 *
 * WARNUNG: Löscht ALLE Patientendaten unwiderruflich!
 *
 * @package PraxisPortal
 * @since   4.0.0
 * @updated 4.2.5
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// =====================================================================
// 1. CRON-JOBS ENTFERNEN (immer, auch bei „Daten behalten")
// =====================================================================
wp_clear_scheduled_hook('pp_daily_cleanup');
wp_clear_scheduled_hook('pp_daily_license_check');
wp_clear_scheduled_hook('pp_cleanup_temp_file');

// =====================================================================
// 2. TOGGLE: Daten behalten?
// =====================================================================
if (get_option('pp_keep_data_on_uninstall', '0') === '1') {
    return;
}

// =====================================================================
// AB HIER: Vollständige Bereinigung
// =====================================================================
global $wpdb;

// =====================================================================
// 3. DATENBANK-TABELLEN
// =====================================================================
$tables = [
    'pp_locations',
    'pp_services',
    'pp_submissions',
    'pp_files',
    'pp_audit_log',
    'pp_portal_users',
    'pp_api_keys',
    'pp_documents',
    'pp_license_cache',
    'pp_medications',
];

foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`");
}

// =====================================================================
// 4. OPTIONS – Alle bekannten Keys
// =====================================================================
$options = [
    // Core / Version / Setup
    'pp_version',
    'pp_db_version',
    'pp_setup_complete',

    // Widget
    'pp_widget_status',
    'pp_widget_position',
    'pp_widget_color',
    'pp_widget_pages',
    'pp_widget_disabled_message',
    'pp_widget_format',
    'pp_widget_delete_after',

    // Praxis-Daten
    'pp_praxis_name',
    'pp_praxis_telefon',
    'pp_praxis_email',
    'pp_praxis_anschrift',
    'pp_praxis_logo',
    'pp_practice_name',
    'pp_practice_phone',
    'pp_practice_email',
    'pp_practice_address',

    // URLs
    'pp_fragebogen_url',
    'pp_anamnesebogen_url',

    // E-Mail
    'pp_email_enabled',
    'pp_email_subject_template',
    'pp_notification_email',

    // Portal
    'pp_portal_enabled',
    'pp_portal_username',
    'pp_portal_password',
    'pp_portal_password_hash',
    'pp_portal_users',
    'pp_session_timeout',

    // Sicherheit
    'pp_rate_limit_submissions',
    'pp_trust_proxy',
    'pp_cookie_samesite',
    'pp_min_form_time',

    // Lizenz
    'pp_license_key',
    'pp_license_data',
    'pp_license_status',
    'pp_license_public_key',

    // Aufbewahrung / DSGVO
    'pp_retention_days',
    'pp_retention_years',
    'pp_audit_retention_days',

    // Urlaub
    'pp_vacation_from',
    'pp_vacation_until',

    // Anamnese-Export-Formate
    'pp_anamnese_kasse_format',
    'pp_anamnese_kasse_pdf_type',
    'pp_anamnese_kasse_delete_after',
    'pp_anamnese_privat_format',
    'pp_anamnese_privat_pdf_type',
    'pp_anamnese_privat_delete_after',

    // GDT / PVS
    'pp_gdt_path',
    'pp_sender_id',
    'pp_receiver_id',
    'pp_image_path',
    'pp_pvs_api_key',

    // Legacy
    'pp_custom_questions',

    // Deinstallation-Toggle (sich selbst aufräumen)
    'pp_keep_data_on_uninstall',
    'pp_delete_data_on_uninstall',
];

foreach ($options as $opt) {
    delete_option($opt);
}

// ── Dynamische Options (Wildcard) ──
$wildcards = [
    "pp\_form\_info\_%",
    "pp\_form\_config\_%",
    "pp\_custom\_fields\_%",
    "pp\_export\_%",
    "pp\_login\_attempts\_%",
    "pp\_rate\_%",
];

foreach ($wildcards as $pattern) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
}

// ── Transients ──
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pp\_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_pp\_%'");

// ── Sicherheitsnetz: Alle verbleibenden pp_* ──
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pp\_%'");

// =====================================================================
// 5. VERSCHLÜSSELUNGSSCHLÜSSEL
// =====================================================================
$home = getenv('HOME') ?: '';

$keyFiles = [
    ABSPATH . '../.pp-encryption-key',
    ABSPATH . '../.pp_encryption_key',
    WP_CONTENT_DIR . '/.pp_encryption_key',
    WP_CONTENT_DIR . '/uploads/pp-encrypted-files/.encryption_key',
];

if ($home !== '' && $home !== '/') {
    $keyFiles[] = $home . '/pp-portal/secure/.encryption_key';
    $keyFiles[] = $home . '/.pp_encryption_key';
}

foreach ($keyFiles as $kf) {
    if (!empty($kf) && file_exists($kf)) {
        $size = @filesize($kf);
        if ($size > 0 && $size < 1024) {
            @file_put_contents($kf, str_repeat("\0", $size));
        }
        @unlink($kf);
    }
}

// =====================================================================
// 6. DATEIEN & VERZEICHNISSE
// =====================================================================

/**
 * Verzeichnis rekursiv und sicher löschen.
 */
function pp_uninstall_rmdir(string $path): void
{
    $path = rtrim(realpath($path) ?: $path, '/');

    if (
        $path === '' ||
        $path === '/' ||
        $path === rtrim(ABSPATH, '/') ||
        $path === rtrim(WP_CONTENT_DIR, '/') ||
        strlen($path) < 10
    ) {
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $it    = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $f) {
        $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($path);
}

$securePaths = [
    dirname(ABSPATH) . '/pp-secure/',
    dirname(ABSPATH) . '/pp-encrypted-uploads/',
    WP_CONTENT_DIR . '/.pp-secure/',
];

if ($home !== '' && $home !== '/') {
    $securePaths[] = $home . '/pp-portal/';
}

foreach ($securePaths as $sp) {
    pp_uninstall_rmdir($sp);
}

$wpUpload = wp_upload_dir();
$basedir  = $wpUpload['basedir'] ?? '';

if ($basedir !== '' && strlen($basedir) > 10) {
    pp_uninstall_rmdir($basedir . '/pp-encrypted-files/');
    pp_uninstall_rmdir($basedir . '/pp-portal/');
}

// =====================================================================
// 7. USER-META
// =====================================================================
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'pp\_%'");
