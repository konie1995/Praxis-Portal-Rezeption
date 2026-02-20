<?php
declare(strict_types=1);
/**
 * Location-Manager
 * 
 * Zentrale CRUD-Klasse für Standorte.
 * Verschlüsselt sensible Felder (E-Mail) automatisch.
 *
 * @package PraxisPortal\Location
 * @since   4.0.0
 */

namespace PraxisPortal\Location;

use PraxisPortal\Security\Encryption;

if (!defined('ABSPATH')) {
    exit;
}

class LocationManager
{
    private Encryption $encryption;
    
    /** Cache für geladene Standorte */
    private array $cache = [];
    
    /** Felder die verschlüsselt gespeichert werden */
    private const ENCRYPTED_FIELDS = [
        'email_notification',
        'email_from_address',
    ];
    
    public function __construct(Encryption $encryption)
    {
        $this->encryption = $encryption;
    }
    
    // =========================================================================
    // LESEN
    // =========================================================================
    
    /**
     * Standort per ID laden
     */
    public function getById(int $id): ?array
    {
        if (isset($this->cache['id_' . $id])) {
            return $this->cache['id_' . $id];
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );
        
        if ($row === null) {
            return null;
        }
        
        $location = $this->decryptFields($row);
        $this->cache['id_' . $id] = $location;
        $this->cache['slug_' . $location['slug']] = $location;
        
        return $location;
    }
    
    /**
     * Standort per Slug laden
     */
    public function getBySlug(string $slug): ?array
    {
        if (isset($this->cache['slug_' . $slug])) {
            return $this->cache['slug_' . $slug];
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug),
            ARRAY_A
        );
        
        if ($row === null) {
            return null;
        }
        
        $location = $this->decryptFields($row);
        $this->cache['id_' . $location['id']] = $location;
        $this->cache['slug_' . $slug] = $location;
        
        return $location;
    }
    
    /**
     * Standort per location_uuid laden
     */
    public function getByUuid(string $uuid): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE uuid = %s", $uuid),
            ARRAY_A
        );
        
        return $row !== null ? $this->decryptFields($row) : null;
    }

    /**
     * Standort-Settings für Export-Klassen (PdfBase, Hl7Export).
     *
     * Mappt die DB-Felder auf die von den Export-Klassen erwarteten Keys.
     *
     * @param string $uuid Standort-UUID
     * @return array Settings-Array mit practice_name, practice_address, etc.
     */
    public function getLocationSettings(string $uuid): array
    {
        if (empty($uuid)) {
            return [];
        }

        $location = $this->getByUuid($uuid);
        if (!$location) {
            return [];
        }

        $address = trim(
            ($location['strasse'] ?? '') . ', '
            . ($location['plz'] ?? '') . ' '
            . ($location['ort'] ?? ''),
            ', '
        );

        return [
            'practice_name'     => $location['name'] ?? '',
            'practice_address'  => $address,
            'practice_phone'    => $location['telefon'] ?? '',
            'practice_email'    => $location['email'] ?? '',
            'practice_logo_url' => $location['logo_url'] ?? '',
        ];
    }
    
    /**
     * Alle aktiven Standorte laden
     */
    public function getActive(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
        
        return array_map([$this, 'decryptFields'], $rows ?: []);
    }
    
    /**
     * Alle Standorte laden (inkl. inaktive)
     */
    public function getAll(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
        
        return array_map([$this, 'decryptFields'], $rows ?: []);
    }
    
    /**
     * Default-Standort laden
     */
    public function getDefault(): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        // Erst expliziten Default suchen
        $row = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE is_default = 1 AND is_active = 1 LIMIT 1",
            ARRAY_A
        );
        
        // Fallback: Ersten aktiven Standort nehmen
        if ($row === null) {
            $row = $wpdb->get_row(
                "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY id ASC LIMIT 1",
                ARRAY_A
            );
        }
        
        return $row !== null ? $this->decryptFields($row) : null;
    }
    
    /**
     * Default-Location-ID (schnell, ohne Felder zu entschlüsseln)
     */
    public function getDefaultId(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        $id = $wpdb->get_var(
            "SELECT id FROM {$table} WHERE is_default = 1 AND is_active = 1 LIMIT 1"
        );
        
        if ($id === null) {
            $id = $wpdb->get_var(
                "SELECT id FROM {$table} WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
            );
        }
        
        return (int) ($id ?? 0);
    }
    
    /**
     * Anzahl der aktiven Standorte
     */
    public function countActive(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
    }
    
    // =========================================================================
    // SCHREIBEN
    // =========================================================================
    
    /**
     * Neuen Standort erstellen
     * 
     * @param array $data Standort-Daten
     * @return int|false Neue ID oder false bei Fehler
     */
    public function create(array $data): int|false
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        // Slug generieren falls nicht vorhanden
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        // Slug einzigartig machen
        $data['slug'] = $this->ensureUniqueSlug($data['slug']);
        
        // Sensible Felder verschlüsseln
        $data = $this->encryptFields($data);
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            return false;
        }
        
        $newId = (int) $wpdb->insert_id;
        
        // Cache invalidieren
        $this->cache = [];
        
        // Hook feuern
        do_action('pp_location_created', $newId);
        
        return $newId;
    }
    
    /**
     * Standort aktualisieren
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        // Sensible Felder verschlüsseln
        $data = $this->encryptFields($data);
        
        $result = $wpdb->update($table, $data, ['id' => $id]);
        
        // Cache invalidieren
        unset($this->cache['id_' . $id]);
        
        // Hook feuern
        do_action('pp_location_updated', $id);
        
        return $result !== false;
    }
    
    /**
     * Standort löschen
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        // Nicht den letzten Standort löschen
        if ($this->countActive() <= 1) {
            return false;
        }
        
        $result = $wpdb->delete($table, ['id' => $id]);
        
        // Cache invalidieren
        $this->cache = [];
        
        // Hook feuern
        do_action('pp_location_deleted', $id);
        
        return $result !== false;
    }
    
    /**
     * Standort als Default setzen
     */
    public function setDefault(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pp_locations';
        
        // Alle zurücksetzen
        $wpdb->update($table, ['is_default' => 0], ['is_default' => 1]);
        
        // Neuen Default setzen
        $result = $wpdb->update($table, ['is_default' => 1], ['id' => $id]);
        
        $this->cache = [];
        
        return $result !== false;
    }
    
    // =========================================================================
    // VERSCHLÜSSELUNG
    // =========================================================================
    
    /**
     * Verschlüsselt sensible Felder vor dem Speichern
     */
    private function encryptFields(array $data): array
    {
        if (!$this->encryption->isAvailable()) {
            return $data;
        }
        
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                // Nicht doppelt verschlüsseln
                if (!str_starts_with($data[$field], 'S:') && !str_starts_with($data[$field], 'O:')) {
                    $data[$field] = $this->encryption->encrypt($data[$field]);
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Entschlüsselt sensible Felder nach dem Laden
     */
    private function decryptFields(array $row): array
    {
        if (!$this->encryption->isAvailable()) {
            return $row;
        }
        
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (
                isset($row[$field])
                && $row[$field] !== ''
                && (str_starts_with($row[$field], 'S:') || str_starts_with($row[$field], 'O:'))
            ) {
                try {
                    $row[$field] = $this->encryption->decrypt($row[$field]);
                } catch (\RuntimeException $e) {
                    // Entschlüsselung fehlgeschlagen – Feld leer lassen
                    $row[$field] = '';
                    error_log('PP LocationManager: Entschlüsselung fehlgeschlagen für ' . $field);
                }
            }
        }
        
        return $row;
    }
    
    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================
    
    /**
     * Stellt sicher, dass ein Slug einzigartig ist
     */
    private function ensureUniqueSlug(string $slug, int $excludeId = 0): string
    {
        global $wpdb;
        $table    = $wpdb->prefix . 'pp_locations';
        $original = $slug;
        $counter  = 1;
        
        while (true) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s AND id != %d",
                $slug,
                $excludeId
            ));
            
            if ($existing === null) {
                break;
            }
            
            $slug = $original . '-' . (++$counter);
        }
        
        return $slug;
    }
}
