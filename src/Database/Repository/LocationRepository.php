<?php
/**
 * LocationRepository – CRUD für pp_locations
 *
 * v4-Migration:
 *  - doc_key  → license_key
 *  - place_id → location_uuid
 *  - Static PP_Database → Repository-Pattern mit DI
 *  - PHP 8.x Null-Safety
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.0.0
 */

namespace PraxisPortal\Database\Repository;

class LocationRepository extends AbstractRepository
{
    /* =========================================================================
     * TABLE
     * ====================================================================== */

    protected function tableName(): string
    {
        return $this->wpdb->prefix . 'pp_locations';
    }

    /* =========================================================================
     * READ
     * ====================================================================== */

    /**
     * Alle Standorte laden
     *
     * @param bool $activeOnly Nur aktive Standorte
     * @return array
     */
    public function getAll(bool $activeOnly = true): array
    {
        $table = $this->tableName();
        $where = $activeOnly ? 'WHERE is_active = 1' : '';

        $results = $this->wpdb->get_results(
            "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );

        if (!is_array($results)) {
            return [];
        }

        return array_map([$this, 'sanitizeRow'], $results);
    }

    /**
     * Standort per ID laden
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
     * Standort per Slug laden
     *
     * @param string $slug
     * @return array|null
     */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE slug = %s LIMIT 1",
                $slug
            ),
            ARRAY_A
        );

        return $row ? $this->sanitizeRow($row) : null;
    }

    /**
     * Standort per location_uuid (ehem. place_id) laden
     *
     * @param string $locationUuid z.B. "LOC-a1b2c3"
     * @return array|null
     */
    public function findByUuid(string $locationUuid): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE uuid = %s LIMIT 1",
                $locationUuid
            ),
            ARRAY_A
        );

        return $row ? $this->sanitizeRow($row) : null;
    }

    /**
     * Standort per license_key (ehem. doc_key) laden
     *
     * @param string $licenseKey z.B. "DOC-26-001-ABC"
     * @return array|null
     */
    public function findByLicenseKey(string $licenseKey): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE license_key = %s AND is_active = 1 LIMIT 1",
                $licenseKey
            ),
            ARRAY_A
        );

        return $row ? $this->sanitizeRow($row) : null;
    }

    /**
     * Default-Standort laden
     *
     * Fallback: Erster aktiver Standort
     *
     * @return array|null
     */
    public function getDefault(): ?array
    {
        $table = $this->tableName();

        $row = $this->wpdb->get_row(
            "SELECT * FROM {$table} WHERE is_default = 1 AND is_active = 1 LIMIT 1",
            ARRAY_A
        );

        // Fallback: erster aktiver
        if (!$row) {
            $row = $this->wpdb->get_row(
                "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY id ASC LIMIT 1",
                ARRAY_A
            );
        }

        return $row ? $this->sanitizeRow($row) : null;
    }

    /**
     * Anzahl aktiver Standorte
     *
     * @return int
     */
    public function countActive(): int
    {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE is_active = 1"
        );
    }

    /* =========================================================================
     * CREATE
     * ====================================================================== */

    /**
     * Neuen Standort anlegen
     *
     * Generiert eindeutigen Slug, erstellt Default-Services.
     *
     * @param array $data Standort-Daten
     * @return int|false Location-ID oder false
     */
    public function create(array $data)
    {
        $table = $this->tableName();

        // Slug generieren falls nicht gesetzt
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name'] ?? 'standort');
        }

        // Slug-Uniqueness sicherstellen
        $data['slug'] = $this->ensureUniqueSlug($data['slug']);

        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        $locationId = (int) $this->wpdb->insert_id;

        // WordPress-Hook für Services etc.
        do_action('pp_location_created', $locationId);

        return $locationId;
    }

    /* =========================================================================
     * UPDATE
     * ====================================================================== */

    /**
     * Standort aktualisieren
     *
     * @param int   $id
     * @param array $data
     * @return int|false Affected rows oder false
     */
    public function update(int $id, array $data): mixed
    {
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->update(
            $this->tableName(),
            $data,
            ['id' => $id],
            null,
            ['%d']
        );

        if ($result !== false) {
            do_action('pp_location_updated', $id);
        }

        return $result;
    }

    /**
     * Standard-Standort setzen
     *
     * Setzt alle anderen auf is_default = 0.
     *
     * @param int $id
     * @return int|false
     */
    public function setDefault(int $id)
    {
        $table = $this->tableName();

        // Alle Default-Flags zurücksetzen
        $this->wpdb->query("UPDATE {$table} SET is_default = 0");

        $result = $this->wpdb->update(
            $table,
            ['is_default' => 1, 'updated_at' => current_time('mysql')],
            ['id' => $id]
        );

        if ($result !== false) {
            do_action('pp_location_updated', $id);
        }

        return $result;
    }

    /**
     * Standort deaktivieren (Soft-Delete)
     *
     * @param int $id
     * @return int|false|\WP_Error
     */
    public function deactivate(int $id)
    {
        if ($this->countActive() <= 1) {
            return new \WP_Error(
                'last_location',
                __('Der letzte aktive Standort kann nicht deaktiviert werden.', 'praxis-portal')
            );
        }

        return $this->wpdb->update(
            $this->tableName(),
            ['is_active' => 0, 'updated_at' => current_time('mysql')],
            ['id' => $id]
        );
    }

    /* =========================================================================
     * DELETE
     * ====================================================================== */

    /**
     * Standort endgültig löschen
     *
     * Löscht auch zugehörige Services.
     *
     * @param int $id
     * @return int|false|\WP_Error
     */
    public function delete(int $id): mixed
    {
        $location = $this->findById($id);

        if (!$location) {
            return new \WP_Error('not_found', 'Standort nicht gefunden.');
        }

        // Letzter aktiver → nicht löschen
        if (($location['is_active'] ?? false) && $this->countActive() <= 1) {
            return new \WP_Error(
                'last_location',
                'Der letzte aktive Standort kann nicht gelöscht werden.'
            );
        }

        $result = $this->wpdb->delete(
            $this->tableName(),
            ['id' => $id],
            ['%d']
        );

        if ($result !== false) {
            // Zugehörige Services löschen
            $servicesTable = $this->wpdb->prefix . 'pp_services';
            $this->wpdb->delete($servicesTable, ['location_id' => $id], ['%d']);

            // Document-Location Links löschen
            $linkTable = $this->wpdb->prefix . 'pp_form_locations';
            $this->wpdb->delete($linkTable, ['location_id' => $id], ['%d']);

            do_action('pp_location_deleted', $id);
        }

        return $result;
    }

    /* =========================================================================
     * HELPERS
     * ====================================================================== */

    /**
     * Eindeutigen Slug generieren
     *
     * @param string $slug Basis-Slug
     * @return string Einzigartiger Slug
     */
    private function ensureUniqueSlug(string $slug): string
    {
        $table    = $this->tableName();
        $baseSlug = $slug;
        $counter  = 1;

        while ($this->wpdb->get_var(
            $this->wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug)
        )) {
            $slug = $baseSlug . '-' . $counter++;
        }

        return $slug;
    }

    /**
     * Null-Werte bereinigen für PHP 8.x
     *
     * @param array $row
     * @return array
     */
    protected function sanitizeRow(array $row): array
    {
        $stringFields = [
            'name', 'slug', 'practice_name', 'practice_owner', 'practice_subtitle',
            'street', 'postal_code', 'city', 'phone', 'phone_emergency',
            'email', 'website', 'logo_url',
            'color_primary', 'color_secondary', 'widget_title', 'widget_subtitle',
            'widget_welcome', 'email_notification', 'email_from_name', 'email_from_address',
            'email_signature', 'vacation_message', 'vacation_start', 'vacation_end',
            'termin_url', 'termin_button_text', 'privacy_url', 'imprint_url',
            'consent_text', 'license_key', 'uuid', 'export_format',
        ];

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] === null) {
                $row[$field] = '';
            }
        }

        return $row;
    }
    
    /**
     * Alias für getAll()
     */
    public function findAll(bool $activeOnly = true): array
    {
        return $this->getAll($activeOnly);
    }
}
