<?php
/**
 * Hauptklasse des Plugins
 * 
 * Singleton. Steuert die Boot-Sequenz, registriert Services und Hooks.
 *
 * @package PraxisPortal\Core
 * @since   4.0.0
 */

namespace PraxisPortal\Core;

use PraxisPortal\Security\Encryption;
use PraxisPortal\Security\KeyManager;
use PraxisPortal\Security\Sanitizer;
use PraxisPortal\Security\RateLimiter;
use PraxisPortal\Location\LocationManager;
use PraxisPortal\Location\LocationResolver;
use PraxisPortal\Location\LocationContext;
use PraxisPortal\Location\ServiceManager;
use PraxisPortal\License\LicenseManager;
use PraxisPortal\License\LicenseClient;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\Database\Schema;
use PraxisPortal\Database\Migration;
use PraxisPortal\Admin\AdminSetupWizard;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Repository\ServiceRepository;
use PraxisPortal\Database\Repository\PortalUserRepository;
use PraxisPortal\Database\Repository\ApiKeyRepository;
use PraxisPortal\Database\Repository\MedicationRepository;
use PraxisPortal\Database\Repository\FormRepository;
use PraxisPortal\Database\Repository\FormLocationRepository;
use PraxisPortal\Database\Repository\DocumentRepository;
use PraxisPortal\Database\Repository\IcdRepository;
use PraxisPortal\Form\FormLoader;
use PraxisPortal\I18n\I18n;
use PraxisPortal\Update\Updater;
use PraxisPortal\Widget\WidgetHandler;
use PraxisPortal\Api\PvsApi;
use PraxisPortal\Portal\PortalAuth;
use PraxisPortal\Export\GdtExport;
use PraxisPortal\Export\FhirExport;
use PraxisPortal\Export\Hl7Export;
use PraxisPortal\Export\Pdf\PdfAnamnese;
use PraxisPortal\Export\Pdf\PdfWidget;
use PraxisPortal\Privacy\PrivacyHandler;
use PraxisPortal\Core\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private static ?self $instance = null;
    private Container $container;
    
    /**
     * Gibt die Plugin-Instanz zurück (Singleton)
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Gibt den Service-Container zurück
     */
    public static function container(): Container
    {
        return self::getInstance()->container;
    }
    
    /**
     * Shortcut: Service aus dem Container holen
     * 
     * @template T
     * @param class-string<T> $service
     * @return T
     */
    public static function make(string $service): object
    {
        return self::container()->get($service);
    }
    
    // =========================================================================
    // BOOT-SEQUENZ
    // =========================================================================
    
    private function __construct()
    {
        $this->container = Container::getInstance();
        
        $this->registerServices();
        $this->registerHooks();
    }
    
    /**
     * Registriert alle Services im Container
     * 
     * Reihenfolge ist wichtig:
     * 1. Sicherheit (Encryption, KeyManager)
     * 2. Datenbank
     * 3. Lizenz
     * 4. Standorte
     * 5. Formulare, Export, Widget, Portal
     */
    private function registerServices(): void
    {
        $c = $this->container;
        
        // --- Sicherheit ---
        $c->register(KeyManager::class, fn() => new KeyManager());
        
        $c->register(Encryption::class, fn(Container $c) => 
            new Encryption($c->get(KeyManager::class))
        );
        
        $c->register(Sanitizer::class, fn() => new Sanitizer());
        $c->register(RateLimiter::class, fn() => new RateLimiter());
        
        // --- I18n ---
        $c->register(I18n::class, fn() => new I18n());
        
        // --- Datenbank ---
        $c->register(Schema::class, fn() => new Schema());

        // --- Migration (nutzt $GLOBALS['wpdb'] intern) ---
        $c->register(Migration::class, fn() => new Migration($GLOBALS['wpdb']));
        
        // --- Repositories (alle Singletons, nutzen $GLOBALS['wpdb'] intern) ---
        $c->register(SubmissionRepository::class, fn(Container $c) => new SubmissionRepository($c->get(Encryption::class)));
        $c->register(FileRepository::class, fn(Container $c) => new FileRepository($c->get(Encryption::class)));
        $c->register(AuditRepository::class, fn(Container $c) => new AuditRepository($c->get(Encryption::class)));
        $c->register(LocationRepository::class, fn() => new LocationRepository());
        $c->register(ServiceRepository::class, fn() => new ServiceRepository());
        $c->register(PortalUserRepository::class, fn() => new PortalUserRepository());
        $c->register(ApiKeyRepository::class, fn() => new ApiKeyRepository());
        $c->register(MedicationRepository::class, fn() => new MedicationRepository());
        $c->register(FormRepository::class, fn() => new FormRepository());
        $c->register(FormLocationRepository::class, fn() => new FormLocationRepository());
        $c->register(DocumentRepository::class, fn() => new DocumentRepository());
        $c->register(IcdRepository::class, fn() => new IcdRepository());
        $c->register(FormLoader::class, fn(Container $c) => new FormLoader($c->get(I18n::class)));
        
        // --- Lizenz ---
        $c->register(LicenseClient::class, fn() => new LicenseClient());
        
        $c->register(LicenseManager::class, fn(Container $c) => 
            new LicenseManager(
                $c->get(LicenseClient::class),
                $c->get(LocationRepository::class)
            )
        );
        
        $c->register(FeatureGate::class, fn(Container $c) => 
            new FeatureGate($c->get(LicenseManager::class))
        );
        
        // --- Plugin-Updater ---
        $c->register(Updater::class, fn(Container $c) => 
            new Updater(
                $c->get(LicenseClient::class),
                $c->get(LicenseManager::class)
            )
        );
        
        // --- Standorte ---
        $c->register(LocationManager::class, fn(Container $c) => 
            new LocationManager($c->get(Encryption::class))
        );
        
        $c->register(ServiceManager::class, fn() => new ServiceManager());
        
        $c->register(LocationResolver::class, fn(Container $c) => 
            new LocationResolver(
                $c->get(LocationManager::class),
                $c->get(ServiceManager::class)
            )
        );
        
        // --- Frontend-Komponenten ---
        $c->register(WidgetHandler::class, fn(Container $c) => 
            new WidgetHandler($c)
        );
        
        $c->register(PvsApi::class, fn(Container $c) => 
            new PvsApi($c->get(Encryption::class))
        );
        
        $c->register(PortalAuth::class, fn(Container $c) => 
            new PortalAuth(
                $c->get(Encryption::class),
                $c->get(RateLimiter::class),
                $c->get(PortalUserRepository::class),
                $c->get(AuditRepository::class)
            )
        );
        
        // --- Exporte (lazy, erst bei Bedarf instanziiert) ---
        $c->register(GdtExport::class, fn(Container $c) => 
            new GdtExport($c)
        );
        
        $c->register(FhirExport::class, fn(Container $c) => 
            new FhirExport($c)
        );
        
        $c->register(Hl7Export::class, fn(Container $c) => 
            new Hl7Export($c)
        );
        
        $c->register(PdfAnamnese::class, fn(Container $c) => 
            new PdfAnamnese($c)
        );
        
        $c->register(PdfWidget::class, fn(Container $c) => 
            new PdfWidget($c)
        );
        
        // --- String-Aliases (für Export-Klassen die String-Keys nutzen) ---
        $c->register('encryption', fn(Container $c) => $c->get(Encryption::class));
        
        // --- DSGVO Privacy Handler ---
        $c->register(PrivacyHandler::class, fn(Container $c) =>
            new PrivacyHandler(
                $c->get(SubmissionRepository::class),
                $c->get(PortalUserRepository::class),
                $c->get(AuditRepository::class),
                $c->get(FileRepository::class),
                $c->get(FeatureGate::class),
                $c->get(Encryption::class)
            )
        );
        
        $c->register('key_manager', fn(Container $c) => $c->get(KeyManager::class));
        $c->register('sanitizer', fn(Container $c) => $c->get(Sanitizer::class));
        $c->register('feature_gate', fn(Container $c) => $c->get(FeatureGate::class));
        $c->register('location_manager', fn(Container $c) => $c->get(LocationManager::class));
        $c->register('submission_repository', fn(Container $c) => $c->get(SubmissionRepository::class));
        $c->register('file_repository', fn(Container $c) => $c->get(FileRepository::class));
        $c->register('icd_repository', fn(Container $c) => $c->get(IcdRepository::class));
    }
    
    /**
     * Registriert WordPress-Hooks
     */
    private function registerHooks(): void
    {
        // Aktivierung / Deaktivierung
        register_activation_hook(PP_PLUGIN_FILE, [$this, 'onActivate']);
        register_deactivation_hook(PP_PLUGIN_FILE, [$this, 'onDeactivate']);
        
        // Plugin geladen
        add_action('plugins_loaded', [$this, 'onPluginsLoaded'], 5);
        
        // WordPress Init
        add_action('init', [$this, 'onInit']);
        
        // Admin
        if (is_admin()) {
            add_action('admin_init', [$this, 'onAdminInit']);
            add_action('admin_menu', [$this, 'onAdminMenu']);
            add_action('admin_enqueue_scripts', [$this, 'onAdminAssets']);
        }
        
        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'onFrontendAssets']);
        
        // Cron
        add_action('pp_daily_cleanup', [$this, 'onDailyCleanup']);
        add_action('pp_daily_license_check', [$this, 'onDailyLicenseCheck']);

        // ── Frontend + Admin AJAX-Endpoints (Hooks-Klasse) ──
        $hooks = new Hooks($this->container);
        $hooks->register();
    }
    
    // =========================================================================
    // HOOK-CALLBACKS
    // =========================================================================
    
    /**
     * Plugin-Aktivierung
     */
    public function onActivate(): void
    {
        // Tabellen erstellen
        $schema = $this->container->get(Schema::class);
        $schema->createTables();
        
        // Verschlüsselungsschlüssel generieren (falls nicht vorhanden)
        $keyManager = $this->container->get(KeyManager::class);
        $keyManager->ensureKeyExists();
        
        // Widget-Defaults setzen (nur wenn noch nicht gesetzt)
        if (get_option('pp_widget_status') === false) {
            update_option('pp_widget_status', 'active');
        }
        if (get_option('pp_widget_pages') === false) {
            update_option('pp_widget_pages', 'all');
        }
        
        // Cron-Jobs registrieren
        if (!wp_next_scheduled('pp_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'pp_daily_cleanup');
        }
        if (!wp_next_scheduled('pp_daily_license_check')) {
            wp_schedule_event(time(), 'daily', 'pp_daily_license_check');
        }
        
        // Version speichern
        update_option('pp_version', PP_VERSION);
        
        // Redirect nach Aktivierung setzen
        set_transient('pp_activation_redirect', true, 30);
        
        // Rewrite-Rules flushen
        flush_rewrite_rules();
    }
    
    /**
     * Plugin-Deaktivierung
     */
    public function onDeactivate(): void
    {
        wp_clear_scheduled_hook('pp_daily_cleanup');
        wp_clear_scheduled_hook('pp_daily_license_check');
        flush_rewrite_rules();
    }
    
    /**
     * Alle Plugins geladen – hier prüfen wir auf Updates
     */
    public function onPluginsLoaded(): void
    {
        // I18n initialisieren
        $this->container->get(I18n::class);
        
        // Plugin-Updater registrieren (Self-Hosted Updates)
        $this->container->get(Updater::class)->register();
        
        // DSGVO Privacy-Hooks registrieren
        $this->container->get(PrivacyHandler::class)->register();
        
        // Version prüfen → Migration ausführen
        $installed = get_option('pp_version', '0');
        if (version_compare($installed, PP_VERSION, '<')) {
            $this->runMigrations($installed);
            update_option('pp_version', PP_VERSION);
        }
    }
    
    /**
     * WordPress Init
     */
    public function onInit(): void
    {
        // LocationResolver: Aktuellen Standort ermitteln
        $resolver = $this->container->get(LocationResolver::class);
        $context  = $resolver->resolve();
        $this->container->set(LocationContext::class, $context);
        
        // Widget und Portal registrieren
        $this->initFrontendComponents();
    }
    
    /**
     * Admin Init – AJAX-Handler registrieren
     */
    public function onAdminInit(): void
    {
        // Admin-Klasse früh erstellen (vor admin_menu)
        $admin = new \PraxisPortal\Admin\Admin($this->container);
        $this->container->set(\PraxisPortal\Admin\Admin::class, $admin);
        
        // Settings API und Early Actions registrieren
        $admin->registerSettings();
        $admin->handleEarlyActions();
        
        // Admin-Bar Items
        add_action('admin_bar_menu', [$admin, 'addAdminBarItems'], 100);
        
        // Admin-Post Download-Handler
        add_action('admin_post_pp_download_log', [$admin, 'handleDownloadLog']);
        add_action('admin_post_pp_export_backup', [$admin, 'handleExportBackup']);

        // ── Admin-AJAX-Handler registrieren ──
        add_action('wp_ajax_pp_admin_action', [$admin, 'handleAjax']);
        
        // Setup-Wizard Redirect nach Erstaktivierung
        if (get_transient('pp_activation_redirect')) {
            delete_transient('pp_activation_redirect');
            if (!isset($_GET['activate-multi'])) {
                // Zum Wizard nur wenn Setup noch nicht abgeschlossen
                $target = AdminSetupWizard::isComplete()
                    ? admin_url('admin.php?page=praxis-portal')
                    : admin_url('admin.php?page=pp-setup');
                wp_safe_redirect($target);
                exit;
            }
        }

        // Ongoing check: redirect to setup wizard if setup not complete
        // Prevents fatal errors from missing database tables
        if (!AdminSetupWizard::isComplete()) {
            $page = sanitize_text_field($_GET['page'] ?? '');
            if ($page !== '' && $page !== 'pp-setup'
                && ($page === 'praxis-portal' || strpos($page, 'pp-') === 0)
            ) {
                wp_safe_redirect(admin_url('admin.php?page=pp-setup'));
                exit;
            }
        }
    }
    
    /**
     * Admin-Menü registrieren
     */
    public function onAdminMenu(): void
    {
        // Admin-Klasse aus Container holen (in onAdminInit erstellt)
        if (!$this->container->has(\PraxisPortal\Admin\Admin::class)) {
            // Sollte nicht vorkommen – onAdminInit läuft normalerweise vor admin_menu
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PP: Admin nicht im Container bei admin_menu – erstelle Instanz (unübliche Hook-Reihenfolge)');
            }
            $admin = new \PraxisPortal\Admin\Admin($this->container);
            $this->container->set(\PraxisPortal\Admin\Admin::class, $admin);
        }

        $admin = $this->container->get(\PraxisPortal\Admin\Admin::class);
        $admin->addAdminMenu();
    }
    
    /**
     * Admin-Assets laden
     */
    public function onAdminAssets(string $hook): void
    {
        // Nur auf Plugin-Seiten
        if (strpos($hook, 'pp-') === false && strpos($hook, 'praxis-portal') === false) {
            return;
        }

        // Admin::enqueueAssets() lädt alles: Basis-CSS/JS, ppAdmin-Objekt,
        // UND seiten-spezifische Assets (Medikamente, Form-Editor, etc.)
        if ($this->container->has(\PraxisPortal\Admin\Admin::class)) {
            $admin = $this->container->get(\PraxisPortal\Admin\Admin::class);
            $admin->enqueueAssets($hook);
        }
    }
    
    /**
     * Frontend-Assets laden
     */
    public function onFrontendAssets(): void
    {
        // Assets werden von Widget::enqueueAssets() geladen (nur wenn Widget aktiv)
        // Hier können später globale Frontend-Assets hinzugefügt werden
    }
    
    /**
     * Tägliche Bereinigung
     */
    public function onDailyCleanup(): void
    {
        // Alte Audit-Logs löschen (Standard: 365 Tage behalten)
        $retentionDays = (int) get_option('pp_audit_retention_days', 365);
        if ($retentionDays > 0) {
            $auditRepo = $this->container->get(AuditRepository::class);
            $auditRepo->deleteOlderThan($retentionDays);
        }

        // Verwaiste Dateien bereinigen (Submissions ohne Referenz)
        $fileRepo = $this->container->get(FileRepository::class);
        $fileRepo->cleanupOrphans();

        // Abgelaufene Rate-Limit-Einträge löschen
        $rateLimiter = $this->container->get(RateLimiter::class);
        $rateLimiter->cleanup();

        // Abgelaufene Portal-Session- und Login-Transients bereinigen
        // (verhindert wp_options-Bloat auf Shared Hosting ohne Object Cache)
        $this->cleanupExpiredTransients([
            'pp_portal_session_',
            'pp_file_token_',
            'pp_login_attempts_',
        ]);
    }

    /**
     * Bereinigt abgelaufene Transients mit bestimmten Prefixen.
     *
     * WordPress löscht abgelaufene Transients nicht automatisch —
     * auf Shared Hosting ohne Object Cache sammeln sie sich in wp_options.
     */
    private function cleanupExpiredTransients(array $prefixes): void
    {
        global $wpdb;
        $now = time();

        foreach ($prefixes as $prefix) {
            $expired = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like('_transient_timeout_' . $prefix) . '%',
                $now
            ));

            if (empty($expired)) {
                continue;
            }

            $transientNames = array_map(function ($timeout) {
                return str_replace('_transient_timeout_', '_transient_', $timeout);
            }, $expired);

            $allToDelete    = array_merge($expired, $transientNames);
            $placeholders   = implode(',', array_fill(0, count($allToDelete), '%s'));

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
                    $allToDelete
                )
            );
        }
    }
    
    /**
     * Tägliche Lizenz-Prüfung
     */
    public function onDailyLicenseCheck(): void
    {
        $license = $this->container->get(LicenseManager::class);
        $license->validateWithServer();
    }
    
    // =========================================================================
    // INTERNE METHODEN
    // =========================================================================
    
    /**
     * Widget und Portal initialisieren
     */
    private function initFrontendComponents(): void
    {
        $encryption = $this->container->get(Encryption::class);
        $context    = $this->container->get(LocationContext::class);
        
        // Widget registrieren (AJAX-Hooks müssen früh verfügbar sein)
        $widget = new \PraxisPortal\Widget\Widget($this->container);
        $widget->register(); // AJAX-Handler + Footer-Hook registrieren
        $this->container->set(\PraxisPortal\Widget\Widget::class, $widget);
        
        // Widget-Shortcodes
        add_shortcode('praxis_widget', [$widget, 'render']);
        add_shortcode('pp_anamnesebogen', [$widget, 'render']);
        add_shortcode('pp_widget', [$widget, 'render']);

        // Fragebogen-Shortcode (eigenständige Seite)
        $fragebogenShortcode = new \PraxisPortal\Form\FragebogenShortcode($this->container);
        add_shortcode('pp_fragebogen', [$fragebogenShortcode, 'render']);
        
        // Portal nur wenn aktiviert
        if (get_option('pp_portal_enabled', '0') === '1') {
            $portal = new \PraxisPortal\Portal\Portal($encryption, $context);
            $this->container->set(\PraxisPortal\Portal\Portal::class, $portal);
            
            // Portal-Shortcodes
            add_shortcode('praxis_portal', [$portal, 'render']);
            add_shortcode('pp_portal', [$portal, 'render']);
        }
    }
    
    /**
     * Migrations ausführen
     */
    private function runMigrations(string $fromVersion): void
    {
        // Schema aktualisieren
        $schema = $this->container->get(Schema::class);
        $schema->createTables(); // dbDelta ist idempotent
        
        // Versions-spezifische Migrationen
        if (version_compare($fromVersion, '4.0.0', '<')) {
            $this->migrateToV4();
        }
        
        // v4.1: Migration-Klasse ausführen (Medikamenten-Import, Default-Standort)
        $migration = $this->container->get(Migration::class);
        $migration->run();
    }
    
    /**
     * Migration von v3.x auf v4.0
     */
    private function migrateToV4(): void
    {
        // Einmalige DDL-Migration: $GLOBALS['wpdb'] ist hier akzeptabel
        $wpdb = $GLOBALS['wpdb'];
        $locations = $wpdb->prefix . 'pp_locations';
        
        // doc_key → license_key umbenennen (wenn alte Spalte existiert)
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$locations}", 0);
        
        if (in_array('doc_key', $columns, true) && !in_array('license_key', $columns, true)) {
            $wpdb->query("ALTER TABLE {$locations} CHANGE COLUMN `doc_key` `license_key` VARCHAR(25) DEFAULT NULL");
            error_log('PP v4.0 Migration: doc_key → license_key');
        }
        
        if (in_array('place_id', $columns, true) && !in_array('uuid', $columns, true)) {
            $wpdb->query("ALTER TABLE {$locations} CHANGE COLUMN `place_id` `uuid` VARCHAR(36) DEFAULT NULL");
            error_log('PP v4.0 Migration: place_id → uuid');
        }
        
        // v4.1: location_uuid → uuid
        if (in_array('location_uuid', $columns, true) && !in_array('uuid', $columns, true)) {
            $wpdb->query("ALTER TABLE {$locations} CHANGE COLUMN `location_uuid` `uuid` VARCHAR(36) DEFAULT NULL");
            error_log('PP v4.1 Migration: location_uuid → uuid');
        }
        
        // Schlüssel an sicheren Ort verschieben
        $keyManager = $this->container->get(KeyManager::class);
        $keyManager->migrateOldKeyFile();
    }
}
