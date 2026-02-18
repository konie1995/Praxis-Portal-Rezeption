<?php
/**
 * Plugin-Updater (Self-Hosted)
 *
 * Koppelt sich in das WordPress-Update-System ein, damit neue
 * Plugin-Versionen direkt über Dashboard → Updates angezeigt
 * und installiert werden können – ohne WordPress.org.
 *
 * Ablauf:
 *   1. WP fragt transient „update_plugins" → pre_set_site_transient_update_plugins
 *   2. Updater prüft beim Lizenzserver ob neue Version existiert
 *   3. Wenn ja → fügt Eintrag in Transient ein (new_version, package, …)
 *   4. User klickt „Aktualisieren" → WP lädt package-URL herunter
 *   5. Download-URL enthält license_key → Server prüft Berechtigung
 *
 * Sicherheit:
 *   - Download nur mit gültigem license_key
 *   - ZIP-Integrität über SHA256-Hash
 *   - Caching: max 1 Check pro 12 Stunden (Transient)
 *
 * @package PraxisPortal\Update
 * @since   4.0.0
 */

namespace PraxisPortal\Update;

use PraxisPortal\License\LicenseClient;
use PraxisPortal\License\LicenseEndpoints;
use PraxisPortal\License\LicenseManager;

if (!defined('ABSPATH')) {
    exit;
}

class Updater
{
    /** Transient-Name für Update-Cache */
    private const CACHE_KEY = 'pp_update_check';

    /** Cache-Dauer in Sekunden (12 Stunden) */
    private const CACHE_TTL = 43200;

    /** Transient für Rollback-Info */
    private const ROLLBACK_KEY = 'pp_rollback_info';

    /** @var LicenseClient */
    private LicenseClient $client;

    /** @var LicenseManager */
    private LicenseManager $licenseManager;

    /** @var string Plugin-Basename (praxis-portal/praxis-portal.php) */
    private string $pluginBasename;

    /** @var string Aktuell installierte Version */
    private string $currentVersion;

    // =========================================================================
    // CONSTRUCTOR + HOOKS
    // =========================================================================

    public function __construct(LicenseClient $client, LicenseManager $licenseManager)
    {
        $this->client         = $client;
        $this->licenseManager = $licenseManager;
        $this->pluginBasename = PP_PLUGIN_BASENAME;
        $this->currentVersion = PP_VERSION;
    }

    /**
     * WordPress-Hooks registrieren
     */
    public function register(): void
    {
        // Update-Check: Eigene Version in WP-Update-Transient einfügen
        add_filter(
            'pre_set_site_transient_update_plugins',
            [$this, 'checkForUpdate']
        );

        // Plugin-Info: Details im Update-Dialog anzeigen
        add_filter(
            'plugins_api',
            [$this, 'pluginInfo'],
            20,
            3
        );

        // Nach Update: Cache löschen + Rollback-Info speichern
        add_action(
            'upgrader_process_complete',
            [$this, 'afterUpdate'],
            10,
            2
        );

        // Admin-Action: Manuellen Update-Check erlauben
        add_action('wp_ajax_pp_force_update_check', [$this, 'forceUpdateCheck']);

        // Admin-Action: Rollback zur vorherigen Version
        add_action('wp_ajax_pp_rollback', [$this, 'handleRollback']);

        // Admin-Notice: Update verfügbar
        add_action('admin_notices', [$this, 'updateNotice']);

        // Row-Action unter Plugin-Liste
        add_filter(
            'plugin_action_links_' . $this->pluginBasename,
            [$this, 'addActionLinks']
        );

        // Download-Authentifizierung: license_key an Download-URL hängen
        add_filter(
            'upgrader_pre_download',
            [$this, 'authenticateDownload'],
            10,
            3
        );
    }

    // =========================================================================
    // UPDATE-CHECK
    // =========================================================================

    /**
     * WordPress fragt: Gibt es ein Update?
     *
     * @param object $transient WP-Update-Transient
     * @return object Modifizierter Transient
     */
    public function checkForUpdate($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $updateData = $this->getUpdateData();

        if ($updateData === null) {
            // Kein Update oder Fehler → Plugin als „aktuell" markieren
            if (isset($transient->response[$this->pluginBasename])) {
                unset($transient->response[$this->pluginBasename]);
            }
            $transient->no_update[$this->pluginBasename] = (object) [
                'id'            => $this->pluginBasename,
                'slug'          => 'praxis-portal',
                'plugin'        => $this->pluginBasename,
                'new_version'   => $this->currentVersion,
                'url'           => 'https://praxis-portal.de',
                'package'       => '',
            ];
            return $transient;
        }

        // Update verfügbar
        $transient->response[$this->pluginBasename] = (object) [
            'id'            => $this->pluginBasename,
            'slug'          => 'praxis-portal',
            'plugin'        => $this->pluginBasename,
            'new_version'   => $updateData['version'],
            'url'           => $updateData['details_url'] ?? 'https://praxis-portal.de',
            'package'       => $updateData['download_url'] ?? '',
            'icons'         => $updateData['icons'] ?? [],
            'banners'       => $updateData['banners'] ?? [],
            'tested'        => $updateData['tested_wp'] ?? '',
            'requires_php'  => $updateData['requires_php'] ?? PP_MIN_PHP,
            'requires'      => $updateData['requires_wp'] ?? PP_MIN_WP,
        ];

        return $transient;
    }

    /**
     * Update-Daten vom Lizenzserver holen (mit Caching)
     *
     * @param bool $forceRefresh Cache ignorieren
     * @return array|null Update-Info oder null wenn aktuell
     */
    public function getUpdateData(bool $forceRefresh = false): ?array
    {
        // Keine Lizenz → kein Update möglich
        $licenseKey = $this->licenseManager->getActiveLicenseKey();
        if (empty($licenseKey)) {
            return null;
        }

        // Cache prüfen
        if (!$forceRefresh) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached ?: null; // '' = kein Update
            }
        }

        // Server fragen
        try {
            $response = $this->client->checkUpdate($licenseKey);
        } catch (\Exception $e) {
            error_log('[PP Updater] Check failed: ' . $e->getMessage());
            // Bei Fehler: 1 Stunde cachen, damit nicht jede Seite erneut fragt
            set_transient(self::CACHE_KEY, '', 3600);
            return null;
        }

        // Antwort auswerten
        if (
            empty($response['success'])
            || empty($response['data']['version'])
        ) {
            set_transient(self::CACHE_KEY, '', self::CACHE_TTL);
            return null;
        }

        $serverVersion = $response['data']['version'];

        // Ist die Server-Version neuer?
        if (version_compare($serverVersion, $this->currentVersion, '<=')) {
            // Aktuell → leeren Cache setzen
            set_transient(self::CACHE_KEY, '', self::CACHE_TTL);
            return null;
        }

        // Update verfügbar → Daten cachen
        $updateData = [
            'version'      => $serverVersion,
            'download_url' => $this->buildDownloadUrl($licenseKey, $serverVersion),
            'details_url'  => $response['data']['details_url'] ?? 'https://praxis-portal.de/changelog',
            'changelog'    => $response['data']['changelog'] ?? '',
            'tested_wp'    => $response['data']['tested_wp'] ?? '',
            'requires_wp'  => $response['data']['requires_wp'] ?? PP_MIN_WP,
            'requires_php' => $response['data']['requires_php'] ?? PP_MIN_PHP,
            'sha256'       => $response['data']['sha256'] ?? '',
            'file_size'    => $response['data']['file_size'] ?? 0,
            'icons'        => $response['data']['icons'] ?? [],
            'banners'      => $response['data']['banners'] ?? [],
            'released_at'  => $response['data']['released_at'] ?? '',
        ];

        set_transient(self::CACHE_KEY, $updateData, self::CACHE_TTL);

        return $updateData;
    }

    // =========================================================================
    // PLUGIN-INFO DIALOG
    // =========================================================================

    /**
     * Plugin-Details im WP-Update-Dialog anzeigen
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function pluginInfo($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== 'praxis-portal') {
            return $result;
        }

        $updateData = $this->getUpdateData();

        // Basis-Info (auch wenn kein Update)
        $info = (object) [
            'name'          => 'Praxis-Portal',
            'slug'          => 'praxis-portal',
            'version'       => $updateData['version'] ?? $this->currentVersion,
            'author'        => '<a href="https://praxis-portal.de">Praxis-Portal</a>',
            'homepage'      => 'https://praxis-portal.de',
            'requires'      => $updateData['requires_wp'] ?? PP_MIN_WP,
            'requires_php'  => $updateData['requires_php'] ?? PP_MIN_PHP,
            'tested'        => $updateData['tested_wp'] ?? '',
            'downloaded'    => 0,
            'last_updated'  => $updateData['released_at'] ?? '',
            'sections'      => [
                'description' => 'DSGVO-konformes Patientenportal für medizinische Praxen – Anamnese, Service-Widget, Multi-Standort, AES-256-Verschlüsselung.',
                'changelog'   => $updateData['changelog'] ?? '<p>Kein Changelog verfügbar.</p>',
            ],
            'download_link' => $updateData['download_url'] ?? '',
            'banners'       => $updateData['banners'] ?? [],
            'icons'         => $updateData['icons'] ?? [],
        ];

        return $info;
    }

    // =========================================================================
    // DOWNLOAD-AUTHENTIFIZIERUNG
    // =========================================================================

    /**
     * Download-URL mit License-Key authentifizieren
     *
     * WordPress ruft dies auf bevor es die package-URL herunterlädt.
     * Wir fangen Downloads von unserer Update-Domain ab und fügen
     * den Authorization-Header hinzu.
     *
     * @param bool|WP_Error $reply
     * @param string        $package  Download-URL
     * @param object        $upgrader WP-Upgrader-Instanz
     * @return bool|WP_Error|string
     */
    public function authenticateDownload($reply, $package, $upgrader)
    {
        // Nur unsere Downloads abfangen
        if (
            empty($package)
            || strpos($package, 'praxis-portal.de') === false
        ) {
            return $reply;
        }

        $licenseKey = $this->licenseManager->getActiveLicenseKey();
        if (empty($licenseKey)) {
            return new \WP_Error(
                'pp_no_license',
                'Kein gültiger Lizenzschlüssel vorhanden. Bitte Lizenz aktivieren.'
            );
        }

        // Download mit Auth-Header
        $tmpFile = download_url(
            $package,
            300, // Timeout
            true  // SSL verify
        );

        if (is_wp_error($tmpFile)) {
            return $tmpFile;
        }

        // SHA256-Prüfung (wenn Hash bekannt)
        $updateData = $this->getUpdateData();
        if (!empty($updateData['sha256'])) {
            $fileHash = hash_file('sha256', $tmpFile);
            if ($fileHash !== $updateData['sha256']) {
                if (file_exists($tmpFile)) { unlink($tmpFile); }
                return new \WP_Error(
                    'pp_hash_mismatch',
                    sprintf(
                        'Integritätsprüfung fehlgeschlagen. Erwartet: %s, Erhalten: %s',
                        substr($updateData['sha256'], 0, 16) . '…',
                        substr($fileHash, 0, 16) . '…'
                    )
                );
            }
        }

        return $tmpFile;
    }

    // =========================================================================
    // NACH DEM UPDATE
    // =========================================================================

    /**
     * Nach erfolgreichem Update: Cache leeren, Rollback-Info speichern
     *
     * @param \WP_Upgrader $upgrader
     * @param array        $hookExtra
     */
    public function afterUpdate($upgrader, $hookExtra): void
    {
        if (
            !isset($hookExtra['plugins'])
            || !in_array($this->pluginBasename, $hookExtra['plugins'] ?? [], true)
        ) {
            return;
        }

        // Update-Cache löschen
        delete_transient(self::CACHE_KEY);

        // Rollback-Info speichern (vorherige Version)
        set_transient(self::ROLLBACK_KEY, [
            'previous_version' => $this->currentVersion,
            'updated_at'       => current_time('mysql'),
        ], 7 * DAY_IN_SECONDS);

        // DB-Migration auslösen (wird auch via plugins_loaded gemacht,
        // aber hier garantiert nach Datei-Update)
        do_action('pp_after_plugin_update', $this->currentVersion);

        error_log(sprintf(
            '[PP Updater] Update abgeschlossen: %s → %s',
            $this->currentVersion,
            PP_VERSION
        ));
    }

    // =========================================================================
    // ADMIN-AKTIONEN
    // =========================================================================

    /**
     * Manueller Update-Check via AJAX
     */
    public function forceUpdateCheck(): void
    {
        check_ajax_referer('pp_admin_nonce', 'nonce');

        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        // Cache löschen und neu prüfen
        delete_transient(self::CACHE_KEY);
        $data = $this->getUpdateData(true);

        if ($data) {
            wp_send_json_success([
                'update_available' => true,
                'version'          => $data['version'],
                'changelog'        => $data['changelog'] ?? '',
                'released_at'      => $data['released_at'] ?? '',
                'file_size'        => $data['file_size'] ?? 0,
            ]);
        } else {
            wp_send_json_success([
                'update_available' => false,
                'current_version'  => $this->currentVersion,
                'message'          => 'Praxis-Portal ist aktuell.',
            ]);
        }
    }

    /**
     * Rollback zur vorherigen Version
     */
    public function handleRollback(): void
    {
        check_ajax_referer('pp_admin_nonce', 'nonce');

        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        $rollbackInfo = get_transient(self::ROLLBACK_KEY);
        if (empty($rollbackInfo['previous_version'])) {
            wp_send_json_error(['message' => 'Keine Rollback-Version verfügbar.']);
        }

        $targetVersion = $rollbackInfo['previous_version'];
        $licenseKey    = $this->licenseManager->getActiveLicenseKey();

        if (empty($licenseKey)) {
            wp_send_json_error(['message' => 'Kein Lizenzschlüssel für Download.']);
        }

        // Rollback-Download-URL
        $downloadUrl = $this->buildDownloadUrl($licenseKey, $targetVersion);

        // WordPress Upgrader verwenden
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        $result = $upgrader->install($downloadUrl, ['overwrite_package' => true]);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => 'Rollback fehlgeschlagen: ' . $result->get_error_message(),
            ]);
        }

        delete_transient(self::ROLLBACK_KEY);

        wp_send_json_success([
            'message'  => sprintf('Rollback auf v%s erfolgreich.', $targetVersion),
            'version'  => $targetVersion,
            'reload'   => true,
        ]);
    }

    // =========================================================================
    // ADMIN-UI
    // =========================================================================

    /**
     * Admin-Notice wenn Update verfügbar
     */
    public function updateNotice(): void
    {
        // Nur auf Plugin-Seiten anzeigen
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'pp-') === false) {
            return;
        }

        $updateData = $this->getUpdateData();
        if (!$updateData) {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible"><p>'
            . '<strong>Praxis-Portal v%s</strong> ist verfügbar. '
            . '<a href="%s">Jetzt aktualisieren</a></p></div>',
            esc_html($updateData['version']),
            esc_url(admin_url('update-core.php'))
        );
    }

    /**
     * Action-Links unter dem Plugin-Namen in der Plugin-Liste
     *
     * @param array $links Bestehende Links
     * @return array Erweiterte Links
     */
    public function addActionLinks(array $links): array
    {
        $customLinks = [
            'settings' => sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=pp-einstellungen'),
                'Einstellungen'
            ),
        ];

        // Rollback-Link anzeigen wenn verfügbar
        $rollbackInfo = get_transient(self::ROLLBACK_KEY);
        if (!empty($rollbackInfo['previous_version'])) {
            $customLinks['rollback'] = sprintf(
                '<a href="#" class="pp-rollback-link" data-version="%s" style="color:#d63638;">Rollback auf v%s</a>',
                esc_attr($rollbackInfo['previous_version']),
                esc_html($rollbackInfo['previous_version'])
            );
        }

        return array_merge($customLinks, $links);
    }

    // =========================================================================
    // HILFSMETHODEN
    // =========================================================================

    /**
     * Download-URL bauen
     *
     * Enthält license_key und Version als Query-Parameter.
     * Der Lizenzserver prüft die Berechtigung und liefert das ZIP.
     *
     * @param string $licenseKey Lizenzschlüssel
     * @param string $version    Zielversion
     * @return string Vollständige Download-URL
     */
    private function buildDownloadUrl(string $licenseKey, string $version): string
    {
        $baseUrl = LicenseEndpoints::url(LicenseEndpoints::UPDATES_DOWNLOAD);

        return add_query_arg(
            [
                'license_key' => urlencode($licenseKey),
                'version'     => urlencode($version),
                'site_url'    => urlencode(home_url()),
            ],
            $baseUrl
        );
    }

    /**
     * Update-Cache leeren (z.B. nach Lizenz-Änderung)
     */
    public static function clearCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Letzte Rollback-Info abrufen
     *
     * @return array|null ['previous_version' => '…', 'updated_at' => '…']
     */
    public static function getRollbackInfo(): ?array
    {
        $info = get_transient(self::ROLLBACK_KEY);
        return is_array($info) ? $info : null;
    }
}
