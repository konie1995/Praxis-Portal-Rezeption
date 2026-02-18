<?php
/**
 * Lizenz-Manager
 * 
 * Zentrale Lizenz-Verwaltung mit Caching, Token-Validierung und Grace Period.
 *
 * Öffentliche API:
 *   LicenseManager::isValid()             → bool   Lizenz gültig?
 *   LicenseManager::getPlan()             → string Plan-Name
 *   LicenseManager::getStatus()           → array  Vollständiger Status
 *   LicenseManager::validateWithServer()  → array  Server-Check erzwingen
 *   LicenseManager::getLimit('locations') → int    Limit abrufen
 *   LicenseManager::canAddLocation()      → bool   Neuer Standort möglich?
 *
 * @package PraxisPortal\License
 * @since   4.0.0
 */

namespace PraxisPortal\License;

if (!defined('ABSPATH')) {
    exit;
}

class LicenseManager
{
    // =========================================================================
    // KONSTANTEN
    // =========================================================================
    
    /** Grace Period nach Token-Ablauf (Tage) */
    private const GRACE_PERIOD_DAYS = 14;
    
    /** Token-Gültigkeit (Tage) */
    private const TOKEN_VALID_DAYS = 7;
    
    /** Cache-Key in wp_options */
    private const OPTION_KEY = 'pp_license_data';
    
    /** Public Key Cache-Key */
    private const PUBLIC_KEY_OPTION = 'pp_license_public_key';
    
    /** Public Key Cache-Dauer (7 Tage) */
    private const PUBLIC_KEY_TTL = 604800;
    
    /** Fallback Public Key */
    private const FALLBACK_PUBLIC_KEY = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsv7MZwpLFRCHNAfmFGDo
M2XPMG9sygIcvTsX/GfQjWPUHyd59CsKRpuSdi/9q1g7bSRFPjC0UQQSpKeggbjL
7+F+px5v2mDJF0ZJPmvhXTJ9Cxn97V22XQEByGVw7jddNQR0wpXGp42/MpVr6nwq
5UbkXbQRBtTtShDryUxhafmCbvO07arz1Wg+j3StItTCxzf24cLmS/Eg/4T/dAAP
dSI0qLWNrs9vymrdv2oTvWa4QhDHJYGlPIyqeoU3Q/0M9nqE/u1JvFRKPE6eSXl9
QA1ie4KYCAfREmbYk0suJMDmML3TK9HJb0EcGvFak80EQsPsuFpPWLMNkNcOst3f
EwIDAQAB
-----END PUBLIC KEY-----';
    
    /**
     * Features pro Plan.
     *
     * WICHTIG: Widget-Services (Rezept, Überweisung, Brillenverordnung,
     * Dokument, Termin, Terminabsage) sind IMMER kostenlos verfügbar.
     * Siehe FeatureGate::isServiceFree(). Das hier betrifft NUR
     * Export-Formate, API-Zugang, Multi-Location und andere System-Features.
     */
    private const PLAN_FEATURES = [
        'free' => [
            'basic_forms',
            'pdf_export',
            'widget_rezept',
            'widget_ueberweisung',
            'widget_brillenverordnung',
            'widget_dokument',
            'widget_termin',
            'widget_terminabsage',
        ],
        'premium' => [
            'basic_forms',
            'gdt_export',
            'pdf_export',
            'fhir_export',
            'hl7_export',
            'api_access',
            'email_notifications',
            'unlimited_submissions',
            'widget_rezept',
            'widget_ueberweisung',
            'widget_brillenverordnung',
            'widget_dokument',
            'widget_termin',
            'widget_terminabsage',
        ],
        'premium_plus' => [
            'basic_forms',
            'gdt_export',
            'pdf_export',
            'fhir_export',
            'hl7_export',
            'api_access',
            'email_notifications',
            'multi_location',
            'white_label',
            'unlimited_submissions',
            'widget_rezept',
            'widget_ueberweisung',
            'widget_brillenverordnung',
            'widget_dokument',
            'widget_termin',
            'widget_terminabsage',
        ],
    ];
    
    /** Limits pro Plan */
    private const PLAN_LIMITS = [
        'free'         => ['locations' => 1, 'requests_per_month' => 50],
        'premium'      => ['locations' => 1, 'requests_per_month' => -1],
        'premium_plus' => ['locations' => 3, 'requests_per_month' => -1],
    ];
    
    // =========================================================================
    // PROPERTIES
    // =========================================================================
    
    private LicenseClient $client;
    private ?object $locationRepository;
    private ?array $cachedData = null;
    
    public function __construct(LicenseClient $client, ?object $locationRepository = null)
    {
        $this->client = $client;
        $this->locationRepository = $locationRepository;
    }
    
    // =========================================================================
    // ÖFFENTLICHE API
    // =========================================================================
    
    /**
     * Ist die Lizenz gültig? (inkl. Grace Period)
     */
    public function isValid(): bool
    {
        $data = $this->getCachedData();
        
        // Kein Lizenzschlüssel → Free-Plan (immer "gültig")
        if (empty($data['license_key'])) {
            return true;
        }
        
        // Token gültig?
        if ($this->isTokenValid($data)) {
            return true;
        }
        
        // Grace Period?
        if ($this->isInGracePeriod($data)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Aktueller Plan
     */
    public function getPlan(): string
    {
        $data = $this->getCachedData();
        return $data['plan'] ?? 'free';
    }
    
    /**
     * Hat die Lizenz ein bestimmtes Feature?
     */
    public function hasFeature(string $feature): bool
    {
        $data = $this->getCachedData();
        
        // Server-Features (wenn vorhanden) haben Vorrang
        $features = $data['features'] ?? null;
        if ($features !== null) {
            return in_array($feature, $features, true);
        }
        
        // Fallback: Plan-basierte Features
        $plan = $this->getPlan();
        return in_array($feature, self::PLAN_FEATURES[$plan] ?? [], true);
    }
    
    /**
     * Limit-Wert abrufen
     */
    public function getLimit(string $key): int
    {
        $data = $this->getCachedData();
        
        // Server-Limits haben Vorrang
        $limits = $data['limits'] ?? null;
        if ($limits !== null && isset($limits[$key])) {
            return (int) $limits[$key];
        }
        
        // Fallback: Plan-basierte Limits
        $plan = $this->getPlan();
        return (int) (self::PLAN_LIMITS[$plan][$key] ?? 0);
    }
    
    /**
     * Kann ein neuer Standort hinzugefügt werden?
     */
    public function canAddLocation(): bool
    {
        $limit = $this->getLimit('locations');
        if ($limit === -1) {
            return true; // Unbegrenzt
        }
        
        if ($this->locationRepository === null) {
            // Kein Repository verfügbar → permissiv (besser als Crash)
            error_log('PP LicenseManager: LocationRepository nicht verfügbar in canAddLocation()');
            return true;
        }
        
        $count = $this->locationRepository->countActive();
        
        return $count < $limit;
    }
    
    /**
     * Vollständiger Lizenz-Status
     */
    public function getStatus(): array
    {
        $data = $this->getCachedData();
        
        return [
            'license_key'    => $data['license_key'] ?? '',
            'plan'           => $this->getPlan(),
            'is_valid'       => $this->isValid(),
            'is_grace'       => $this->isInGracePeriod($data),
            'features'       => $data['features'] ?? self::PLAN_FEATURES[$this->getPlan()] ?? [],
            'limits'         => $data['limits'] ?? self::PLAN_LIMITS[$this->getPlan()] ?? [],
            'valid_until'    => $data['valid_until'] ?? null,
            'token_expires'  => $data['token_expires_at'] ?? null,
            'last_check'     => $data['last_server_check'] ?? null,
        ];
    }
    
    /**
     * Aktiven Lizenzschlüssel zurückgeben
     */
    public function getActiveLicenseKey(): string
    {
        $data = $this->getCachedData();
        return $data['license_key'] ?? '';
    }
    
    /**
     * Server-Validierung erzwingen
     */
    public function validateWithServer(): array
    {
        $data = $this->getCachedData();
        $licenseKey = $data['license_key'] ?? '';
        
        if ($licenseKey === '') {
            return ['success' => true, 'plan' => 'free'];
        }
        
        $result = $this->client->validate($licenseKey);
        
        if (!empty($result['success']) && !empty($result['data'])) {
            $this->updateCache($result['data']);
        }
        
        // Timestamp setzen auch bei Fehler
        $cached = $this->getCachedData();
        $cached['last_server_check'] = current_time('mysql');
        update_option(self::OPTION_KEY, $cached);
        
        return $result;
    }
    
    /**
     * Lizenz aktivieren
     */
    public function activate(string $licenseKey): array
    {
        $result = $this->client->activate($licenseKey);
        
        if (!empty($result['success']) && !empty($result['data'])) {
            $this->updateCache(array_merge($result['data'], [
                'license_key' => $licenseKey,
            ]));
        }
        
        return $result;
    }
    
    /**
     * Lizenz deaktivieren
     */
    public function deactivate(): array
    {
        $data = $this->getCachedData();
        $licenseKey = $data['license_key'] ?? '';
        
        if ($licenseKey === '') {
            return ['success' => true];
        }
        
        $result = $this->client->deactivate($licenseKey);
        
        // Lokale Daten zurücksetzen
        delete_option(self::OPTION_KEY);
        $this->cachedData = null;
        
        return $result;
    }
    
    /**
     * Cron-Job für tägliche Lizenz-Prüfung registrieren
     */
    public static function registerCron(): void
    {
        if (!wp_next_scheduled('pp_daily_license_check')) {
            wp_schedule_event(time(), 'daily', 'pp_daily_license_check');
        }
    }
    
    // =========================================================================
    // INTERNE METHODEN
    // =========================================================================
    
    /**
     * Gecachte Lizenzdaten laden
     */
    private function getCachedData(): array
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }
        
        $this->cachedData = get_option(self::OPTION_KEY, []);
        
        if (!is_array($this->cachedData)) {
            $this->cachedData = [];
        }
        
        return $this->cachedData;
    }
    
    /**
     * Cache aktualisieren
     */
    private function updateCache(array $data): void
    {
        $cached = $this->getCachedData();
        $merged = array_merge($cached, $data);
        $merged['last_server_check'] = current_time('mysql');
        
        update_option(self::OPTION_KEY, $merged);
        $this->cachedData = $merged;
    }
    
    /**
     * Ist der Token noch gültig?
     */
    private function isTokenValid(array $data): bool
    {
        $expires = $data['token_expires_at'] ?? null;
        if ($expires === null) {
            return false;
        }
        
        return strtotime($expires) > time();
    }
    
    /**
     * Ist die Lizenz in der Grace Period?
     */
    private function isInGracePeriod(array $data): bool
    {
        $expires = $data['token_expires_at'] ?? null;
        if ($expires === null) {
            return false;
        }
        
        $expiryTime = strtotime($expires);
        $graceEnd   = $expiryTime + (self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS);
        
        return time() > $expiryTime && time() <= $graceEnd;
    }
    
    /**
     * Lizenz für einen bestimmten Standort aktivieren
     */
    public function activateForLocation(int $locationId): array
    {
        $data = $this->getCachedData();
        $licenseKey = $data['license_key'] ?? '';
        
        if ($licenseKey === '') {
            return ['success' => false, 'error' => 'Kein Lizenzschlüssel vorhanden'];
        }
        
        return $this->activate($licenseKey);
    }
    
    /**
     * Lizenz-Status für einen bestimmten Standort
     */
    public function getStatusForLocation(int $locationId): array
    {
        return $this->getStatus();
    }
    
    /**
     * Lizenz für einen Standort neu prüfen
     */
    public function refreshForLocation(int $locationId): array
    {
        return $this->validateWithServer();
    }
}
