<?php
declare(strict_types=1);
/**
 * ServiceRepository – CRUD für pp_services
 *
 * Services sind die einzelnen Widget-Dienste pro Standort
 * (Rezept, Überweisung, Termin, Anamnesebogen, etc.)
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.0.0
 */

namespace PraxisPortal\Database\Repository;

class ServiceRepository extends AbstractRepository
{
    /* =========================================================================
     * TABLE
     * ====================================================================== */

    protected function tableName(): string
    {
        return $this->wpdb->prefix . 'pp_services';
    }

    /* =========================================================================
     * READ
     * ====================================================================== */

    /**
     * Alle Services eines Standorts laden
     *
     * @param int  $locationId
     * @param bool $activeOnly Nur aktive Services
     * @return array
     */
    public function getByLocation(int $locationId, bool $activeOnly = true): array
    {
        $table = $this->tableName();
        $where = "WHERE location_id = %d" . ($activeOnly ? " AND is_active = 1" : "");

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC",
                $locationId
            ),
            ARRAY_A
        );

        if (!is_array($results)) {
            return [];
        }

        return array_map([$this, 'sanitizeRow'], $results);
    }

    /**
     * Service per ID laden
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

        return $row ? $this->sanitizeRow($row) : null;
    }

    /**
     * Service per Standort + service_key laden
     *
     * @param int    $locationId
     * @param string $serviceKey z.B. "rezept", "termin", "anamnesebogen"
     * @return array|null
     */
    public function findByKey(int $locationId, string $serviceKey): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE location_id = %d AND service_key = %s LIMIT 1",
                $locationId,
                $serviceKey
            ),
            ARRAY_A
        );

        return $row ? $this->sanitizeRow($row) : null;
    }

    /**
     * Prüfen ob ein Service für einen Standort aktiv ist
     *
     * @param int    $locationId
     * @param string $serviceKey
     * @return bool
     */
    public function isActive(int $locationId, string $serviceKey): bool
    {
        $service = $this->findByKey($locationId, $serviceKey);
        return $service !== null && !empty($service['is_active']);
    }

    /* =========================================================================
     * CREATE
     * ====================================================================== */

    /**
     * Service erstellen
     *
     * @param array $data
     * @return int|false Service-ID oder false
     */
    public function create(array $data)
    {
        $result = $this->wpdb->insert($this->tableName(), $data);
        return $result ? (int) $this->wpdb->insert_id : false;
    }

    /**
     * Standard-Services für einen Standort erstellen
     *
     * Wird bei Standort-Erstellung aufgerufen.
     *
     * @param int $locationId
     * @return void
     */
    public function createDefaults(int $locationId): void
    {
        $defaults = [
            [
                'service_key'         => 'anamnesebogen',
                'service_type'        => 'builtin',
                'label'               => 'Anamnesebogen',
                'description'         => 'Füllen Sie den Anamnesebogen vorab aus',
                'icon'                => '📋',
                'is_active'           => 1,
                'patient_restriction' => 'all',
                'sort_order'          => 1,
            ],
            [
                'service_key'         => 'termin',
                'service_type'        => 'builtin',
                'label'               => 'Termin anfragen',
                'description'         => 'Fragen Sie einen Termin an',
                'icon'                => '📅',
                'is_active'           => 1,
                'patient_restriction' => 'all',
                'sort_order'          => 2,
            ],
            [
                'service_key'         => 'terminabsage',
                'service_type'        => 'builtin',
                'label'               => 'Termin absagen',
                'description'         => 'Sagen Sie einen bestehenden Termin ab',
                'icon'                => '❌',
                'is_active'           => 1,
                'patient_restriction' => 'patients_only',
                'sort_order'          => 3,
            ],
            [
                'service_key'         => 'rezept',
                'service_type'        => 'builtin',
                'label'               => 'Rezept bestellen',
                'description'         => 'Fordern Sie ein Folgerezept an',
                'icon'                => '💊',
                'is_active'           => 1,
                'patient_restriction' => 'patients_only',
                'sort_order'          => 4,
            ],
            [
                'service_key'         => 'ueberweisung',
                'service_type'        => 'builtin',
                'label'               => 'Überweisung anfragen',
                'description'         => 'Fordern Sie eine Überweisung an',
                'icon'                => '📄',
                'is_active'           => 1,
                'patient_restriction' => 'patients_only',
                'sort_order'          => 5,
            ],
            [
                'service_key'         => 'brillenverordnung',
                'service_type'        => 'builtin',
                'label'               => 'Brillenverordnung',
                'description'         => 'Fordern Sie eine Brillenverordnung an',
                'icon'                => '👓',
                'is_active'           => 0,
                'patient_restriction' => 'patients_only',
                'sort_order'          => 6,
            ],
            [
                'service_key'         => 'dokument',
                'service_type'        => 'builtin',
                'label'               => 'Dokument hochladen',
                'description'         => 'Laden Sie Dokumente hoch (z.B. Befunde)',
                'icon'                => '📎',
                'is_active'           => 0,
                'patient_restriction' => 'patients_only',
                'sort_order'          => 7,
            ],
            [
                'service_key'         => 'downloads',
                'service_type'        => 'builtin',
                'label'               => 'Downloads',
                'description'         => 'Formulare und Dokumente zum Herunterladen',
                'icon'                => '📥',
                'is_active'           => 0,
                'patient_restriction' => 'all',
                'sort_order'          => 8,
            ],
            [
                'service_key'         => 'notfall',
                'service_type'        => 'builtin',
                'label'               => 'Notfall',
                'description'         => 'Notfall-Kontaktinformationen',
                'icon'                => '🚨',
                'is_active'           => 1,
                'patient_restriction' => 'all',
                'sort_order'          => 9,
            ],
        ];

        foreach ($defaults as $service) {
            $service['location_id'] = $locationId;

            $existing = $this->findByKey($locationId, $service['service_key']);
            if (!$existing) {
                $this->create($service);
            } else {
                // Sort-Order auf aktuellen Standard bringen (überschreibt alte 10/20/30-Werte)
                $this->update((int) $existing['id'], ['sort_order' => $service['sort_order']]);
            }
        }
    }

    /* =========================================================================
     * UPDATE
     * ====================================================================== */

    /**
     * Service aktualisieren
     *
     * @param int   $id
     * @param array $data
     * @return int|false
     */
    public function update(int $id, array $data): mixed
    {
        return $this->wpdb->update(
            $this->tableName(),
            $data,
            ['id' => $id]
        );
    }

    /**
     * Service aktivieren / deaktivieren
     *
     * @param int  $id
     * @param bool $active
     * @return int|false
     */
    public function toggle(int $id, bool $active)
    {
        return $this->wpdb->update(
            $this->tableName(),
            ['is_active' => $active ? 1 : 0],
            ['id' => $id]
        );
    }

    /**
     * Sortierung aktualisieren (Batch)
     *
     * @param array $order [id => sort_order, ...]
     * @return void
     */
    public function updateSortOrder(array $order): void
    {
        foreach ($order as $id => $sortOrder) {
            $this->wpdb->update(
                $this->tableName(),
                ['sort_order' => (int) $sortOrder],
                ['id' => (int) $id]
            );
        }
    }

    /* =========================================================================
     * DELETE
     * ====================================================================== */

    /**
     * Service löschen
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
     * Alle Services eines Standorts löschen
     *
     * Wird bei Standort-Löschung aufgerufen.
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

    /* =========================================================================
     * HELPERS
     * ====================================================================== */

    /**
     * Null-Werte bereinigen (PHP 8.x)
     */
    protected function sanitizeRow(array $row): array
    {
        $stringFields = [
            'service_key', 'service_type', 'label', 'description',
            'icon', 'external_url', 'custom_fields', 'patient_restriction',
        ];

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] === null) {
                $row[$field] = '';
            }
        }

        return $row;
    }
}
