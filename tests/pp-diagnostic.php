<?php
/**
 * Praxis-Portal v4.2.6 – Umfassende Diagnose & Testseite
 *
 * Registriert sich als Admin-Seite unter Praxis Portal → 🔍 Diagnose.
 * Testet: DB, Options, AJAX, Assets, Templates, Prefix-Konsistenz, Widget, Crons, ...
 *
 * INSTALLATION (LocalWP):
 *   Diese Datei liegt bereits im Plugin unter tests/pp-diagnostic.php
 *   und wird automatisch geladen. Aufruf über Admin → Praxis Portal → 🔍 Diagnose.
 *
 * @package PraxisPortal\Tests
 * @since   4.2.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class PP_Diagnostic
{
    /** @var array{pass:int, fail:int, warn:int, info:int} */
    private array $stats = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0];

    /** @var array<array{type:string, group:string, label:string, detail:string}> */
    private array $results = [];

    /** @var string Aktuell getestete Gruppe */
    private string $currentGroup = '';

    // =====================================================================
    // BOOTSTRAP
    // =====================================================================

    public static function boot(): void
    {
        $self = new self();
        add_action('admin_menu', [$self, 'registerMenu'], 999);
        add_action('wp_ajax_pp_run_diagnostic', [$self, 'ajaxRun']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'praxis-portal',
            'Diagnose',
            '🔍 Diagnose',
            'manage_options',
            'pp-diagnostic',
            [$this, 'renderPage'],
            999
        );
    }

    // =====================================================================
    // PAGE RENDER
    // =====================================================================

    public function renderPage(): void
    {
        $this->runAllTests();
        $this->renderHTML();
    }

    // =====================================================================
    // AJAX ENDPOINT
    // =====================================================================

    public function ajaxRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }

        $group = sanitize_text_field($_POST['group'] ?? 'all');

        switch ($group) {
            case 'ajax':
                $this->testAjaxHandlers();
                break;
            case 'db':
                $this->testDatabase();
                break;
            default:
                $this->runAllTests();
        }

        wp_send_json_success([
            'stats'   => $this->stats,
            'results' => $this->results,
        ]);
    }

    // =====================================================================
    // ALL TESTS
    // =====================================================================

    private function runAllTests(): void
    {
        $this->testPrefixConsistency();
        $this->testDatabase();
        $this->testOptions();
        $this->testCrons();
        $this->testAdminPages();
        $this->testAjaxHandlers();
        $this->testFrontendAjax();
        $this->testAssets();
        $this->testTemplates();
        $this->testForms();
        $this->testShortcodes();
        $this->testWidget();
        $this->testUninstaller();
        $this->testI18n();
        $this->testSecurity();
        $this->testLocalization();
        $this->testLogicCSS();
        $this->testLogicJS();
        $this->testLogicSQL();
        $this->testLogicHooks();
        $this->testLogicTemplates();
        $this->testLogicConstants();
    }

    // =====================================================================
    // 1. PREFIX-KONSISTENZ (pp4 → pp)
    // =====================================================================

    private function testPrefixConsistency(): void
    {
        $this->group('Prefix-Konsistenz (pp4 => pp)');
        $pluginDir = dirname(__DIR__);

        $extensions = ['php', 'js', 'css'];
        $pp4Found   = [];
        $selfFile   = str_replace('\\', '/', __FILE__); // Sich selbst ausschließen

        foreach ($extensions as $ext) {
            $files = $this->findFiles($pluginDir, $ext);
            foreach ($files as $file) {
                // Diagnose-Datei selbst ausschließen (enthält pp4 als Test-Strings)
                if (str_replace('\\', '/', $file) === $selfFile) {
                    continue;
                }
                $content = file_get_contents($file);
                if (preg_match_all('/pp4[_\-]/', $content, $m, PREG_OFFSET_CAPTURE)) {
                    $rel = str_replace($pluginDir . '/', '', $file);
                    foreach ($m[0] as $match) {
                        $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                        $pp4Found[] = "{$rel}:{$line} => {$match[0]}";
                    }
                }
            }
        }

        if (empty($pp4Found)) {
            $this->pass('Kein pp4_ oder pp4- im Code gefunden');
        } else {
            foreach ($pp4Found as $loc) {
                $this->fail("Alter Prefix gefunden: {$loc}");
            }
        }

        // Prüfe Options in DB
        global $wpdb;
        $oldOpts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'pp4\_%'"
        );
        if ((int) $oldOpts > 0) {
            $this->fail("{$oldOpts} alte pp4_* Options in wp_options gefunden (Migration nötig?)");
        } else {
            $this->pass('Keine pp4_* Options in Datenbank');
        }

        // Prüfe User-Meta
        $oldMeta = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'pp4\_%'"
        );
        if ((int) $oldMeta > 0) {
            $this->warn("{$oldMeta} alte pp4_* Einträge in usermeta");
        } else {
            $this->pass('Keine pp4_* Einträge in usermeta');
        }

        // Prüfe Transients
        $oldTransients = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%_transient_pp4\_%'"
        );
        if ((int) $oldTransients > 0) {
            $this->warn("{$oldTransients} alte pp4_* Transients gefunden");
        } else {
            $this->pass('Keine pp4_* Transients');
        }
    }

    // =====================================================================
    // 2. DATENBANK
    // =====================================================================

    private function testDatabase(): void
    {
        $this->group('Datenbank');
        global $wpdb;

        $tables = [
            // Spalten exakt aus Schema.php (Stand 4.2.6)
            'pp_locations'    => ['id','license_key','uuid','name','slug','practice_name','practice_owner','practice_subtitle','street','postal_code','city','phone','phone_emergency','email','website','opening_hours','logo_url','color_primary','color_secondary','widget_title','widget_subtitle','widget_welcome','widget_position','email_notification','email_from_name','email_from_address','email_signature','vacation_mode','vacation_message','vacation_start','vacation_end','termin_url','termin_button_text','privacy_url','imprint_url','consent_text','is_active','is_default','export_format','sort_order','created_at','updated_at'],
            'pp_services'     => ['id','location_id','service_key','service_type','label','description','icon','is_active','patient_restriction','external_url','open_in_new_tab','custom_fields','sort_order'],
            'pp_submissions'  => ['id','location_id','service_key','submission_hash','name_hash','encrypted_data','signature_data','ip_hash','user_agent_hash','consent_given','consent_timestamp','consent_version','consent_hash','request_type','status','response_text','response_sent_at','response_sent_by','created_at','updated_at','deleted_at'],
            'pp_files'        => ['id','submission_id','file_id','original_name_encrypted','mime_type','file_size','created_at'],
            'pp_audit_log'    => ['id','action','entity_type','entity_id','wp_user_id','portal_username','ip_hash','user_agent_hash','details_encrypted','created_at'],
            'pp_portal_users' => ['id','username','password_hash','display_name','email','location_id','can_view','can_edit','can_delete','can_export','is_active','last_login','created_at'],
            'pp_api_keys'     => ['id','location_id','api_key_hash','name','can_fetch_gdt','can_fetch_files','can_download_pdf','ip_whitelist','last_used_at','last_used_ip','use_count','is_active','created_at','created_by'],
            'pp_documents'    => ['id','location_id','title','description','file_path','mime_type','file_size','is_active','sort_order','created_at','updated_at'],
            'pp_license_cache'=> ['id','cache_key','cache_value','expires_at','created_at'],
            'pp_medications'  => ['id','name','dosage','form','pzn','created_at'],
        ];

        foreach ($tables as $table => $expectedCols) {
            $fullName = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$fullName}'");

            if (!$exists) {
                $this->fail("Tabelle {$table} fehlt");
                continue;
            }

            $this->pass("Tabelle {$table} existiert");

            // Spalten prüfen
            $actualCols = $wpdb->get_col("SHOW COLUMNS FROM `{$fullName}`", 0);
            $missing    = array_diff($expectedCols, $actualCols);
            $extra      = array_diff($actualCols, $expectedCols);

            if (!empty($missing)) {
                $this->fail("{$table}: Fehlende Spalten (" . count($missing) . "): " . implode(', ', $missing));
                // Detail: Was erwartet vs. was vorhanden
                $this->info("{$table} SOLL: " . implode(', ', $expectedCols));
                $this->info("{$table} IST:  " . implode(', ', $actualCols));
            }
            if (!empty($extra)) {
                $this->warn("{$table}: Unbekannte Spalten (" . count($extra) . "): " . implode(', ', $extra) . " — evtl. Schema.php aktualisieren");
            }
            if (empty($missing) && empty($extra)) {
                $this->pass("{$table}: Schema stimmt überein (" . count($actualCols) . " Spalten)");
            } elseif (empty($missing) && !empty($extra)) {
                $this->pass("{$table}: Alle erwarteten Spalten vorhanden (" . count($expectedCols) . " erwartet, " . count($actualCols) . " vorhanden)");
            }
        }

        // DB Version
        $dbVersion = get_option('pp_db_version', '');
        $schemaVersion = defined('PraxisPortal\Database\Schema::VERSION')
            ? \PraxisPortal\Database\Schema::VERSION
            : '?';
        if ($dbVersion === $schemaVersion) {
            $this->pass("DB-Version: {$dbVersion}");
        } else {
            $this->warn("DB-Version {$dbVersion} ≠ Schema {$schemaVersion}");
        }

        // Indices prüfen (medications)
        $medTable = $wpdb->prefix . 'pp_medications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$medTable}'")) {
            $indices = $wpdb->get_results("SHOW INDEX FROM `{$medTable}`", ARRAY_A);
            $indexNames = array_unique(array_column($indices, 'Key_name'));
            $this->info("Medications Indices: " . implode(', ', $indexNames));
        }

        // Broken medications
        $medTable = $wpdb->prefix . 'pp_medications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$medTable}'")) {
            $broken = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$medTable}` WHERE name LIKE '%,%' AND (dosage IS NULL OR dosage = '') AND name NOT LIKE '#%'"
            );
            $comments = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$medTable}` WHERE name LIKE '#%' OR name LIKE '=%'"
            );
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$medTable}`");

            $this->info("Medikamente: {$total} gesamt, {$broken} fehlerhaft, {$comments} Kommentarzeilen");

            if ($broken > 0) {
                $this->warn("{$broken} Medikamente scheinen defekte CSV-Daten im name-Feld zu haben");
            }
        }
    }

    // =====================================================================
    // 3. OPTIONS
    // =====================================================================

    private function testOptions(): void
    {
        $this->group('WordPress Options');

        // Kern-Options die gesetzt sein müssen
        $required = [
            'pp_version'       => 'Plugin-Version',
            'pp_widget_status' => 'Widget-Status',
        ];

        foreach ($required as $key => $label) {
            $val = get_option($key, '__NICHT_GESETZT__');
            if ($val === '__NICHT_GESETZT__') {
                $this->warn("{$label} ({$key}) nicht gesetzt");
            } else {
                $this->pass("{$label}: {$val}");
            }
        }

        // Setup
        $setup = get_option('pp_setup_complete', '0');
        if ($setup === '1') {
            $this->pass('Setup abgeschlossen (pp_setup_complete = 1)');
        } else {
            $this->info('Setup noch nicht abgeschlossen');
        }

        // Alle pp_* Options auflisten
        global $wpdb;
        $allOpts = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) as val_len
             FROM {$wpdb->options}
             WHERE option_name LIKE 'pp\_%'
             AND option_name NOT LIKE '_transient%'
             ORDER BY option_name",
            ARRAY_A
        );

        $this->info(count($allOpts) . " pp_* Options in Datenbank");

        // Deinstallation-Toggle prüfen
        $keepData = get_option('pp_keep_data_on_uninstall', '0');
        if ($keepData === '1') {
            $this->pass('Daten-behalten-Toggle: AKTIV ✓');
        } else {
            $this->info('Daten-behalten-Toggle: inaktiv (Deinstallation löscht alles)');
        }

        // Widget Pages
        $widgetPages = get_option('pp_widget_pages', 'all');
        $this->info("Widget-Seiten: {$widgetPages}");

        // Prüfe auf leere Pflicht-Options
        $shouldExist = [
            'pp_notification_email' => 'Benachrichtigungs-E-Mail',
        ];
        foreach ($shouldExist as $key => $label) {
            $val = get_option($key, '');
            if (empty($val)) {
                $this->info("{$label} ({$key}) ist leer");
            }
        }
    }

    // =====================================================================
    // 4. CRON-JOBS
    // =====================================================================

    private function testCrons(): void
    {
        $this->group('Cron-Jobs');

        $crons = [
            'pp_daily_cleanup'       => 'Tägliche Bereinigung',
            'pp_daily_license_check' => 'Tägliche Lizenzprüfung',
        ];

        foreach ($crons as $hook => $label) {
            $next = wp_next_scheduled($hook);
            if ($next) {
                $when = human_time_diff($next) . ($next > time() ? ' (in der Zukunft)' : ' (überfällig!)');
                $this->pass("{$label}: Nächste Ausführung in {$when}");
            } else {
                $this->warn("{$label} ({$hook}) nicht geplant");
            }
        }
    }

    // =====================================================================
    // 5. ADMIN-SEITEN
    // =====================================================================

    private function testAdminPages(): void
    {
        $this->group('Admin-Seiten');

        $pages = [
            'praxis-portal'  => 'Dashboard (Eingänge)',
            'pp-standorte'   => 'Standorte',
            'pp-medications' => 'Medikamente',
            'pp-forms'       => 'Fragebögen',
            'pp-dsgvo'       => 'DSGVO',
            'pp-audit'       => 'Audit-Log',
            'pp-einstellungen' => 'Einstellungen',
            'pp-system'      => 'System',
            'pp-license'     => 'Lizenz',
        ];

        // Hidden pages (unter options.php registriert, nicht im Menü)
        $hidden = [
            'pp-location-edit' => 'Standort bearbeiten',
            'pp-form-editor'   => 'Formular-Editor',
            'pp-setup'         => 'Setup-Wizard',
        ];

        global $submenu, $_registered_pages;

        foreach ($pages as $slug => $label) {
            $hookName = get_plugin_page_hookname($slug, '');
            if (isset($_registered_pages[$hookName]) || isset($_registered_pages["praxis-portal_page_{$slug}"])) {
                $this->pass("{$label} ({$slug}) registriert");
            } else {
                // Fallback: check in submenu
                $found = false;
                if (isset($submenu['praxis-portal'])) {
                    foreach ($submenu['praxis-portal'] as $item) {
                        if ($item[2] === $slug) {
                            $found = true;
                            break;
                        }
                    }
                }
                if ($found) {
                    $this->pass("{$label} ({$slug}) registriert (Submenu)");
                } else {
                    $this->fail("{$label} ({$slug}) nicht registriert!");
                }
            }
        }

        foreach ($hidden as $slug => $label) {
            // Hidden pages werden unter options.php registriert
            $hookKey = "options_page_{$slug}";
            if (isset($_registered_pages[$hookKey])) {
                $this->pass("{$label} ({$slug}) registriert (versteckt unter options.php)");
            } else {
                // Fallback: Globaler Check
                $found = false;
                foreach ($_registered_pages as $key => $val) {
                    if (strpos($key, $slug) !== false) {
                        $this->pass("{$label} ({$slug}) registriert als {$key}");
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->warn("{$label} ({$slug}) nicht als hidden page gefunden");
                }
            }
        }
    }

    // =====================================================================
    // 6. AJAX-HANDLER (Admin)
    // =====================================================================

    private function testAjaxHandlers(): void
    {
        $this->group('AJAX-Handler (Admin)');

        // Alle sub_actions die im JS aufgerufen werden
        $jsActions = [
            // Submissions
            'view_submission', 'update_status', 'delete_submission',
            // Locations
            'save_location', 'delete_location', 'toggle_service',
            'add_custom_service', 'edit_custom_service', 'delete_custom_service',
            'save_termin_config', 'save_portal_user', 'delete_portal_user',
            'refresh_license', 'generate_api_key', 'revoke_api_key',
            // System
            'send_test_email', 'run_cleanup', 'create_backup',
            'delete_backup', 'restore_backup', 'reset_encryption',
            // DSGVO
            'search_patient', 'export_patient', 'delete_patient_data',
            // Form Editor
            'save_form', 'delete_form', 'clone_form', 'export_form', 'import_form',
            // License
            'save_license_key', 'activate_license',
            // Medications
            'medication_create', 'medication_update', 'medication_delete',
            'medication_delete_all', 'medication_import_batch',
            'medication_cleanup_comments', 'medication_repair_broken', 'medication_delete_broken',
            'medication_import_standard',
        ];

        // Prüfe ob wp_ajax_pp_admin_action Hook registriert ist
        if (has_action('wp_ajax_pp_admin_action')) {
            $this->pass('wp_ajax_pp_admin_action Hook registriert');
        } else {
            $this->fail('wp_ajax_pp_admin_action Hook NICHT registriert!');
        }

        // Dispatch-Map prüfen (durch Source-Code Analyse)
        $adminFile = dirname(__DIR__) . '/src/Admin/Admin.php';
        if (file_exists($adminFile)) {
            $source = file_get_contents($adminFile);
            foreach ($jsActions as $action) {
                if (strpos($source, "'{$action}'") !== false) {
                    $this->pass("Handler: {$action}");
                } else {
                    $this->fail("Handler fehlt: {$action} — JS ruft auf, aber keine PHP-Zuordnung!");
                }
            }
        } else {
            $this->fail('Admin.php nicht gefunden!');
        }

        // JS-seitig: ppAjax Verfügbarkeit
        $adminJs = dirname(__DIR__) . '/admin/js/admin.js';
        if (file_exists($adminJs)) {
            $jsContent = file_get_contents($adminJs);
            if (strpos($jsContent, 'function ppAjax') !== false) {
                $this->pass('ppAjax() Funktion definiert');
            } else {
                $this->fail('ppAjax() Funktion FEHLT in admin.js!');
            }
            if (strpos($jsContent, 'function ppNotice') !== false) {
                $this->pass('ppNotice() Funktion definiert');
            } else {
                $this->fail('ppNotice() Funktion FEHLT in admin.js!');
            }
            if (strpos($jsContent, 'window.ppAjax') !== false) {
                $this->pass('ppAjax() global verfügbar (window.ppAjax)');
            } else {
                $this->fail('ppAjax() NICHT global verfügbar — medications-admin.js kann es nicht nutzen!');
            }
        }

        // Prüfe ob Nonce korrekt benannt ist
        $this->info('Admin-Nonce: pp_admin_nonce');
    }

    // =====================================================================
    // 7. FRONTEND-AJAX (Widget)
    // =====================================================================

    private function testFrontendAjax(): void
    {
        $this->group('Frontend AJAX (Widget)');

        $frontendHooks = [
            'wp_ajax_pp_submit_service_request'        => 'Service-Anfrage (eingeloggt)',
            'wp_ajax_nopriv_pp_submit_service_request'  => 'Service-Anfrage (Gast)',
            'wp_ajax_pp_widget_upload'                  => 'Datei-Upload (eingeloggt)',
            'wp_ajax_nopriv_pp_widget_upload'            => 'Datei-Upload (Gast)',
            'wp_ajax_pp_medication_search'              => 'Medikamenten-Suche (eingeloggt)',
            'wp_ajax_nopriv_pp_medication_search'        => 'Medikamenten-Suche (Gast)',
        ];

        foreach ($frontendHooks as $hook => $label) {
            if (has_action($hook)) {
                $this->pass("{$label}");
            } else {
                $this->fail("{$label} ({$hook}) nicht registriert!");
            }
        }

        // Hooks.php Dupletten-Check: sowohl Plugin.php als auch Hooks.php registrieren
        $hooksFile = dirname(__DIR__) . '/src/Core/Hooks.php';
        $pluginFile = dirname(__DIR__) . '/src/Core/Plugin.php';

        if (file_exists($hooksFile) && file_exists($pluginFile)) {
            $hooksSrc  = file_get_contents($hooksFile);
            $pluginSrc = file_get_contents($pluginFile);

            // Prüfe ob Shortcodes doppelt registriert werden
            $hookShortcodes = [];
            if (preg_match_all("/add_shortcode\s*\(\s*'([^']+)'/", $hooksSrc, $m)) {
                $hookShortcodes = $m[1];
            }
            $pluginShortcodes = [];
            if (preg_match_all("/add_shortcode\s*\(\s*'([^']+)'/", $pluginSrc, $m)) {
                $pluginShortcodes = $m[1];
            }

            $dupes = array_intersect($hookShortcodes, $pluginShortcodes);
            if (!empty($dupes)) {
                $this->warn('Shortcodes doppelt registriert (Hooks.php + Plugin.php): ' . implode(', ', $dupes));
            }

            // Prüfe ob AJAX doppelt registriert wird
            $hookAjax = [];
            if (preg_match_all("/add_action\s*\(\s*'wp_ajax[^']*pp_([^']+)'/", $hooksSrc, $m)) {
                $hookAjax = $m[1];
            }
            $widgetFile = dirname(__DIR__) . '/src/Widget/Widget.php';
            $widgetAjax = [];
            if (file_exists($widgetFile)) {
                $widgetSrc = file_get_contents($widgetFile);
                if (preg_match_all("/add_action\s*\(\s*'wp_ajax[^']*pp_([^']+)'/", $widgetSrc, $m)) {
                    $widgetAjax = $m[1];
                }
            }

            $ajaxDupes = array_intersect($hookAjax, $widgetAjax);
            if (!empty($ajaxDupes)) {
                $this->warn('AJAX-Hooks doppelt registriert (Hooks.php + Widget.php): ' . implode(', ', $ajaxDupes));
            }
        }

        // Nonce-Konsistenz
        $nonces = [
            'pp_widget_nonce'            => 'Widget-Nonce',
            'pp_widget_upload_nonce'     => 'Upload-Nonce',
            'pp_medication_search_nonce' => 'Medikamenten-Such-Nonce',
        ];

        $widgetJs = dirname(__DIR__) . '/assets/js/widget.js';
        if (file_exists($widgetJs)) {
            $jsContent = file_get_contents($widgetJs);
            foreach ($nonces as $nonce => $label) {
                if (strpos($jsContent, $nonce) !== false || strpos($jsContent, str_replace('_nonce', '', $nonce)) !== false) {
                    $this->pass("JS nutzt {$label}");
                } else {
                    $this->info("{$label} nicht direkt in widget.js referenziert (wird evtl. dynamisch übergeben)");
                }
            }
        }
    }

    // =====================================================================
    // 8. ASSETS (CSS/JS)
    // =====================================================================

    private function testAssets(): void
    {
        $this->group('Assets (CSS/JS)');

        $pluginDir = dirname(__DIR__);

        $files = [
            'admin/css/admin.css'              => 'Admin-CSS',
            'admin/css/medications-admin.css'   => 'Medikamenten-CSS',
            'admin/js/admin.js'                => 'Admin-JS',
            'admin/js/medications-admin.js'     => 'Medikamenten-JS',
            'assets/css/widget.css'            => 'Widget-CSS',
            'assets/js/widget.js'              => 'Widget-JS',
            'assets/css/portal.css'            => 'Portal-CSS',
            'assets/js/portal.js'              => 'Portal-JS',
        ];

        foreach ($files as $path => $label) {
            $full = $pluginDir . '/' . $path;
            if (file_exists($full)) {
                $size = filesize($full);
                if ($size < 100) {
                    $this->warn("{$label}: Existiert aber nur {$size} Bytes (leer?)");
                } else {
                    $this->pass("{$label}: " . $this->humanSize($size));
                }
            } else {
                $this->fail("{$label} ({$path}) fehlt!");
            }
        }

        // Prüfe ob CSS Variablen konsistent sind
        $medCss = file_get_contents($pluginDir . '/admin/css/medications-admin.css');
        if (strpos($medCss, '--pp-med-primary') !== false) {
            $this->pass('Medikamenten-CSS nutzt CSS-Variablen');
        } else {
            $this->warn('Medikamenten-CSS ohne CSS-Variablen');
        }

        // Prüfe wp_enqueue korrekte Handle-Namen
        $adminPhp = file_get_contents($pluginDir . '/src/Admin/Admin.php');
        $enqueues = [
            'pp-admin'              => 'Admin CSS/JS Handle',
            'pp-medications-admin'  => 'Medikamenten CSS/JS Handle',
        ];

        foreach ($enqueues as $handle => $label) {
            if (strpos($adminPhp, "'{$handle}'") !== false) {
                $this->pass("{$label} ({$handle}) registriert");
            } else {
                $this->fail("{$label} ({$handle}) NICHT in enqueueAssets!");
            }
        }

        // wp_localize_script Checks
        if (strpos($adminPhp, "wp_localize_script('pp-admin', 'ppAdmin'") !== false) {
            $this->pass("ppAdmin JS-Objekt wird via wp_localize_script übergeben");
        } else {
            $this->fail("wp_localize_script für ppAdmin FEHLT!");
        }

        // medications-admin.js Abhängigkeit von pp-admin
        if (preg_match("/pp-medications-admin.*\['jquery',\s*'pp-admin'\]/s", $adminPhp)) {
            $this->pass("medications-admin.js hat pp-admin als Abhängigkeit");
        } else {
            $this->warn("medications-admin.js Abhängigkeit von pp-admin prüfen");
        }
    }

    // =====================================================================
    // 9. TEMPLATES
    // =====================================================================

    private function testTemplates(): void
    {
        $this->group('Templates');

        $pluginDir = dirname(__DIR__);

        $templates = [
            'templates/widget/main.php'                  => 'Widget Main',
            'templates/widget/vacation.php'              => 'Urlaubsmodus',
            'templates/widget/steps/welcome.php'         => 'Step: Willkommen',
            'templates/widget/steps/services.php'        => 'Step: Services',
            'templates/widget/steps/location.php'        => 'Step: Standort',
            'templates/widget/forms/rezept.php'          => 'Form: Rezept',
            'templates/widget/forms/ueberweisung.php'    => 'Form: Überweisung',
            'templates/widget/forms/brillenverordnung.php' => 'Form: Brillenverordnung',
            'templates/widget/forms/dokument.php'        => 'Form: Dokument',
            'templates/widget/forms/termin.php'          => 'Form: Termin',
            'templates/widget/forms/terminabsage.php'    => 'Form: Terminabsage',
            'templates/widget/partials/success.php'      => 'Partial: Erfolg',
            'templates/portal.php'                       => 'Portal',
        ];

        foreach ($templates as $path => $label) {
            $full = $pluginDir . '/' . $path;
            if (file_exists($full)) {
                $content = file_get_contents($full);

                // Prüfe auf pp4 im Template
                if (preg_match('/pp4[_\-]/', $content)) {
                    $this->fail("{$label}: Enthält noch pp4-Referenzen!");
                } else {
                    $this->pass("{$label} vorhanden");
                }

                // Prüfe auf fehlende Variablen (häufiger Fehler)
                if (strpos($path, 'widget/') !== false && strpos($content, '$renderer') !== false) {
                    if (strpos($content, 'WidgetRenderer') === false && strpos($content, '@var') === false) {
                        $this->info("{$label}: Nutzt \$renderer aber kein @var Docblock");
                    }
                }
            } else {
                $this->fail("{$label} ({$path}) fehlt!");
            }
        }
    }

    // =====================================================================
    // 10. FORMULARE (JSON)
    // =====================================================================

    private function testForms(): void
    {
        $this->group('Formular-JSONs');

        $pluginDir = dirname(__DIR__);
        $formDir   = $pluginDir . '/forms';

        if (!is_dir($formDir)) {
            $this->fail('forms/ Verzeichnis fehlt!');
            return;
        }

        $files = glob($formDir . '/*.json');
        if (empty($files)) {
            $this->warn('Keine JSON-Formulare gefunden');
            return;
        }

        foreach ($files as $file) {
            $name    = basename($file);
            $content = file_get_contents($file);
            $data    = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->fail("{$name}: Ungültiges JSON! — " . json_last_error_msg());
                continue;
            }

            $fieldCount = 0;
            if (isset($data['sections'])) {
                foreach ($data['sections'] as $section) {
                    $fieldCount += count($section['fields'] ?? []);
                }
            } elseif (isset($data['fields'])) {
                $fieldCount = count($data['fields']);
            }

            $this->pass("{$name}: Gültiges JSON, {$fieldCount} Felder");
        }
    }

    // =====================================================================
    // 11. SHORTCODES
    // =====================================================================

    private function testShortcodes(): void
    {
        $this->group('Shortcodes');

        $shortcodes = [
            'praxis_widget'   => 'Widget-Shortcode',
            'praxis_portal'   => 'Portal-Shortcode',
            'pp_anamnesebogen' => 'Anamnesebogen-Shortcode',
            'pp_widget'       => 'Widget-Alias',
            'pp_portal'       => 'Portal-Alias',
        ];

        global $shortcode_tags;

        foreach ($shortcodes as $tag => $label) {
            if (isset($shortcode_tags[$tag])) {
                $callback = $shortcode_tags[$tag];
                $callbackDesc = is_array($callback)
                    ? get_class($callback[0]) . '::' . $callback[1]
                    : (is_string($callback) ? $callback : 'Closure');
                $this->pass("{$label} [{$tag}] => {$callbackDesc}");
            } else {
                $this->warn("{$label} [{$tag}] nicht registriert");
            }
        }
    }

    // =====================================================================
    // 12. WIDGET
    // =====================================================================

    private function testWidget(): void
    {
        $this->group('Widget');

        // Widget-Status
        $status = get_option('pp_widget_status', 'active');
        $this->info("Widget-Status: {$status}");

        // wp_footer Hook
        if (has_action('wp_footer')) {
            $this->pass('wp_footer hat registrierte Callbacks');
        }

        // wp_enqueue_scripts
        if (has_action('wp_enqueue_scripts')) {
            $this->pass('wp_enqueue_scripts hat registrierte Callbacks');
        }

        // Standort-Konfiguration
        global $wpdb;
        $locTable = $wpdb->prefix . 'pp_locations';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$locTable}'")) {
            $activeLocations = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$locTable}` WHERE is_active = 1"
            );
            $totalLocations = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$locTable}`");
            $this->info("Standorte: {$activeLocations} aktiv / {$totalLocations} gesamt");

            if ($totalLocations === 0) {
                $this->warn('Keine Standorte konfiguriert — Widget zeigt Setup-Hinweis');
            }

            // Services pro Standort
            $svcTable = $wpdb->prefix . 'pp_services';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$svcTable}'")) {
                $locations = $wpdb->get_results(
                    "SELECT l.id, l.name, COUNT(s.id) as svc_count,
                            SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) as active_count
                     FROM `{$locTable}` l
                     LEFT JOIN `{$svcTable}` s ON s.location_id = l.id
                     GROUP BY l.id
                     LIMIT 20",
                    ARRAY_A
                );

                foreach ($locations as $loc) {
                    $name = $loc['name'] ?: '(unbenannt)';
                    $this->info("Standort '{$name}': {$loc['active_count']}/{$loc['svc_count']} Services aktiv");

                    if ((int) $loc['active_count'] === 0) {
                        $this->warn("Standort '{$name}' hat keine aktiven Services!");
                    }
                }
            }
        }

        // Widget-CSS Variablen
        $widgetCss = dirname(__DIR__) . '/assets/css/widget.css';
        if (file_exists($widgetCss)) {
            $css = file_get_contents($widgetCss);
            $varsCount = substr_count($css, '--pp-');
            $this->info("Widget-CSS: {$varsCount} CSS-Variablen (--pp-*)");
        }
    }

    // =====================================================================
    // 13. UNINSTALLER
    // =====================================================================

    private function testUninstaller(): void
    {
        $this->group('Uninstaller');

        $uninstallFile = dirname(__DIR__) . '/uninstall.php';
        if (!file_exists($uninstallFile)) {
            $this->fail('uninstall.php fehlt!');
            return;
        }

        $content = file_get_contents($uninstallFile);

        // pp4 Check
        if (preg_match('/pp4[_\-]/', $content)) {
            $this->fail('uninstall.php enthält noch pp4-Referenzen!');
        } else {
            $this->pass('Kein pp4 im Uninstaller');
        }

        // Toggle
        if (strpos($content, 'pp_keep_data_on_uninstall') !== false) {
            $this->pass('Daten-behalten-Toggle implementiert');
        } else {
            $this->fail('Daten-behalten-Toggle FEHLT');
        }

        // Tabellen
        $tables = [
            'pp_locations', 'pp_services', 'pp_submissions', 'pp_files',
            'pp_audit_log', 'pp_portal_users', 'pp_api_keys', 'pp_documents',
            'pp_license_cache', 'pp_medications',
        ];
        foreach ($tables as $t) {
            if (strpos($content, $t) !== false) {
                $this->pass("Tabelle {$t} wird gelöscht");
            } else {
                $this->fail("Tabelle {$t} FEHLT im Uninstaller!");
            }
        }

        // Kritische Options
        $criticalOpts = [
            'pp_version', 'pp_db_version', 'pp_setup_complete',
            'pp_widget_status', 'pp_license_key', 'pp_portal_enabled',
            'pp_keep_data_on_uninstall',
        ];
        foreach ($criticalOpts as $opt) {
            if (strpos($content, "'{$opt}'") !== false) {
                $this->pass("Option {$opt} wird gelöscht");
            } else {
                // Wird evtl. durch Wildcard erfasst
                if (strpos($content, "LIKE 'pp\\_%'") !== false) {
                    $this->pass("Option {$opt} wird durch Wildcard erfasst");
                } else {
                    $this->fail("Option {$opt} FEHLT im Uninstaller!");
                }
            }
        }

        // Crons
        $crons = ['pp_daily_cleanup', 'pp_daily_license_check', 'pp_cleanup_temp_file'];
        foreach ($crons as $c) {
            if (strpos($content, $c) !== false) {
                $this->pass("Cron {$c} wird entfernt");
            } else {
                $this->fail("Cron {$c} FEHLT im Uninstaller!");
            }
        }

        // Encryption key cleanup
        if (strpos($content, 'encryption') !== false && strpos($content, 'unlink') !== false) {
            $this->pass('Verschlüsselungsschlüssel werden gelöscht');
        } else {
            $this->fail('Encryption-Key-Cleanup fehlt!');
        }

        // User-Meta
        if (strpos($content, 'usermeta') !== false) {
            $this->pass('User-Meta wird bereinigt');
        } else {
            $this->fail('User-Meta-Cleanup fehlt!');
        }

        // Transients
        if (strpos($content, '_transient_pp') !== false) {
            $this->pass('Transients werden bereinigt');
        } else {
            $this->fail('Transient-Cleanup fehlt!');
        }
    }

    // =====================================================================
    // 14. I18N
    // =====================================================================

    private function testI18n(): void
    {
        $this->group('Internationalisierung');

        // I18n Klasse
        $i18nFile = dirname(__DIR__) . '/src/I18n/I18n.php';
        if (!file_exists($i18nFile)) {
            $this->fail('I18n/I18n.php fehlt!');
            return;
        }

        $content = file_get_contents($i18nFile);

        if (strpos($content, 'public function t(') !== false) {
            $this->pass('I18n::t() Methode vorhanden');
        } else {
            $this->fail('I18n::t() Methode FEHLT!');
        }

        // Sprachdateien
        $langDir = dirname(__DIR__) . '/languages';
        if (is_dir($langDir)) {
            $poFiles = glob($langDir . '/*.po');
            $moFiles = glob($langDir . '/*.mo');
            $this->info('Sprachdateien: ' . count($poFiles) . ' .po, ' . count($moFiles) . ' .mo');
        } else {
            $this->info('Kein languages/ Verzeichnis');
        }
    }

    // =====================================================================
    // 15. SICHERHEIT
    // =====================================================================

    private function testSecurity(): void
    {
        $this->group('Sicherheit');

        $pluginDir = dirname(__DIR__);

        // index.php Dateien (Directory Listing verhindern)
        $dirs = ['src', 'src/Admin', 'src/Core', 'src/Widget', 'src/Database',
                 'templates', 'templates/widget'];
        foreach ($dirs as $dir) {
            $indexFile = $pluginDir . '/' . $dir . '/index.php';
            if (file_exists($indexFile)) {
                $this->pass("index.php in {$dir}/");
            } else {
                $this->warn("Kein index.php in {$dir}/ (Directory Listing möglich)");
            }
        }

        // Encryption
        $encFile = $pluginDir . '/src/Security/Encryption.php';
        if (file_exists($encFile)) {
            $content = file_get_contents($encFile);
            if (strpos($content, 'openssl') !== false || strpos($content, 'sodium') !== false) {
                $this->pass('Verschlüsselung nutzt openssl/sodium');
            } else {
                $this->warn('Verschlüsselungs-Algorithmus prüfen');
            }
        }

        // Rate Limiter
        $rlFile = $pluginDir . '/src/Security/RateLimiter.php';
        if (file_exists($rlFile)) {
            $this->pass('RateLimiter vorhanden');
        } else {
            $this->warn('RateLimiter fehlt');
        }

        // Nonce-Prüfung im AJAX
        $adminPhp = file_get_contents($pluginDir . '/src/Admin/Admin.php');
        if (strpos($adminPhp, 'check_ajax_referer') !== false) {
            $this->pass('Admin-AJAX prüft Nonce');
        } else {
            $this->fail('Admin-AJAX prüft KEINEN Nonce!');
        }

        if (strpos($adminPhp, 'current_user_can') !== false) {
            $this->pass('Admin-AJAX prüft Capability');
        } else {
            $this->fail('Admin-AJAX prüft KEINE Capability!');
        }
    }

    // =====================================================================
    // 16. LOCALIZATION (wp_localize_script Konsistenz)
    // =====================================================================

    private function testLocalization(): void
    {
        $this->group('JS-Localization Konsistenz');

        $pluginDir = dirname(__DIR__);

        // Admin JS nutzt ppAdmin.ajaxUrl / ppAdmin.nonce
        $adminJs = file_get_contents($pluginDir . '/admin/js/admin.js');
        $adminPhp = file_get_contents($pluginDir . '/src/Admin/Admin.php');

        // Prüfe ob JS die gleichen Keys nutzt wie PHP übergibt
        $jsKeys = [];
        if (preg_match_all('/ppAdmin\.(\w+)/', $adminJs, $m)) {
            $jsKeys = array_unique($m[1]);
        }

        $phpKeys = [];
        if (preg_match("/wp_localize_script\s*\(\s*'pp-admin'.*?\[(.+?)\]\s*\)/s", $adminPhp, $m)) {
            if (preg_match_all("/'(\w+)'\s*=>/", $m[1], $km)) {
                $phpKeys = $km[1];
            }
        }

        if (!empty($jsKeys) && !empty($phpKeys)) {
            $missingInPhp = array_diff($jsKeys, $phpKeys);
            // i18n sub-keys ausschließen
            $missingInPhp = array_filter($missingInPhp, fn($k) => !in_array($k, ['i18n']));

            foreach ($phpKeys as $k) {
                $this->pass("ppAdmin.{$k} wird von PHP übergeben");
            }

            foreach ($missingInPhp as $k) {
                if ($k === 'i18n') continue;
                $this->warn("JS nutzt ppAdmin.{$k} — aber PHP übergibt es nicht direkt");
            }
        }

        // Widget JS Konsistenz
        $widgetJs = file_get_contents($pluginDir . '/assets/js/widget.js');
        $widgetPhp = file_get_contents($pluginDir . '/src/Widget/Widget.php');

        $jsWidgetKeys = [];
        if (preg_match_all('/pp_widget\.(\w+)/', $widgetJs, $m)) {
            $jsWidgetKeys = array_unique($m[1]);
        }

        $phpWidgetKeys = [];
        if (preg_match("/wp_localize_script\s*\(\s*'pp-widget'.*?\[(.+?)\]\s*\)\s*;/s", $widgetPhp, $m)) {
            if (preg_match_all("/'(\w+)'\s*=>/", $m[1], $km)) {
                $phpWidgetKeys = $km[1];
            }
        }

        if (!empty($jsWidgetKeys)) {
            $missingWidget = array_diff($jsWidgetKeys, $phpWidgetKeys);
            $missingWidget = array_filter($missingWidget, fn($k) => !in_array($k, ['i18n']));
            foreach ($missingWidget as $k) {
                $this->fail("Widget-JS nutzt pp_widget.{$k} — aber PHP übergibt es nicht!");
            }
            if (empty($missingWidget)) {
                $this->pass("Widget JS↔PHP Localization konsistent");
            }
        }
    }

    // =====================================================================
    // 17. LAUFZEIT: WIDGET RENDERING
    // =====================================================================

    private function testLogicCSS(): void
    {
        $this->group('Laufzeit: Widget Rendering');
        $pluginDir = dirname(__DIR__);

        // --- 1. Template-Dateien: pp-step-active vs data-active ---
        $mainTpl = $pluginDir . '/templates/widget/main.php';
        if (file_exists($mainTpl)) {
            $html = file_get_contents($mainTpl);

            // BUG-CHECK: data-active="1" OHNE pp-step-active => Step bleibt unsichtbar
            if (preg_match_all('/data-step=["\'](\w+)["\']/', $html, $stepMatches)) {
                foreach ($stepMatches[1] as $step) {
                    // Finde die Zeile mit diesem data-step
                    $pattern = '/[^>]*data-step=["\']' . preg_quote($step, '/') . '["\'][^>]*/';
                    if (preg_match($pattern, $html, $lineMatch)) {
                        $tag = $lineMatch[0];
                        $hasDataActive  = (strpos($tag, 'data-active') !== false);
                        $hasCssClass    = (strpos($tag, 'pp-step-active') !== false);

                        if ($hasDataActive && !$hasCssClass) {
                            $this->fail("Step '{$step}': data-active='1' OHNE CSS-Klasse pp-step-active => Step bleibt unsichtbar!");
                        } elseif ($hasCssClass) {
                            $this->pass("Step '{$step}': pp-step-active korrekt gesetzt");
                        }
                    }
                }
            }

            // BUG-CHECK: Mindestens 1 Step muss initial sichtbar sein
            if (strpos($html, 'pp-step-active') !== false) {
                $this->pass('Mindestens 1 Step hat initiale Sichtbarkeit (pp-step-active)');
            } else {
                $this->fail('KEIN Step hat pp-step-active => Widget oeffnet sich leer!');
            }
        }

        // --- 2. CSS-Klassen: JS classList vs CSS-Definitionen ---
        $widgetCss = $pluginDir . '/assets/css/widget.css';
        $widgetJs  = $pluginDir . '/assets/js/widget.js';

        if (file_exists($widgetCss) && file_exists($widgetJs)) {
            $css = file_get_contents($widgetCss);
            $js  = file_get_contents($widgetJs);

            // Kritische Klassen die JS per classList setzt => muessen im CSS existieren
            $jsClasses = [];
            if (preg_match_all("/classList\.\w+\(['\"]([^'\"]+)['\"]/", $js, $m)) {
                $jsClasses = array_unique($m[1]);
            }

            $missingCss = [];
            foreach ($jsClasses as $cls) {
                if (strpos($cls, 'pp-') !== 0) continue;
                if (strpos($css, '.' . $cls) === false) {
                    $missingCss[] = $cls;
                }
            }

            if (empty($missingCss)) {
                $this->pass('Alle ' . count($jsClasses) . ' JS-classList-Klassen haben CSS-Definitionen');
            } else {
                foreach ($missingCss as $cls) {
                    $this->fail("JS setzt Klasse '{$cls}' per classList, aber CSS hat keine .{$cls} Definition");
                }
            }
        }

        // --- 3. Laufzeit: Services aus DB laden ---
        global $wpdb;
        $svcTable = $wpdb->prefix . 'pp_services';
        $locTable = $wpdb->prefix . 'pp_locations';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$svcTable}'")) {
            $activeServices = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$svcTable}` WHERE is_active = 1");
            $totalServices  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$svcTable}`");

            if ($totalServices === 0) {
                $this->warn('Keine Services in DB => Widget zeigt leere Service-Liste');
            } elseif ($activeServices === 0) {
                $this->fail('Services vorhanden (' . $totalServices . ') aber KEINE aktiv => Widget zeigt leere Liste!');
            } else {
                $this->pass("{$activeServices}/{$totalServices} Services aktiv");
            }

            // Services ohne gueltige Location
            if ($wpdb->get_var("SHOW TABLES LIKE '{$locTable}'")) {
                $orphans = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM `{$svcTable}` s 
                     LEFT JOIN `{$locTable}` l ON s.location_id = l.id 
                     WHERE l.id IS NULL"
                );
                if ($orphans > 0) {
                    $this->fail("{$orphans} Services ohne gueltigen Standort (verwaiste Daten)");
                } else {
                    $this->pass('Alle Services sind einem Standort zugeordnet');
                }
            }
        }

        // --- 4. Multi-Location Logik ---
        if ($wpdb->get_var("SHOW TABLES LIKE '{$locTable}'")) {
            $activeLocCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$locTable}` WHERE is_active = 1");
            $defaultCount   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$locTable}` WHERE is_default = 1");

            if ($activeLocCount > 1) {
                $this->info("Multi-Standort aktiv ({$activeLocCount} Standorte)");

                // Pruefen ob Widget-Template den Location-Step hat
                if (file_exists($mainTpl) && strpos(file_get_contents($mainTpl), 'data-step="location"') !== false) {
                    $this->pass('Location-Step im Widget-Template vorhanden');
                } else {
                    $this->fail('Multi-Standort aktiv aber Location-Step fehlt im Template!');
                }
            }

            if ($defaultCount === 0 && $activeLocCount > 0) {
                $this->warn('Kein Standard-Standort definiert (is_default = 1)');
            } elseif ($defaultCount > 1) {
                $this->warn("{$defaultCount} Standorte als Standard markiert (sollte max. 1 sein)");
            }

            // Standorte ohne Pflichtfelder
            $incomplete = $wpdb->get_results(
                "SELECT id, name FROM `{$locTable}` 
                 WHERE is_active = 1 
                 AND (practice_name IS NULL OR practice_name = '' 
                      OR email IS NULL OR email = '')
                 LIMIT 10",
                ARRAY_A
            );
            foreach ($incomplete as $loc) {
                $this->warn("Standort #{$loc['id']} ({$loc['name']}): Praxis-Name oder E-Mail fehlt");
            }
        }
    }

    // =====================================================================
    // 18. LAUFZEIT: JS REFERENZEN & LOCALIZE
    // =====================================================================

    private function testLogicJS(): void
    {
        $this->group('Laufzeit: JS Referenzen & Localize');
        $pluginDir = dirname(__DIR__);

        // --- 1. Undefinierte Objekt-Referenzen im Widget-JS ---
        $jsFile = $pluginDir . '/assets/js/widget.js';
        if (!file_exists($jsFile)) {
            $this->fail('assets/js/widget.js nicht gefunden');
            return;
        }
        $js = file_get_contents($jsFile);

        // BUG-CHECK: config. / settings. / app. statt W. (pp_widget)
        $suspectObjects = [
            'config.'    => 'W (pp_widget)',
            'settings.'  => 'W (pp_widget) oder ppAdmin',
            'app.'       => 'W (pp_widget)',
            'plugin.'    => 'nicht definiert',
            'PP.'        => 'nicht definiert',
            'PP4.'       => 'nicht definiert',
        ];

        $jsLines = explode("\n", $js);
        $foundSuspect = false;
        foreach ($suspectObjects as $pattern => $suggestion) {
            foreach ($jsLines as $lineNo => $line) {
                $trimmed = trim($line);
                // Kommentare ueberspringen
                if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0) continue;
                // In Strings ueberspringen (einfacher Check)
                if (preg_match('/^\s*["\']/', $trimmed)) continue;

                if (strpos($trimmed, $pattern) !== false) {
                    // Nicht in einem String-Kontext?
                    $before = substr($trimmed, 0, strpos($trimmed, $pattern));
                    $singleQuotes = substr_count($before, "'") - substr_count($before, "\\'");
                    $doubleQuotes = substr_count($before, '"') - substr_count($before, '\\"');
                    // Ungerade Anzahl = wir sind IN einem String
                    if ($singleQuotes % 2 === 0 && $doubleQuotes % 2 === 0) {
                        $this->fail("widget.js:" . ($lineNo + 1) . " => '{$pattern}' benutzt, aber Objekt existiert nicht (stattdessen: {$suggestion})");
                        $foundSuspect = true;
                    }
                }
            }
        }
        if (!$foundSuspect) {
            $this->pass('Keine undefinierten JS-Objekt-Referenzen (config., settings., app., etc.)');
        }

        // --- 2. wp_localize_script Keys: PHP vs JS Abgleich ---
        $widgetPhp = $pluginDir . '/src/Widget/Widget.php';
        if (file_exists($widgetPhp)) {
            $phpContent = file_get_contents($widgetPhp);

            // PHP-Keys aus wp_localize_script extrahieren
            $phpKeys = [];
            if (preg_match("/wp_localize_script\s*\(\s*'pp-widget'.*?\[(.+?)\]\s*\)/s", $phpContent, $m)) {
                if (preg_match_all("/'(\w+)'\s*=>/", $m[1], $km)) {
                    $phpKeys = $km[1];
                }
            }

            // JS-Keys: W.xxx oder pp_widget.xxx
            $jsKeys = [];
            if (preg_match_all('/\bW\.(\w+)/', $js, $m)) {
                $jsKeys = array_merge($jsKeys, $m[1]);
            }
            if (preg_match_all('/pp_widget\.(\w+)/', $js, $m)) {
                $jsKeys = array_merge($jsKeys, $m[1]);
            }
            $jsKeys = array_unique($jsKeys);

            // JS benutzt Keys die PHP nicht uebergibt?
            $missingInPHP = array_diff($jsKeys, $phpKeys);
            // i18n Sub-Keys und interne Properties rausfiltern
            $missingInPHP = array_filter($missingInPHP, function ($k) {
                return !in_array($k, ['i18n', 'call', 'apply', 'bind', 'prototype', 'length', 'toString']);
            });

            if (empty($missingInPHP)) {
                $this->pass('Alle JS-Keys (W.xxx) werden von PHP (wp_localize_script) uebergeben');
            } else {
                foreach ($missingInPHP as $k) {
                    $this->fail("JS benutzt W.{$k} / pp_widget.{$k} aber PHP uebergibt es NICHT => undefined");
                }
            }

            // PHP uebergibt Keys die JS nie nutzt (Info)
            $unusedInJS = array_diff($phpKeys, $jsKeys);
            $unusedInJS = array_filter($unusedInJS, fn($k) => $k !== 'i18n');
            if (!empty($unusedInJS)) {
                $this->info('PHP uebergibt ' . count($unusedInJS) . ' Keys die JS nie benutzt: ' . implode(', ', $unusedInJS));
            }
        }

        // --- 3. Admin JS: ppAjax() definiert? ---
        $adminJs = $pluginDir . '/admin/js/admin.js';
        if (file_exists($adminJs)) {
            $ajsContent = file_get_contents($adminJs);
            if (strpos($ajsContent, 'function ppAjax') !== false || strpos($ajsContent, 'window.ppAjax') !== false) {
                $this->pass('ppAjax() ist in admin.js definiert');
            } else {
                $this->fail('ppAjax() wird von Admin-Seiten benutzt aber ist NICHT in admin.js definiert');
            }
        }

        // --- 4. data-Attribute: JS liest, Template setzt? ---
        $jsDataAttrs = [];
        if (preg_match_all("/getAttribute\(['\"]([^'\"]+)['\"]\)/", $js, $m)) {
            foreach ($m[1] as $attr) {
                if (strpos($attr, 'data-') === 0) {
                    $jsDataAttrs[$attr] = true;
                }
            }
        }
        // [data-xxx] Selektoren
        if (preg_match_all('/\[data-([\w-]+)/', $js, $m)) {
            foreach ($m[1] as $attr) {
                $jsDataAttrs["data-{$attr}"] = true;
            }
        }

        $templateFiles = $this->findFiles($pluginDir . '/templates/widget', 'php');
        $allTemplateHTML = '';
        foreach ($templateFiles as $f) {
            $allTemplateHTML .= file_get_contents($f);
        }

        $missingAttrs = [];
        $dynamicAttrs = [
            'data-id', 'data-type', 'data-filter', 'data-tab',
            // Dynamisch von JS erzeugt (Formular-Builder, Medikamenten-Suche, Inline-Edit):
            'data-condition-field', 'data-condition-value', 'data-condition-contains',
            'data-name', 'data-dosage', 'data-pzn', 'data-index',
            'data-field-id', 'data-original-text',
        ];
        foreach ($jsDataAttrs as $attr => $_) {
            if (in_array($attr, $dynamicAttrs)) continue;
            if (strpos($allTemplateHTML, $attr) === false) {
                $missingAttrs[] = $attr;
            }
        }

        if (empty($missingAttrs)) {
            $this->pass('Alle ' . count($jsDataAttrs) . ' JS data-Attribute werden von Templates bereitgestellt');
        } else {
            foreach ($missingAttrs as $attr) {
                $this->warn("JS liest '{$attr}' aber kein Widget-Template setzt es");
            }
        }
    }

    // =====================================================================
    // 19. LAUFZEIT: SQL & DATENBANK-QUERIES
    // =====================================================================

    private function testLogicSQL(): void
    {
        $this->group('Laufzeit: SQL & Datenbank-Queries');
        $pluginDir = dirname(__DIR__);
        $selfFile  = str_replace('\\', '/', __FILE__);

        global $wpdb;

        // --- 1. Medikamenten-Query testen (Hauptproblem-Bereich) ---
        $medTable = $wpdb->prefix . 'pp_medications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$medTable}'")) {
            $medCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$medTable}`");
            $this->info("Medikamenten-Tabelle: {$medCount} Eintraege");

            // Die exakte Query ausfuehren die renderMedicationsPage() nutzt
            $wpdb->last_error = '';
            $testResult = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, dosage, form, pzn, created_at FROM `{$medTable}` WHERE name NOT LIKE %s AND name NOT LIKE %s ORDER BY name ASC LIMIT %d OFFSET %d",
                '#%', '=%', 50, 0
            ), ARRAY_A);

            if ($wpdb->last_error) {
                $this->fail("Medikamenten-Query FEHLER: {$wpdb->last_error}");
            } else {
                $this->pass('Medikamenten-Listenquery funktioniert (' . count($testResult ?: []) . ' Ergebnisse)');
            }

            // Suchquery testen
            $wpdb->last_error = '';
            $searchResult = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name FROM `{$medTable}` WHERE name NOT LIKE %s AND name NOT LIKE %s AND (name LIKE %s OR dosage LIKE %s OR pzn LIKE %s) ORDER BY name ASC LIMIT %d OFFSET %d",
                '#%', '=%', '%test%', '%test%', '%test%', 50, 0
            ), ARRAY_A);

            if ($wpdb->last_error) {
                $this->fail("Medikamenten-Suchquery FEHLER: {$wpdb->last_error}");
            } else {
                $this->pass('Medikamenten-Suchquery funktioniert');
            }

            // Kommentar/Header-Zaehlung
            $wpdb->last_error = '';
            $badCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$medTable}` WHERE name LIKE %s OR name LIKE %s",
                '#%', '=%'
            ));

            if ($wpdb->last_error) {
                $this->fail("Kommentarzeilen-Query FEHLER: {$wpdb->last_error}");
            } else {
                if ($badCount > 0) {
                    $this->warn("{$badCount} Kommentar-/Header-Zeilen in Medikamenten (importiert aus CSV)");
                } else {
                    $this->pass('Keine Kommentarzeilen in Medikamenten-Tabelle');
                }
            }
        } else {
            $this->info('Medikamenten-Tabelle existiert nicht (noch kein Import)');
        }

        // --- 2. Code-Scan: %% in LIKE-Klauseln (WP 6.x bricht) ---
        $phpFiles = $this->findFiles($pluginDir . '/src', 'php');
        $doublePercent = 0;
        foreach ($phpFiles as $file) {
            if (str_replace('\\', '/', $file) === $selfFile) continue;
            $lines = file($file);
            $rel   = str_replace($pluginDir . '/', '', $file);

            foreach ($lines as $lineNo => $line) {
                if (preg_match('/["\'].*%%.*["\']/', $line) && stripos($line, 'LIKE') !== false) {
                    $this->fail("{$rel}:" . ($lineNo + 1) . " => %% in LIKE-Klausel (WP 6.x bricht, nutze \$wpdb->prepare() mit %s)");
                    $doublePercent++;
                }
            }
        }
        if ($doublePercent === 0) {
            $this->pass('Keine doppelten %% in LIKE-Klauseln (WP 6.x kompatibel)');
        }

        // --- 3. Verschachteltes prepare() ---
        foreach ($phpFiles as $file) {
            if (str_replace('\\', '/', $file) === $selfFile) continue;
            $content = file_get_contents($file);
            $rel     = str_replace($pluginDir . '/', '', $file);

            if (preg_match('/\$wpdb->prepare\s*\([^)]*\$wpdb->prepare/', $content)) {
                $this->fail("{$rel} => Verschachteltes \$wpdb->prepare() (Doppel-Escaping!)");
            }
        }

        // --- 4. Submissions-Query testen (DSGVO-relevant) ---
        $subTable = $wpdb->prefix . 'pp_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$subTable}'")) {
            $wpdb->last_error = '';
            $subCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$subTable}`");
            if ($wpdb->last_error) {
                $this->fail("Submissions-Query FEHLER: {$wpdb->last_error}");
            } else {
                $this->pass("Submissions-Tabelle erreichbar ({$subCount} Eintraege)");
            }

            // Geloeschte aber nicht purgierte Eintraege
            $softDeleted = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$subTable}` WHERE deleted_at IS NOT NULL");
            if ($softDeleted > 0) {
                $this->info("{$softDeleted} soft-deleted Submissions (DSGVO: regelmaessig purgen)");
            }
        }

        // --- 5. Audit-Log Query ---
        $auditTable = $wpdb->prefix . 'pp_audit_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$auditTable}'")) {
            $wpdb->last_error = '';
            $auditCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$auditTable}`");
            if ($wpdb->last_error) {
                $this->fail("Audit-Log-Query FEHLER: {$wpdb->last_error}");
            } else {
                $this->pass("Audit-Log erreichbar ({$auditCount} Eintraege)");
            }
        }
    }

    // =====================================================================
    // 20. LAUFZEIT: HOOK-DUPLIKATE & ENQUEUE
    // =====================================================================

    private function testLogicHooks(): void
    {
        $this->group('Laufzeit: Hooks, Enqueue & Assets');
        $pluginDir = dirname(__DIR__);
        $selfFile  = str_replace('\\', '/', __FILE__);

        // --- 1. Hook-Duplikate aus verschiedenen Dateien ---
        $phpFiles = $this->findFiles($pluginDir . '/src', 'php');
        $hooks = [];

        foreach ($phpFiles as $file) {
            if (str_replace('\\', '/', $file) === $selfFile) continue;
            $lines = file($file);
            $rel   = str_replace($pluginDir . '/', '', $file);

            foreach ($lines as $lineNo => $line) {
                if (preg_match("/add_(filter|action)\s*\(\s*['\"]([^'\"]+)['\"]/", $line, $m)) {
                    $hooks[$m[2]][] = ['file' => $rel, 'line' => $lineNo + 1];
                }
            }
        }

        $duplicates = 0;
        // Hooks die BEWUSST aus mehreren Dateien registriert werden (verschiedene Callbacks)
        $legitimateMultiFile = [
            'admin_notices',        // Hooks.php (Security-Warnungen) + Updater.php (Update-Hinweise)
            'wp_enqueue_scripts',   // Plugin.php (Platzhalter) + Widget.php (Widget-Assets)
            'admin_init',           // Plugin.php (Boot) + diverse Untermodule
            'init',                 // Mehrere Module brauchen init
        ];

        foreach ($hooks as $hookName => $registrations) {
            $files = array_unique(array_column($registrations, 'file'));
            if (count($files) > 1) {
                if (in_array($hookName, $legitimateMultiFile)) {
                    $this->info("Hook '{$hookName}' bewusst aus " . count($files) . " Dateien (verschiedene Callbacks)");
                } else {
                    $locs = array_map(fn($r) => "{$r['file']}:{$r['line']}", $registrations);
                    $this->fail("Hook '{$hookName}' aus " . count($files) . " Dateien: " . implode(', ', $locs));
                    $duplicates++;
                }
            }
        }
        if ($duplicates === 0) {
            $this->pass('Keine unbeabsichtigten Hook-Duplikate aus verschiedenen Dateien');
        }

        // --- 2. CSS/JS Dateien: existieren sie wirklich? ---
        $assetFiles = [
            'assets/css/widget.css'           => 'Widget CSS',
            'assets/js/widget.js'             => 'Widget JS',
            'admin/css/admin.css'             => 'Admin CSS',
            'admin/js/admin.js'               => 'Admin JS',
            'admin/css/medications-admin.css'  => 'Medikamenten CSS',
            'admin/js/medications-admin.js'    => 'Medikamenten JS',
        ];

        foreach ($assetFiles as $path => $desc) {
            $fullPath = $pluginDir . '/' . $path;
            if (file_exists($fullPath)) {
                $size = filesize($fullPath);
                if ($size < 10) {
                    $this->warn("{$desc}: {$path} existiert aber ist fast leer ({$size} Bytes)");
                } else {
                    $this->pass("{$desc}: {$path} (" . $this->humanSize($size) . ")");
                }
            } else {
                $this->fail("{$desc}: {$path} FEHLT auf der Festplatte!");
            }
        }

        // --- 3. Doppelte Shortcodes ---
        $shortcodes = [];
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            $rel     = str_replace($pluginDir . '/', '', $file);
            if (preg_match_all("/add_shortcode\s*\(\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
                foreach ($m[1] as $sc) {
                    $shortcodes[$sc][] = $rel;
                }
            }
        }
        $scDups = 0;
        foreach ($shortcodes as $sc => $files) {
            if (count($files) > 1) {
                $this->fail("Shortcode [{$sc}] in " . count($files) . " Dateien: " . implode(', ', $files));
                $scDups++;
            }
        }
        if ($scDups === 0 && count($shortcodes) > 0) {
            $this->pass("Keine doppelten Shortcodes (" . count($shortcodes) . " registriert)");
        }

        // --- 4. Script-Dependency Check ---
        $adminPhp = $pluginDir . '/src/Admin/Admin.php';
        if (file_exists($adminPhp)) {
            $content = file_get_contents($adminPhp);
            // Medications JS haengt von pp-admin ab
            if (preg_match("/wp_enqueue_script\s*\(\s*'pp-medications-admin'.*?\[([^\]]*)\]/s", $content, $m)) {
                $deps = $m[1];
                if (strpos($deps, "'pp-admin'") !== false) {
                    $this->pass("pp-medications-admin hat Dependency auf pp-admin (ppAjax verfuegbar)");
                } else {
                    $this->fail("pp-medications-admin FEHLT Dependency 'pp-admin' => ppAjax() ist undefined!");
                }
            }
        }
    }

    // =====================================================================
    // 21. LAUFZEIT: TEMPLATE & FORMULAR KONSISTENZ
    // =====================================================================

    private function testLogicTemplates(): void
    {
        $this->group('Laufzeit: Template & Formular Konsistenz');
        $pluginDir = dirname(__DIR__);

        // --- 1. Step-Templates vorhanden ---
        $requiredSteps = ['location', 'welcome', 'services'];
        foreach ($requiredSteps as $step) {
            $path = $pluginDir . "/templates/widget/steps/{$step}.php";
            if (file_exists($path)) {
                $this->pass("Step-Template {$step}.php vorhanden");
            } else {
                $this->fail("Step-Template {$step}.php FEHLT");
            }
        }

        // --- 2. Formular-Templates vs DB-Services ---
        global $wpdb;
        $svcTable = $wpdb->prefix . 'pp_services';
        $formDir  = $pluginDir . '/templates/widget/forms';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$svcTable}'")) {
            $dbServiceTypes = $wpdb->get_col("SELECT DISTINCT service_type FROM `{$svcTable}` WHERE is_active = 1");
            foreach ($dbServiceTypes as $type) {
                if (empty($type)) continue;
                // Einige Services brauchen kein Template (externe URLs, Kontakt)
                $noTemplate = ['kontakt', 'anamnesebogen', 'notfall', 'downloads', 'builtin'];
                if (in_array($type, $noTemplate)) continue;

                $formPath = "{$formDir}/{$type}.php";
                if (file_exists($formPath)) {
                    $this->pass("Aktiver Service '{$type}' hat Formular-Template");
                } else {
                    $this->warn("Aktiver Service '{$type}' in DB aber KEIN Template in forms/{$type}.php");
                }
            }
        }

        // --- 3. Formular-JSONs vs Service-Types ---
        $jsonDir = $pluginDir . '/data/forms';
        if (is_dir($jsonDir)) {
            $jsonFiles = glob($jsonDir . '/*.json');
            foreach ($jsonFiles as $jsonFile) {
                $json = json_decode(file_get_contents($jsonFile), true);
                if ($json === null) {
                    $this->fail("Formular-JSON " . basename($jsonFile) . " ist KEIN gueltiges JSON: " . json_last_error_msg());
                } else {
                    $this->pass("Formular-JSON " . basename($jsonFile) . " valide");
                }
            }
        }

        // --- 4. renderStep()-Aufrufe vs vorhandene Templates ---
        $mainTpl = $pluginDir . '/templates/widget/main.php';
        if (file_exists($mainTpl)) {
            $mainContent = file_get_contents($mainTpl);
            if (preg_match_all("/renderStep\(['\"]([^'\"]+)['\"]\)/", $mainContent, $m)) {
                foreach ($m[1] as $stepName) {
                    $stepFile = $pluginDir . "/templates/widget/steps/{$stepName}.php";
                    if (!file_exists($stepFile)) {
                        $this->fail("main.php ruft renderStep('{$stepName}') aber steps/{$stepName}.php existiert nicht");
                    }
                }
            }
        }

        // --- 5. Encoding: Typographische Anfuehrungszeichen (PHP Parse Error Risiko) ---
        $templateFiles = $this->findFiles($pluginDir . '/templates', 'php');
        $badEncoding = 0;
        foreach ($templateFiles as $file) {
            $content = file_get_contents($file);
            $rel     = str_replace($pluginDir . '/', '', $file);
            if (preg_match('/[\x{201E}\x{201C}\x{201D}\x{2018}\x{2019}]/u', $content)) {
                $this->warn("{$rel} => Typographische Anfuehrungszeichen (Parse-Error-Risiko)");
                $badEncoding++;
            }
        }
        if ($badEncoding === 0 && count($templateFiles) > 0) {
            $this->pass('Keine typographischen Anfuehrungszeichen in Templates');
        }

        // --- 6. REGRESSION: services.php muss service_key nutzen (nicht 'key') ---
        $svcTpl = $pluginDir . '/templates/widget/steps/services.php';
        if (file_exists($svcTpl)) {
            $svcContent = file_get_contents($svcTpl);
            if (strpos($svcContent, "\$service['key']") !== false) {
                $this->fail("services.php nutzt \$service['key'] aber DB-Spalte heisst 'service_key' => leere data-service!");
            } else {
                $this->pass("services.php nutzt korrekt 'service_key' (nicht 'key')");
            }
        }

        // --- 7. REGRESSION: JS-Submit-Selektor muss Formular-Klasse matchen ---
        $jsFile = $pluginDir . '/assets/js/widget.js';
        if (file_exists($jsFile)) {
            $jsContent = file_get_contents($jsFile);
            if (strpos($jsContent, "'.pp-submit-form'") !== false) {
                $this->fail("widget.js lauscht auf '.pp-submit-form' aber Formulare haben Klasse '.pp-service-form' => Submit feuert nie!");
            } else {
                $this->pass("JS-Submit-Selektor passt zur Formular-Klasse");
            }
        }

        // --- 8. REGRESSION: v3-Flow (Welcome zuerst, dann Location) ---
        if (file_exists($mainTpl)) {
            $mainContent = file_get_contents($mainTpl);
            $welcomePos  = strpos($mainContent, 'data-step="welcome"');
            $locationPos = strpos($mainContent, 'data-step="location"');
            $servicesPos = strpos($mainContent, 'data-step="services"');

            if ($welcomePos !== false && $servicesPos !== false) {
                if ($welcomePos < $servicesPos) {
                    $this->pass("v3-Flow: Welcome vor Services (korrekt)");
                } else {
                    $this->fail("Welcome-Step kommt NACH Services-Step (soll v3-Flow: Welcome zuerst)");
                }
            }

            if ($welcomePos !== false && $locationPos !== false) {
                if ($welcomePos < $locationPos) {
                    $this->pass("v3-Flow: Welcome vor Location (korrekt)");
                } else {
                    $this->fail("Welcome-Step kommt NACH Location-Step (soll v3-Flow: Patient-Frage zuerst)");
                }
            }

            // Welcome muss pp-step-active haben (Initial sichtbar)
            if (preg_match('/data-step="welcome"/', $mainContent)) {
                // Finde das pp-step div mit data-step="welcome"
                if (preg_match('/class="pp-step pp-step-active"[^>]*data-step="welcome"/', $mainContent)) {
                    $this->pass("Welcome-Step ist initial sichtbar (pp-step-active)");
                } elseif (preg_match('/class="pp-step"[^>]*data-step="welcome"/', $mainContent)) {
                    $this->fail("Welcome-Step hat KEIN pp-step-active => Widget startet mit leerem Bildschirm!");
                }
            }
        }

        // --- 9. REGRESSION: Medikationsliste Container muss in Rezept-Formular existieren ---
        $rezeptTpl = $pluginDir . '/templates/widget/forms/rezept.php';
        if (file_exists($rezeptTpl)) {
            $rezeptContent = file_get_contents($rezeptTpl);
            if (strpos($rezeptContent, 'pp-medication-list') !== false) {
                $this->pass("rezept.php hat pp-medication-list Container (ausgewaehlte Medikamente sichtbar)");
            } else {
                $this->fail("rezept.php FEHLT pp-medication-list Container => JS renderMedicationList() hat kein Ziel-Element!");
            }

            // CSS-Klasse muss zum Template passen
            $cssFile = $pluginDir . '/assets/css/widget.css';
            if (file_exists($cssFile)) {
                $cssContent = file_get_contents($cssFile);
                if (strpos($rezeptContent, 'pp-medication-input-wrapper') !== false) {
                    if (strpos($cssContent, '.pp-medication-input-wrapper') !== false) {
                        $this->pass("CSS-Klasse pp-medication-input-wrapper passt zum Template");
                    } else {
                        $this->fail("Template hat pp-medication-input-wrapper aber CSS definiert andere Klasse => Autocomplete-Dropdown falsch positioniert!");
                    }
                }
            }
        }

        // --- 10. REGRESSION: Medikamenten-Suche muss DB nutzen (nicht CSV) ---
        $handlerFile = $pluginDir . '/src/Widget/WidgetHandler.php';
        if (file_exists($handlerFile)) {
            $handlerContent = file_get_contents($handlerFile);
            if (strpos($handlerContent, 'medicationspraxis.csv') !== false) {
                $this->fail("WidgetHandler sucht noch in CSV statt DB => Admin-Aenderungen erscheinen nicht im Widget!");
            } else {
                $this->pass("WidgetHandler nutzt DB-Tabelle als einzige Datenquelle (nicht CSV)");
            }
        }
    }

    // =====================================================================
    // 22. LAUFZEIT: KONSTANTEN, ALIASE & LIZENZ
    // =====================================================================

    private function testLogicConstants(): void
    {
        $this->group('Laufzeit: Konstanten, Aliase & Lizenz');
        $pluginDir = dirname(__DIR__);
        $selfFile  = str_replace('\\', '/', __FILE__);

        // --- 1. Pflicht-Konstanten ---
        $requiredConstants = [
            'PP_VERSION'     => 'Plugin-Version',
            'PP_PLUGIN_DIR'  => 'Plugin-Verzeichnis',
            'PP_PLUGIN_URL'  => 'Plugin-URL',
            'PP_PLUGIN_FILE' => 'Haupt-Plugin-Datei',
        ];

        foreach ($requiredConstants as $const => $desc) {
            if (defined($const)) {
                $this->pass("{$const} = " . constant($const));
            } else {
                $this->fail("{$const} ist NICHT definiert ({$desc})");
            }
        }

        // --- 2. PP4_-Aliase korrekt? ---
        $aliases = ['PP4_VERSION' => 'PP_VERSION', 'PP4_PLUGIN_DIR' => 'PP_PLUGIN_DIR', 'PP4_PLUGIN_URL' => 'PP_PLUGIN_URL'];
        foreach ($aliases as $alias => $original) {
            if (defined($alias) && defined($original)) {
                if (constant($alias) === constant($original)) {
                    $this->pass("{$alias} ist korrekter Alias von {$original}");
                } else {
                    $this->fail("{$alias} (" . constant($alias) . ") != {$original} (" . constant($original) . ") => Alias-Mismatch!");
                }
            } elseif (!defined($alias)) {
                $this->warn("{$alias} nicht definiert — Code der PP4_ nutzt wird brechen");
            }
        }

        // --- 3. PP4_-Nutzung im Code (sollte PP_ sein) ---
        $phpFiles = $this->findFiles($pluginDir . '/src', 'php');
        $pp4Usage = [];
        foreach ($phpFiles as $file) {
            if (str_replace('\\', '/', $file) === $selfFile) continue;
            $lines = file($file);
            $rel   = str_replace($pluginDir . '/', '', $file);
            foreach ($lines as $lineNo => $line) {
                if (preg_match('/PP4_(VERSION|PLUGIN_DIR|PLUGIN_URL|PLUGIN_FILE)/', $line, $m)) {
                    $pp4Usage[] = "{$rel}:" . ($lineNo + 1) . " => PP4_{$m[1]}";
                }
            }
        }

        if (empty($pp4Usage)) {
            $this->pass('Kein PP4_-Konstantengebrauch in src/ (alle auf PP_ migriert)');
        } else {
            foreach ($pp4Usage as $usage) {
                $this->warn("Alter Alias: {$usage} (PP4_ statt PP_)");
            }
            $this->info(count($pp4Usage) . "x PP4_ => Aliase in praxis-portal.php muessen bestehen bleiben");
        }

        // --- 4. Schema-Version vs Plugin-Version ---
        if (defined('PP_VERSION') && class_exists('\\PraxisPortal\\Database\\Schema')) {
            if (defined('PraxisPortal\\Database\\Schema::VERSION')) {
                $schemaVersion = \PraxisPortal\Database\Schema::VERSION;
                if ($schemaVersion === PP_VERSION) {
                    $this->pass("Schema {$schemaVersion} = Plugin " . PP_VERSION);
                } else {
                    $this->warn("Schema {$schemaVersion} != Plugin " . PP_VERSION . " => DB-Migration pruefen");
                }
            }
        }

        // --- 5. Lizenz-Status ---
        global $wpdb;
        $ltable = $wpdb->prefix . 'pp_license_cache';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$ltable}'")) {
            $cached = $wpdb->get_results("SELECT cache_key, cache_value FROM `{$ltable}` LIMIT 5", ARRAY_A);
            if (!empty($cached)) {
                foreach ($cached as $c) {
                    $this->info("Lizenz-Cache: {$c['cache_key']} = " . substr($c['cache_value'], 0, 80));
                }
            } else {
                $this->info('Lizenz-Cache leer (noch keine Lizenzpruefung)');
            }
        }

        // --- 6. Lizenzgebundene Features: Option vorhanden? ---
        $licenseOptions = [
            'pp_license_key'    => 'Lizenzschluessel',
            'pp_license_status' => 'Lizenzstatus',
            'pp_license_type'   => 'Lizenztyp (basic/pro/enterprise)',
        ];
        foreach ($licenseOptions as $opt => $desc) {
            $val = get_option($opt, '__NOT_SET__');
            if ($val !== '__NOT_SET__') {
                $display = (strpos($opt, '_key') !== false) ? substr($val, 0, 8) . '***' : $val;
                $this->info("{$desc}: {$display}");
            }
        }

        // --- 7. Portal-Status ---
        $portalEnabled = get_option('pp_portal_enabled', '0');
        $portalUrl     = get_option('pp_portal_url', '');
        $this->info('Portal: ' . ($portalEnabled ? 'aktiviert' : 'deaktiviert') . ($portalUrl ? " ({$portalUrl})" : ''));
    }

    // =====================================================================
    // HELPER: Ergebnisse
    // =====================================================================

    private function group(string $name): void
    {
        $this->currentGroup = $name;
    }

    private function pass(string $msg): void
    {
        $this->addResult('pass', $msg);
    }

    private function fail(string $msg): void
    {
        $this->addResult('fail', $msg);
    }

    private function warn(string $msg): void
    {
        $this->addResult('warn', $msg);
    }

    private function info(string $msg): void
    {
        $this->addResult('info', $msg);
    }

    private function addResult(string $type, string $detail): void
    {
        $this->stats[$type]++;
        $this->results[] = [
            'type'   => $type,
            'group'  => $this->currentGroup,
            'detail' => $detail,
        ];
    }

    private function findFiles(string $dir, string $ext): array
    {
        $results = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() === $ext && strpos($file->getPath(), 'node_modules') === false) {
                $results[] = $file->getPathname();
            }
        }
        return $results;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 2) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    // =====================================================================
    // HTML OUTPUT
    // =====================================================================

    private function renderHTML(): void
    {
        $s = $this->stats;
        $total = $s['pass'] + $s['fail'] + $s['warn'] + $s['info'];
        ?>
        <div class="wrap">
            <h1>🔍 Praxis-Portal Diagnose</h1>
            <p class="description">Umfassende Prüfung aller Plugin-Komponenten — v<?php echo esc_html(defined('PP_VERSION') ? PP_VERSION : '?'); ?></p>

            <!-- Zusammenfassung -->
            <div style="display:flex; gap:16px; margin:20px 0; flex-wrap:wrap;">
                <div style="flex:1; min-width:120px; padding:20px; background:#d1fae5; border-radius:8px; text-align:center;">
                    <div style="font-size:32px; font-weight:700; color:#065f46;">✅ <?php echo $s['pass']; ?></div>
                    <div style="color:#065f46; font-size:13px;">Bestanden</div>
                </div>
                <div style="flex:1; min-width:120px; padding:20px; background:<?php echo $s['fail'] > 0 ? '#fee2e2' : '#f3f4f6'; ?>; border-radius:8px; text-align:center;">
                    <div style="font-size:32px; font-weight:700; color:<?php echo $s['fail'] > 0 ? '#991b1b' : '#9ca3af'; ?>;">❌ <?php echo $s['fail']; ?></div>
                    <div style="color:#991b1b; font-size:13px;">Fehler</div>
                </div>
                <div style="flex:1; min-width:120px; padding:20px; background:<?php echo $s['warn'] > 0 ? '#fef3c7' : '#f3f4f6'; ?>; border-radius:8px; text-align:center;">
                    <div style="font-size:32px; font-weight:700; color:<?php echo $s['warn'] > 0 ? '#92400e' : '#9ca3af'; ?>;">⚠️ <?php echo $s['warn']; ?></div>
                    <div style="color:#92400e; font-size:13px;">Warnungen</div>
                </div>
                <div style="flex:1; min-width:120px; padding:20px; background:#eff6ff; border-radius:8px; text-align:center;">
                    <div style="font-size:32px; font-weight:700; color:#1e40af;">ℹ️ <?php echo $s['info']; ?></div>
                    <div style="color:#1e40af; font-size:13px;">Info</div>
                </div>
            </div>

            <!-- Filter-Buttons -->
            <div style="margin-bottom:16px;">
                <button type="button" class="button pp-diag-filter" data-filter="all">Alle (<?php echo $total; ?>)</button>
                <button type="button" class="button pp-diag-filter" data-filter="fail" style="color:#991b1b;">❌ Fehler (<?php echo $s['fail']; ?>)</button>
                <button type="button" class="button pp-diag-filter" data-filter="warn" style="color:#92400e;">⚠️ Warnungen (<?php echo $s['warn']; ?>)</button>
                <button type="button" class="button pp-diag-filter" data-filter="pass" style="color:#065f46;">✅ Bestanden (<?php echo $s['pass']; ?>)</button>
            </div>

            <!-- Ergebnisse nach Gruppen -->
            <?php
            $byGroup = [];
            foreach ($this->results as $r) {
                $byGroup[$r['group']][] = $r;
            }

            foreach ($byGroup as $group => $items):
                $groupFails = count(array_filter($items, fn($r) => $r['type'] === 'fail'));
                $groupWarns = count(array_filter($items, fn($r) => $r['type'] === 'warn'));
                $groupIcon = $groupFails > 0 ? '❌' : ($groupWarns > 0 ? '⚠️' : '✅');
                ?>
                <div class="pp-diag-group" style="margin-bottom:16px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                    <div style="padding:12px 16px; background:#f9fafb; border-bottom:1px solid #e5e7eb; font-weight:600; font-size:14px; cursor:pointer;"
                         onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'">
                        <?php echo $groupIcon; ?> <?php echo esc_html($group); ?>
                        <span style="float:right; font-weight:400; color:#6b7280; font-size:12px;">
                            <?php echo count($items); ?> Tests
                            <?php if ($groupFails): ?><span style="color:#991b1b;"> · <?php echo $groupFails; ?> Fehler</span><?php endif; ?>
                            <?php if ($groupWarns): ?><span style="color:#92400e;"> · <?php echo $groupWarns; ?> Warnungen</span><?php endif; ?>
                        </span>
                    </div>
                    <div style="<?php echo ($groupFails > 0 || $groupWarns > 0) ? '' : 'display:none;'; ?>">
                        <table class="widefat" style="border:0; border-radius:0;">
                            <tbody>
                            <?php foreach ($items as $r):
                                $icon = match($r['type']) {
                                    'pass' => '✅',
                                    'fail' => '❌',
                                    'warn' => '⚠️',
                                    'info' => 'ℹ️',
                                };
                                $bg = match($r['type']) {
                                    'fail' => '#fef2f2',
                                    'warn' => '#fffbeb',
                                    default => '',
                                };
                                ?>
                                <tr class="pp-diag-row" data-type="<?php echo $r['type']; ?>"
                                    style="<?php echo $bg ? "background:{$bg};" : ''; ?>">
                                    <td style="width:30px; text-align:center; padding:8px;"><?php echo $icon; ?></td>
                                    <td style="padding:8px; font-size:13px;"><?php echo esc_html($r['detail']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Zeitstempel -->
            <p style="color:#9ca3af; font-size:12px; margin-top:24px;">
                Diagnose ausgeführt: <?php echo current_time('d.m.Y H:i:s'); ?> · 
                PHP <?php echo PHP_VERSION; ?> · 
                WP <?php echo get_bloginfo('version'); ?> · 
                MySQL <?php global $wpdb; echo $wpdb->db_version(); ?>
            </p>
        </div>

        <script>
        jQuery(function($) {
            // Filter
            $('.pp-diag-filter').on('click', function() {
                var filter = $(this).data('filter');
                $('.pp-diag-filter').removeClass('button-primary');
                $(this).addClass('button-primary');

                if (filter === 'all') {
                    $('.pp-diag-row').show();
                    $('.pp-diag-group > div:last-child').show();
                } else {
                    $('.pp-diag-row').hide();
                    $('.pp-diag-row[data-type="' + filter + '"]').show();
                    // Gruppen ohne sichtbare Rows ausblenden
                    $('.pp-diag-group').each(function() {
                        var visible = $(this).find('.pp-diag-row:visible').length;
                        $(this).find('> div:last-child')[visible > 0 ? 'show' : 'hide']();
                        $(this).toggle(visible > 0);
                    });
                }
            });
        });
        </script>
        <?php
    }
}

// Auto-Boot wenn wir im Admin sind
if (is_admin()) {
    PP_Diagnostic::boot();
}
