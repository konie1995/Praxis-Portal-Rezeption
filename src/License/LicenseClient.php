<?php
/**
 * Lizenzserver-Client
 * 
 * HTTP-Client für die Kommunikation mit dem Lizenzserver.
 * Nutzt WordPress HTTP API (wp_remote_*).
 *
 * @package PraxisPortal\License
 * @since   4.0.0
 */

namespace PraxisPortal\License;

if (!defined('ABSPATH')) {
    exit;
}

class LicenseClient
{
    /** Request Timeout in Sekunden */
    private const TIMEOUT = 10;
    
    // =========================================================================
    // ÖFFENTLICHE API
    // =========================================================================
    
    /**
     * Lizenz aktivieren
     */
    public function activate(string $licenseKey, array $siteData = []): array
    {
        return $this->post(
            LicenseEndpoints::activateUrl(),
            $licenseKey,
            array_merge($this->getDefaultPayload($licenseKey), $siteData)
        );
    }
    
    /**
     * Lizenz validieren
     */
    public function validate(string $licenseKey): array
    {
        return $this->post(
            LicenseEndpoints::validateUrl(),
            $licenseKey,
            $this->getDefaultPayload($licenseKey)
        );
    }
    
    /**
     * Lizenz deaktivieren
     */
    public function deactivate(string $licenseKey): array
    {
        return $this->post(
            LicenseEndpoints::deactivateUrl(),
            $licenseKey,
            ['license_key' => $licenseKey, 'site_url' => home_url()]
        );
    }
    
    /**
     * Lizenz-Status abrufen
     */
    public function getStatus(string $licenseKey): array
    {
        return $this->get(
            LicenseEndpoints::statusUrl(),
            $licenseKey,
            ['license_key' => $licenseKey]
        );
    }
    
    /**
     * Features abrufen
     */
    public function getFeatures(string $licenseKey): array
    {
        return $this->get(
            LicenseEndpoints::featuresUrl(),
            $licenseKey
        );
    }
    
    /**
     * Heartbeat senden
     */
    public function heartbeat(string $licenseKey, array $locationData = []): array
    {
        return $this->post(
            LicenseEndpoints::heartbeatUrl(),
            $licenseKey,
            array_merge($this->getDefaultPayload($licenseKey), [
                'locations' => $locationData,
            ])
        );
    }
    
    /**
     * Standorte synchronisieren
     */
    public function syncLocations(string $licenseKey, array $locations): array
    {
        return $this->post(
            LicenseEndpoints::locationsSyncUrl(),
            $licenseKey,
            [
                'license_key' => $licenseKey,
                'site_url'    => home_url(),
                'locations'   => $locations,
            ]
        );
    }
    
    /**
     * Neuen Standort registrieren
     */
    public function registerLocation(string $licenseKey, array $locationData): array
    {
        return $this->post(
            LicenseEndpoints::locationsRegisterUrl(),
            $licenseKey,
            array_merge(['license_key' => $licenseKey], $locationData)
        );
    }
    
    /**
     * Standort aktualisieren
     */
    public function updateLocation(string $licenseKey, string $uuid, array $data): array
    {
        return $this->request(
            'PUT',
            LicenseEndpoints::locationUpdateUrl($uuid),
            $licenseKey,
            $data
        );
    }
    
    /**
     * Standort deregistrieren
     */
    public function deleteLocation(string $licenseKey, string $uuid): array
    {
        return $this->request(
            'DELETE',
            LicenseEndpoints::locationDeleteUrl($uuid),
            $licenseKey
        );
    }
    
    /**
     * Public Key abrufen
     */
    public function getPublicKey(): array
    {
        return $this->get(LicenseEndpoints::publicKeyUrl(), '');
    }
    
    /**
     * Update prüfen
     */
    public function checkUpdate(string $licenseKey): array
    {
        return $this->get(
            LicenseEndpoints::updateCheckUrl(),
            $licenseKey,
            ['current_version' => PP_VERSION]
        );
    }
    
    // =========================================================================
    // HTTP-METHODEN
    // =========================================================================
    
    /**
     * GET-Request
     */
    private function get(string $url, string $licenseKey, array $params = []): array
    {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $this->request('GET', $url, $licenseKey);
    }
    
    /**
     * POST-Request
     */
    private function post(string $url, string $licenseKey, array $body = []): array
    {
        return $this->request('POST', $url, $licenseKey, $body);
    }
    
    /**
     * Generischer HTTP-Request
     */
    private function request(string $method, string $url, string $licenseKey, ?array $body = null): array
    {
        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => LicenseEndpoints::getHeaders($licenseKey),
        ];
        
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        
        $response = wp_remote_request($url, $args);
        
        // Netzwerk-Fehler
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => 'network_error',
                'message' => $response->get_error_message(),
            ];
        }
        
        $statusCode = wp_remote_retrieve_response_code($response);
        $rawBody    = wp_remote_retrieve_body($response);
        $data       = json_decode($rawBody, true);
        
        // JSON-Parse-Fehler
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success'     => false,
                'error'       => 'parse_error',
                'message'     => 'Ungültige Server-Antwort',
                'status_code' => $statusCode,
                'raw_body'    => substr($rawBody, 0, 500),
            ];
        }
        
        // HTTP-Fehler
        if ($statusCode >= 400) {
            return [
                'success'     => false,
                'error'       => $data['error'] ?? 'http_error',
                'message'     => $data['message'] ?? 'HTTP ' . $statusCode,
                'status_code' => $statusCode,
                'data'        => $data['data'] ?? null,
            ];
        }
        
        return $data ?? ['success' => true];
    }
    
    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================
    
    /**
     * Standard-Payload für Requests
     */
    private function getDefaultPayload(string $licenseKey): array
    {
        return [
            'license_key'    => $licenseKey,
            'site_url'       => home_url(),
            'site_name'      => get_bloginfo('name'),
            'admin_email'    => get_option('admin_email'),
            'plugin_version' => PP_VERSION,
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo('version'),
        ];
    }
}
