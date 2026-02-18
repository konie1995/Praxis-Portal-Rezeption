<?php
/**
 * Rate-Limiter
 * 
 * Schutz gegen Spam und DoS-Angriffe auf öffentliche Endpunkte.
 * Nutzt WordPress Transients für IP-basiertes Rate-Limiting.
 *
 * @package PraxisPortal\Security
 * @since   4.0.0
 */

namespace PraxisPortal\Security;

if (!defined('ABSPATH')) {
    exit;
}

class RateLimiter
{
    /** Max. Anfragen pro Zeitfenster */
    private const DEFAULT_MAX_REQUESTS = 10;
    
    /** Zeitfenster in Sekunden (5 Minuten) */
    private const DEFAULT_WINDOW = 300;

    /** @var bool Ob ein externer Object Cache verfügbar ist */
    private $hasObjectCache = false;

    public function __construct()
    {
        // wp_using_ext_object_cache() kann null zurückgeben wenn WP noch nicht fertig geladen ist
        $this->hasObjectCache = function_exists('wp_using_ext_object_cache')
            ? (bool) wp_using_ext_object_cache()
            : false;
    }
    
    /**
     * Prüft ob die aktuelle IP rate-limited ist
     * 
     * @param string $context  Kontext (z.B. 'widget', 'portal_login')
     * @param int    $maxReqs  Max. Anfragen im Zeitfenster
     * @param int    $window   Zeitfenster in Sekunden
     */
    public function isLimited(
        string $context = 'default',
        int    $maxReqs = self::DEFAULT_MAX_REQUESTS,
        int    $window  = self::DEFAULT_WINDOW
    ): bool {
        $key = $this->getCacheKey($context);
        $count = (int) $this->getCount($key);
        
        return $count >= $maxReqs;
    }
    
    /**
     * Zähler erhöhen
     */
    public function increment(string $context = 'default', int $window = self::DEFAULT_WINDOW): void
    {
        $key = $this->getCacheKey($context);
        $count = (int) $this->getCount($key);
        
        $this->setCount($key, $count + 1, $window);
    }
    
    /**
     * Prüft und zählt in einem Schritt (atomar)
     *
     * @return array{allowed: bool, current: int, limit: int, retry_after: int}
     */
    public function attempt(
        string $context = 'default',
        int    $maxReqs = self::DEFAULT_MAX_REQUESTS,
        int    $window  = self::DEFAULT_WINDOW
    ): array {
        $key = $this->getCacheKey($context);
        $count = (int) $this->getCount($key);

        // Bereits überschritten?
        if ($count >= $maxReqs) {
            return [
                'allowed'     => false,
                'current'     => $count,
                'limit'       => $maxReqs,
                'retry_after' => $window,
            ];
        }

        // Inkrementieren und erlauben
        $this->setCount($key, $count + 1, $window);

        return [
            'allowed'     => true,
            'current'     => $count + 1,
            'limit'       => $maxReqs,
            'retry_after' => 0,
        ];
    }
    
    /**
     * Zähler zurücksetzen
     */
    public function reset(string $context = 'default'): void
    {
        $key = $this->getCacheKey($context);
        if ($this->hasObjectCache) {
            delete_transient($key);
        } else {
            wp_cache_delete($key, 'pp_rate_limits');
        }
    }

    // =========================================================================
    // STORAGE LAYER (Object-Cache-aware)
    // =========================================================================

    /**
     * Zähler lesen.
     *
     * MIT Object Cache (Redis/Memcached): Transients nutzen (landen im Object Cache,
     * NICHT in wp_options → kein Bloat).
     *
     * OHNE Object Cache (Shared Hosting): wp_cache (In-Memory nur für diesen Request).
     * Weniger effektiv, aber verhindert wp_options-Bloat bei tausenden IPs.
     * Fallback: Noop → Rate-Limiting greift nicht, aber DB bleibt sauber.
     */
    private function getCount(string $key): int
    {
        if ($this->hasObjectCache) {
            return (int) get_transient($key);
        }

        // Ohne Object Cache: In-Memory Cache (nur pro Request)
        $value = wp_cache_get($key, 'pp_rate_limits');
        return $value !== false ? (int) $value : 0;
    }

    /**
     * Zähler schreiben.
     */
    private function setCount(string $key, int $count, int $ttl): void
    {
        if ($this->hasObjectCache) {
            set_transient($key, $count, $ttl);
        } else {
            // In-Memory: TTL wird nicht erzwungen, aber Daten verschwinden
            // nach dem Request. Für Shared Hosting akzeptabel — der RateLimiter
            // ist dort etwas lockerer, dafür kein DB-Bloat.
            wp_cache_set($key, $count, 'pp_rate_limits', $ttl);
        }
    }

    // =========================================================================
    // KEY GENERATION
    // =========================================================================
    
    /**
     * Cache-Key generieren (IP-basiert, gehashed)
     *
     * WordPress Transient-Namen dürfen max. 172 Zeichen lang sein.
     * pp_rate_ (8) + context (max 20) + _ (1) + hash (16) = max 45 Zeichen.
     */
    private function getCacheKey(string $context): string
    {
        $ip = $this->getClientIp();
        $hash = substr(hash('sha256', $ip . $context), 0, 16);
        
        // Context kürzen um WordPress Transient-Limit einzuhalten
        $safeContext = substr(sanitize_key($context), 0, 20);
        
        return 'pp_rate_' . $safeContext . '_' . $hash;
    }
    
    /**
     * Client-IP ermitteln
     */
    private function getClientIp(): string
    {
        // Proxy-Header nur auswerten wenn konfiguriert
        $trustProxy = (defined('PP_TRUST_PROXY') && PP_TRUST_PROXY)
                   || get_option('pp_trust_proxy', '0') === '1';
        
        if ($trustProxy) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
                $ip = $_SERVER[$header] ?? '';
                if ($ip !== '') {
                    // Bei X-Forwarded-For: Erste IP nehmen
                    if (str_contains($ip, ',')) {
                        $ip = trim(explode(',', $ip)[0]);
                    }
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Abgelaufene Rate-Limit-Einträge bereinigen.
     *
     * Nur relevant wenn Object Cache aktiv ist (Transients in wp_options).
     * Ohne Object Cache werden keine Transients geschrieben → nichts zu bereinigen.
     */
    public function cleanup(): void
    {
        if (!$this->hasObjectCache) {
            return; // Keine DB-Transients → nichts zu tun
        }

        $wpdb = $GLOBALS['wpdb'];
        
        $now = time();

        // 1. Abgelaufene Timeout-Einträge finden
        $expired = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND option_value < %d",
                $wpdb->esc_like('_transient_timeout_pp_rate_') . '%',
                $now
            )
        );

        if (!empty($expired)) {
            // 2. Abgelaufene Timeouts und zugehörige Transients löschen
            $transientNames = array_map(function ($timeout) {
                return str_replace('_transient_timeout_', '_transient_', $timeout);
            }, $expired);

            $allToDelete = array_merge($expired, $transientNames);
            $placeholders = implode(',', array_fill(0, count($allToDelete), '%s'));

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
                    $allToDelete
                )
            );
        }
    }
}
