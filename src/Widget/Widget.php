<?php
/**
 * Sticky Widget für Service-Anfragen (v4)
 * 
 * Rendert das Widget-UI im Frontend mittels Template-System.
 * AJAX-Handler in WidgetHandler, Templates via WidgetRenderer.
 * 
 * v4-Änderungen gegenüber v3:
 * - DI statt Singleton (Container)
 * - Multi-Location via LocationContext (location_uuid statt location_id)
 * - Services via ServiceManager
 * - Feature-Gating via FeatureGate
 * - Alle Options mit pp_ Prefix
 * - I18n via I18n-Klasse
 * - PLACE-ID + License-Key pro Standort
 * 
 * Services:
 * - Rezept-Bestellung (Bestandspatienten)
 * - Überweisung (Bestandspatienten)
 * - Brillenverordnung inkl. Prismen/HSA (Bestandspatienten)
 * - Dokument-Upload (Bestandspatienten)
 * - Terminanfrage (alle / extern)
 * - Terminabsage (Bestandspatienten)
 * - Downloads (alle)
 * - Notfall (alle)
 * - Anamnesebogen (Link)
 * 
 * @package    PraxisPortal\Widget
 * @since      4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Widget;

use PraxisPortal\Core\Container;
use PraxisPortal\Location\LocationContext;
use PraxisPortal\Location\LocationManager;
use PraxisPortal\Location\ServiceManager;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\I18n\I18n;

// Kein direkter Zugriff
if (!defined('ABSPATH')) {
    exit;
}

class Widget
{
    /** @var Container DI-Container */
    private Container $container;

    /** @var LocationContext Standort-Kontext */
    private LocationContext $locationContext;

    /** @var LocationManager Standort-Verwaltung */
    private LocationManager $locationManager;

    /** @var ServiceManager Service-Verwaltung */
    private ServiceManager $serviceManager;

    /** @var FeatureGate Lizenz-Prüfung */
    private FeatureGate $featureGate;

    /** @var I18n Internationalisierung */
    private I18n $i18n;

    /** @var WidgetHandler AJAX-Handler */
    private WidgetHandler $handler;

    /** @var WidgetRenderer Template-Renderer */
    private WidgetRenderer $renderer;

    /** @var array|null Gecachte Standort-Settings */
    private ?array $cachedSettings = null;

    /** @var string|null Gecachte Location-UUID */
    private ?string $cachedLocationUuid = null;

    // =========================================================================
    // CONSTRUCTOR & HOOKS
    // =========================================================================

    /**
     * @param Container $container DI-Container
     */
    public function __construct(Container $container)
    {
        $this->container       = $container;
        $this->locationContext  = $container->get(LocationContext::class);
        $this->locationManager = $container->get(LocationManager::class);
        $this->serviceManager  = $container->get(ServiceManager::class);
        $this->featureGate     = $container->get(FeatureGate::class);
        $this->i18n            = $container->get(I18n::class);
        $this->handler         = $container->get(WidgetHandler::class);
        $this->renderer        = new WidgetRenderer($this);
    }

    /**
     * WordPress-Hooks registrieren
     */
    public function register(): void
    {
        // Frontend-Rendering
        add_action('wp_footer', [$this, 'renderWidget']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        // AJAX: Service-Anfragen (eingeloggt + nicht eingeloggt)
        add_action('wp_ajax_pp_submit_service_request', [$this->handler, 'handleServiceRequest']);
        add_action('wp_ajax_nopriv_pp_submit_service_request', [$this->handler, 'handleServiceRequest']);

        // AJAX: Datei-Upload
        add_action('wp_ajax_pp_widget_upload', [$this->handler, 'handleFileUpload']);
        add_action('wp_ajax_nopriv_pp_widget_upload', [$this->handler, 'handleFileUpload']);

        // AJAX: Medikamenten-Suche
        add_action('wp_ajax_pp_medication_search', [$this->handler, 'handleMedicationSearch']);
        add_action('wp_ajax_nopriv_pp_medication_search', [$this->handler, 'handleMedicationSearch']);
    }

    // =========================================================================
    // FRAGEBOGEN-URL (Statisch)
    // =========================================================================

    /**
     * Ermittelt die URL zur Anamnesebogen-Seite
     * 
     * 1. Manuelle Einstellung (pp_fragebogen_url)
     * 2. Automatische Suche nach Seite mit Shortcode
     * 
     * @return string|false URL oder false
     */
    public static function getFragebogenUrl()
    {
        // 1. Manuelle URL
        $manualUrl = get_option('pp_fragebogen_url', '');
        if (!empty($manualUrl)) {
            return esc_url($manualUrl);
        }

        // 2. Automatisch Seite mit Shortcode finden
        $wpdb = $GLOBALS['wpdb'];

        $page = $wpdb->get_row(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_content LIKE '%[pp_fragebogen%' 
             AND post_status = 'publish' 
             AND post_type IN ('page', 'post')
             ORDER BY post_type ASC, ID ASC
             LIMIT 1"
        );

        if ($page) {
            return get_permalink($page->ID);
        }

        return false;
    }

    /**
     * Prüft ob eine Fragebogen-Seite existiert
     */
    public static function hasFragebogenPage(): bool
    {
        return self::getFragebogenUrl() !== false;
    }

    // =========================================================================
    // STANDORT-SETTINGS
    // =========================================================================

    /**
     * Lädt Settings für den aktuellen oder gegebenen Standort
     * 
     * Reihenfolge:
     * 1. LocationContext (URL-Parameter, Shortcode)
     * 2. Explizite location_uuid
     * 3. Default-Standort
     * 
     * @param string|null $locationUuid Optionale Location-UUID
     * @return array Standort-Einstellungen mit Fallbacks
     */
    public function getLocationSettings(?string $locationUuid = null): array
    {
        // Cache prüfen
        if ($this->cachedSettings !== null && $this->cachedLocationUuid === $locationUuid) {
            return $this->cachedSettings;
        }

        // Location-UUID auflösen
        if ($locationUuid === null) {
            $locationUuid = $this->locationContext->getLocationUuid();
        }

        $this->cachedLocationUuid = $locationUuid;

        // Location-Daten aus DB laden
        $location = $locationUuid
            ? $this->locationManager->getByUuid($locationUuid)
            : null;

        // Defaults mit Fallbacks
        $defaults = [
            'location_uuid'      => $locationUuid,
            'location_id'        => $location['id'] ?? null,
            'place_id'           => $location['uuid'] ?? '',
            'license_key'        => $location['license_key'] ?? '',
            'practice_name'      => '',
            'practice_owner'     => '',
            'practice_subtitle'  => '',
            'phone'              => get_option('pp_praxis_telefon', ''),
            'phone_emergency'    => '',
            'email'              => get_option('admin_email'),
            'website'            => home_url(),
            'logo_url'           => '',
            'color_primary'      => get_option('pp_widget_color', '#2563eb'),
            'color_secondary'    => '#28a745',
            'widget_title'       => $this->i18n->t('Online-Service'),
            'widget_subtitle'    => $this->i18n->t('Nutzen Sie unseren'),
            'widget_welcome'     => $this->i18n->t('Wie können wir Ihnen helfen?'),
            'widget_position'    => get_option('pp_widget_position', 'right'),
            'vacation_mode'      => false,
            'vacation_message'   => '',
            'vacation_start'     => '',
            'vacation_end'       => '',
            'termin_url'         => '',
            'termin_button_text' => $this->i18n->t('Termin vereinbaren'),
            'privacy_url'        => $this->getPrivacyUrl(),
            'imprint_url'        => '',
            'email_notification' => '',
            'email_from_name'    => '',
            'email_from_address' => '',
            'email_signature'    => '',
        ];

        if ($location) {
            // Location-Daten überschreiben (nur nicht-leere Werte)
            $this->cachedSettings = array_merge(
                $defaults,
                array_filter($location, fn($v) => $v !== null && $v !== '')
            );

            // Vacation Mode: Standort-spezifisch oder zeitgesteuert
            $this->cachedSettings['vacation_mode'] = $this->isVacationActive($location);

            // Globaler Override: Widget komplett deaktiviert oder Urlaub
            $globalStatus = get_option('pp_widget_status', 'active');
            if ($globalStatus === 'vacation' || $globalStatus === 'disabled') {
                $this->cachedSettings['vacation_mode'] = true;
            }
        } else {
            $this->cachedSettings = $defaults;

            // Globaler Urlaubsmodus
            $globalStatus = get_option('pp_widget_status', 'active');
            if ($globalStatus === 'vacation') {
                $this->cachedSettings['vacation_mode'] = true;
            }
        }

        return $this->cachedSettings;
    }

    /**
     * Prüft ob der Urlaubsmodus für einen Standort aktiv ist
     * 
     * Berücksichtigt Zeitfenster (vacation_start / vacation_end)
     * 
     * @param array $location Standort-Daten
     * @return bool
     */
    private function isVacationActive(array $location): bool
    {
        if (empty($location['vacation_mode'])) {
            return false;
        }

        // Zeitfenster prüfen
        $start = $location['vacation_start'] ?? '';
        $end   = $location['vacation_end'] ?? '';

        if (!empty($start) && !empty($end)) {
            $now      = current_time('timestamp');
            $startTs  = strtotime($start);
            $endTs    = strtotime($end);

            if ($startTs && $endTs) {
                return ($now >= $startTs && $now <= $endTs);
            }
        }

        // Kein Zeitfenster = dauerhaft aktiv
        return true;
    }

    // =========================================================================
    // SERVICES
    // =========================================================================

    /**
     * Lädt die Services für den aktuellen Standort
     * 
     * @param string|null $locationUuid Optionale UUID
     * @return array Aktive Services
     */
    public function getLocationServices(?string $locationUuid = null): array
    {
        // Location-ID ermitteln (ServiceManager erwartet int)
        $locationId = null;

        if ($locationUuid !== null) {
            // Explizite UUID → Location-ID nachschlagen
            $location = $this->locationManager->getByUuid($locationUuid);
            $locationId = (int) ($location['id'] ?? 0);
        }

        if ($locationId === null || $locationId < 1) {
            // Fallback auf LocationContext
            $locationId = $this->locationContext->getLocationId();
        }

        if ($locationId < 1) {
            return $this->getDefaultServices();
        }

        // Services aus ServiceManager
        $services = $this->serviceManager->getActiveServices($locationId);

        // Wenn keine konfiguriert, Defaults verwenden
        if (empty($services)) {
            return $this->getDefaultServices();
        }

        return $services;
    }

    /**
     * Rendert die Service-Buttons als HTML
     * 
     * Unterscheidet:
     * - builtin: Internes Formular (Rezept, Überweisung, etc.)
     * - external: Externer Link in neuem Tab (Termin-URL)
     * - link: Direkter Link (Anamnesebogen)
     * 
     * @param array  $services         Aktive Services
     * @param string $locationUuid     Standort-UUID
     * @param array  $locationSettings Standort-Einstellungen
     * @return string HTML
     */
    public function renderServicesHtml(
        array $services,
        string $locationUuid,
        array $locationSettings
    ): string {
        $terminUrl        = $locationSettings['termin_url'] ?? '';
        $terminButtonText = $locationSettings['termin_button_text']
            ?: $this->i18n->t('Termin vereinbaren');

        ob_start();

        // Services in 2 Gruppen aufteilen basierend auf patient_restriction
        $patientsOnlyServices = array_filter($services, fn($s) =>
            in_array($s['patient_restriction'] ?? 'all', ['patients_only', 'patient_only'], true)
        );
        $allServices = array_filter($services, fn($s) =>
            !in_array($s['patient_restriction'] ?? 'all', ['patients_only', 'patient_only'], true)
        );

        // 1. Nur für Bestandspatienten
        if (!empty($patientsOnlyServices)) {
            echo '<div class="pp-services-section pp-patient-services">';
            echo '<p class="pp-services-label">' . esc_html($this->i18n->t('Für Patienten unserer Praxis:')) . '</p>';
            echo '<div class="pp-service-buttons">';

            foreach ($patientsOnlyServices as $service) {
                $this->renderServiceButton($service, $locationUuid, $locationSettings);
            }

            echo '</div></div>';
        }

        // 2. Für alle
        if (!empty($allServices)) {
            echo '<div class="pp-services-section pp-public-services">';
            echo '<p class="pp-services-label">' . esc_html($this->i18n->t('Für alle:')) . '</p>';
            echo '<div class="pp-service-buttons">';

            foreach ($allServices as $service) {
                $this->renderServiceButton($service, $locationUuid, $locationSettings);
            }

            echo '</div></div>';
        }

        return ob_get_clean();
    }

    /**
     * Rendert einen einzelnen Service-Button
     */
    private function renderServiceButton(
        array $service,
        string $locationUuid,
        array $locationSettings
    ): void {
        $key   = esc_attr($service['service_key']);
        $label = esc_html($service['label'] ?? $key);
        $icon  = esc_html($service['icon'] ?? '📋');
        $type  = $service['service_type'] ?? 'builtin';

        // Feature-Gate: Alle Widget-Services (inkl. Brillenverordnung) sind im
        // Free-Plan verfügbar. Siehe FeatureGate::isServiceFree().
        // Das monatliche Anfrage-Limit (50/Monat Free) wird beim Submit geprüft,
        // nicht hier – Patienten sollen die Services sehen können.
        if (!($service['is_active'] ?? true)) {
            return;
        }

        // ── Termin-Service: spezielle Modus-Behandlung ──
        if ($service['service_key'] === 'termin') {
            $terminConfig = [];
            if (!empty($service['custom_fields'])) {
                $terminConfig = json_decode($service['custom_fields'], true) ?: [];
            }
            $terminMode  = $terminConfig['mode'] ?? 'disabled';
            $externalUrl = $service['external_url'] ?? '';

            // Fallback: Wenn external_url gesetzt aber kein Modus → external
            if (!empty($externalUrl) && $terminMode === 'disabled') {
                $terminMode = 'external';
            }
            // Fallback: termin_url aus Standort-Einstellungen
            if ($terminMode === 'external' && empty($externalUrl)) {
                $externalUrl = $locationSettings['termin_url'] ?? '';
            }

            if ($terminMode === 'disabled' || !$service['is_active']) {
                return;
            }

            $terminButtonText = $locationSettings['termin_button_text'] ?? '';

            if ($terminMode === 'external' && !empty($externalUrl)) {
                // Prüfe ob externer Drittanbieter (DSGVO-Hinweis)
                $externalHost = wp_parse_url($externalUrl, PHP_URL_HOST);
                $siteHost     = wp_parse_url(home_url(), PHP_URL_HOST);
                $isThirdParty = ($externalHost && $externalHost !== $siteHost);
                $btnLabel     = esc_html($terminButtonText ?: $label);

                if ($isThirdParty) {
                    printf(
                        '<button type="button" class="pp-service-btn pp-service-external"'
                        . ' data-external-url="%s"'
                        . ' data-external-name="%s"'
                        . ' data-external-host="%s">'
                        . '<span class="pp-service-icon">%s</span>'
                        . '<span class="pp-service-label">%s</span>'
                        . '<span class="pp-external-badge">↗</span>'
                        . '</button>',
                        esc_attr($externalUrl),
                        esc_attr($terminButtonText ?: $label),
                        esc_attr($externalHost),
                        $icon,
                        $btnLabel
                    );
                } else {
                    $newTab = !empty($service['open_in_new_tab']) ? ' target="_blank" rel="noopener"' : '';
                    printf(
                        '<a href="%s"%s class="pp-service-btn pp-service-link">'
                        . '<span class="pp-service-icon">%s</span>'
                        . '<span class="pp-service-label">%s</span>'
                        . '</a>',
                        esc_url($externalUrl),
                        $newTab,
                        $icon,
                        $btnLabel
                    );
                }
            } elseif ($terminMode === 'form') {
                printf(
                    '<button type="button" class="pp-service-btn pp-service-builtin"'
                    . ' data-service="termin" data-location="%s">'
                    . '<span class="pp-service-icon">%s</span>'
                    . '<span class="pp-service-label">%s</span>'
                    . '</button>',
                    esc_attr($locationUuid),
                    $icon,
                    $label
                );
            }
            return;
        }

        // ── Standard-Services ──
        switch ($type) {
            case 'external':
                $url = $service['external_url'] ?? $locationSettings['termin_url'] ?? '';
                if (empty($url)) {
                    return;
                }
                printf(
                    '<a href="%s" target="_blank" rel="noopener" class="pp-service-btn pp-service-external" data-service="%s">'
                    . '<span class="pp-service-icon">%s</span>'
                    . '<span class="pp-service-label">%s</span>'
                    . '</a>',
                    esc_url($url),
                    $key,
                    $icon,
                    $label
                );
                break;

            case 'link':
                $url = $service['external_url'] ?? '';
                if (empty($url)) {
                    return;
                }
                printf(
                    '<a href="%s" class="pp-service-btn pp-service-link" data-service="%s">'
                    . '<span class="pp-service-icon">%s</span>'
                    . '<span class="pp-service-label">%s</span>'
                    . '</a>',
                    esc_url($url),
                    $key,
                    $icon,
                    $label
                );
                break;

            default: // builtin
                printf(
                    '<button type="button" class="pp-service-btn pp-service-builtin" '
                    . 'data-service="%s" data-location="%s">'
                    . '<span class="pp-service-icon">%s</span>'
                    . '<span class="pp-service-label">%s</span>'
                    . '</button>',
                    $key,
                    esc_attr($locationUuid),
                    $icon,
                    $label
                );
                break;
        }
    }

    /**
     * Default-Services (wenn nichts in DB konfiguriert)
     */
    private function getDefaultServices(): array
    {
        $fragebogenUrl = self::getFragebogenUrl();

        return [
            [
                'service_key'     => 'rezept',
                'label'           => $this->i18n->t('Rezepte'),
                'icon'            => '💊',
                'service_type'    => 'builtin',
                'is_patient_only' => true,
                'sort_order'      => 1,
            ],
            [
                'service_key'     => 'ueberweisung',
                'label'           => $this->i18n->t('Überweisung'),
                'icon'            => '📄',
                'service_type'    => 'builtin',
                'is_patient_only' => true,
                'sort_order'      => 2,
            ],
            [
                'service_key'     => 'brillenverordnung',
                'label'           => $this->i18n->t('Brillenverordnung'),
                'icon'            => '👓',
                'service_type'    => 'builtin',
                'is_patient_only' => true,
                'sort_order'      => 3,
            ],
            [
                'service_key'     => 'dokument',
                'label'           => $this->i18n->t('Dokumente'),
                'icon'            => '📎',
                'service_type'    => 'builtin',
                'is_patient_only' => true,
                'sort_order'      => 4,
            ],
            [
                'service_key'     => 'downloads',
                'label'           => $this->i18n->t('Downloads'),
                'icon'            => '📥',
                'service_type'    => 'builtin',
                'is_patient_only' => false,
                'sort_order'      => 5,
            ],
            [
                'service_key'     => 'termin',
                'label'           => $this->i18n->t('Termine'),
                'icon'            => '📅',
                'service_type'    => 'external',
                'is_patient_only' => false,
                'sort_order'      => 6,
            ],
            [
                'service_key'     => 'terminabsage',
                'label'           => $this->i18n->t('Terminabsage'),
                'icon'            => '❌',
                'service_type'    => 'builtin',
                'is_patient_only' => true,
                'sort_order'      => 7,
            ],
            [
                'service_key'     => 'notfall',
                'label'           => $this->i18n->t('Notfall'),
                'icon'            => '🚨',
                'service_type'    => 'builtin',
                'is_patient_only' => false,
                'sort_order'      => 8,
                'custom_fields'   => json_encode([
                    'show_112'                 => true,
                    'emergency_text'           => '',
                    'practice_emergency_label' => '',
                    'show_bereitschaftsdienst' => true,
                    'show_giftnotruf'          => true,
                    'show_telefonseelsorge'    => true,
                    'custom_numbers'           => [],
                    'additional_info'          => '',
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'service_key'     => 'anamnesebogen',
                'label'           => $this->i18n->t('Anamnesebogen'),
                'icon'            => '📝',
                'service_type'    => 'link',
                'external_url'    => $fragebogenUrl ?: '',
                'is_patient_only' => false,
                'is_active'       => $fragebogenUrl !== false,
                'sort_order'      => 9,
            ],
        ];
    }

    // =========================================================================
    // ASSETS
    // =========================================================================

    /**
     * CSS + JS im Frontend laden
     */
    public function enqueueAssets(): void
    {
        $widgetStatus = get_option('pp_widget_status', 'active');

        // Komplett deaktiviert → keine Assets
        if ($widgetStatus === 'disabled') {
            return;
        }

        $isVacation = ($widgetStatus === 'vacation');

        // Seiteneinschränkungen prüfen (nicht im Urlaub)
        if (!$isVacation && !$this->shouldShowWidget()) {
            return;
        }

        // CSS immer laden
        wp_enqueue_style(
            'pp-widget',
            PP_PLUGIN_URL . 'assets/css/widget.css',
            [],
            PP_VERSION
        );

        // Im Urlaubsmodus kein JS
        if ($isVacation) {
            return;
        }

        // Widget-JS laden
        wp_enqueue_script(
            'pp-widget',
            PP_PLUGIN_URL . 'assets/js/widget.js',
            ['jquery'],
            PP_VERSION,
            true
        );

        // JS-Konfiguration
        wp_localize_script('pp-widget', 'pp_widget', [
            'ajax_url'         => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('pp_widget_nonce'),
            'upload_nonce'     => wp_create_nonce('pp_widget_upload_nonce'),
            'search_nonce'     => wp_create_nonce('pp_medication_search_nonce'),
            'max_file_size'    => 10 * 1024 * 1024,
            'min_form_time'    => 5,
            'vacation_mode'    => false,
            'anamnesebogen_url'=> get_option('pp_anamnesebogen_url', ''),
            'i18n'             => [
                'sending'          => $this->i18n->t('Wird gesendet...'),
                'success'          => $this->i18n->t('Anfrage erfolgreich gesendet!'),
                'error'            => $this->i18n->t('Verbindungsfehler. Bitte prüfen Sie Ihre Internetverbindung.'),
                'file_too_large'   => $this->i18n->t('Datei zu groß (max. 10MB)'),
                'invalid_file_type'=> $this->i18n->t('Ungültiger Dateityp'),
                'upload_success'   => $this->i18n->t('Datei hochgeladen'),
                'required_field'   => $this->i18n->t('Bitte füllen Sie dieses Feld aus.'),
                'max_medications'  => $this->i18n->t('Maximal 3 Medikamente möglich.'),
                'form_too_fast'    => $this->i18n->t('Bitte nehmen Sie sich etwas mehr Zeit.'),
                'searching'        => $this->i18n->t('Suche...'),
                'no_results'       => $this->i18n->t('Keine Medikamente gefunden'),
                'min_chars'        => $this->i18n->t('Mindestens 2 Zeichen eingeben'),
                // Dynamische Medikamenten-Zeilen (widget.js)
                'medication'       => $this->i18n->t('Medikament'),
                'med_placeholder'  => $this->i18n->t('Name des Medikaments eingeben...'),
                'med_type'         => $this->i18n->t('Art'),
                'med_eye_drops'    => $this->i18n->t('Augentropfen'),
                'med_eye_ointment' => $this->i18n->t('Augensalbe'),
                'med_tablets'      => $this->i18n->t('Tabletten'),
                'med_other'        => $this->i18n->t('Sonstiges'),
                'med_remove'       => $this->i18n->t('Entfernen'),
            ],
        ]);
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    /**
     * Widget als Shortcode rendern (gibt String zurück)
     * 
     * @param array $atts Shortcode-Attribute
     * @return string HTML-Output
     */
    public function render(array $atts = []): string
    {
        // Shortcode-Attribute verarbeiten
        $atts = shortcode_atts([
            'location' => '',
            'standort' => '', // Alias für deutschsprachige Nutzer
            'services' => '',
            'style'    => '',
        ], $atts, 'praxis_widget');

        // Multistandort: Shortcode-Attribut an LocationResolver übergeben
        $locationSlug = $atts['location'] ?: $atts['standort'];
        if (!empty($locationSlug)) {
            \PraxisPortal\Location\LocationResolver::setShortcodeLocation($locationSlug);
            // Resolver-Cache invalidieren (wichtig bei mehreren Shortcodes pro Seite)
            if ($this->container->has(\PraxisPortal\Location\LocationResolver::class)) {
                $this->container->get(\PraxisPortal\Location\LocationResolver::class)->resetCache();
            }
        }

        // Output buffering für Template
        ob_start();
        $this->renderWidget();
        $output = ob_get_clean();

        // Shortcode-Location zurücksetzen (für weitere Shortcodes auf der Seite)
        \PraxisPortal\Location\LocationResolver::setShortcodeLocation('');
        if ($this->container->has(\PraxisPortal\Location\LocationResolver::class)) {
            $this->container->get(\PraxisPortal\Location\LocationResolver::class)->resetCache();
        }

        return $output;
    }

    /**
     * Widget im Footer rendern
     */
    public function renderWidget(): void
    {
        // ── Status prüfen ──
        $widgetStatus = get_option('pp_widget_status', 'active');

        if ($widgetStatus === 'disabled') {
            echo '<!-- PP4 Widget: deaktiviert (Einstellungen → Widget → Status) -->';
            return;
        }

        // ── Multi-Standort ──
        $allLocations    = $this->locationManager->getActive();
        $isMultiLocation = count($allLocations) > 1;

        // ── Settings + Services ──
        $settings = $this->getLocationSettings();
        $services = $this->getLocationServices();

        // ── Fallbacks für Pflichtfelder ──
        if (empty($settings['practice_name'])) {
            $settings['practice_name'] = get_bloginfo('name') ?: 'Praxis';
        }
        if (empty($settings['email_notification']) || !is_email($settings['email_notification'] ?? '')) {
            $settings['email_notification'] = get_option('admin_email');
        }

        // ── Konfiguration prüfen ──
        if (!$this->isLocationConfigured($settings, $services, $isMultiLocation, $allLocations)) {
            if (current_user_can('manage_options')) {
                $this->renderSetupNotice($settings);
            } else {
                echo '<!-- PP4 Widget: Konfiguration unvollständig -->';
            }
            return;
        }

        // ── Urlaubsmodus ──
        $isVacation = ($widgetStatus === 'vacation') || ($settings['vacation_mode'] ?? false);

        // Seiteneinschränkungen (nicht im Urlaub)
        if (!$isVacation && !$this->shouldShowWidget()) {
            echo '<!-- PP4 Widget: auf dieser Seite nicht aktiv (Einstellungen → Widget → Sichtbarkeit) -->';
            return;
        }

        // ── Template-Context ──
        $context = [
            // Kern-Daten
            'settings'          => $settings,
            'services'          => $services,
            'is_multi_location' => $isMultiLocation,
            'is_multisite'      => $isMultiLocation, // Template-Alias
            'all_locations'     => $allLocations,
            'locations'         => $allLocations,     // Template-Alias
            'location_uuid'     => $settings['location_uuid'] ?? '',
            'location_id'       => (int) ($settings['location_id'] ?? 0),

            // Widget-Darstellung
            'widget_position'        => $settings['widget_position'] ?: 'right',
            'widget_color'           => $settings['color_primary'] ?? '#2563eb',
            'widget_color_secondary' => $settings['color_secondary'] ?? '#28a745',
            'widget_title'           => $settings['widget_title'] ?: $this->i18n->t('Online-Service'),
            'widget_subtitle'        => $settings['widget_subtitle'] ?: $this->i18n->t('Nutzen Sie unseren'),
            'widget_welcome'         => $settings['widget_welcome'] ?: $this->i18n->t('Wie können wir Ihnen helfen?'),
            'welcome_text'           => $settings['widget_welcome'] ?: $this->i18n->t('Wie können wir Ihnen helfen?'), // Template-Alias

            // Praxis-Daten
            'practice_name'     => $settings['practice_name'] ?? '',
            'praxis_name'       => $settings['practice_name'] ?? '',
            'logo_url'          => $settings['logo_url'] ?? '',
            'phone'             => $settings['phone'] ?? '',
            'phone_emergency'   => $settings['phone_emergency'] ?: ($settings['phone'] ?? ''),
            'termin_url'        => $settings['termin_url'] ?? '',
            'termin_button_text'=> $settings['termin_button_text'] ?: $this->i18n->t('Termin vereinbaren'),
            'privacy_url'       => $settings['privacy_url'] ?? '',

            // Urlaubsmodus
            'is_vacation'       => $isVacation,
            'vacation_message'  => $settings['vacation_message'] ?? '',
            'vacation_text'     => $settings['vacation_message'] ?? '', // Template-Alias
            'vacation_end'      => $settings['vacation_end'] ?? '',
        ];

        $this->renderer->setContext($context);

        // ── Template rendern ──
        if ($isVacation) {
            $this->renderer->display('vacation');
        } else {
            $this->renderer->display('main');
        }
    }

    // =========================================================================
    // SICHTBARKEIT & KONFIGURATION
    // =========================================================================

    /**
     * Prüft ob Widget auf aktueller Seite angezeigt werden soll
     */
    private function shouldShowWidget(): bool
    {
        $enabledPages = get_option('pp_widget_pages', 'all');

        if ($enabledPages === 'none') {
            return false;
        }

        if ($enabledPages === 'all') {
            return true;
        }

        // Spezifische Seiten-IDs
        $pageIds = array_filter(array_map('intval', explode(',', $enabledPages)));

        if (empty($pageIds)) {
            return false;
        }

        // Aktuelle Seiten-ID ermitteln
        $currentId = (int) get_the_ID();

        // Auf Nicht-Singular-Seiten (Archive, Suche, 404, Blog-Index)
        // → Widget auf diesen Seiten zeigen, wenn Startseite ausgewählt ist
        if ($currentId === 0) {
            $frontPageId = (int) get_option('page_on_front');
            return $frontPageId > 0 && in_array($frontPageId, $pageIds, true);
        }

        return in_array($currentId, $pageIds, true);
    }

    /**
     * Prüft ob mindestens ein Standort konfiguriert ist
     */
    private function isLocationConfigured(
        array $settings,
        array $services,
        bool $isMultiLocation,
        array $allLocations
    ): bool {
        if ($isMultiLocation) {
            foreach ($allLocations as $loc) {
                $locUuid     = $loc['uuid'] ?? '';
                $locSettings = $this->getLocationSettings($locUuid);
                $locId       = (int) ($loc['id'] ?? 0);

                // Services laden mit Fallback auf Defaults
                $locServices = $locId > 0
                    ? $this->serviceManager->getActiveServices($locId)
                    : [];
                if (empty($locServices)) {
                    $locServices = $this->getDefaultServices();
                }

                // Pflichtfeld-Fallbacks (analog zu renderWidget)
                if (empty($locSettings['practice_name'])) {
                    $locSettings['practice_name'] = $loc['name'] ?? (get_bloginfo('name') ?: 'Praxis');
                }
                if (empty($locSettings['email_notification']) || !is_email($locSettings['email_notification'] ?? '')) {
                    $locSettings['email_notification'] = get_option('admin_email');
                }

                if ($this->isSingleLocationConfigured($locSettings, $locServices)) {
                    return true;
                }
            }
            return false;
        }

        return $this->isSingleLocationConfigured($settings, $services);
    }

    /**
     * Prüft ob ein einzelner Standort konfiguriert ist
     */
    private function isSingleLocationConfigured(array $settings, array $services): bool
    {
        if (empty($settings['practice_name']) || trim($settings['practice_name']) === '') {
            return false;
        }

        if (empty($settings['email_notification']) || !is_email($settings['email_notification'])) {
            return false;
        }

        if (empty($services)) {
            return false;
        }

        return true;
    }

    /**
     * Datenschutz-URL ermitteln
     */
    private function getPrivacyUrl(): string
    {
        $privacyPageId = get_option('wp_page_for_privacy_policy');
        return $privacyPageId ? (get_permalink($privacyPageId) ?: '') : '';
    }

    // =========================================================================
    // ADMIN-HINWEIS (Nicht konfiguriert)
    // =========================================================================

    /**
     * Zeigt Admin-Hinweis wenn Widget nicht konfiguriert
     */
    private function renderSetupNotice(array $settings): void
    {
        $missing = [];

        if (empty($settings['practice_name'])) {
            $missing[] = $this->i18n->t('Praxisname');
        }
        if (empty($settings['email_notification']) || !is_email($settings['email_notification'] ?? '')) {
            $missing[] = $this->i18n->t('E-Mail für Benachrichtigungen');
        }

        $services = $this->getLocationServices();
        if (empty($services)) {
            $missing[] = $this->i18n->t('Mindestens ein aktiver Service');
        }

        $adminUrl = admin_url('admin.php?page=pp-locations');

        ?>
        <div id="pp-setup-notice" style="
            position: fixed;
            bottom: 20px;
            right: 20px;
            max-width: 350px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            z-index: 99999;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        ">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <span style="font-size: 28px;">⚙️</span>
                <div>
                    <h4 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600;">
                        <?php echo esc_html($this->i18n->t('Widget noch nicht aktiv')); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; font-size: 13px; opacity: 0.9; line-height: 1.4;">
                        <?php echo esc_html($this->i18n->t('Bitte vervollständigen Sie die Standort-Konfiguration:')); ?>
                    </p>
                    <ul style="margin: 0 0 15px 0; padding-left: 18px; font-size: 13px; opacity: 0.9;">
                        <?php foreach ($missing as $item): ?>
                            <li style="margin-bottom: 4px;">❌ <?php echo esc_html($item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?php echo esc_url($adminUrl); ?>" style="
                        display: inline-block;
                        background: white;
                        color: #667eea;
                        padding: 8px 16px;
                        border-radius: 6px;
                        text-decoration: none;
                        font-weight: 600;
                        font-size: 13px;
                    ">
                        <?php echo esc_html($this->i18n->t('Jetzt einrichten')); ?> →
                    </a>
                </div>
            </div>
            <p style="margin: 15px 0 0 0; font-size: 11px; opacity: 0.7; text-align: center;">
                ℹ️ <?php echo esc_html($this->i18n->t('Dieser Hinweis ist nur für Admins sichtbar')); ?>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // GETTER (für Renderer / Handler)
    // =========================================================================

    /**
     * @return I18n
     */
    public function getI18n(): I18n
    {
        return $this->i18n;
    }

    /**
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * @return WidgetRenderer
     */
    public function getRenderer(): WidgetRenderer
    {
        return $this->renderer;
    }

    /**
     * Cache leeren (z.B. nach Standortwechsel)
     */
    public function clearCache(): void
    {
        $this->cachedSettings      = null;
        $this->cachedLocationUuid  = null;
    }
}
