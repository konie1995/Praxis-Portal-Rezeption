<?php
/**
 * Admin – Zentraler Backend-Controller
 *
 * Registriert das WordPress-Admin-Menü, Settings-API, Assets und
 * delegiert Rendering + AJAX an spezialisierte Sub-Klassen.
 *
 * v4-Änderungen gegenüber v3:
 *  - DI via Container (keine manuellen new …)
 *  - Alle Options mit pp_ Prefix
 *  - Multi-Location durchgängig berücksichtigt
 *  - Lazy-Loading der Sub-Klassen (erst bei Bedarf)
 *  - PLACE-ID + Lizenzkey pro Standort
 *  - Einheitliche AJAX-Fehlerbehandlung
 *
 * Sub-Klassen:
 *  - AdminSubmissions  → Eingänge (Liste + Detail)
 *  - AdminLocations    → Standorte + Services + Portal-User
 *  - AdminSettings     → Einstellungs-Tabs
 *  - AdminAudit        → Audit-Log Viewer
 *  - AdminDsgvo        → DSGVO-Tools (Suche, Export, Löschung)
 *  - AdminSystem       → Health-Checks, Backup
 *  - AdminLicense      → Lizenz-Verwaltung + Admin-Bar
 *  - AdminFormEditor   → Formular-JSON-Editor
 *  - AdminMedications  → Medikamenten-Datenbank-Verwaltung
 *  - AdminIcd          → ICD-10-Code-Zuordnungen
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class Admin
{
    /* =====================================================================
     * KONSTANTEN
     * ================================================================== */

    /** Settings-Group für register_setting() */
    public const SETTINGS_GROUP = 'pp_settings';

    /** Capability für Admin-Zugriff */
    public const CAPABILITY = 'manage_options';

    /** Menu-Slug (Hauptmenü) */
    public const MENU_SLUG = 'praxis-portal';

    /* =====================================================================
     * DEPENDENCIES
     * ================================================================== */

    private Container $container;

    /** Lazy-loaded Sub-Klassen (werden erst bei render/ajax instanziiert) */
    private ?AdminSubmissions $submissions = null;
    private ?AdminLocations   $locations   = null;
    private ?AdminSettings    $settings    = null;
    private ?AdminAudit       $audit       = null;
    private ?AdminDsgvo       $dsgvo       = null;
    private ?AdminSystem      $system      = null;
    private ?AdminLicense     $license     = null;
    private ?AdminFormEditor  $formEditor  = null;
    private ?AdminMedications $medications = null;
    private ?AdminIcd        $icd         = null;

    /* =====================================================================
     * CONSTRUCTOR
     * ================================================================== */

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * i18n-Shortcut für Übersetzungen im Admin-Bereich.
     */
    private function t(string $text): string
    {
        return I18n::translate($text);
    }

    /* =====================================================================
     * PUBLIC: HOOK-REGISTRIERUNG (von Plugin::onAdminInit aufgerufen)
     * ================================================================== */

    /**
     * Alle Admin-Hooks registrieren.
     *
     * HINWEIS: Plugin.php ruft die Methoden (addAdminMenu, registerSettings,
     * handleEarlyActions, handleAjax, etc.) direkt auf — daher werden hier
     * KEINE add_action/add_filter mehr registriert um Duplikate zu vermeiden.
     *
     * @see \PraxisPortal\Core\Plugin::onAdminInit()
     * @see \PraxisPortal\Core\Plugin::onAdminMenu()
     */
    public function register(): void
    {
        // Alle Hooks werden von Plugin.php gesteuert.
    }

    /* =====================================================================
     * ADMIN-MENÜ
     * ================================================================== */

    /**
     * WordPress-Admin-Menü mit allen Unterseiten registrieren
     */
    public function addAdminMenu(): void
    {
        // ── Hauptmenü ─────────────────────────────────────────
        add_menu_page(
            'Praxis-Portal',
            'Praxis-Portal',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderSubmissionsPage'],
            'dashicons-clipboard',
            30
        );

        // ── Untermenüs ────────────────────────────────────────

        // 📥 Eingänge
        add_submenu_page(
            self::MENU_SLUG,
            'Eingänge',
            '📥 Eingänge',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderSubmissionsPage'],
            10
        );

        // 📍 Standorte
        add_submenu_page(
            self::MENU_SLUG,
            'Standorte',
            '📍 Standorte',
            self::CAPABILITY,
            'pp-standorte',
            [$this, 'renderLocationsPage'],
            15
        );

        // 📍 Standort bearbeiten (versteckt)
        add_submenu_page(
            'options.php',
            'Standort bearbeiten',
            'Standort bearbeiten',
            self::CAPABILITY,
            'pp-location-edit',
            [$this, 'renderLocationEditPage']
        );

        // 💊 Medikamente
        add_submenu_page(
            self::MENU_SLUG,
            'Medikamenten-Datenbank',
            '💊 Medikamente',
            self::CAPABILITY,
            'pp-medications',
            [$this, 'renderMedicationsPage'],
            20
        );

        // 📝 Fragebögen
        add_submenu_page(
            self::MENU_SLUG,
            'Fragebögen',
            '📝 Fragebögen',
            self::CAPABILITY,
            'pp-forms',
            [$this, 'renderFormsPage'],
            28
        );

        // 📝 Formular-Editor (versteckt)
        add_submenu_page(
            'options.php',
            'Formular-Editor',
            'Formular-Editor',
            self::CAPABILITY,
            'pp-form-editor',
            [$this, 'renderFormEditorPage']
        );

        // 🏥 ICD-Zuordnungen
        add_submenu_page(
            self::MENU_SLUG,
            'ICD-10 Zuordnungen',
            '🏥 ICD-Codes',
            self::CAPABILITY,
            'pp-icd',
            [$this, 'renderIcdPage'],
            29
        );

        // 🔒 DSGVO
        add_submenu_page(
            self::MENU_SLUG,
            'DSGVO / Patientenrechte',
            '🔒 DSGVO',
            self::CAPABILITY,
            'pp-dsgvo',
            [$this, 'renderDsgvoPage'],
            60
        );

        // 📜 Audit-Log
        add_submenu_page(
            self::MENU_SLUG,
            'Audit-Log',
            '📜 Audit-Log',
            self::CAPABILITY,
            'pp-audit',
            [$this, 'renderAuditPage'],
            70
        );

        // ⚙️ Einstellungen
        add_submenu_page(
            self::MENU_SLUG,
            'Einstellungen',
            '⚙️ Einstellungen',
            self::CAPABILITY,
            'pp-einstellungen',
            [$this, 'renderSettingsPage'],
            80
        );

        // 🔧 System-Status
        add_submenu_page(
            self::MENU_SLUG,
            'System-Status',
            '🔧 System-Status',
            self::CAPABILITY,
            'pp-system',
            [$this, 'renderSystemPage'],
            90
        );

        // 🔑 Lizenz
        add_submenu_page(
            self::MENU_SLUG,
            'Lizenz & Abonnement',
            '🔑 Lizenz',
            self::CAPABILITY,
            'pp-license',
            [$this, 'renderLicensePage'],
            95
        );

        // 🚀 Setup-Wizard (versteckt)
        add_submenu_page(
            'options.php',
            'Praxis-Portal Einrichtung',
            'Einrichtung',
            self::CAPABILITY,
            'pp-setup',
            [$this, 'renderSetupPage']
        );
    }

    /* =====================================================================
     * SETTINGS API
     * ================================================================== */

    /**
     * Alle Plugin-Einstellungen registrieren (pp_ Prefix)
     */
    public function registerSettings(): void
    {
        $group = self::SETTINGS_GROUP;

        // ── Allgemein ─────────────────────────────────────────
        register_setting($group, 'pp_retention_days', [
            'type'              => 'integer',
            'default'           => 90,
            'sanitize_callback' => fn($v) => max(7, min(365, (int) $v)),
        ]);

        // ── Portal ────────────────────────────────────────────
        register_setting($group, 'pp_portal_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);
        register_setting($group, 'pp_session_timeout', [
            'type'              => 'integer',
            'default'           => 60,
            'sanitize_callback' => fn($v) => in_array((int) $v, [15, 30, 60, 120, 240], true) ? (int) $v : 60,
        ]);
        register_setting($group, 'pp_cookie_samesite', [
            'type'              => 'string',
            'default'           => 'Lax',
            'sanitize_callback' => fn($v) => in_array($v, ['Strict', 'Lax'], true) ? $v : 'Lax',
        ]);

        // ── Widget ────────────────────────────────────────────
        register_setting($group, 'pp_widget_status', [
            'type'              => 'string',
            'default'           => 'active',
            'sanitize_callback' => fn($v) => in_array($v, ['active', 'vacation', 'disabled'], true) ? $v : 'active',
        ]);
        register_setting($group, 'pp_widget_disabled_message', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'wp_kses_post',
        ]);
        register_setting($group, 'pp_vacation_from', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting($group, 'pp_vacation_until', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // ── E-Mail ────────────────────────────────────────────
        register_setting($group, 'pp_notification_email', [
            'type'              => 'string',
            'default'           => get_option('admin_email'),
            'sanitize_callback' => 'sanitize_email',
        ]);
        register_setting($group, 'pp_email_enabled', [
            'type'    => 'boolean',
            'default' => true,
        ]);
        register_setting($group, 'pp_email_subject_template', [
            'type'              => 'string',
            'default'           => 'Neue Anfrage: {type}',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // ── API ───────────────────────────────────────────────
        register_setting($group, 'pp_pvs_api_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);
        register_setting($group, 'pp_trust_proxy', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        // ── Export-Einstellungen ──────────────────────────────
        $exportFormats = ['pdf', 'gdt', 'gdt_image', 'hl7', 'fhir'];
        $pdfTypes      = ['full', 'stammdaten'];

        // Widget-Export
        register_setting($group, 'pp_export_widget_format', [
            'type'              => 'string',
            'default'           => 'pdf',
            'sanitize_callback' => fn($v) => in_array($v, $exportFormats, true) ? $v : 'pdf',
        ]);
        register_setting($group, 'pp_export_widget_delete_after', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        // Anamnese Kasse
        register_setting($group, 'pp_export_anamnese_kasse_pdf_type', [
            'type'              => 'string',
            'default'           => 'stammdaten',
            'sanitize_callback' => fn($v) => in_array($v, $pdfTypes, true) ? $v : 'stammdaten',
        ]);
        register_setting($group, 'pp_export_anamnese_kasse_format', [
            'type'              => 'string',
            'default'           => 'pdf',
            'sanitize_callback' => fn($v) => in_array($v, $exportFormats, true) ? $v : 'pdf',
        ]);
        register_setting($group, 'pp_export_anamnese_kasse_delete_after', [
            'type'    => 'boolean',
            'default' => true,
        ]);

        // Anamnese Privat
        register_setting($group, 'pp_export_anamnese_privat_pdf_type', [
            'type'              => 'string',
            'default'           => 'full',
            'sanitize_callback' => fn($v) => in_array($v, $pdfTypes, true) ? $v : 'full',
        ]);
        register_setting($group, 'pp_export_anamnese_privat_format', [
            'type'              => 'string',
            'default'           => 'pdf',
            'sanitize_callback' => fn($v) => in_array($v, $exportFormats, true) ? $v : 'pdf',
        ]);
        register_setting($group, 'pp_export_anamnese_privat_delete_after', [
            'type'    => 'boolean',
            'default' => true,
        ]);

        // PVS-Archiv
        register_setting($group, 'pp_pvs_archive_gdt_path', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting($group, 'pp_pvs_archive_image_path', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting($group, 'pp_pvs_archive_sender_id', [
            'type'              => 'string',
            'default'           => 'PRAXPORTAL',
            'sanitize_callback' => fn($v) => strtoupper(substr(sanitize_text_field($v), 0, 10)),
        ]);
        register_setting($group, 'pp_pvs_archive_receiver_id', [
            'type'              => 'string',
            'default'           => 'PRAX_EDV',
            'sanitize_callback' => fn($v) => strtoupper(substr(sanitize_text_field($v), 0, 10)),
        ]);

        // Anamnesebogen-URL
        register_setting($group, 'pp_anamnesebogen_url', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
        ]);

        // ── Sicherheit ────────────────────────────────────────
        register_setting($group, 'pp_rate_limit_submissions', [
            'type'              => 'integer',
            'default'           => 10,
            'sanitize_callback' => fn($v) => max(1, min(100, (int) $v)),
        ]);
    }

    /* =====================================================================
     * RENDER DELEGATES
     *
     * Jede Seite delegiert an die zuständige Sub-Klasse.
     * Sub-Klassen werden Lazy-Loaded.
     * ================================================================== */

    public function renderSubmissionsPage(): void
    {
        $this->getSubmissions()->renderPage();
    }

    public function renderLocationsPage(): void
    {
        $this->getLocations()->renderListPage();
    }

    public function renderLocationEditPage(): void
    {
        $this->getLocations()->renderEditPage();
    }

    public function renderMedicationsPage(): void
    {
        $this->getMedications()->renderPage();
    }

    public function renderIcdPage(): void
    {
        $this->getIcd()->renderPage();
    }

    public function renderFormsPage(): void
    {
        $this->getFormEditor()->renderListPage();
    }

    public function renderFormEditorPage(): void
    {
        $this->getFormEditor()->renderEditorPage();
    }

    public function renderDsgvoPage(): void
    {
        $this->getDsgvo()->renderPage();
    }

    public function renderAuditPage(): void
    {
        $this->getAudit()->renderPage();
    }

    public function renderSettingsPage(): void
    {
        $this->getSettings()->renderPage();
    }

    public function renderSystemPage(): void
    {
        $this->getSystem()->renderPage();
    }

    public function renderLicensePage(): void
    {
        $this->getLicense()->renderPage();
    }

    public function renderSetupPage(): void
    {
        $wizard = new AdminSetupWizard($this->container);
        $wizard->render();
    }

    /* =====================================================================
     * AJAX DISPATCHER
     * ================================================================== */

    /**
     * Zentraler AJAX-Handler: Dispatcht anhand 'sub_action'
     */
    public function handleAjax(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => $this->t('Keine Berechtigung.')], 403);
        }

        check_ajax_referer('pp_admin_nonce', 'nonce');

        $subAction = sanitize_text_field($_POST['sub_action'] ?? $_GET['sub_action'] ?? '');

        if ($subAction === '') {
            wp_send_json_error(['message' => $this->t('Keine Aktion angegeben.')], 400);
        }

        // ── Dispatch-Map ──────────────────────────────────────
        $dispatchers = [
            // Submissions
            'view_submission'   => fn() => $this->getSubmissions()->ajaxView(),
            'update_status'     => fn() => $this->getSubmissions()->ajaxUpdateStatus(),
            'update_brille_values' => fn() => $this->getSubmissions()->ajaxUpdateBrilleValues(),
            'delete_submission' => fn() => $this->getSubmissions()->ajaxDelete(),
            'download_file'     => fn() => $this->getSubmissions()->ajaxDownloadFile(),
            'export_csv'        => fn() => $this->getSubmissions()->ajaxExportCsv(),
            'export_bdt'        => fn() => $this->getSubmissions()->ajaxExportBdt(),
            'export_pdf'        => fn() => $this->getSubmissions()->ajaxExportPdf(),

            // Locations
            'save_location'       => fn() => $this->getLocations()->ajaxSave(),
            'delete_location'     => fn() => $this->getLocations()->ajaxDelete(),
            'toggle_service'      => fn() => $this->getLocations()->ajaxToggleService(),
            'add_custom_service'  => fn() => $this->getLocations()->ajaxAddService(),
            'edit_custom_service' => fn() => $this->getLocations()->ajaxEditService(),
            'delete_custom_service' => fn() => $this->getLocations()->ajaxDeleteService(),
            'delete_document'     => fn() => $this->getLocations()->ajaxDeleteDocument(),
            'save_termin_config'  => fn() => $this->getLocations()->ajaxSaveTerminConfig(),
            'save_notfall_config' => fn() => $this->getLocations()->ajaxSaveNotfallConfig(),
            'update_patient_restriction' => fn() => $this->getLocations()->ajaxUpdatePatientRestriction(),
            'save_portal_user'    => fn() => $this->getLocations()->ajaxSavePortalUser(),
            'delete_portal_user'  => fn() => $this->getLocations()->ajaxDeletePortalUser(),
            'refresh_license'     => fn() => $this->getLocations()->ajaxRefreshLicense(),
            'generate_api_key'    => fn() => $this->getLocations()->ajaxGenerateApiKey(),
            'revoke_api_key'      => fn() => $this->getLocations()->ajaxRevokeApiKey(),

            // System
            'send_test_email' => fn() => $this->getSystem()->ajaxTestEmail(),
            'run_cleanup'     => fn() => $this->getSystem()->ajaxRunCleanup(),
            'create_backup'   => fn() => $this->getSystem()->ajaxCreateBackup(),
            'delete_backup'   => fn() => $this->getSystem()->ajaxDeleteBackup(),
            'restore_backup'  => fn() => $this->getSystem()->ajaxRestoreBackup(),
            'reset_encryption' => fn() => $this->getSystem()->ajaxResetEncryption(),

            // DSGVO
            'search_patient'     => fn() => $this->getDsgvo()->ajaxSearch(),
            'export_patient'     => fn() => $this->getDsgvo()->ajaxExport(),
            'delete_patient_data' => fn() => $this->getDsgvo()->ajaxDelete(),

            // Form Editor
            'save_form'   => fn() => $this->getFormEditor()->ajaxSave(),
            'delete_form' => fn() => $this->getFormEditor()->ajaxDelete(),
            'clone_form'  => fn() => $this->getFormEditor()->ajaxClone(),
            'export_form' => fn() => $this->getFormEditor()->ajaxExport(),
            'import_form' => fn() => $this->getFormEditor()->ajaxImport(),
            'form_toggle_location' => fn() => $this->getFormEditor()->ajaxToggleLocation(),

            // License
            'save_license_key' => fn() => $this->getLicense()->ajaxSaveLicenseKey(),
            'activate_license' => fn() => $this->getLicense()->ajaxActivateLicense(),

            // Medications
            'medication_create'      => fn() => $this->getMedications()->ajaxMedicationCreate(),
            'medication_update'      => fn() => $this->getMedications()->ajaxMedicationUpdate(),
            'medication_delete'      => fn() => $this->getMedications()->ajaxMedicationDelete(),
            'medication_delete_all'  => fn() => $this->getMedications()->ajaxMedicationDeleteAll(),
            'medication_import_batch' => fn() => $this->getMedications()->ajaxMedicationImportBatch(),
            'medication_cleanup_comments' => fn() => $this->getMedications()->ajaxMedicationCleanupComments(),
            'medication_repair_broken'   => fn() => $this->getMedications()->ajaxMedicationRepairBroken(),
            'medication_delete_broken'   => fn() => $this->getMedications()->ajaxMedicationDeleteBroken(),
            'medication_import_standard' => fn() => $this->getMedications()->ajaxMedicationImportStandard(),

            // ICD-Zuordnungen
            'icd_save'   => fn() => $this->getIcd()->handleSave(),
            'icd_delete' => fn() => $this->getIcd()->handleDelete(),
            'icd_toggle' => fn() => $this->getIcd()->handleToggle(),
            'icd_seed'   => fn() => $this->getIcd()->handleSeed(),
            'icd_merge'  => fn() => $this->getIcd()->handleMerge(),
        ];

        if (!isset($dispatchers[$subAction])) {
            wp_send_json_error([
                'message' => $this->t('Unbekannte Aktion:') . ' ' . $subAction,
            ], 400);
        }

        // ── Lizenz-Check für Medikamenten-Aktionen (Zukunftssicherung) ──
        if (str_starts_with($subAction, 'medication_')) {
            $featureGate = $this->container->get(FeatureGate::class);
            if (!$featureGate->canManageMedications()) {
                wp_send_json_error([
                    'message' => $this->t('Diese Funktion ist für Ihren Plan nicht verfügbar.'),
                ], 403);
            }
        }

        // Dispatcher aufrufen
        ($dispatchers[$subAction])();

        // Falls der Handler kein wp_send_json_* aufgerufen hat
        wp_die();
    }

    /* =====================================================================
     * EARLY ACTIONS (vor HTML-Output)
     * ================================================================== */

    /**
     * Aktionen die vor dem HTML-Output passieren müssen
     * (z.B. Redirects, File-Downloads, Header-Modifikationen)
     */
    public function handleEarlyActions(): void
    {
        // Nur auf unseren Seiten
        $page = sanitize_text_field($_GET['page'] ?? '');

        // ── Setup-Wizard POST-Aktionen abfangen (vor HTML-Output) ──
        if ($page === 'pp-setup' && !empty($_POST['pp_wizard_action'])) {
            $wizard = new AdminSetupWizard($this->container);
            $wizard->handlePostAction();
            // handlePostAction() macht wp_safe_redirect + exit
            return;
        }

        if (strpos($page, 'pp-') !== 0) {
            return;
        }

        // DSGVO-Export (sendet JSON/CSV direkt)
        if ($page === 'pp-dsgvo' && !empty($_POST['pp_export_patient'])) {
            check_admin_referer('pp_dsgvo_action');
            $this->getDsgvo()->handleEarlyExport();
        }

        // Lizenz-Key speichern (Redirect danach)
        if ($page === 'pp-license' && !empty($_POST['pp_save_license'])) {
            check_admin_referer('pp_save_license');
            $this->getLicense()->handleSaveLicenseKey();
        }

        // Location speichern
        if ($page === 'pp-location-edit' && !empty($_POST['pp_save_location'])) {
            check_admin_referer('pp_save_location');
            $this->getLocations()->handleSaveLocation();
        }

        // Dokument hochladen
        if ($page === 'pp-location-edit' && !empty($_POST['pp_upload_doc'])) {
            $this->getLocations()->handleDocumentUpload();
        }
    }

    /* =====================================================================
     * ADMIN-BAR
     * ================================================================== */

    /**
     * Lizenzstatus in der Admin-Bar anzeigen
     */
    public function addAdminBarItems(\WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $this->getLicense()->renderAdminBarItem($adminBar);
    }

    /* =====================================================================
     * DOWNLOAD HANDLERS (admin-post.php)
     * ================================================================== */

    public function handleDownloadLog(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die($this->t('Keine Berechtigung'));
        }
        check_admin_referer('pp_download_log');
        $this->getSystem()->handleDownloadLog();
    }

    public function handleExportBackup(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die($this->t('Keine Berechtigung'));
        }
        check_admin_referer('pp_export_backup');
        $this->getSystem()->handleExportBackup();
    }

    /* =====================================================================
     * ASSETS
     * ================================================================== */

    /**
     * Admin-CSS/JS laden (aufgerufen von Plugin::onAdminAssets)
     */
    public function enqueueAssets(string $hook): void
    {
        // Nur auf Plugin-Seiten
        if (strpos($hook, 'pp-') === false && strpos($hook, 'praxis-portal') === false) {
            return;
        }

        wp_enqueue_style(
            'pp-admin',
            PP_PLUGIN_URL . 'admin/css/admin.css',
            [],
            PP_VERSION
        );

        wp_enqueue_script(
            'pp-admin',
            PP_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            PP_VERSION,
            true
        );

        wp_localize_script('pp-admin', 'ppAdmin', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('pp_admin_nonce'),
            'version'  => PP_VERSION,
            'i18n'     => [
                'confirm_delete'  => $this->t('Wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.'),
                'confirm_cleanup' => $this->t('Wirklich bereinigen? Gelöschte Daten werden endgültig entfernt.'),
                'saving'          => $this->t('Speichern…'),
                'saved'           => $this->t('Gespeichert'),
                'error'           => $this->t('Fehler aufgetreten'),
                'loading'         => $this->t('Laden…'),
            ],
        ]);

        // Seiten-spezifische Assets
        $page = sanitize_text_field($_GET['page'] ?? '');

        if ($page === 'pp-form-editor') {
            wp_enqueue_code_editor(['type' => 'application/json']);
            wp_enqueue_script('wp-theme-plugin-editor');
        }

        if ($page === 'pp-medications') {
            wp_enqueue_style(
                'pp-medications-admin',
                PP_PLUGIN_URL . 'admin/css/medications-admin.css',
                [],
                PP_VERSION
            );
            wp_enqueue_script(
                'pp-medications-admin',
                PP_PLUGIN_URL . 'admin/js/medications-admin.js',
                ['jquery', 'pp-admin'],
                PP_VERSION,
                true
            );
            wp_localize_script('pp-medications-admin', 'ppMedI18n', [
                'importing'              => $this->t('Importiere...'),
                'standard_imported'      => $this->t('Standard-Medikamente importiert'),
                'repair_confirm'         => $this->t('Fehlerhafte Einträge automatisch reparieren?'),
                'repaired'               => $this->t('Einträge repariert'),
                'delete_broken_confirm'  => $this->t('ALLE fehlerhaften Einträge löschen?'),
                'deleted'                => $this->t('Gelöscht'),
                'cleanup_confirm'        => $this->t('Kommentarzeilen löschen?'),
                'cleaned'                => $this->t('Bereinigt'),
                'seed_confirm'           => $this->t('Standard-Medikamente aus der mitgelieferten CSV importieren?'),
                'enter_name'             => $this->t('Bitte Bezeichnung eingeben.'),
                'added'                  => $this->t('Medikament hinzugefügt'),
                'delete_confirm'         => $this->t('Medikament wirklich löschen?'),
                'saved'                  => $this->t('Gespeichert'),
                'delete_all_confirm'     => $this->t('ALLE Medikamente löschen? Dies kann nicht rückgängig gemacht werden!'),
                'all_deleted'            => $this->t('Alle Medikamente gelöscht'),
                'select_csv'             => $this->t('Bitte eine CSV-Datei auswählen.'),
                'csv_empty'              => $this->t('CSV-Datei ist leer oder ungültig.'),
                'medications_imported'   => $this->t('Medikamente importiert'),
                'import_error'           => $this->t('Import-Fehler bei Batch'),
            ]);
        }
    }

    /* =====================================================================
     * PRIVATE: LAZY-LOADED SUB-KLASSEN
     * ================================================================== */

    private function getSubmissions(): AdminSubmissions
    {
        if ($this->submissions === null) {
            $this->submissions = new AdminSubmissions($this->container);
        }
        return $this->submissions;
    }

    private function getLocations(): AdminLocations
    {
        if ($this->locations === null) {
            $this->locations = new AdminLocations($this->container);
        }
        return $this->locations;
    }

    private function getSettings(): AdminSettings
    {
        if ($this->settings === null) {
            $this->settings = new AdminSettings($this->container);
        }
        return $this->settings;
    }

    private function getAudit(): AdminAudit
    {
        if ($this->audit === null) {
            $this->audit = new AdminAudit($this->container);
        }
        return $this->audit;
    }

    private function getDsgvo(): AdminDsgvo
    {
        if ($this->dsgvo === null) {
            $this->dsgvo = new AdminDsgvo($this->container);
        }
        return $this->dsgvo;
    }

    private function getSystem(): AdminSystem
    {
        if ($this->system === null) {
            $this->system = new AdminSystem($this->container);
        }
        return $this->system;
    }

    private function getLicense(): AdminLicense
    {
        if ($this->license === null) {
            $this->license = new AdminLicense($this->container);
        }
        return $this->license;
    }

    private function getFormEditor(): AdminFormEditor
    {
        if ($this->formEditor === null) {
            $this->formEditor = new AdminFormEditor($this->container);
        }
        return $this->formEditor;
    }

    private function getMedications(): AdminMedications
    {
        if ($this->medications === null) {
            $this->medications = new AdminMedications($this->container);
        }
        return $this->medications;
    }

    private function getIcd(): AdminIcd
    {
        if ($this->icd === null) {
            $this->icd = new AdminIcd($this->container);
        }
        return $this->icd;
    }
}
