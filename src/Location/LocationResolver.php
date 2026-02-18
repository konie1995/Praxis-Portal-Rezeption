<?php
/**
 * Location-Resolver
 * 
 * Zentrale Stelle die den aktuellen Standort aus dem Request-Kontext ermittelt.
 * 
 * DIES IST DIE LÖSUNG FÜR DAS MULTISTANDORT-PROBLEM:
 * In v3.x war die Location-Auflösung über PP_Widget, PP_Portal,
 * PP_Admin_Locations und $GLOBALS verstreut. Jetzt gibt es genau
 * EINE Stelle die den Standort bestimmt.
 *
 * Prioritäten:
 * 1. Expliziter URL-Parameter: ?location=<slug> oder ?lid=<id>
 * 2. Shortcode-Attribut: [pp_widget location="hauptpraxis"]
 * 3. Portal-Session: Eingeloggter User → sein Standort
 * 4. Cookie: pp_preferred_location (verschlüsselt)
 * 5. Default-Standort: is_default = 1 in der DB
 *
 * @package PraxisPortal\Location
 * @since   4.0.0
 */

namespace PraxisPortal\Location;

if (!defined('ABSPATH')) {
    exit;
}

class LocationResolver
{
    private LocationManager $locationManager;
    private ServiceManager  $serviceManager;
    
    /** Cache: bereits aufgelöster Kontext */
    private ?LocationContext $resolvedContext = null;

    /** Shortcode-Location (ersetzt $GLOBALS['pp_shortcode_location']) */
    private static string $shortcodeLocation = '';

    /**
     * Shortcode-Location setzen (wird von Widget::render() aufgerufen)
     * Setzt auch den Cache zurück, damit bei mehreren Shortcodes auf einer Seite
     * jeder den richtigen Standort bekommt.
     */
    public static function setShortcodeLocation(string $slug): void
    {
        self::$shortcodeLocation = $slug;
    }

    /**
     * Resolver-Cache zurücksetzen (z.B. zwischen Shortcode-Aufrufen)
     */
    public function resetCache(): void
    {
        $this->resolvedContext = null;
    }
    
    public function __construct(
        LocationManager $locationManager,
        ServiceManager  $serviceManager
    ) {
        $this->locationManager = $locationManager;
        $this->serviceManager  = $serviceManager;
    }
    
    // =========================================================================
    // ÖFFENTLICHE API
    // =========================================================================
    
    /**
     * Ermittelt den aktuellen Standort und erstellt einen LocationContext
     * 
     * Wird einmalig pro Request aufgerufen (in Plugin::onInit).
     * Das Ergebnis wird im Container als LocationContext registriert.
     */
    public function resolve(): LocationContext
    {
        if ($this->resolvedContext !== null) {
            return $this->resolvedContext;
        }
        
        // Alle aktiven Standorte laden
        $allLocations   = $this->locationManager->getActive();
        $locationCount  = count($allLocations);
        $isMultiLocation = $locationCount > 1;
        
        // Kein Standort vorhanden? Leeren Context erstellen
        if ($locationCount === 0) {
            $this->resolvedContext = $this->createEmptyContext();
            return $this->resolvedContext;
        }
        
        // Standort ermitteln
        $location    = null;
        $resolvedVia = 'default';
        
        // Priorität 1: URL-Parameter
        $location = $this->resolveFromUrl();
        if ($location !== null) {
            $resolvedVia = 'url_parameter';
        }
        
        // Priorität 2: Shortcode-Attribut (wird zur Laufzeit gesetzt)
        if ($location === null) {
            $location = $this->resolveFromShortcode();
            if ($location !== null) {
                $resolvedVia = 'shortcode';
            }
        }
        
        // Priorität 3: Portal-Session
        if ($location === null) {
            $location = $this->resolveFromSession();
            if ($location !== null) {
                $resolvedVia = 'portal_session';
            }
        }
        
        // Priorität 4: Cookie-Präferenz
        if ($location === null) {
            $location = $this->resolveFromCookie();
            if ($location !== null) {
                $resolvedVia = 'cookie';
            }
        }
        
        // Priorität 5: Default-Standort
        if ($location === null) {
            $location = $this->locationManager->getDefault();
            $resolvedVia = 'default';
        }
        
        // Immer noch nichts? Ersten nehmen.
        if ($location === null && !empty($allLocations)) {
            $location = $allLocations[0];
            $resolvedVia = 'first_available';
        }
        
        if ($location === null) {
            $this->resolvedContext = $this->createEmptyContext();
            return $this->resolvedContext;
        }
        
        // Services für diesen Standort laden
        $services = $this->serviceManager->getActiveServices(
            (int) $location['id']
        );
        
        // Context erstellen
        $this->resolvedContext = new LocationContext(
            locationId:      (int) $location['id'],
            slug:            $location['slug'] ?? '',
            locationUuid:    $location['uuid'] ?? null,
            licenseKey:      $location['license_key'] ?? null,
            settings:        $location,
            services:        $services,
            isMultiLocation: $isMultiLocation,
            allLocations:    $allLocations,
            resolvedVia:     $resolvedVia
        );
        
        return $this->resolvedContext;
    }
    
    /**
     * Erstellt einen LocationContext für einen spezifischen Standort
     * (z.B. für Admin oder wenn ein anderer Standort als der aktuelle benötigt wird)
     */
    public function resolveForLocation(int $locationId): ?LocationContext
    {
        $location = $this->locationManager->getById($locationId);
        if ($location === null) {
            return null;
        }
        
        $allLocations   = $this->locationManager->getActive();
        $isMultiLocation = count($allLocations) > 1;
        $services = $this->serviceManager->getActiveServices($locationId);
        
        return new LocationContext(
            locationId:      $locationId,
            slug:            $location['slug'] ?? '',
            locationUuid:    $location['uuid'] ?? null,
            licenseKey:      $location['license_key'] ?? null,
            settings:        $location,
            services:        $services,
            isMultiLocation: $isMultiLocation,
            allLocations:    $allLocations,
            resolvedVia:     'explicit'
        );
    }
    
    /**
     * Setzt die Location-Präferenz als Cookie
     * (wird aufgerufen wenn der User einen Standort wählt)
     */
    public function setPreference(string $slug): void
    {
        // Cookie verschlüsselt setzen (7 Tage)
        $value = wp_hash($slug . '|' . get_current_blog_id());
        $cookieValue = $slug . ':' . $value;
        
        // Nur setzen wenn sich der Wert geändert hat (Cache-Kompatibilität)
        if (($_COOKIE['pp_preferred_location'] ?? '') === $cookieValue) {
            return;
        }
        
        setcookie(
            'pp_preferred_location',
            $cookieValue,
            [
                'expires'  => time() + (7 * DAY_IN_SECONDS),
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly'  => true,
                'samesite'  => 'Lax',
            ]
        );
    }
    
    // =========================================================================
    // AUFLÖSUNGS-STRATEGIEN
    // =========================================================================
    
    /**
     * Priorität 1: URL-Parameter
     * 
     * ?location=kamen      → nach Slug
     * ?lid=3                → nach ID
     */
    private function resolveFromUrl(): ?array
    {
        // Nach Slug
        $slug = isset($_GET['location']) ? sanitize_title($_GET['location']) : '';
        if ($slug !== '') {
            $location = $this->locationManager->getBySlug($slug);
            if ($location !== null && !empty($location['is_active'])) {
                return $location;
            }
        }
        
        // Nach ID
        $lid = isset($_GET['lid']) ? absint($_GET['lid']) : 0;
        if ($lid > 0) {
            $location = $this->locationManager->getById($lid);
            if ($location !== null && !empty($location['is_active'])) {
                return $location;
            }
        }
        
        return null;
    }
    
    /**
     * Priorität 2: Shortcode-Attribut
     * 
     * [pp_widget location="hauptpraxis"]
     * Der Wert wird vom Shortcode in einen Global geschrieben.
     */
    private function resolveFromShortcode(): ?array
    {
        // Shortcode setzt diesen Wert via setShortcodeLocation()
        $slug = self::$shortcodeLocation;
        if ($slug === '') {
            return null;
        }
        
        $location = $this->locationManager->getBySlug(sanitize_title($slug));
        if ($location !== null && !empty($location['is_active'])) {
            return $location;
        }
        
        return null;
    }
    
    /**
     * Priorität 3: Portal-Session (transient-basiert)
     *
     * PortalAuth speichert Sessions in WordPress-Transients
     * (Prefix: pp_portal_session_), der Token liegt im Cookie pp_portal_session.
     */
    private function resolveFromSession(): ?array
    {
        $token = sanitize_text_field($_COOKIE['pp_portal_session'] ?? '');
        if (empty($token)) {
            return null;
        }

        $session = get_transient('pp_portal_session_' . $token);
        if (!is_array($session) || empty($session['location_id'])) {
            return null;
        }

        $location = $this->locationManager->getById((int) $session['location_id']);
        if ($location !== null && !empty($location['is_active'])) {
            return $location;
        }

        return null;
    }
    
    /**
     * Priorität 4: Cookie-Präferenz
     * 
     * Signierter Cookie der den bevorzugten Standort speichert.
     */
    private function resolveFromCookie(): ?array
    {
        $cookie = $_COOKIE['pp_preferred_location'] ?? '';
        if ($cookie === '') {
            return null;
        }
        
        // Format: "slug:hash"
        $parts = explode(':', $cookie, 2);
        if (count($parts) !== 2) {
            return null;
        }
        
        [$slug, $hash] = $parts;
        
        // Signatur prüfen
        $expected = wp_hash($slug . '|' . get_current_blog_id());
        if (!hash_equals($expected, $hash)) {
            return null;
        }
        
        $location = $this->locationManager->getBySlug(sanitize_title($slug));
        if ($location !== null && !empty($location['is_active'])) {
            return $location;
        }
        
        return null;
    }
    
    /**
     * Erstellt einen leeren Context (kein Standort konfiguriert)
     */
    private function createEmptyContext(): LocationContext
    {
        return new LocationContext(
            locationId:      0,
            slug:            '',
            locationUuid:    null,
            licenseKey:      null,
            settings:        [],
            services:        [],
            isMultiLocation: false,
            allLocations:    [],
            resolvedVia:     'none'
        );
    }
}
