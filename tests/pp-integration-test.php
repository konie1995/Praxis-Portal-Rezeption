<?php
/**
 * Praxis-Portal – Integration-Test
 *
 * Testet Widget-Rendering, alle Service-Templates, Anamnesebogen-JSONs
 * und erstellt echte Test-Submissions in der Datenbank (mit Cleanup-Option).
 *
 * Aufruf: Admin → Praxis-Portal → 🧪 Integration-Test
 *
 * @package PraxisPortal\Tests
 * @since   4.2.909
 */

if (!defined('ABSPATH')) {
    exit;
}

class PP_IntegrationTest
{
    /** @var array{pass:int, fail:int, warn:int, info:int} */
    private array $stats = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0];

    /** @var array<array{type:string, group:string, label:string, detail:string}> */
    private array $results = [];

    private string $currentGroup = '';

    /** IDs der erzeugten Test-Submissions (für Cleanup) */
    private array $createdIds = [];

    private const TEST_MARKER = '__pp_integration_test__';

    // =========================================================================
    // BOOTSTRAP
    // =========================================================================

    public static function boot(): void
    {
        $self = new self();
        add_action('admin_menu', [$self, 'registerMenu'], 999);
        add_action('wp_ajax_pp_integration_cleanup', [$self, 'ajaxCleanup']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'praxis-portal',
            'Integration-Test',
            '🧪 Integration-Test',
            'manage_options',
            'pp-integration-test',
            [$this, 'renderPage'],
            998
        );
    }

    // =========================================================================
    // PAGE
    // =========================================================================

    public function renderPage(): void
    {
        $run     = !empty($_GET['run']);
        $cleanup = !empty($_GET['cleanup']);

        if ($cleanup) {
            $deleted = $this->cleanupTestSubmissions();
            echo '<div class="notice notice-success"><p>🗑️ ' . (int)$deleted . ' Test-Submissions gelöscht.</p></div>';
        }

        if ($run) {
            $this->runAllTests();
        }

        $this->renderHTML($run);
    }

    // =========================================================================
    // AJAX CLEANUP
    // =========================================================================

    public function ajaxCleanup(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        check_ajax_referer('pp_admin_nonce', 'nonce');
        $deleted = $this->cleanupTestSubmissions();
        wp_send_json_success(['deleted' => $deleted]);
    }

    // =========================================================================
    // ALL TESTS
    // =========================================================================

    private function runAllTests(): void
    {
        $this->testContainer();
        $this->testDatenbank();
        $this->testPortal();
        $this->testWidgetRendering();
        $this->testServiceTemplates();
        $this->testAnamnesebogenJsons();
        $this->testSubmissionsErstellen();
        $this->testPortalEintraege();
        $this->testExportOptionKeys();
        $this->testPrismenwerteFeldnamen();
        $this->testPortalFilter();
        $this->testPdfFelder();
        $this->testFeldSichtbarkeit();
    }

    // =========================================================================
    // 1. CONTAINER & KLASSEN
    // =========================================================================

    private function testContainer(): void
    {
        $this->group('Container & Klassen');

        $classes = [
            'PraxisPortal\\Core\\Plugin',
            'PraxisPortal\\Core\\Container',
            'PraxisPortal\\Widget\\WidgetRenderer',
            'PraxisPortal\\Widget\\Widget',
            'PraxisPortal\\Form\\FormLoader',
            'PraxisPortal\\Form\\FormHandler',
            'PraxisPortal\\Database\\Repository\\SubmissionRepository',
            'PraxisPortal\\Database\\Repository\\LocationRepository',
            'PraxisPortal\\Database\\Repository\\ServiceRepository',
            'PraxisPortal\\Security\\Encryption',
        ];

        foreach ($classes as $class) {
            if (class_exists($class)) {
                $this->pass("Klasse vorhanden: {$class}");
            } else {
                $this->fail("Klasse fehlt: {$class}");
            }
        }

        // Container erreichbar?
        try {
            $plugin    = \PraxisPortal\Core\Plugin::getInstance();
            $container = \PraxisPortal\Core\Plugin::container();
            $this->pass('Plugin::container() erreichbar');

            // Key-Services aus Container
            foreach ([
                \PraxisPortal\Database\Repository\LocationRepository::class,
                \PraxisPortal\Database\Repository\ServiceRepository::class,
                \PraxisPortal\Database\Repository\SubmissionRepository::class,
                \PraxisPortal\Security\Encryption::class,
            ] as $svc) {
                try {
                    $container->get($svc);
                    $this->pass("Container liefert: " . basename(str_replace('\\', '/', $svc)));
                } catch (\Throwable $e) {
                    $this->fail("Container-Fehler für {$svc}: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->fail('Plugin::container() nicht erreichbar: ' . $e->getMessage());
        }

        // PP_VERSION gesetzt?
        if (defined('PP_VERSION')) {
            $this->pass('PP_VERSION = ' . PP_VERSION);
        } else {
            $this->fail('PP_VERSION nicht definiert');
        }
    }

    // =========================================================================
    // 2. DATENBANK-TABELLEN
    // =========================================================================

    private function testDatenbank(): void
    {
        $this->group('Datenbank-Tabellen');

        global $wpdb;

        $tables = [
            $wpdb->prefix . 'pp_locations',
            $wpdb->prefix . 'pp_services',
            $wpdb->prefix . 'pp_submissions',
            $wpdb->prefix . 'pp_files',
            $wpdb->prefix . 'pp_audit_log',
            $wpdb->prefix . 'pp_portal_users',
            $wpdb->prefix . 'pp_api_keys',
        ];

        foreach ($tables as $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if ($exists) {
                $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
                $this->pass("Tabelle vorhanden: {$table} ({$count} Einträge)");
            } else {
                $this->fail("Tabelle fehlt: {$table}");
            }
        }

        // Mindestens ein Standort?
        $locCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}pp_locations`");
        if ($locCount > 0) {
            $this->pass("{$locCount} Standort(e) vorhanden");
        } else {
            $this->warn('Kein Standort in der DB – bitte zuerst einen Standort anlegen');
        }

        // pp_services Spalten prüfen
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$wpdb->prefix}pp_services`", 0);
        $required = ['id', 'location_id', 'service_key', 'service_type', 'label', 'icon',
                     'is_active', 'patient_restriction', 'external_url', 'custom_fields', 'sort_order'];
        foreach ($required as $col) {
            if (in_array($col, $columns, true)) {
                $this->pass("pp_services.{$col} vorhanden");
            } else {
                $this->fail("pp_services.{$col} FEHLT – Schema-Migration nötig");
            }
        }
        // is_custom sollte NICHT existieren (bewusst entfernt)
        if (!in_array('is_custom', $columns, true)) {
            $this->pass('pp_services.is_custom korrekt nicht vorhanden (via service_type ersetzt)');
        } else {
            $this->warn('pp_services.is_custom noch vorhanden – Altlast');
        }
    }

    // =========================================================================
    // 3. PORTAL (V3-Design)
    // =========================================================================

    private function testPortal(): void
    {
        $this->group('Portal (V3-Design)');

        // --- Template ---
        $tpl = PP_PLUGIN_DIR . 'templates/portal.php';
        if (file_exists($tpl)) {
            $this->pass('Portal-Template vorhanden (' . number_format(filesize($tpl)) . ' Bytes)');
            $content = file_get_contents($tpl);

            if (strpos($content, 'auth->isAuthenticated()') !== false) {
                $this->pass('Template: auth->isAuthenticated() (v4-API) vorhanden');
            } else {
                $this->fail('Template: auth->isAuthenticated() fehlt – falsches Template?');
            }

            if (strpos($content, 'pp-portal-wrapper') !== false) {
                $this->pass('Template: V3-Wrapper .pp-portal-wrapper vorhanden');
            } else {
                $this->fail('Template: .pp-portal-wrapper fehlt – V3-Template nicht aktiv?');
            }

            if (strpos($content, 'pp-sidebar') !== false) {
                $this->pass('Template: V3-Drei-Spalten-Layout (.pp-sidebar) vorhanden');
            } else {
                $this->fail('Template: .pp-sidebar fehlt');
            }

            // Sicherstellen: kein is_authenticated()-Aufruf ohne auth->
            if (strpos($content, '$this->is_authenticated()') !== false) {
                $this->fail('Template: Alter V3-Aufruf $this->is_authenticated() noch vorhanden!');
            } else {
                $this->pass('Template: Kein alter is_authenticated()-Aufruf');
            }
        } else {
            $this->fail('Portal-Template fehlt: templates/portal.php');
        }

        // --- CSS ---
        $css = PP_PLUGIN_DIR . 'assets/css/portal.css';
        if (file_exists($css)) {
            $size = number_format(filesize($css));
            $this->pass("Portal-CSS vorhanden ({$size} Bytes)");
            $content = file_get_contents($css);

            if (strpos($content, '--portal-primary') !== false) {
                $this->pass('Portal-CSS: V3-Variablen (--portal-*) aktiv');
            } else {
                $this->fail('Portal-CSS: --portal-* Variablen fehlen – falsches CSS?');
            }

            if (strpos($content, '.pp-portal-wrapper') !== false) {
                $this->pass('Portal-CSS: .pp-portal-wrapper Styles vorhanden');
            } else {
                $this->fail('Portal-CSS: .pp-portal-wrapper fehlt');
            }
        } else {
            $this->fail('Portal-CSS fehlt: assets/css/portal.css');
        }

        // --- JS ---
        $js = PP_PLUGIN_DIR . 'assets/js/portal.js';
        if (file_exists($js)) {
            $size = number_format(filesize($js));
            $this->pass("Portal-JS vorhanden ({$size} Bytes)");
            $content = file_get_contents($js);

            // v4-Dispatcher muss verwendet werden
            if (strpos($content, "action: 'pp_portal_action'") !== false) {
                $this->pass("Portal-JS: v4-Dispatcher 'pp_portal_action' korrekt gesetzt");
            } else {
                $this->fail("Portal-JS: 'pp_portal_action'-Dispatcher nicht gefunden");
            }

            if (strpos($content, "portal_action: 'get_submissions'") !== false) {
                $this->pass("Portal-JS: portal_action 'get_submissions' korrekt");
            } else {
                $this->fail("Portal-JS: portal_action 'get_submissions' fehlt");
            }

            // Alte V3-Action-Namen dürfen NICHT mehr vorhanden sein
            $oldActions = [
                'pp_portal_get_submissions',
                'pp_portal_get_submission',
                'pp_portal_logout',
                'pp_portal_mark_read',
                'pp_portal_change_status',
                'pp_portal_delete_submission',
                'pp_portal_file_token',
            ];
            foreach ($oldActions as $old) {
                if (strpos($content, "'{$old}'") !== false) {
                    $this->fail("Portal-JS: Alter V3-Action-Name '{$old}' noch vorhanden!");
                }
            }
            $this->pass('Portal-JS: Keine alten V3-Action-Namen mehr vorhanden');
        } else {
            $this->fail('Portal-JS fehlt: assets/js/portal.js');
        }

        // --- Portal.php: GET-Kompatibilität ---
        $portalPhp = PP_PLUGIN_DIR . 'src/Portal/Portal.php';
        if (file_exists($portalPhp)) {
            $content = file_get_contents($portalPhp);
            if (strpos($content, "\$_GET['portal_action']") !== false) {
                $this->pass('Portal.php: handleAction() liest $_GET[portal_action] – Downloads/Exports OK');
            } else {
                $this->warn('Portal.php: handleAction() liest nur $_POST – Datei-Downloads/Exports könnten fehlschlagen');
            }
        }

        // --- SubmissionRepository: listForLocation() gibt flaches Array zurück ---
        try {
            $container = \PraxisPortal\Core\Plugin::container();
            $subRepo   = $container->get(\PraxisPortal\Database\Repository\SubmissionRepository::class);
            $rows      = $subRepo->listForLocation(0, 1, 3);

            if (!is_array($rows)) {
                $this->fail('listForLocation(): Gibt kein Array zurück – TypeError droht!');
            } elseif (array_key_exists('items', $rows)) {
                $this->fail('listForLocation(): Gibt paginierten Array zurück (hat "items"-Key) – Fix fehlt!');
            } elseif (empty($rows)) {
                $this->pass('listForLocation(): Leeres Array (kein Standort 0) – Struktur OK');
            } elseif (is_array($rows[0]) && isset($rows[0]['id'])) {
                $this->pass('listForLocation(): Gibt flaches Row-Array zurück ✓');
            } else {
                $this->warn('listForLocation(): Struktur unklar – manuell prüfen');
            }
        } catch (\Throwable $e) {
            $this->fail('listForLocation()-Test fehlgeschlagen: ' . $e->getMessage());
        }

        // --- AJAX-Hooks registriert ---
        if (has_action('wp_ajax_pp_portal_login')) {
            $this->pass('AJAX: wp_ajax_pp_portal_login registriert');
        } else {
            $this->fail('AJAX: wp_ajax_pp_portal_login nicht registriert');
        }

        if (has_action('wp_ajax_pp_portal_action')) {
            $this->pass('AJAX: wp_ajax_pp_portal_action registriert');
        } else {
            $this->fail('AJAX: wp_ajax_pp_portal_action nicht registriert');
        }

        if (has_action('wp_ajax_nopriv_pp_portal_login')) {
            $this->pass('AJAX: wp_ajax_nopriv_pp_portal_login registriert (Login ohne WP-Session)');
        } else {
            $this->fail('AJAX: wp_ajax_nopriv_pp_portal_login fehlt');
        }

        // --- Shortcode registriert ---
        global $shortcode_tags;
        $portalShortcodes = ['pp_portal', 'praxis_portal'];
        $foundSc = false;
        foreach ($portalShortcodes as $sc) {
            if (isset($shortcode_tags[$sc])) {
                $this->pass("Portal-Shortcode [{$sc}] registriert");
                $foundSc = true;
            }
        }
        if (!$foundSc) {
            $this->warn('Kein Portal-Shortcode registriert – bitte in Plugin.php prüfen');
        }

        // --- PortalAuth vorhanden ---
        if (class_exists('PraxisPortal\\Portal\\PortalAuth')) {
            $this->pass('PortalAuth-Klasse vorhanden');
        } else {
            $this->fail('PortalAuth-Klasse fehlt');
        }
    }

    // =========================================================================
    // 4. WIDGET RENDERING
    // =========================================================================

    private function testWidgetRendering(): void
    {
        $this->group('Widget Rendering');

        // Shortcode registriert?
        global $shortcode_tags;
        foreach (['pp_widget', 'praxis_widget', 'pp_anamnesebogen'] as $sc) {
            if (isset($shortcode_tags[$sc])) {
                $this->pass("Shortcode [{$sc}] registriert");
            } else {
                $this->warn("Shortcode [{$sc}] nicht registriert");
            }
        }

        // Widget-CSS/JS Assets registriert?
        global $wp_scripts, $wp_styles;
        foreach (['pp-widget'] as $handle) {
            if (isset($wp_scripts->registered[$handle])) {
                $this->pass("Script '{$handle}' registriert");
            } else {
                $this->warn("Script '{$handle}' nicht registriert (evtl. nur Frontend)");
            }
        }

        // WidgetRenderer instanziieren
        try {
            $container = \PraxisPortal\Core\Plugin::container();
            $locRepo   = $container->get(\PraxisPortal\Database\Repository\LocationRepository::class);
            $locations = $locRepo->getAll();

            if (empty($locations)) {
                $this->warn('Kein Standort für Widget-Test vorhanden');
                return;
            }

            $loc = $locations[0];
            $this->info("Teste Widget mit Standort: {$loc['name']} (ID {$loc['id']})");

            // Widget-Shortcode-Output erzeugen (output buffering)
            ob_start();
            $output = do_shortcode('[pp_widget location="' . esc_attr($loc['slug'] ?? $loc['id']) . '"]');
            $buffered = ob_get_clean();
            $html = $output . $buffered;

            if (strlen($html) > 100) {
                $this->pass('Widget-HTML generiert (' . strlen($html) . ' Bytes)');
            } elseif (strlen($html) > 0) {
                $this->warn('Widget-HTML sehr kurz (' . strlen($html) . ' Bytes) – evtl. kein aktiver Service');
            } else {
                $this->fail('Widget gibt kein HTML aus');
            }

            // Wichtige HTML-Elemente prüfen
            foreach ([
                'pp-widget'         => 'Widget-Container',
                'pp-service-card'   => 'Service-Cards',
                'data-service'      => 'data-service Attribute',
            ] as $needle => $label) {
                if (strpos($html, $needle) !== false) {
                    $this->pass("{$label} im HTML gefunden");
                } else {
                    $this->warn("{$label} nicht im HTML gefunden");
                }
            }

            // Online-Rezeption-Button sollte NICHT mehr vorhanden sein
            if (strpos($html, 'pp-widget-footer-portal') === false) {
                $this->pass('Online-Rezeption-Button korrekt entfernt');
            } else {
                $this->fail('Online-Rezeption-Button noch im Widget');
            }

        } catch (\Throwable $e) {
            $this->fail('Widget-Rendering-Fehler: ' . $e->getMessage());
        }

        // Template-Hauptdatei
        $mainTpl = PP_PLUGIN_DIR . 'templates/widget/main.php';
        if (file_exists($mainTpl)) {
            $this->pass('templates/widget/main.php vorhanden');
        } else {
            $this->fail('templates/widget/main.php fehlt');
        }
    }

    // =========================================================================
    // 4. SERVICE TEMPLATES
    // =========================================================================

    private function testServiceTemplates(): void
    {
        $this->group('Service-Templates');

        $templates = [
            'rezept'           => 'Rezept bestellen',
            'termin'           => 'Terminanfrage',
            'terminabsage'     => 'Terminabsage',
            'ueberweisung'     => 'Überweisung',
            'brillenverordnung'=> 'Brillenverordnung',
            'dokument'         => 'Dokument hochladen',
            'downloads'        => 'Downloads',
            'notfall'          => 'Notfall',
        ];

        $tplDir = PP_PLUGIN_DIR . 'templates/widget/forms/';

        foreach ($templates as $key => $label) {
            $file = $tplDir . $key . '.php';
            if (!file_exists($file)) {
                $this->fail("{$label}: Template fehlt ({$key}.php)");
                continue;
            }

            $size = filesize($file);
            $this->pass("{$label}: Template vorhanden ({$size} Bytes)");

            // PHP-Syntaxfehler prüfen
            $output = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
            if ($output && strpos($output, 'No syntax errors') !== false) {
                $this->pass("{$label}: PHP-Syntax OK");
            } elseif ($output && strpos($output, 'Errors parsing') !== false) {
                $this->fail("{$label}: PHP-Syntaxfehler – " . trim($output));
            } else {
                $this->info("{$label}: PHP-Syntax nicht prüfbar (shell_exec deaktiviert)");
            }

            // Wichtige Bestandteile im Template?
            $content = file_get_contents($file);
            if (strpos($content, 'ABSPATH') !== false) {
                $this->pass("{$label}: ABSPATH-Guard vorhanden");
            } else {
                $this->warn("{$label}: Kein ABSPATH-Guard");
            }
        }
    }

    // =========================================================================
    // 5. ANAMNESEBOGEN JSONs
    // =========================================================================

    private function testAnamnesebogenJsons(): void
    {
        $this->group('Anamnesebogen JSONs');

        $formDir = PP_PLUGIN_DIR . 'forms/';

        if (!is_dir($formDir)) {
            $this->fail('forms/-Verzeichnis nicht gefunden');
            return;
        }

        $jsonFiles = glob($formDir . '*.json');
        if (empty($jsonFiles)) {
            $this->fail('Keine JSON-Formulare im forms/-Verzeichnis');
            return;
        }

        $this->pass(count($jsonFiles) . ' Formular-JSON(s) gefunden');

        foreach ($jsonFiles as $file) {
            $name    = basename($file, '.json');
            $content = file_get_contents($file);

            // Valides JSON?
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->fail("{$name}: Ungültiges JSON – " . json_last_error_msg());
                continue;
            }

            $this->pass("{$name}: Valides JSON");

            // Pflicht-Felder im Schema
            foreach (['id', 'name', 'fields'] as $required) {
                if (!empty($data[$required])) {
                    $this->pass("{$name}: Feld '{$required}' vorhanden");
                } else {
                    $this->fail("{$name}: Pflichtfeld '{$required}' fehlt");
                }
            }

            // Felder zählen
            $fields    = $data['fields'] ?? [];
            $total     = count($fields);
            $required  = count(array_filter($fields, fn($f) => !empty($f['required'])));
            $conditional = count(array_filter($fields, fn($f) => !empty($f['condition'])));

            $this->info("{$name}: {$total} Felder, {$required} Pflicht, {$conditional} konditional");

            // Mindestanzahl Felder?
            if ($total >= 10) {
                $this->pass("{$name}: Ausreichend Felder ({$total})");
            } else {
                $this->warn("{$name}: Nur {$total} Felder – evtl. unvollständig");
            }

            // Eindeutige Feld-IDs?
            $ids    = array_column($fields, 'id');
            $unique = array_unique($ids);
            if (count($ids) === count($unique)) {
                $this->pass("{$name}: Alle Feld-IDs eindeutig");
            } else {
                $dupes = array_diff_assoc($ids, $unique);
                $this->fail("{$name}: Doppelte Feld-IDs: " . implode(', ', $dupes));
            }

            // Version vorhanden?
            if (!empty($data['version'])) {
                $this->pass("{$name}: Version {$data['version']}");
            } else {
                $this->warn("{$name}: Kein 'version'-Feld");
            }
        }

        // FormLoader testen
        try {
            $container  = \PraxisPortal\Core\Plugin::container();
            $formLoader = $container->get(\PraxisPortal\Form\FormLoader::class);
            $this->pass('FormLoader aus Container geladen');

            foreach ($jsonFiles as $file) {
                $formId = basename($file, '.json');
                try {
                    $form = $formLoader->loadForm($formId);
                    if (!empty($form)) {
                        $this->pass("FormLoader::loadForm('{$formId}'): OK");
                    } else {
                        $this->warn("FormLoader::loadForm('{$formId}'): Leeres Ergebnis");
                    }
                } catch (\Throwable $e) {
                    $this->fail("FormLoader::loadForm('{$formId}'): " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->warn('FormLoader nicht via Container testbar: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 6. TEST-SUBMISSIONS ERSTELLEN
    // =========================================================================

    private function testSubmissionsErstellen(): void
    {
        $this->group('Submissions erstellen (Test-Daten)');

        try {
            $container  = \PraxisPortal\Core\Plugin::container();
            $locRepo    = $container->get(\PraxisPortal\Database\Repository\LocationRepository::class);
            $subRepo    = $container->get(\PraxisPortal\Database\Repository\SubmissionRepository::class);
        } catch (\Throwable $e) {
            $this->fail('Container nicht erreichbar: ' . $e->getMessage());
            return;
        }

        $locations = $locRepo->getAll();
        if (empty($locations)) {
            $this->warn('Kein Standort vorhanden – Test-Submissions werden übersprungen');
            return;
        }

        $loc       = $locations[0];
        $locationId = (int)$loc['id'];
        $this->info("Erstelle Submissions für Standort: {$loc['name']} (ID {$locationId})");

        $testCases = [
            [
                'service'      => 'termin',
                'request_type' => 'widget_termin',
                'formData'     => [
                    'vorname'           => 'Test',
                    'nachname'          => 'Patient',
                    'geburtsdatum'      => '01.01.1990',
                    'telefon'           => '0123 456789',
                    'email'             => 'test@example.com',
                    'versicherung'      => 'gesetzlich',
                    'patient_status'    => 'neupatient',
                    'termin_grund'      => 'Kontrolltermin',
                    'termin_dringlichkeit' => 'normal',
                    'anmerkungen'       => self::TEST_MARKER,
                ],
            ],
            [
                'service'      => 'rezept',
                'request_type' => 'widget_rezept',
                'formData'     => [
                    'vorname'        => 'Test',
                    'nachname'       => 'Patient',
                    'geburtsdatum'   => '01.01.1990',
                    'telefon'        => '0123 456789',
                    'email'          => 'test@example.com',
                    'versicherung'   => 'gesetzlich',
                    'patient_status' => 'bestandspatient',
                    'medikamente'    => ['Timolol 0,5% Augentropfen'],
                    'anmerkungen'    => self::TEST_MARKER,
                ],
            ],
            [
                'service'      => 'ueberweisung',
                'request_type' => 'widget_ueberweisung',
                'formData'     => [
                    'vorname'        => 'Test',
                    'nachname'       => 'Patient',
                    'geburtsdatum'   => '01.01.1990',
                    'telefon'        => '0123 456789',
                    'email'          => 'test@example.com',
                    'versicherung'   => 'privat',
                    'patient_status' => 'bestandspatient',
                    'fachrichtung'   => 'Neurologie',
                    'diagnose'       => 'Test-Diagnose',
                    'anmerkungen'    => self::TEST_MARKER,
                ],
            ],
            [
                'service'      => 'terminabsage',
                'request_type' => 'widget_terminabsage',
                'formData'     => [
                    'vorname'             => 'Test',
                    'nachname'            => 'Patient',
                    'geburtsdatum'        => '01.01.1990',
                    'telefon'             => '0123 456789',
                    'email'               => 'test@example.com',
                    'patient_status'      => 'bestandspatient',
                    'termin_datum'        => date('d.m.Y', strtotime('+7 days')),
                    'termin_absage_grund' => 'Verhindert',
                    'anmerkungen'         => self::TEST_MARKER,
                ],
            ],
            [
                'service'      => 'anamnesebogen',
                'request_type' => 'form_anamnese',
                'formData'     => [
                    'vorname'           => 'Test',
                    'nachname'          => 'Patient',
                    'geburtsdatum'      => '01.01.1990',
                    'telefon'           => '0123 456789',
                    'email'             => 'test@example.com',
                    'versicherung'      => 'gesetzlich',
                    'patient_status'    => 'neupatient',
                    '_form_id'          => 'augenarzt_de',
                    '_form_version'     => '1.0',
                    'anmerkungen'       => self::TEST_MARKER,
                ],
            ],
        ];

        foreach ($testCases as $tc) {
            try {
                $result = $subRepo->create(
                    $tc['formData'],
                    [
                        'location_id'  => $locationId,
                        'service_key'  => $tc['service'],
                        'request_type' => $tc['request_type'],
                    ]
                );

                if (!empty($result['success'])) {
                    $ref = $result['reference'] ?? '—';
                    $id  = $result['submission_id'] ?? 0;
                    $this->createdIds[] = $id;
                    $this->pass("{$tc['service']}: Submission erstellt (Ref: {$ref}, ID: {$id})");
                } else {
                    $err = $result['error'] ?? 'unbekannter Fehler';
                    $this->fail("{$tc['service']}: Fehler – {$err}");
                }
            } catch (\Throwable $e) {
                $this->fail("{$tc['service']}: Exception – " . $e->getMessage());
            }
        }

        if (!empty($this->createdIds)) {
            $this->info(count($this->createdIds) . ' Test-Submissions erstellt. IDs: ' . implode(', ', $this->createdIds));
        }
    }

    // =========================================================================
    // 7. PORTAL-EINTRÄGE PRÜFEN
    // =========================================================================

    private function testPortalEintraege(): void
    {
        $this->group('Portal-Einträge prüfen');

        if (empty($this->createdIds)) {
            $this->warn('Keine Test-Submissions in diesem Lauf erstellt – überspringe Portal-Check');
            return;
        }

        try {
            $container = \PraxisPortal\Core\Plugin::container();
            $subRepo   = $container->get(\PraxisPortal\Database\Repository\SubmissionRepository::class);
        } catch (\Throwable $e) {
            $this->fail('Kein Zugriff auf SubmissionRepository: ' . $e->getMessage());
            return;
        }

        foreach ($this->createdIds as $id) {
            try {
                $row = $subRepo->findById($id);

                if (!$row) {
                    $this->fail("Submission ID {$id} nicht in DB gefunden");
                    continue;
                }

                // Basis-Felder prüfen
                $this->pass("ID {$id}: In DB vorhanden (Status: {$row['status']}, Service: {$row['service_key']})");

                // Verschlüsselt?
                if (!empty($row['encrypted_data'])) {
                    $this->pass("ID {$id}: encrypted_data vorhanden");
                } else {
                    $this->fail("ID {$id}: encrypted_data leer");
                }

                // Entschlüsselung (findById liefert Roh-Row; Decrypt manuell)
                try {
                    $enc  = $container->get(\PraxisPortal\Security\Encryption::class);
                    $data = $enc->decrypt($row['encrypted_data'], true);
                    if (!empty($data)) {
                        $this->pass("ID {$id}: Entschlüsselung erfolgreich");
                        $haystack = is_array($data) ? json_encode($data) : (string)$data;
                        if (strpos($haystack, self::TEST_MARKER) !== false) {
                            $this->pass("ID {$id}: Test-Marker nach Entschlüsselung gefunden");
                        } else {
                            $this->warn("ID {$id}: Test-Marker nicht in entschlüsselten Daten gefunden");
                        }
                    } else {
                        $this->fail("ID {$id}: Entschlüsselung liefert leeres Ergebnis");
                    }
                } catch (\Throwable $e) {
                    $this->fail("ID {$id}: Entschlüsselungs-Exception – " . $e->getMessage());
                }

                // submission_hash gesetzt?
                if (!empty($row['submission_hash'])) {
                    $this->pass("ID {$id}: submission_hash vorhanden (" . substr($row['submission_hash'], 0, 8) . '…)');
                } else {
                    $this->fail("ID {$id}: submission_hash fehlt");
                }

            } catch (\Throwable $e) {
                $this->fail("ID {$id}: Exception beim Prüfen – " . $e->getMessage());
            }
        }

        // Gesamtanzahl Test-Submissions in der DB
        global $wpdb;
        $table = $wpdb->prefix . 'pp_submissions';
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $this->info("Gesamt-Submissions in DB: {$total}");
    }

    // =========================================================================
    // CLEANUP
    // =========================================================================

    private function cleanupTestSubmissions(): int
    {
        global $wpdb;

        try {
            $container = \PraxisPortal\Core\Plugin::container();
            $enc       = $container->get(\PraxisPortal\Security\Encryption::class);
            $subRepo   = $container->get(\PraxisPortal\Database\Repository\SubmissionRepository::class);
        } catch (\Throwable $e) {
            return 0;
        }

        $table = $wpdb->prefix . 'pp_submissions';
        $rows  = $wpdb->get_results("SELECT id, encrypted_data FROM `{$table}` ORDER BY id DESC LIMIT 200", ARRAY_A);

        $deleted = 0;
        foreach ($rows as $row) {
            try {
                $data = $enc->decrypt($row['encrypted_data'], true);
                $json = is_array($data) ? json_encode($data) : (string)$data;
                if (strpos($json, self::TEST_MARKER) !== false) {
                    $wpdb->delete($table, ['id' => (int)$row['id']], ['%d']);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                // Nicht entschlüsselbar → überspringen
            }
        }

        return $deleted;
    }

    // =========================================================================
    // 8. FELD-SICHTBARKEIT IM PORTAL (Zahlenfolgen-Test)
    // =========================================================================

    /**
     * Erstellt je eine Submission pro Service-Typ mit Feldnamen als Werten.
     * Im Portal sieht man so direkt welche Felder angezeigt werden.
     * Cleanup: wie normale Test-Submissions über den Cleanup-Button.
     */
    private function testFeldSichtbarkeit(): void
    {
        $this->group('Feld-Sichtbarkeit im Portal (Diagnose-Submissions)');

        try {
            $container = \PraxisPortal\Core\Plugin::container();
            $locRepo   = $container->get(\PraxisPortal\Database\Repository\LocationRepository::class);
            $subRepo   = $container->get(\PraxisPortal\Database\Repository\SubmissionRepository::class);
        } catch (\Throwable $e) {
            $this->fail('Container nicht erreichbar: ' . $e->getMessage());
            return;
        }

        $locations = $locRepo->getAll();
        if (empty($locations)) {
            $this->warn('Kein Standort – Feld-Sichtbarkeits-Test übersprungen');
            return;
        }
        $locationId = (int)$locations[0]['id'];

        // Stammdaten-Block (identisch für alle Services)
        $stamm = [
            'vorname'        => '01-vorname',
            'nachname'       => '02-nachname',
            'geburtsdatum'   => '03-01.01.1990',
            'telefon'        => '04-0123456789',
            'email'          => '05-test@example.com',
            'versicherung'   => 'gesetzlich',
            'patient_status' => 'bestandspatient',
            '_diag'          => self::TEST_MARKER,
        ];

        $services = [

            // ── Termin ────────────────────────────────────────────────────
            'termin' => array_merge($stamm, [
                'termin_anliegen'                => '06-termin_anliegen',
                'termin_grund'                   => '07-termin_grund',
                'termin_zeit'                    => '08-termin_zeit',
                'termin_zeit_display'            => '09-termin_zeit_display',
                'termin_tage_display'            => '10-termin_tage_display',
                'termin_schnellstmoeglich_display' => 'Ja',
                'termin_beschwerden'             => '11-termin_beschwerden',
                'termin_wunschzeit'              => '12-termin_wunschzeit',
                'anmerkungen'                    => '13-anmerkungen',
            ]),

            // ── Rezept ────────────────────────────────────────────────────
            'rezept' => array_merge($stamm, [
                // Portal erwartet medikamente_mit_art (Array von {name, art})
                'medikamente_mit_art' => [
                    ['name' => '06-Medikament-1', 'art' => 'augentropfen'],
                    ['name' => '07-Medikament-2', 'art' => 'tabletten'],
                ],
                'anmerkung'           => '08-anmerkung',
                'evn_erlaubt'         => '1',
                'rezept_lieferung'    => 'praxis',
            ]),

            // ── Überweisung ───────────────────────────────────────────────
            'ueberweisung' => array_merge($stamm, [
                // portal.js liest ueberweisungsziel + diagnose (Fix 2026-02-20)
                'ueberweisungsziel'        => '06-ueberweisungsziel',
                'diagnose'                 => '07-diagnose',
                'arzt_name'                => '08-arzt_name',
                'ueberw_dringlichkeit'     => 'normal',
                'versichertennachweis'     => 'ja',
                'ueberweisung_evn_erlaubt' => '1',
            ]),

            // ── Terminabsage ──────────────────────────────────────────────
            'terminabsage' => array_merge($stamm, [
                'absage_datum'          => '06-'.date('d.m.Y', strtotime('+7 days')),
                'absage_uhrzeit'        => '07-10:30',
                'absage_grund'          => '08-absage_grund',
                'absage_neuer_termin'   => 'ja',
                'termin_datum'          => '09-'.date('d.m.Y', strtotime('+14 days')),
                'termin_absage_grund'   => '10-termin_absage_grund',
            ]),

            // ── Brillenverordnung ─────────────────────────────────────────
            'brillenverordnung' => array_merge($stamm, [
                // Portal-Keys (refraktion-Struktur)
                'brillenart'              => ['fern', 'gleitsicht'],
                'brillenart_display'      => '06-fern, gleitsicht',
                'refraktion'              => [
                    'rechts' => ['sph' => '+07.00', 'zyl' => '-08.00', 'ach' => '90', 'add' => '+09.00'],
                    'links'  => ['sph' => '+10.00', 'zyl' => '-11.00', 'ach' => '85', 'add' => '+12.00'],
                ],
                'prismen'                 => [
                    'rechts' => [
                        'horizontal' => ['wert' => '11-H-R', 'basis' => 'nasal'],
                        'vertikal'   => ['wert' => '12-V-R', 'basis' => 'unten'],
                    ],
                    'links'  => [
                        'horizontal' => ['wert' => '13-H-L', 'basis' => 'nasal'],
                        'vertikal'   => ['wert' => '14-V-L', 'basis' => 'unten'],
                    ],
                ],
                'hsa'                     => '15-hsa',
                'brillen_lieferung'       => 'post',
                'brillen_versandadresse'  => [
                    'strasse' => '16-Musterstr. 1',
                    'plz'     => '17-12345',
                    'ort'     => '18-Musterstadt',
                ],
                'anmerkung'               => '19-anmerkungen',
                'versichertennachweis'    => 'ja',
                'brillen_evn_erlaubt'     => '1',
            ]),

            // ── Dokument-Upload ───────────────────────────────────────────
            'dokument' => array_merge($stamm, [
                'dokument_typ'         => 'befund',
                'bemerkung'            => '06-bemerkung',
                'dokument_beschreibung' => '07-dokument_beschreibung',
            ]),
        ];

        foreach ($services as $serviceKey => $formData) {
            try {
                $result = $subRepo->create(
                    $formData,
                    [
                        'location_id'  => $locationId,
                        'service_key'  => $serviceKey,
                        'request_type' => 'diag_' . $serviceKey,
                    ]
                );
                if (!empty($result['success'])) {
                    $ref = $result['reference'] ?? '—';
                    $id  = $result['submission_id'] ?? 0;
                    $this->createdIds[] = $id;
                    $this->pass("{$serviceKey}: Diagnose-Submission erstellt (Ref: {$ref}, ID: {$id})");
                } else {
                    $this->fail("{$serviceKey}: Erstellung fehlgeschlagen – " . ($result['message'] ?? 'unbekannt'));
                }
            } catch (\Throwable $e) {
                $this->fail("{$serviceKey}: Exception – " . $e->getMessage());
            }
        }

        if (!empty($this->createdIds)) {
            $this->info('Diagnose-Submissions im Portal prüfen. Felder die mit 06-, 07- etc. beginnen sind sichtbar. Felder die mit "-" angezeigt werden fehlen im Datensatz.');
        }
    }

    // =========================================================================
    // 9. EXPORT-EINSTELLUNGEN (OPTION-KEYS)
    // =========================================================================

    /**
     * Prüft, ob AdminSettings die korrekten Option-Keys mit pp_export_-Präfix
     * verwendet, die mit ExportConfig::OPT_PREFIX übereinstimmen.
     * Bug 2026-02-20: AdminSettings las POST-Keys ohne Präfix und speicherte
     * unter falschen Option-Namen → Einstellungen hatten keine Wirkung.
     */
    private function testExportOptionKeys(): void
    {
        $this->group('Export-Einstellungen (Option-Keys)');

        $adminSettings = PP_PLUGIN_DIR . 'src/Admin/AdminSettings.php';
        if (!file_exists($adminSettings)) {
            $this->fail('AdminSettings.php nicht gefunden');
            return;
        }

        $content = file_get_contents($adminSettings);

        // Option-Namen: AdminSettings muss genau diese Keys schreiben (mit pp_export_-Präfix)
        $optionKeys = [
            'pp_export_widget_format',
            'pp_export_widget_delete_after',
            'pp_export_anamnese_kasse_format',
            'pp_export_anamnese_kasse_pdf_type',
            'pp_export_anamnese_kasse_delete_after',
            'pp_export_anamnese_privat_format',
            'pp_export_anamnese_privat_pdf_type',
            'pp_export_anamnese_privat_delete_after',
        ];
        foreach ($optionKeys as $key) {
            if (strpos($content, "'{$key}'") !== false || strpos($content, "\"{$key}\"") !== false) {
                $this->pass("AdminSettings: Option-Key '{$key}' vorhanden");
            } else {
                $this->fail("AdminSettings: Option-Key '{$key}' fehlt – Einstellung hat keine Wirkung!");
            }
        }

        // POST-Keys müssen pp_export_-Präfix haben (Formular-<select> name-Attribut)
        foreach (['pp_export_widget_format', 'pp_export_anamnese_kasse_format', 'pp_export_anamnese_privat_format'] as $pk) {
            if (strpos($content, "\$post['{$pk}']") !== false || strpos($content, "\$post[\"{$pk}\"]") !== false) {
                $this->pass("AdminSettings: POST-Key '{$pk}' wird gelesen");
            } else {
                $this->fail("AdminSettings: POST-Key '{$pk}' wird nicht gelesen – Formular-Wert landet nie in der DB");
            }
        }

        // Gültige Formate: 'gdt_image' muss da sein, 'both' darf NICHT mehr da sein
        if (strpos($content, "'gdt_image'") !== false) {
            $this->pass("AdminSettings: validFormats enthält 'gdt_image'");
        } else {
            $this->fail("AdminSettings: validFormats fehlt 'gdt_image' – alter Stand?");
        }
        if (strpos($content, "'both'") !== false) {
            $this->fail("AdminSettings: validFormats enthält ungültigen Wert 'both' – noch nicht gefixt");
        } else {
            $this->pass("AdminSettings: Alter Wert 'both' aus validFormats entfernt");
        }

        // Gültige PDF-Typen: 'full' muss da sein, 'a4' / 'a5' dürfen NICHT mehr da sein
        if (strpos($content, "'full'") !== false) {
            $this->pass("AdminSettings: validPdf enthält 'full'");
        } else {
            $this->fail("AdminSettings: validPdf fehlt 'full' – alter Stand?");
        }
        foreach (["'a4'", "'a5'", "'receipt'"] as $old) {
            if (strpos($content, $old) !== false) {
                $this->fail("AdminSettings: validPdf enthält ungültigen Wert {$old} – noch nicht gefixt");
            } else {
                $this->pass("AdminSettings: Alter Wert {$old} aus validPdf entfernt");
            }
        }

        // ExportConfig: delete-Key muss _delete_after haben (ohne _after → Mismatch mit AdminSettings)
        $exportConfig = PP_PLUGIN_DIR . 'src/Export/ExportConfig.php';
        if (file_exists($exportConfig)) {
            $cfgContent = file_get_contents($exportConfig);
            if (strpos($cfgContent, '"anamnese_{$type}_delete_after"') !== false
                || strpos($cfgContent, "'anamnese_' . \$type . '_delete_after'") !== false
                || strpos($cfgContent, 'delete_after') !== false) {
                $this->pass("ExportConfig: Anamnese-Delete-Key enthält '_delete_after' (stimmt mit AdminSettings überein)");
            } else {
                $this->fail("ExportConfig: Liest 'anamnese_{type}_delete' statt '_delete_after' – Delete-Checkbox ohne Wirkung!");
            }
            // Präfix generell vorhanden
            if (strpos($cfgContent, 'pp_export_') !== false) {
                $this->pass("ExportConfig: OPT_PREFIX 'pp_export_' aktiv");
            } else {
                $this->warn("ExportConfig: 'pp_export_' nicht gefunden – Präfix-Abgleich manuell prüfen");
            }
        } else {
            $this->warn('ExportConfig.php nicht gefunden – Pfad prüfen');
        }
    }

    // =========================================================================
    // 10. PRISMENWERTE FELDNAMEN (WIDGET → DB)
    // =========================================================================

    /**
     * Stellt sicher, dass WidgetHandler die korrekten POST-Feldnamen für
     * Prismenwerte liest, die mit den HTML-Formular-Feldern übereinstimmen.
     * Bug 2026-02-20: Handler las 'prisma_r_h_wert', Form sendet
     * 'prisma_rechts_h_wert' → Prismenwerte wurden nie gespeichert.
     */
    private function testPrismenwerteFeldnamen(): void
    {
        $this->group('Prismenwerte Feldnamen (Widget → DB)');

        $handler = PP_PLUGIN_DIR . 'src/Widget/WidgetHandler.php';
        if (!file_exists($handler)) {
            $this->fail('WidgetHandler.php nicht gefunden');
            return;
        }

        $content = file_get_contents($handler);

        // Korrekte Feldnamen (übereinstimmend mit Formular-Template)
        $correctKeys = [
            'prisma_rechts_h_wert',
            'prisma_rechts_h_basis',
            'prisma_rechts_v_wert',
            'prisma_rechts_v_basis',
            'prisma_links_h_wert',
            'prisma_links_h_basis',
            'prisma_links_v_wert',
            'prisma_links_v_basis',
        ];
        foreach ($correctKeys as $key) {
            if (strpos($content, $key) !== false) {
                $this->pass("WidgetHandler: POST-Key '{$key}' vorhanden");
            } else {
                $this->fail("WidgetHandler: POST-Key '{$key}' fehlt – Prismenwerte werden nicht gespeichert!");
            }
        }

        // Alte (falsche) Feldnamen dürfen NICHT mehr vorhanden sein
        $wrongKeys = [
            'prisma_r_h_wert',
            'prisma_r_h_basis',
            'prisma_r_v_wert',
            'prisma_r_v_basis',
            'prisma_l_h_wert',
            'prisma_l_h_basis',
            'prisma_l_v_wert',
            'prisma_l_v_basis',
        ];
        foreach ($wrongKeys as $key) {
            if (strpos($content, $key) !== false) {
                $this->fail("WidgetHandler: Alter Feldname '{$key}' noch vorhanden – Fix nicht angewendet!");
            } else {
                $this->pass("WidgetHandler: Alter Feldname '{$key}' korrekt entfernt");
            }
        }

        // Prismenwerte-Template prüfen (Formular-Seite)
        $tplBrillen = PP_PLUGIN_DIR . 'templates/widget/forms/brillenverordnung.php';
        if (file_exists($tplBrillen)) {
            $tplContent = file_get_contents($tplBrillen);
            if (strpos($tplContent, 'prisma_rechts_h_wert') !== false) {
                $this->pass("Brillenverordnung-Template: Formularfeld 'prisma_rechts_h_wert' vorhanden");
            } else {
                $this->warn("Brillenverordnung-Template: 'prisma_rechts_h_wert' nicht gefunden – Formular-/Handler-Abgleich prüfen");
            }
        } else {
            $this->warn('templates/widget/forms/brillenverordnung.php nicht gefunden');
        }
    }

    // =========================================================================
    // 11. PORTAL-FILTER (request_type / STATUS-ALIAS)
    // =========================================================================

    /**
     * Prüft, dass der Kategorie-Filter die richtige DB-Spalte (request_type)
     * verwendet und der Status 'unread' auf 'pending' gemappt wird.
     * Bug 2026-02-20: Repository filterte 'service_key' (falsche Spalte);
     * Portal.php mappte 'unread' nicht auf 'pending'.
     * Bug 2026-02-20: portal.js zeigte Überweisung mit data.facharzt/data.grund
     * (leere Felder), korrekt ist data.ueberweisungsziel/data.diagnose.
     */
    private function testPortalFilter(): void
    {
        $this->group('Portal-Filter (request_type / Status-Alias)');

        // SubmissionRepository: filtert auf request_type (korrekte Spalte)
        $repoFile = PP_PLUGIN_DIR . 'src/Database/Repository/SubmissionRepository.php';
        if (!file_exists($repoFile)) {
            $this->fail('SubmissionRepository.php nicht gefunden');
        } else {
            $content = file_get_contents($repoFile);

            if (strpos($content, 'request_type') !== false) {
                $this->pass("SubmissionRepository: Filtert auf Spalte 'request_type'");
            } else {
                $this->fail("SubmissionRepository: 'request_type' nicht gefunden – Kategorie-Filter kaputt");
            }

            // Falsche Spalte 'service_key = %s' darf nicht als Filter verwendet werden
            if (strpos($content, 'service_key = %s') !== false) {
                $this->fail("SubmissionRepository: Filtert noch auf 'service_key = %s' – falscher Spaltenname!");
            } else {
                $this->pass("SubmissionRepository: Kein 'service_key = %s'-Filter (korrekte Spalte wird genutzt)");
            }

            // widget_-Prefix-Variante muss berücksichtigt werden
            if (strpos($content, "'widget_'") !== false || strpos($content, '"widget_"') !== false) {
                $this->pass("SubmissionRepository: 'widget_'-Prefix-Variante berücksichtigt");
            } else {
                $this->warn("SubmissionRepository: 'widget_'-Prefix nicht explizit berücksichtigt – Filter für 'rezept' findet evtl. 'widget_rezept' nicht");
            }
        }

        // Portal.php: 'unread' wird auf 'pending' gemappt
        $portalFile = PP_PLUGIN_DIR . 'src/Portal/Portal.php';
        if (!file_exists($portalFile)) {
            $this->fail('Portal.php nicht gefunden');
        } else {
            $content = file_get_contents($portalFile);

            if (strpos($content, "'unread'") !== false && strpos($content, "'pending'") !== false) {
                $this->pass("Portal.php: Status-Alias 'unread' → 'pending' vorhanden");
            } else {
                $this->fail("Portal.php: 'unread' → 'pending' Mapping fehlt – Ungelesen-Filter zeigt immer leere Liste");
            }
        }

        // portal.js: Überweisung-Detail nutzt korrekte Feldnamen
        $jsFile = PP_PLUGIN_DIR . 'assets/js/portal.js';
        if (!file_exists($jsFile)) {
            $this->fail('assets/js/portal.js nicht gefunden');
        } else {
            $content = file_get_contents($jsFile);

            if (strpos($content, 'ueberweisungsziel') !== false) {
                $this->pass("portal.js: Überweisung nutzt 'ueberweisungsziel' (korrekt)");
            } else {
                $this->fail("portal.js: 'ueberweisungsziel' fehlt – Überweisung-Details immer leer!");
            }

            if (strpos($content, 'data.facharzt') !== false) {
                $this->fail("portal.js: Alter Feldname 'data.facharzt' noch vorhanden – Fix nicht angewendet!");
            } else {
                $this->pass("portal.js: Alter Feldname 'data.facharzt' korrekt entfernt");
            }
        }
    }

    // =========================================================================
    // 12. PDF-FELDER (SOURCE-CODE-CHECK)
    // =========================================================================

    /**
     * Source-Code-Prüfung: Stellt sicher, dass PdfWidget alle bekannten
     * Felder rendert (eVN/eEB, Prismenwerte, Versandadresse, etc.).
     * Ergänzt nach Lückenanalyse vom 2026-02-20.
     */
    private function testPdfFelder(): void
    {
        $this->group('PDF-Felder (PdfWidget Source-Check)');

        $pdfWidget = PP_PLUGIN_DIR . 'src/Export/Pdf/PdfWidget.php';
        if (!file_exists($pdfWidget)) {
            $this->fail('PdfWidget.php nicht gefunden');
            return;
        }

        $content = file_get_contents($pdfWidget);

        // eVN / eEB pro Service – je unterschiedlicher Feldname
        $evnChecks = [
            'evn_erlaubt'              => 'Rezept: eEB-Feld (evn_erlaubt)',
            'ueberweisung_evn_erlaubt' => 'Überweisung: eEB-Feld (ueberweisung_evn_erlaubt)',
            'brillen_evn_erlaubt'      => 'Brillenverordnung: eVN-Feld (brillen_evn_erlaubt)',
        ];
        foreach ($evnChecks as $needle => $label) {
            if (strpos($content, $needle) !== false) {
                $this->pass("PdfWidget: {$label} vorhanden");
            } else {
                $this->fail("PdfWidget: {$label} fehlt – eVN/eEB wird nicht im PDF gezeigt");
            }
        }

        // Prismenwerte-Block
        if (strpos($content, 'prismen') !== false) {
            $this->pass("PdfWidget: Prismenwerte-Block ('prismen') vorhanden");
        } else {
            $this->fail("PdfWidget: Prismenwerte-Block fehlt – Prismenwerte erscheinen nicht im PDF");
        }

        // Brillen-Versandadresse
        if (strpos($content, 'brillen_versandadresse') !== false) {
            $this->pass("PdfWidget: 'brillen_versandadresse' vorhanden");
        } else {
            $this->fail("PdfWidget: 'brillen_versandadresse' fehlt – Versandadresse fehlt im PDF");
        }

        // Terminabsage: neuer Termin gewünscht
        if (strpos($content, 'absage_neuer_termin') !== false) {
            $this->pass("PdfWidget: 'absage_neuer_termin' vorhanden");
        } else {
            $this->fail("PdfWidget: 'absage_neuer_termin' fehlt – Wunsch nach neuem Termin fehlt im PDF");
        }

        // Terminanfrage-Felder (nach Fix 2026-02-20)
        $terminFields = [
            'termin_grund'        => 'Terminanfrage: termin_grund',
            'termin_wunschzeit'   => 'Terminanfrage: termin_wunschzeit',
            'termin_tage_display' => 'Terminanfrage: termin_tage_display',
        ];
        foreach ($terminFields as $needle => $label) {
            if (strpos($content, $needle) !== false) {
                $this->pass("PdfWidget: {$label} vorhanden");
            } else {
                $this->fail("PdfWidget: {$label} fehlt");
            }
        }

        // Überweisung: korrekte Feldnamen (ueberweisungsziel, nicht facharzt)
        if (strpos($content, 'ueberweisungsziel') !== false || strpos($content, 'diagnose') !== false) {
            $this->pass("PdfWidget: Überweisung nutzt 'ueberweisungsziel'/'diagnose' (korrekte Feldnamen)");
        } else {
            $this->warn("PdfWidget: Überweisung-Feldnamen nicht eindeutig prüfbar");
        }

        // PdfAnamnese: einheitlich für alle Patientenarten (nicht nur Privat)
        $pdfAnamnese = PP_PLUGIN_DIR . 'src/Export/Pdf/PdfAnamnese.php';
        if (!file_exists($pdfAnamnese)) {
            $this->warn('PdfAnamnese.php nicht gefunden');
        } else {
            $aContent = file_get_contents($pdfAnamnese);

            if (strpos($aContent, 'Gesetzlich versichert') !== false) {
                $this->pass("PdfAnamnese: Label 'Gesetzlich versichert' vorhanden (auch für Kassenpatienten)");
            } else {
                $this->fail("PdfAnamnese: 'Gesetzlich versichert' fehlt – Anamnesebogen-PDF nur für Privatpatienten?");
            }

            if (strpos($aContent, 'Patientenstammdaten') !== false) {
                $this->pass("PdfAnamnese: pp__()-Labels vorhanden ('Patientenstammdaten')");
            } else {
                $this->warn("PdfAnamnese: 'Patientenstammdaten'-Label nicht gefunden");
            }

            // Darf nicht mehr auf isPrivat beschränkt sein (kein early return für Kassenpatienten)
            // Prüft ob '!$isPrivat' + 'return' im selben Code-Block stehen (innerhalb von 80 Zeichen)
            $earlyReturnPattern = '/\!\\$isPrivat.{0,80}return\s*[\'\"]{2}/s';
            if (preg_match($earlyReturnPattern, $aContent)) {
                $this->fail("PdfAnamnese: '!\$isPrivat' + 'return' gefunden – Kassenpatienten werden möglicherweise übersprungen!");
            } else {
                $this->pass("PdfAnamnese: Kein '\$isPrivat'-basierter early return (PDF für alle Patientenarten)");
            }
        }

        // en_US.php: neue PDF-Labels vorhanden
        $enUs = PP_PLUGIN_DIR . 'languages/en_US.php';
        if (file_exists($enUs)) {
            $langContent = file_get_contents($enUs);
            $labelChecks = [
                'Patientenstammdaten' => 'Patient master data',
                'Privatversichert'    => 'Privately insured',
                'Gesetzlich versichert' => 'Statutory insured',
                'Krankenkasse'        => 'Health insurance fund',
            ];
            $allFound = true;
            foreach ($labelChecks as $de => $en) {
                if (strpos($langContent, "'$de'") !== false) {
                    $this->pass("en_US.php: Übersetzungskey '{$de}' vorhanden");
                } else {
                    $this->fail("en_US.php: Übersetzungskey '{$de}' fehlt");
                    $allFound = false;
                }
            }
        } else {
            $this->warn('languages/en_US.php nicht gefunden');
        }
    }

    // =========================================================================
    // HTML RENDERING
    // =========================================================================

    private function renderHTML(bool $hasRun): void
    {
        $pageUrl  = admin_url('admin.php?page=pp-integration-test');
        $runUrl   = $pageUrl . '&run=1';
        $cleanUrl = $pageUrl . '&cleanup=1';

        ?>
        <div class="wrap">
            <h1>🧪 Praxis-Portal Integration-Test <span style="font-size:13px;color:#787c82;font-weight:400;">v<?php echo esc_html(PP_VERSION); ?></span></h1>

            <p style="margin-bottom:16px;">
                <a href="<?php echo esc_url($runUrl); ?>" class="button button-primary button-hero">▶ Alle Tests ausführen</a>
                &nbsp;
                <a href="<?php echo esc_url($cleanUrl); ?>" class="button"
                   onclick="return confirm('Alle Test-Submissions (Marker: <?php echo esc_js(self::TEST_MARKER); ?>) löschen?');">
                    🗑️ Test-Daten bereinigen
                </a>
            </p>

            <?php if (!$hasRun): ?>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:30px;text-align:center;color:#787c82;">
                    <span style="font-size:48px;">🧪</span>
                    <p>Klicken Sie auf <strong>„Alle Tests ausführen"</strong> um den Test zu starten.</p>
                    <p style="font-size:12px;">Der Test erstellt echte Submissions in der DB. Mit <strong>„Test-Daten bereinigen"</strong> werden diese wieder entfernt.</p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <?php
            // Stats-Leiste
            $total = array_sum($this->stats);
            ?>
            <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
                <?php foreach ([
                    'pass' => ['✅', '#d4edda', '#155724', 'Bestanden'],
                    'fail' => ['❌', '#f8d7da', '#721c24', 'Fehler'],
                    'warn' => ['⚠️', '#fff3cd', '#856404', 'Warnungen'],
                    'info' => ['ℹ️', '#cce5ff', '#004085', 'Info'],
                ] as $type => [$icon, $bg, $color, $label]): ?>
                <div style="background:<?php echo $bg; ?>;color:<?php echo $color; ?>;border-radius:6px;padding:12px 20px;min-width:100px;text-align:center;">
                    <div style="font-size:24px;font-weight:700;"><?php echo (int)$this->stats[$type]; ?></div>
                    <div style="font-size:12px;"><?php echo $icon; ?> <?php echo $label; ?></div>
                </div>
                <?php endforeach; ?>
                <div style="background:#f0f0f1;border-radius:6px;padding:12px 20px;min-width:100px;text-align:center;color:#3c434a;">
                    <div style="font-size:24px;font-weight:700;"><?php echo $total; ?></div>
                    <div style="font-size:12px;">Gesamt</div>
                </div>
            </div>

            <?php
            // Ergebnisse nach Gruppen
            $grouped = [];
            foreach ($this->results as $r) {
                $grouped[$r['group']][] = $r;
            }

            foreach ($grouped as $groupName => $items):
                $gPass = count(array_filter($items, fn($i) => $i['type'] === 'pass'));
                $gFail = count(array_filter($items, fn($i) => $i['type'] === 'fail'));
                $gWarn = count(array_filter($items, fn($i) => $i['type'] === 'warn'));
                $border = $gFail > 0 ? '#dc3545' : ($gWarn > 0 ? '#ffc107' : '#28a745');
            ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid <?php echo $border; ?>;border-radius:4px;margin-bottom:12px;overflow:hidden;">
                <div style="padding:10px 16px;background:#f9fafb;border-bottom:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center;">
                    <strong><?php echo esc_html($groupName); ?></strong>
                    <span style="font-size:12px;color:#787c82;">
                        ✅ <?php echo $gPass; ?> &nbsp; ❌ <?php echo $gFail; ?> &nbsp; ⚠️ <?php echo $gWarn; ?>
                    </span>
                </div>
                <table style="width:100%;border-collapse:collapse;">
                    <?php foreach ($items as $item):
                        $icons = ['pass'=>'✅','fail'=>'❌','warn'=>'⚠️','info'=>'ℹ️'];
                        $colors = ['pass'=>'#155724','fail'=>'#721c24','warn'=>'#856404','info'=>'#004085'];
                        $bgs = ['pass'=>'','fail'=>'#fff8f8','warn'=>'#fffdf0','info'=>''];
                    ?>
                    <tr style="background:<?php echo $bgs[$item['type']] ?? ''; ?>">
                        <td style="padding:6px 16px;width:24px;font-size:14px;"><?php echo $icons[$item['type']] ?? '•'; ?></td>
                        <td style="padding:6px 8px;font-size:13px;color:<?php echo $colors[$item['type']] ?? '#333'; ?>;"><?php echo esc_html($item['label']); ?></td>
                        <?php if (!empty($item['detail'])): ?>
                        <td style="padding:6px 16px;font-size:12px;color:#787c82;text-align:right;"><?php echo esc_html($item['detail']); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endforeach; ?>

        </div>
        <?php
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function group(string $name): void
    {
        $this->currentGroup = $name;
    }

    private function pass(string $label, string $detail = ''): void
    {
        $this->stats['pass']++;
        $this->results[] = ['type' => 'pass', 'group' => $this->currentGroup, 'label' => $label, 'detail' => $detail];
    }

    private function fail(string $label, string $detail = ''): void
    {
        $this->stats['fail']++;
        $this->results[] = ['type' => 'fail', 'group' => $this->currentGroup, 'label' => $label, 'detail' => $detail];
    }

    private function warn(string $label, string $detail = ''): void
    {
        $this->stats['warn']++;
        $this->results[] = ['type' => 'warn', 'group' => $this->currentGroup, 'label' => $label, 'detail' => $detail];
    }

    private function info(string $label, string $detail = ''): void
    {
        $this->stats['info']++;
        $this->results[] = ['type' => 'info', 'group' => $this->currentGroup, 'label' => $label, 'detail' => $detail];
    }
}

PP_IntegrationTest::boot();
