<?php
/**
 * Location-Kontext
 * 
 * Trägt alle Standort-bezogenen Daten durch den gesamten Request.
 * Wird einmalig vom LocationResolver erstellt und im Container registriert.
 * 
 * ALLE Klassen die Standort-Daten brauchen, nutzen dieses Objekt.
 * Kein direkter DB-Zugriff für Location-Daten in anderen Klassen!
 *
 * @package PraxisPortal\Location
 * @since   4.0.0
 */

namespace PraxisPortal\Location;

if (!defined('ABSPATH')) {
    exit;
}

class LocationContext
{
    // =========================================================================
    // PROPERTIES (alle readonly nach Erstellung)
    // =========================================================================
    
    /** Lokale DB-ID des Standorts */
    private int $locationId;
    
    /** Slug des Standorts (URL-freundlich) */
    private string $slug;
    
    /** Server-vergebene UUID (z.B. LOC-a1b2c3) */
    private ?string $locationUuid;
    
    /** Lizenzschlüssel (z.B. DOC-26-001-ABC) */
    private ?string $licenseKey;
    
    /** Standort-Einstellungen (Name, Farben, etc.) */
    private array $settings;
    
    /** Aktive Services für diesen Standort */
    private array $services;
    
    /** Ob es mehrere aktive Standorte gibt */
    private bool $isMultiLocation;
    
    /** Alle aktiven Standorte (für Standort-Auswahl) */
    private array $allLocations;
    
    /** Wie der Standort aufgelöst wurde */
    private string $resolvedVia;
    
    // =========================================================================
    // KONSTRUKTOR
    // =========================================================================
    
    public function __construct(
        int     $locationId,
        string  $slug,
        ?string $locationUuid,
        ?string $licenseKey,
        array   $settings,
        array   $services,
        bool    $isMultiLocation,
        array   $allLocations,
        string  $resolvedVia = 'default'
    ) {
        $this->locationId     = $locationId;
        $this->slug           = $slug;
        $this->locationUuid   = $locationUuid;
        $this->licenseKey     = $licenseKey;
        $this->settings       = $settings;
        $this->services       = $services;
        $this->isMultiLocation = $isMultiLocation;
        $this->allLocations   = $allLocations;
        $this->resolvedVia    = $resolvedVia;
    }
    
    // =========================================================================
    // GETTER
    // =========================================================================
    
    /** Lokale DB-ID */
    public function getLocationId(): int
    {
        return $this->locationId;
    }
    
    /** URL-Slug */
    public function getSlug(): string
    {
        return $this->slug;
    }
    
    /** Server-UUID (z.B. LOC-a1b2c3) */
    public function getLocationUuid(): ?string
    {
        return $this->locationUuid;
    }
    
    /** Lizenzschlüssel (z.B. DOC-26-001-ABC) */
    public function getLicenseKey(): ?string
    {
        return $this->licenseKey;
    }
    
    /** Alle Standort-Einstellungen */
    public function getSettings(): array
    {
        return $this->settings;
    }
    
    /**
     * Einzelne Einstellung abrufen
     * 
     * @param string $key     Setting-Name
     * @param mixed  $default Fallback-Wert
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
    
    /** Praxisname */
    public function getPracticeName(): string
    {
        return $this->settings['practice_name'] ?? '';
    }
    
    /** Standortname */
    public function getName(): string
    {
        return $this->settings['name'] ?? '';
    }
    
    /** Primärfarbe */
    public function getColorPrimary(): string
    {
        return $this->settings['color_primary'] ?? '#0066cc';
    }
    
    /** Sekundärfarbe */
    public function getColorSecondary(): string
    {
        return $this->settings['color_secondary'] ?? '#28a745';
    }
    
    /** E-Mail für Benachrichtigungen */
    public function getNotificationEmail(): string
    {
        return $this->settings['email_notification'] ?? '';
    }
    
    /** Urlaubsmodus aktiv? */
    public function isVacationMode(): bool
    {
        // Global deaktiviert?
        $globalStatus = get_option('pp_widget_status', 'active');
        if ($globalStatus === 'vacation' || $globalStatus === 'disabled') {
            return true;
        }
        
        return !empty($this->settings['vacation_mode']);
    }
    
    /** Aktive Services */
    public function getServices(): array
    {
        return $this->services;
    }
    
    /** Multi-Standort? */
    public function isMultiLocation(): bool
    {
        return $this->isMultiLocation;
    }
    
    /** Alle aktiven Standorte */
    public function getAllLocations(): array
    {
        return $this->allLocations;
    }
    
    /** Wie wurde der Standort aufgelöst? (debug) */
    public function getResolvedVia(): string
    {
        return $this->resolvedVia;
    }
    
    // =========================================================================
    // KONTEXT FÜR JAVASCRIPT (Frontend)
    // =========================================================================
    
    /**
     * Gibt Daten zurück die fürs Frontend (wp_localize_script) benötigt werden.
     * Enthält KEINE sensiblen Daten!
     */
    public function toFrontendData(): array
    {
        return [
            'location_id'      => $this->locationId,
            'slug'             => $this->slug,
            'name'             => $this->getName(),
            'practice_name'    => $this->getPracticeName(),
            'color_primary'    => $this->getColorPrimary(),
            'color_secondary'  => $this->getColorSecondary(),
            'is_multi'         => $this->isMultiLocation,
            'vacation_mode'    => $this->isVacationMode(),
            'vacation_message' => $this->settings['vacation_message'] ?? '',
            'termin_url'       => $this->settings['termin_url'] ?? '',
            'privacy_url'      => $this->settings['privacy_url'] ?? '',
        ];
    }
}
