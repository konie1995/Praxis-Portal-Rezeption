<?php
/**
 * Hooks – Zentrale Hook-Registrierung
 *
 * Registriert alle WordPress-Hooks, die nicht direkt in
 * Plugin::registerHooks() stehen. Wird von Plugin::onInit() aufgerufen.
 *
 * Trennung nach Bereichen:
 *  - AJAX (Widget-Formulare, Medication-Suche, Portal)
 *  - REST-API (PVS-Endpunkte)
 *  - Shortcodes
 *  - Template-Redirects (Downloads, Portal-Seite)
 *  - Privacy (DSGVO-Export & -Löschung)
 *  - Admin (Notices, Sicherheits-Warnungen)
 *
 * @package PraxisPortal\Core
 * @since   4.0.0
 */

namespace PraxisPortal\Core;

if (!defined('ABSPATH')) {
    exit;
}

use PraxisPortal\Widget\WidgetHandler;
use PraxisPortal\Portal\PortalAuth;
use PraxisPortal\Api\PvsApi;

class Hooks
{
    /** @var Container */
    private Container $container;

    /** @var bool */
    private bool $registered = false;

    /**
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Alle Hooks registrieren
     *
     * Idempotent – kann mehrfach aufgerufen werden.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registerAjax();
        $this->registerRest();
        $this->registerShortcodes();
        $this->registerTemplateRedirects();
        // Privacy-Hooks → PrivacyHandler::register() (wird von Plugin.php aufgerufen)
        $this->registerAdminHooks();
        $this->registerInternalHooks();

        $this->registered = true;
    }

    /* =========================================================================
     * AJAX-HOOKS
     * ====================================================================== */

    private function registerAjax(): void
    {
        // ── Widget-AJAX (pp_submit_service_request, pp_widget_upload,
        //    pp_medication_search) → registriert in Widget.php
        // ── Admin-AJAX (pp_admin_action) → registriert in Admin.php

        // ── Widget-Services laden (API-Endpunkt) ──────────────
        add_action('wp_ajax_pp_load_services', [$this, 'handleLoadServices']);
        add_action('wp_ajax_nopriv_pp_load_services', [$this, 'handleLoadServices']);

        // ── Formular-Definition laden (API-Endpunkt) ──────────
        add_action('wp_ajax_pp_load_form', [$this, 'handleLoadForm']);
        add_action('wp_ajax_nopriv_pp_load_form', [$this, 'handleLoadForm']);

        // ── Portal-AJAX ────────────────────────────────────────
        add_action('wp_ajax_pp_portal_login', [$this, 'handlePortalLogin']);
        add_action('wp_ajax_nopriv_pp_portal_login', [$this, 'handlePortalLogin']);

        add_action('wp_ajax_pp_portal_action', [$this, 'handlePortalAction']);
        add_action('wp_ajax_nopriv_pp_portal_action', [$this, 'handlePortalAction']);
    }

    /* =========================================================================
     * REST-API
     * ====================================================================== */

    private function registerRest(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * REST-Routen für PVS-API registrieren
     */
    public function registerRestRoutes(): void
    {
        $namespace = 'praxis-portal/v1';

        // ── Anamnese-Endpunkte ─────────────────────────────────
        register_rest_route($namespace, '/submissions', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetSubmissions'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);

        register_rest_route($namespace, '/submissions/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetSubmission'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);

        register_rest_route($namespace, '/submissions/(?P<id>\d+)/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restUpdateStatus'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);

        // ── Export-Endpunkte ───────────────────────────────────
        register_rest_route($namespace, '/submissions/(?P<id>\d+)/gdt', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetGdt'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);

        register_rest_route($namespace, '/submissions/(?P<id>\d+)/fhir', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetFhir'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);

        register_rest_route($namespace, '/submissions/(?P<id>\d+)/pdf', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetPdf'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);

        register_rest_route($namespace, '/submissions/(?P<id>\d+)/files', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetFiles'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);

        // ── Widget-Endpunkte ───────────────────────────────────
        register_rest_route($namespace, '/widget/services', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetServices'],
            'permission_callback' => [$this, 'restRateLimitPublic'],
        ]);

        // ── Status / Health ────────────────────────────────────
        register_rest_route($namespace, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetStatus'],
            'permission_callback' => [$this, 'restCheckApiKey'],
        ]);
    }

    /* =========================================================================
     * SHORTCODES
     * ====================================================================== */

    private function registerShortcodes(): void
    {
        // Shortcodes werden direkt in Plugin.php registriert
        // (praxis_widget, pp_anamnesebogen, pp_widget → Widget::render)
        // (praxis_portal, pp_portal → Portal::render)
    }

    /* =========================================================================
     * TEMPLATE REDIRECTS
     * ====================================================================== */

    private function registerTemplateRedirects(): void
    {
        // Download-Handling für öffentliche Dokumente
        add_action('template_redirect', [$this, 'handleDocumentDownload']);

        // Portal-PDF-Druck-Seite
        add_action('template_redirect', [$this, 'handlePrintPage']);
    }

    /* =========================================================================
     * PRIVACY (DSGVO)
     * ====================================================================== */

    /**
     * Privacy-Hooks werden jetzt von PrivacyHandler::register() registriert.
     * Callback-Methoden registerPrivacyExporter/Eraser bleiben als Delegation erhalten.
     * @see \PraxisPortal\Privacy\PrivacyHandler::register()
     */
    private function registerPrivacy(): void
    {
        // Leerer Stub — PrivacyHandler registriert die Hooks direkt
    }

    /* =========================================================================
     * ADMIN-HOOKS
     * ====================================================================== */

    private function registerAdminHooks(): void
    {
        if (!is_admin()) {
            return;
        }

        // Sicherheitswarnungen im Admin
        add_action('admin_notices', [$this, 'showSecurityWarnings']);

        // Manuelle Bereinigung
        add_action('admin_post_pp_manual_cleanup', [$this, 'handleManualCleanup']);

        // Plugin-Action-Links → werden von Update/Updater.php registriert
        // (dort auch mit Rollback-Link)
    }

    /* =========================================================================
     * INTERNE HOOKS
     * ====================================================================== */

    private function registerInternalHooks(): void
    {
        // Wenn ein neuer Standort erstellt wird → Default-Services anlegen
        add_action('pp_location_created', [$this, 'onLocationCreated']);
    }

    /* =========================================================================
     * CALLBACK-DISPATCHER
     *
     * Alle Callbacks delegieren an die zuständigen Service-Klassen.
     * So bleibt Hooks.php schlank (nur Routing, keine Logik).
     * ====================================================================== */

    // ── AJAX ────────────────────────────────────────────────────

    public function handleFormSubmission(): void
    {
        $handler = $this->container->get(WidgetHandler::class);
        $handler->handleServiceRequest();
    }

    public function handleMedicationSearch(): void
    {
        $handler = $this->container->get(WidgetHandler::class);
        $handler->handleMedicationSearch();
    }

    public function handleLoadServices(): void
    {
        $handler = $this->container->get(WidgetHandler::class);
        $handler->handleLoadServices();
    }

    public function handleLoadForm(): void
    {
        $handler = $this->container->get(WidgetHandler::class);
        $handler->handleLoadForm();
    }

    public function handlePortalLogin(): void
    {
        if ($this->container->has(\PraxisPortal\Portal\Portal::class)) {
            $portal = $this->container->get(\PraxisPortal\Portal\Portal::class);
            $portal->handleLogin();
        } else {
            wp_send_json_error(['message' => 'Portal nicht aktiviert'], 403);
        }
    }

    public function handlePortalAction(): void
    {
        if ($this->container->has(\PraxisPortal\Portal\Portal::class)) {
            $portal = $this->container->get(\PraxisPortal\Portal\Portal::class);
            $portal->handleAction();
        } else {
            wp_send_json_error(['message' => 'Portal nicht aktiviert'], 403);
        }
    }

    public function handleAdminAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung'], 403);
            return;
        }

        // Nonce-Prüfung und Dispatch werden von Admin::handleAjax() übernommen
        // (verwendet pp_admin_nonce, konsistent mit admin.js)
        if ($this->container->has(\PraxisPortal\Admin\Admin::class)) {
            $admin = $this->container->get(\PraxisPortal\Admin\Admin::class);
            $admin->handleAjax();
        } else {
            wp_send_json_error(['message' => 'Admin nicht verfügbar'], 500);
        }
    }

    // ── REST-API ────────────────────────────────────────────────

    public function restCheckApiKey(\WP_REST_Request $request): bool
    {
        $apiKey = $request->get_header('X-API-Key')
            ?: $request->get_param('api_key')
            ?: '';

        if (empty($apiKey)) {
            return false;
        }

        $repo = $this->container->get(\PraxisPortal\Database\Repository\ApiKeyRepository::class);
        
        // IP ermitteln (Proxy-Header nur wenn konfiguriert)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $trustProxy = (defined('PP_TRUST_PROXY') && PP_TRUST_PROXY)
                    || get_option('pp_trust_proxy', '0') === '1';
        if ($trustProxy) {
            $forwarded = $request->get_header('X-Forwarded-For');
            if (!empty($forwarded)) {
                $ip = trim(explode(',', $forwarded)[0]);
            }
        }
        
        $result = $repo->validate($apiKey, sanitize_text_field($ip));

        if (is_wp_error($result)) {
            return false;
        }

        // Key-Daten für spätere Verwendung im Request speichern
        $request->set_param('_pp_api_key_data', $result);

        return true;
    }

    /**
     * Rate-Limiting für öffentliche REST-Endpunkte (30/min pro IP)
     */
    public function restRateLimitPublic(\WP_REST_Request $request): bool
    {
        $rateLimiter = $this->container->get(\PraxisPortal\Security\RateLimiter::class);
        $result = $rateLimiter->attempt('rest_public', 30, 60);
        return $result['allowed'];
    }

    public function restGetSubmissions(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getSubmissions', $request);
    }

    public function restGetSubmission(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getSubmission', $request);
    }

    public function restUpdateStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('updateStatus', $request);
    }

    public function restGetGdt(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getGdt', $request);
    }

    public function restGetFhir(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getFhir', $request);
    }

    public function restGetPdf(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getPdf', $request);
    }

    public function restGetFiles(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getFiles', $request);
    }

    public function restGetServices(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getServices', $request);
    }

    public function restGetStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->delegateToApi('getStatus', $request);
    }

    /**
     * Delegation an PvsApi-Klasse
     */
    private function delegateToApi(string $method, \WP_REST_Request $request): \WP_REST_Response
    {
        if (!$this->container->has(PvsApi::class)) {
            return new \WP_REST_Response(['error' => 'API nicht verfügbar'], 500);
        }

        $api = $this->container->get(PvsApi::class);

        if (!method_exists($api, $method)) {
            return new \WP_REST_Response(['error' => 'Methode nicht implementiert'], 501);
        }

        return $api->$method($request);
    }

    // ── Shortcodes ──────────────────────────────────────────────

    public function shortcodePortal(array $atts = []): string
    {
        if ($this->container->has(\PraxisPortal\Portal\Portal::class)) {
            $portal = $this->container->get(\PraxisPortal\Portal\Portal::class);
            return $portal->render($atts);
        }
        return '<!-- Praxis-Portal: Portal nicht aktiviert -->';
    }

    public function shortcodeWidget(array $atts = []): string
    {
        $widget = $this->container->get(\PraxisPortal\Widget\Widget::class);
        return $widget->render($atts);
    }

    // ── Template Redirects ──────────────────────────────────────

    public function handleDocumentDownload(): void
    {
        if (empty($_GET['pp_download'])) {
            return;
        }

        $docId = absint($_GET['pp_download']);
        $nonce = sanitize_text_field($_GET['pp_nonce'] ?? '');

        // Nonce prüfen
        if (!wp_verify_nonce($nonce, 'pp_download_' . $docId)) {
            wp_die(
                esc_html__('Ungültiger oder abgelaufener Download-Link.', 'praxis-portal'),
                403
            );
        }

        // Dokument laden (über Container, nicht direkt instanziieren)
        $docRepo = $this->container->get(\PraxisPortal\Database\Repository\DocumentRepository::class);
        $doc     = $docRepo->findActiveById($docId);

        if (!$doc || empty($doc['file_path'])) {
            wp_die(
                esc_html__('Dokument nicht gefunden.', 'praxis-portal'),
                404
            );
        }

        // Datei prüfen
        $filePath = $doc['file_path'];

        // Relativer Pfad → absolut auflösen
        if (!str_starts_with($filePath, '/') && !str_starts_with($filePath, ABSPATH)) {
            $uploadDir = wp_upload_dir();
            $filePath  = trailingslashit($uploadDir['basedir']) . $filePath;
        }

        // Boundary-Check: Pfad muss innerhalb des Upload-Verzeichnisses liegen (LFI-Schutz)
        $realPath   = realpath($filePath);
        $uploadBase = realpath(wp_upload_dir()['basedir']);

        if (!$realPath || !$uploadBase || !str_starts_with($realPath, $uploadBase)) {
            wp_die(
                esc_html__('Zugriff verweigert.', 'praxis-portal'),
                403
            );
        }
        $filePath = $realPath;

        if (!file_exists($filePath) || !is_readable($filePath)) {
            wp_die(
                esc_html__('Datei nicht mehr verfügbar.', 'praxis-portal'),
                404
            );
        }

        // Audit-Log (optional)
        if ($this->container->has(\PraxisPortal\Database\Repository\AuditRepository::class)) {
            $auditRepo = $this->container->get(\PraxisPortal\Database\Repository\AuditRepository::class);
            $auditRepo->logSubmission('document_downloaded', $docId, [
                'title'       => $doc['title'] ?? '',
                'location_id' => $doc['location_id'] ?? 0,
                'ip'          => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
        }

        // Datei ausliefern
        $mimeType = $doc['mime_type'] ?: 'application/octet-stream';
        $fileName = sanitize_file_name($doc['title'] ?? basename($filePath));

        // Extension aus Original-Datei anhängen, falls im Titel keine
        if (!pathinfo($fileName, PATHINFO_EXTENSION)) {
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            if ($ext) {
                $fileName .= '.' . $ext;
            }
        }

        nocache_headers();
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
    }

    public function handlePrintPage(): void
    {
        if (empty($_GET['pp_print'])) {
            return;
        }

        // PDF-Druck-Seite (wird in Export/Pdf/ implementiert)
    }

    // ── Privacy (DSGVO) ─────────────────────────────────────────

    public function registerPrivacyExporter(array $exporters): array
    {
        $exporters['praxis-portal'] = [
            'exporter_friendly_name' => 'Praxis-Portal',
            'callback'               => [$this, 'privacyExporterCallback'],
        ];
        return $exporters;
    }

    public function registerPrivacyEraser(array $erasers): array
    {
        $erasers['praxis-portal'] = [
            'eraser_friendly_name' => 'Praxis-Portal',
            'callback'             => [$this, 'privacyEraserCallback'],
        ];
        return $erasers;
    }

    public function privacyExporterCallback(string $email, int $page = 1): array
    {
        if ($this->container->has(\PraxisPortal\Privacy\PrivacyHandler::class)) {
            $handler = $this->container->get(\PraxisPortal\Privacy\PrivacyHandler::class);
            return $handler->exportPersonalData($email, $page);
        }
        return ['data' => [], 'done' => true];
    }

    public function privacyEraserCallback(string $email, int $page = 1): array
    {
        if ($this->container->has(\PraxisPortal\Privacy\PrivacyHandler::class)) {
            $handler = $this->container->get(\PraxisPortal\Privacy\PrivacyHandler::class);
            return $handler->erasePersonalData($email, $page);
        }
        return [
            'items_removed'  => 0,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }

    // ── Admin ───────────────────────────────────────────────────

    public function showSecurityWarnings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // SSL-Warnung
        if (!is_ssl() && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Praxis-Portal:</strong> ';
            echo esc_html__('Kein SSL/HTTPS erkannt. Patientendaten sollten nur über verschlüsselte Verbindungen übertragen werden.', 'praxis-portal');
            echo '</p></div>';
        }

        // Nginx-Warnung: .htaccess bietet keinen Schutz
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (stripos($serverSoftware, 'nginx') !== false) {
            $uploadDir = Config::getUploadDir();
            if (!empty($uploadDir) && strpos($uploadDir, ABSPATH) === 0) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>Praxis-Portal:</strong> ';
                echo esc_html__('Nginx-Server erkannt. .htaccess-Regeln bieten hier keinen Schutz. Bitte konfigurieren Sie Nginx so, dass der Zugriff auf das Upload-Verzeichnis blockiert wird, oder verschieben Sie den Key-Pfad außerhalb des Web-Roots (PP_ENCRYPTION_KEY_PATH).', 'praxis-portal');
                echo '</p></div>';
            }
        }
    }

    public function handleManualCleanup(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung');
        }

        check_admin_referer('pp_manual_cleanup');

        // Bereinigung durchführen
        do_action('pp_daily_cleanup');

        wp_safe_redirect(add_query_arg('pp_cleanup', 'done', wp_get_referer()));
        exit;
    }

    public function addPluginActionLinks(array $links): array
    {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=pp-einstellungen'),
            esc_html__('Einstellungen', 'praxis-portal')
        );

        array_unshift($links, $settings);
        return $links;
    }

    // ── Interne Hooks ───────────────────────────────────────────

    public function onLocationCreated(int $locationId): void
    {
        // Default-Services für neuen Standort erstellen
        $serviceRepo = $this->container->get(\PraxisPortal\Database\Repository\ServiceRepository::class);
        $serviceRepo->createDefaults($locationId);
    }
}
