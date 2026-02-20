<?php
declare(strict_types=1);
/**
 * ApiKeyRepository – CRUD für pp_api_keys
 *
 * API-Keys werden von PVS-Systemen (Praxis-Verwaltungs-Software)
 * zur Authentifizierung der REST-API verwendet.
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.0.0
 */

namespace PraxisPortal\Database\Repository;

class ApiKeyRepository extends AbstractRepository
{
    /* =========================================================================
     * TABLE
     * ====================================================================== */

    protected function tableName(): string
    {
        return $this->wpdb->prefix . 'pp_api_keys';
    }

    /* =========================================================================
     * READ
     * ====================================================================== */

    /**
     * Alle API-Keys eines Standorts
     *
     * @param int $locationId
     * @return array
     */
    public function getByLocation(int $locationId): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE location_id = %d ORDER BY created_at DESC",
                $locationId
            ),
            ARRAY_A
        );

        return is_array($results) ? $results : [];
    }

    /**
     * API-Key per ID laden
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /* =========================================================================
     * VALIDATE
     * ====================================================================== */

    /**
     * API-Key validieren
     *
     * Prüft Existenz, Aktiv-Status und IP-Whitelist.
     * Aktualisiert Nutzungsstatistiken bei Erfolg.
     *
     * @param string $apiKey  64-Zeichen Hex-String
     * @param string $clientIp Client-IP für Whitelist-Prüfung
     * @return array|\WP_Error Key-Daten bei Erfolg, WP_Error bei Fehler
     */
    public function validate(string $apiKey, string $clientIp = '')
    {
        $table = $this->tableName();
        $keyHash = hash('sha256', $apiKey);

        $keyData = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE api_key_hash = %s AND is_active = 1 LIMIT 1",
                $keyHash
            ),
            ARRAY_A
        );

        if (!$keyData) {
            return new \WP_Error('invalid_key', 'Ungültiger API-Key');
        }

        // IP-Whitelist prüfen
        if (!empty($keyData['ip_whitelist'])) {
            $allowedIps = json_decode($keyData['ip_whitelist'], true);

            if (!empty($allowedIps) && is_array($allowedIps)) {
                if (!empty($clientIp) && !in_array($clientIp, $allowedIps, true)) {
                    return new \WP_Error(
                        'ip_not_allowed',
                        'IP-Adresse nicht erlaubt'
                    );
                }
            }
        }

        // Nutzungsstatistiken aktualisieren
        $this->wpdb->update(
            $table,
            [
                'last_used_at' => current_time('mysql'),
                'last_used_ip' => $clientIp ?: null,
                'use_count'    => ((int) ($keyData['use_count'] ?? 0)) + 1,
            ],
            ['id' => $keyData['id']]
        );

        return $keyData;
    }

    /**
     * Prüfen ob Key eine bestimmte Berechtigung hat
     *
     * @param array  $keyData    Daten aus validate()
     * @param string $permission z.B. "can_fetch_gdt", "can_fetch_files", "can_download_pdf"
     * @return bool
     */
    public function hasPermission(array $keyData, string $permission): bool
    {
        return !empty($keyData[$permission]);
    }

    /* =========================================================================
     * CREATE
     * ====================================================================== */

    /**
     * Neuen API-Key erstellen
     *
     * Generiert kryptographisch sicheren 64-Zeichen Hex-Key.
     *
     * @param int    $locationId
     * @param string $name        Beschreibung des Keys
     * @return string|false API-Key (Klartext) oder false
     */
    public function create(int $locationId, string $name = '')
    {
        $apiKey = bin2hex(random_bytes(32)); // 64 Hex-Zeichen
        $keyHash = hash('sha256', $apiKey);
        $keyPrefix = substr($apiKey, 0, 8); // Erste 8 Zeichen für Identifikation

        $label = $name ?: 'API Key ' . date('Y-m-d H:i');

        $result = $this->wpdb->insert($this->tableName(), [
            'location_id'  => $locationId,
            'api_key_hash' => $keyHash,
            'key_prefix'   => $keyPrefix,
            'name'         => $label,
            'label'        => $label,
            'can_fetch_gdt'    => 1,
            'can_fetch_files'  => 1,
            'can_download_pdf' => 1,
            'is_active'   => 1,
            'created_at'  => current_time('mysql'),
            'created_by'  => get_current_user_id(),
        ]);

        return $result ? $apiKey : false;
    }

    /* =========================================================================
     * UPDATE
     * ====================================================================== */

    /**
     * API-Key aktualisieren (Name, Berechtigungen, IP-Whitelist)
     *
     * @param int   $id
     * @param array $data
     * @return int|false
     */
    public function update(int $id, array $data): mixed
    {
        // ip_whitelist als JSON speichern falls Array übergeben
        if (isset($data['ip_whitelist']) && is_array($data['ip_whitelist'])) {
            $data['ip_whitelist'] = json_encode(
                array_values(array_filter($data['ip_whitelist'])),
                JSON_UNESCAPED_UNICODE
            );
        }

        return $this->wpdb->update(
            $this->tableName(),
            $data,
            ['id' => $id]
        );
    }

    /**
     * API-Key deaktivieren
     *
     * @param int $id
     * @return int|false
     */
    public function deactivate(int $id)
    {
        return $this->wpdb->update(
            $this->tableName(),
            ['is_active' => 0],
            ['id' => $id]
        );
    }

    /**
     * API-Key aktivieren
     *
     * @param int $id
     * @return int|false
     */
    public function activate(int $id)
    {
        return $this->wpdb->update(
            $this->tableName(),
            ['is_active' => 1],
            ['id' => $id]
        );
    }

    /* =========================================================================
     * DELETE
     * ====================================================================== */

    /**
     * API-Key endgültig löschen
     *
     * @param int $id
     * @return int|false
     */
    public function delete(int $id): mixed
    {
        return $this->wpdb->delete(
            $this->tableName(),
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Alle Keys eines Standorts löschen
     *
     * @param int $locationId
     * @return int|false
     */
    public function deleteByLocation(int $locationId)
    {
        return $this->wpdb->delete(
            $this->tableName(),
            ['location_id' => $locationId],
            ['%d']
        );
    }
}
