<?php
/**
 * Service-Manager
 * 
 * Verwaltet Services pro Standort (Rezept, Überweisung, etc.)
 *
 * @package PraxisPortal\Location
 * @since   4.0.0
 */

namespace PraxisPortal\Location;

if (!defined('ABSPATH')) {
    exit;
}

class ServiceManager
{
    /** Standard-Services die bei Standort-Erstellung angelegt werden */
    public const DEFAULT_SERVICES = [
        [
            'service_key'  => 'termin',
            'label'        => 'Terminanfrage',
            'icon'         => '📅',
            'sort_order'   => 1,
            'service_type' => 'builtin',
            'patient_restriction' => 'all',
        ],
        [
            'service_key'  => 'terminabsage',
            'label'        => 'Terminabsage',
            'icon'         => '🚫',
            'sort_order'   => 2,
            'service_type' => 'builtin',
            'patient_restriction' => 'patients_only',
        ],
        [
            'service_key'  => 'rezept',
            'label'        => 'Rezept bestellen',
            'icon'         => '💊',
            'sort_order'   => 3,
            'service_type' => 'builtin',
            'patient_restriction' => 'patients_only',
        ],
        [
            'service_key'  => 'ueberweisung',
            'label'        => 'Überweisung anfragen',
            'icon'         => '📄',
            'sort_order'   => 4,
            'service_type' => 'builtin',
            'patient_restriction' => 'patients_only',
        ],
        [
            'service_key'  => 'brillenverordnung',
            'label'        => 'Brillenverordnung',
            'icon'         => '👓',
            'sort_order'   => 5,
            'service_type' => 'builtin',
            'patient_restriction' => 'all',
        ],
        [
            'service_key'  => 'dokument',
            'label'        => 'Dokument hochladen',
            'icon'         => '📎',
            'sort_order'   => 6,
            'service_type' => 'builtin',
            'patient_restriction' => 'patients_only',
        ],
        [
            'service_key'  => 'downloads',
            'label'        => 'Dokumenten-Download',
            'icon'         => '📥',
            'sort_order'   => 7,
            'service_type' => 'builtin',
            'patient_restriction' => 'patients_only',
        ],
        [
            'service_key'  => 'anamnesebogen',
            'label'        => 'Anamnesebogen',
            'icon'         => '📋',
            'sort_order'   => 8,
            'service_type' => 'builtin',
            'patient_restriction' => 'all',
        ],
        [
            'service_key'  => 'notfall',
            'label'        => 'Notfall',
            'icon'         => '🚨',
            'sort_order'   => 9,
            'service_type' => 'builtin',
            'patient_restriction' => 'all',
        ],
    ];
    
    // =========================================================================
    // LESEN
    // =========================================================================
    
    /**
     * Aktive Services für einen Standort laden
     */
    public function getActiveServices(int $locationId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_services';
        
        $services = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE location_id = %d AND is_active = 1 ORDER BY sort_order ASC",
                $locationId
            ),
            ARRAY_A
        );
        
        return $services ?: [];
    }
    
    /**
     * Alle Services für einen Standort (inkl. inaktive)
     */
    public function getAllServices(int $locationId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_services';
        
        $services = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE location_id = %d ORDER BY sort_order ASC",
                $locationId
            ),
            ARRAY_A
        );
        
        return $services ?: [];
    }
    
    /**
     * Einzelnen Service laden
     */
    public function getService(int $locationId, string $serviceKey): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_services';
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE location_id = %d AND service_key = %s",
                $locationId,
                $serviceKey
            ),
            ARRAY_A
        );
    }
    
    // =========================================================================
    // SCHREIBEN
    // =========================================================================
    
    /**
     * Standard-Services für einen neuen Standort erstellen
     */
    public function createDefaultServices(int $locationId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_services';
        
        foreach (self::DEFAULT_SERVICES as $service) {
            $wpdb->insert($table, array_merge($service, [
                'location_id' => $locationId,
                'is_active'   => 1,
            ]));
        }
    }
    
    /**
     * Service aktivieren/deaktivieren
     */
    public function toggleService(int $locationId, string $serviceKey, bool $active): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_services';
        
        $result = $wpdb->update(
            $table,
            ['is_active' => $active ? 1 : 0],
            ['location_id' => $locationId, 'service_key' => $serviceKey]
        );
        
        return $result !== false;
    }
    
    /**
     * Custom Service hinzufügen
     */
    public function addCustomService(int $locationId, array $data): int|false
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_services';
        
        $serviceKey = 'custom_' . sanitize_title($data['label'] ?? 'service');
        $serviceKey = $this->ensureUniqueKey($locationId, $serviceKey);
        
        $result = $wpdb->insert($table, [
            'location_id'         => $locationId,
            'service_key'         => $serviceKey,
            'service_type'        => 'custom',
            'label'               => sanitize_text_field($data['label'] ?? ''),
            'description'         => sanitize_text_field($data['description'] ?? ''),
            'icon'                => sanitize_text_field($data['icon'] ?? '📋'),
            'is_active'           => 1,
            'patient_restriction' => sanitize_text_field($data['patient_restriction'] ?? 'all'),
            'external_url'        => esc_url_raw($data['external_url'] ?? ''),
            'sort_order'          => (int) ($data['sort_order'] ?? 99),
        ]);
        
        return $result !== false ? (int) $wpdb->insert_id : false;
    }
    
    /**
     * Service löschen
     */
    public function deleteService(int $serviceId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_services';
        
        return $wpdb->delete($table, ['id' => $serviceId]) !== false;
    }
    
    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================
    
    private function ensureUniqueKey(int $locationId, string $key): string
    {
        global $wpdb;
        $table    = $wpdb->prefix . 'pp_services';
        $original = $key;
        $counter  = 1;
        
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE location_id = %d AND service_key = %s",
            $locationId, $key
        )) !== null) {
            $key = $original . '_' . (++$counter);
        }
        
        return $key;
    }
}
