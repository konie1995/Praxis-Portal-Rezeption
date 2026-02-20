<?php
declare(strict_types=1);
/**
 * Lizenzserver-Endpunkte
 * 
 * Zentrale Definition aller REST-API Endpunkte für den Lizenzserver.
 * KEINE Logik – nur Konstanten und URL-Builder.
 *
 * Namenskonvention:
 *   license_key    = Lizenzschlüssel (z.B. DOC-26-001-ABC)
 *   location_uuid  = Server-vergebene Standort-UUID (z.B. LOC-a1b2c3)
 *   place_id       = VERALTET, nicht mehr verwenden → location_uuid
 *   doc_key        = VERALTET, nicht mehr verwenden → license_key
 *
 * @package PraxisPortal\License
 * @since   4.0.0
 */

namespace PraxisPortal\License;

if (!defined('ABSPATH')) {
    exit;
}

class LicenseEndpoints
{
    // =========================================================================
    // BASIS-URLs
    // =========================================================================
    
    /** Produktions-API */
    public const BASE_URL = 'https://api.praxis-portal.de/v1';
    
    /** Legacy-API (für bestehende Installationen, Fallback) */
    public const LEGACY_BASE_URL = 'https://augenarztkamen.de/wp-json/pp-license/v1';
    
    /** Staging-API (nur für Entwicklung) */
    public const STAGING_BASE_URL = 'https://staging-api.praxis-portal.de/v1';
    
    // =========================================================================
    // LIZENZ-ENDPUNKTE
    // =========================================================================
    
    /** POST: Lizenz aktivieren */
    public const LICENSE_ACTIVATE   = '/license/activate';
    
    /** POST: Lizenz validieren (Status prüfen) */
    public const LICENSE_VALIDATE   = '/license/validate';
    
    /** POST: Lizenz deaktivieren */
    public const LICENSE_DEACTIVATE = '/license/deactivate';
    
    /** GET: Aktueller Lizenz-Status */
    public const LICENSE_STATUS     = '/license/status';
    
    /** GET: Verfügbare Features und Limits */
    public const LICENSE_FEATURES   = '/license/features';
    
    /** POST: Heartbeat / Token-Refresh */
    public const LICENSE_HEARTBEAT  = '/license/heartbeat';
    
    // =========================================================================
    // STANDORT-ENDPUNKTE
    // =========================================================================
    
    /** POST: Alle Standorte synchronisieren */
    public const LOCATIONS_SYNC     = '/locations/sync';
    
    /** POST: Neuen Standort registrieren */
    public const LOCATIONS_REGISTER = '/locations/register';
    
    /** PUT: Standort aktualisieren (mit {uuid} am Ende) */
    public const LOCATIONS_UPDATE   = '/locations/{uuid}';
    
    /** DELETE: Standort deregistrieren (mit {uuid} am Ende) */
    public const LOCATIONS_DELETE   = '/locations/{uuid}';
    
    /** GET: Alle Standorte abrufen */
    public const LOCATIONS_LIST     = '/locations';
    
    // =========================================================================
    // SICHERHEITS-ENDPUNKTE
    // =========================================================================
    
    /** GET: Aktuellen Public Key für Token-Validierung */
    public const SECURITY_PUBLIC_KEY = '/security/public-key';
    
    // =========================================================================
    // UPDATE-ENDPUNKTE
    // =========================================================================
    
    /** GET: Plugin-Update prüfen */
    public const UPDATES_CHECK    = '/updates/check';
    
    /** GET: Update-Paket herunterladen */
    public const UPDATES_DOWNLOAD = '/updates/download';
    
    // =========================================================================
    // URL-BUILDER
    // =========================================================================
    
    /**
     * Gibt die Basis-URL zurück
     * 
     * Reihenfolge:
     * 1. wp-config.php Konstante PP_LICENSE_API_URL
     * 2. Definierte Basis-URL
     * 3. Legacy-URL als Fallback
     */
    public static function getBaseUrl(): string
    {
        // Manuelle Konfiguration (z.B. für Staging)
        if (defined('PP_LICENSE_API_URL')) {
            return rtrim(PP_LICENSE_API_URL, '/');
        }
        
        return self::BASE_URL;
    }
    
    /**
     * Baut eine vollständige URL für einen Endpunkt
     * 
     * @param string $endpoint  Endpunkt-Konstante
     * @param array  $params    Platzhalter-Ersetzungen (z.B. ['uuid' => 'LOC-abc'])
     * @return string Vollständige URL
     */
    public static function url(string $endpoint, array $params = []): string
    {
        $url = self::getBaseUrl() . $endpoint;
        
        // Platzhalter ersetzen
        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', urlencode($value), $url);
        }
        
        return $url;
    }
    
    /**
     * URL für Lizenz-Aktivierung
     */
    public static function activateUrl(): string
    {
        return self::url(self::LICENSE_ACTIVATE);
    }
    
    /**
     * URL für Lizenz-Validierung
     */
    public static function validateUrl(): string
    {
        return self::url(self::LICENSE_VALIDATE);
    }
    
    /**
     * URL für Lizenz-Deaktivierung
     */
    public static function deactivateUrl(): string
    {
        return self::url(self::LICENSE_DEACTIVATE);
    }
    
    /**
     * URL für Lizenz-Status
     */
    public static function statusUrl(): string
    {
        return self::url(self::LICENSE_STATUS);
    }
    
    /**
     * URL für Features
     */
    public static function featuresUrl(): string
    {
        return self::url(self::LICENSE_FEATURES);
    }
    
    /**
     * URL für Heartbeat
     */
    public static function heartbeatUrl(): string
    {
        return self::url(self::LICENSE_HEARTBEAT);
    }
    
    /**
     * URL für Standort-Sync
     */
    public static function locationsSyncUrl(): string
    {
        return self::url(self::LOCATIONS_SYNC);
    }
    
    /**
     * URL für Standort-Registrierung
     */
    public static function locationsRegisterUrl(): string
    {
        return self::url(self::LOCATIONS_REGISTER);
    }
    
    /**
     * URL für Standort-Update
     */
    public static function locationUpdateUrl(string $uuid): string
    {
        return self::url(self::LOCATIONS_UPDATE, ['uuid' => $uuid]);
    }
    
    /**
     * URL für Standort-Löschung
     */
    public static function locationDeleteUrl(string $uuid): string
    {
        return self::url(self::LOCATIONS_DELETE, ['uuid' => $uuid]);
    }
    
    /**
     * URL für Public Key
     */
    public static function publicKeyUrl(): string
    {
        return self::url(self::SECURITY_PUBLIC_KEY);
    }
    
    /**
     * URL für Update-Check
     */
    public static function updateCheckUrl(): string
    {
        return self::url(self::UPDATES_CHECK);
    }
    
    // =========================================================================
    // HTTP-HEADER
    // =========================================================================
    
    /**
     * Standard-Header für API-Requests
     */
    public static function getHeaders(string $licenseKey = ''): array
    {
        $headers = [
            'Content-Type'     => 'application/json',
            'X-Plugin-Version' => PP_VERSION,
            'X-Site-URL'       => home_url(),
            'X-PHP-Version'    => PHP_VERSION,
        ];
        
        if ($licenseKey !== '') {
            $headers['X-License-Key'] = $licenseKey;
        }
        
        return $headers;
    }
}
