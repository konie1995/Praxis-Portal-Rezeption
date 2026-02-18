<?php
/**
 * MedicationRepository – Medikamenten-Stammdaten
 *
 * CRUD + Suche für die Medikamenten-Datenbank
 * (Autocomplete im Widget).
 *
 * Tabelle: {prefix}pp_medications
 *  - id, name, wirkstoff, staerke, einheit, dosage(legacy), form(legacy),
 *    pzn, standard_dosierung, einnahme_hinweis, kategorie, hinweise,
 *    ist_aktiv, verwendung_count, location_id, created_at, updated_at
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.0.0
 * @version 4.2.9 – Schema-Erweiterung auf v3-Parität
 */

declare(strict_types=1);

namespace PraxisPortal\Database\Repository;

if (!defined('ABSPATH')) {
    exit;
}

class MedicationRepository extends AbstractRepository
{
    protected string $tableKey = 'medications';

    /* -----------------------------------------------------------------
     * READ
     * -------------------------------------------------------------- */

    /**
     * Suche nach Name/Wirkstoff (Autocomplete)
     *
     * Sortierung wie v3:
     * 1. Name beginnt mit Suchbegriff (höchste Priorität)
     * 2. Name enthält Suchbegriff
     * 3. Nur Wirkstoff enthält Suchbegriff (niedrigste)
     * → dann: verwendung_count DESC, name ASC
     *
     * @param string   $term       Suchbegriff (min 2 Zeichen)
     * @param int      $limit      Max Ergebnisse
     * @param int|null $locationId NULL = alle + standortübergreifende
     * @return array
     */
    public function search(string $term, int $limit = 15, ?int $locationId = null): array
    {
        if (strlen($term) < 2) {
            return [];
        }

        $table     = $this->table();
        $likeStart = $this->wpdb->esc_like($term) . '%';
        $likeFull  = '%' . $this->wpdb->esc_like($term) . '%';

        // Standortfilter: eigene + globale (location_id IS NULL)
        $locationClause = '';
        $locationParams = [];
        if ($locationId !== null) {
            $locationClause = 'AND (location_id IS NULL OR location_id = %d)';
            $locationParams[] = $locationId;
        }

        // ── FULLTEXT-Suche (schnell, nutzt Index) ──────────────────
        // Nur wenn Term ≥ 3 Zeichen (MySQL ft_min_word_len Default = 3)
        // und kein Sonderzeichen das BOOLEAN MODE stört
        if (strlen($term) >= 3 && $this->hasFulltextIndex()) {
            // Boolean Mode: term* für Prefix-Match
            $ftTerm   = '+' . preg_replace('/[^\p{L}\p{N}\s]/u', '', $term) . '*';
            $ftParams = [$ftTerm, $ftTerm, $likeStart, $likeFull, ...$locationParams, $limit];

            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, name, wirkstoff, staerke, einheit, pzn,
                            standard_dosierung, einnahme_hinweis, kategorie, location_id,
                            MATCH(name, wirkstoff, dosage) AGAINST(%s IN BOOLEAN MODE) AS ft_score
                     FROM {$table}
                     WHERE ist_aktiv = 1
                       AND MATCH(name, wirkstoff, dosage) AGAINST(%s IN BOOLEAN MODE)
                     {$locationClause}
                     ORDER BY
                       CASE
                         WHEN name LIKE %s THEN 0
                         WHEN name LIKE %s THEN 1
                         ELSE 2
                       END,
                       ft_score DESC,
                       verwendung_count DESC,
                       name ASC
                     LIMIT %d",
                    ...$ftParams
                ),
                ARRAY_A
            ) ?: [];

            // FULLTEXT hat Ergebnisse → fertig
            if (!empty($results)) {
                return $results;
            }
        }

        // ── Fallback: LIKE-Suche (langsamer, aber immer verfügbar) ──
        $likeParams = [$likeFull, $likeFull, ...$locationParams, $likeStart, $likeFull, $limit];

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, name, wirkstoff, staerke, einheit, pzn,
                        standard_dosierung, einnahme_hinweis, kategorie, location_id
                 FROM {$table}
                 WHERE ist_aktiv = 1
                   AND (name LIKE %s OR wirkstoff LIKE %s)
                 {$locationClause}
                 ORDER BY
                   CASE
                     WHEN name LIKE %s THEN 0
                     WHEN name LIKE %s THEN 1
                     ELSE 2
                   END,
                   verwendung_count DESC,
                   name ASC
                 LIMIT %d",
                ...$likeParams
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Prüft ob FULLTEXT-Index auf Medikamenten-Tabelle existiert.
     * Ergebnis wird pro Request gecacht.
     */
    private function hasFulltextIndex(): bool
    {
        static $hasIndex = null;
        if ($hasIndex !== null) {
            return $hasIndex;
        }

        $table = $this->table();
        $hasIndex = (bool) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_ft_search'",
            DB_NAME,
            $table
        ));

        return $hasIndex;
    }

    /**
     * Alle Medikamente (für Export / Admin)
     */
    public function getAll(): array
    {
        $table = $this->table();
        return $this->wpdb->get_results(
            "SELECT id, name, wirkstoff, staerke, einheit, pzn,
                    standard_dosierung, einnahme_hinweis, kategorie,
                    hinweise, ist_aktiv, verwendung_count, location_id
             FROM {$table}
             ORDER BY name ASC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Einzelnes Medikament nach ID
     */
    public function findById(int $id): ?array
    {
        $table = $this->table();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Anzahl aller Einträge (optional gefiltert)
     *
     * @param string $where  WHERE-Bedingung (z.B. "name LIKE %s")
     * @param array  $params prepare()-Parameter
     */
    public function count(string $where = '1=1', array $params = []): int
    {
        $table = $this->table();
        $sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, ...$params);
        }

        return (int) $this->wpdb->get_var($sql);
    }

    /* -----------------------------------------------------------------
     * CREATE
     * -------------------------------------------------------------- */

    /**
     * Medikament anlegen
     *
     * @param array $data [name, wirkstoff?, staerke?, einheit?, pzn?, 
     *                      standard_dosierung?, einnahme_hinweis?, kategorie?,
     *                      hinweise?, ist_aktiv?, location_id?]
     * @return int|false  Insert-ID oder false
     */
    public function create(array $data): int|false
    {
        $name = sanitize_text_field($data['name'] ?? '');
        if (empty($name)) {
            return false;
        }

        $wirkstoff = sanitize_text_field($data['wirkstoff'] ?? '');
        $staerke   = sanitize_text_field($data['staerke'] ?? '');

        $insertData = [
            'name'               => $name,
            'wirkstoff'          => $wirkstoff !== '' ? $wirkstoff : null,
            'staerke'            => $staerke !== '' ? $staerke : null,
            'einheit'            => !empty($data['einheit']) ? sanitize_text_field($data['einheit']) : null,
            'dosage'             => trim($wirkstoff . ($staerke ? ' ' . $staerke : '')) ?: null,
            'form'               => !empty($data['kategorie']) ? sanitize_text_field($data['kategorie']) : (!empty($data['form']) ? sanitize_text_field($data['form']) : null),
            'pzn'                => !empty($data['pzn']) ? sanitize_text_field($data['pzn']) : null,
            'standard_dosierung' => !empty($data['standard_dosierung']) ? sanitize_text_field($data['standard_dosierung']) : null,
            'einnahme_hinweis'   => !empty($data['einnahme_hinweis']) ? sanitize_text_field($data['einnahme_hinweis']) : null,
            'kategorie'          => !empty($data['kategorie']) ? sanitize_text_field($data['kategorie']) : (!empty($data['form']) ? sanitize_text_field($data['form']) : null),
            'hinweise'           => !empty($data['hinweise']) ? sanitize_textarea_field($data['hinweise']) : null,
            'ist_aktiv'          => isset($data['ist_aktiv']) ? (int) $data['ist_aktiv'] : 1,
            'created_at'         => current_time('mysql'),
        ];

        // Format-Array dynamisch (NULL-Werte ausschließen)
        $formats = [];
        $filtered = [];
        foreach ($insertData as $key => $value) {
            if ($value !== null) {
                $filtered[$key] = $value;
                $formats[] = ($key === 'ist_aktiv') ? '%d' : '%s';
            }
        }

        // Optional: Standort-Zuordnung (NULL = global)
        if (isset($data['location_id']) && $data['location_id'] !== null) {
            $filtered['location_id'] = (int) $data['location_id'];
            $formats[] = '%d';
        }

        $result = $this->wpdb->insert($this->table(), $filtered, $formats);

        return $result ? (int) $this->wpdb->insert_id : false;
    }

    /* -----------------------------------------------------------------
     * UPDATE
     * -------------------------------------------------------------- */

    /**
     * Medikament aktualisieren
     */
    public function update(int $id, array $data): bool
    {
        $updateData = [];
        $formats    = [];

        // String-Felder
        $stringFields = [
            'name', 'wirkstoff', 'staerke', 'einheit', 'pzn',
            'standard_dosierung', 'einnahme_hinweis', 'kategorie',
        ];
        foreach ($stringFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = sanitize_text_field($data[$field]);
                $formats[] = '%s';
            }
        }

        // Textarea-Feld
        if (isset($data['hinweise'])) {
            $updateData['hinweise'] = sanitize_textarea_field($data['hinweise']);
            $formats[] = '%s';
        }

        // Legacy-Felder synchronisieren
        if (isset($data['wirkstoff']) || isset($data['staerke'])) {
            $wirkstoff = $data['wirkstoff'] ?? '';
            $staerke   = $data['staerke'] ?? '';
            $updateData['dosage'] = trim($wirkstoff . ($staerke ? ' ' . $staerke : ''));
            $formats[] = '%s';
        }
        if (isset($data['kategorie'])) {
            $updateData['form'] = sanitize_text_field($data['kategorie']);
            $formats[] = '%s';
        }

        // Int-Feld
        if (isset($data['ist_aktiv'])) {
            $updateData['ist_aktiv'] = (int) $data['ist_aktiv'];
            $formats[] = '%d';
        }

        // location_id separat (int, nullable)
        if (array_key_exists('location_id', $data)) {
            $updateData['location_id'] = $data['location_id'] !== null ? (int) $data['location_id'] : null;
            $formats[] = $data['location_id'] !== null ? '%d' : null;
        }

        if (empty($updateData)) {
            return false;
        }

        $result = $this->wpdb->update(
            $this->table(),
            $updateData,
            ['id' => $id],
            $formats,
            ['%d']
        );

        return $result !== false;
    }

    /* -----------------------------------------------------------------
     * DELETE
     * -------------------------------------------------------------- */

    /**
     * Einzelnes Medikament löschen
     */
    public function delete(int $id): bool
    {
        return (bool) $this->wpdb->delete(
            $this->table(),
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Alle Medikamente löschen (Gefahrenzone)
     *
     * @return int Anzahl gelöschter Zeilen
     */
    public function deleteAll(): int
    {
        $table = $this->table();
        $count = $this->count();

        // DELETE statt TRUNCATE → kann per Transaktion zurückgerollt werden
        $this->wpdb->query('START TRANSACTION');
        $result = $this->wpdb->query("DELETE FROM {$table} WHERE 1=1");

        if ($result === false) {
            $this->wpdb->query('ROLLBACK');
            return 0;
        }

        $this->wpdb->query('COMMIT');
        return $count;
    }

    /* -----------------------------------------------------------------
     * BATCH
     * -------------------------------------------------------------- */

    /**
     * Mehrere Medikamente auf einmal einfügen (Import)
     *
     * @param array $rows Array von [name, wirkstoff?, staerke?, pzn?, kategorie?, standard_dosierung?, einnahme_hinweis?]
     * @return int  Anzahl eingefügt
     */
    public function bulkInsert(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $table    = $this->table();
        $now      = current_time('mysql');
        $inserted = 0;

        // Batch von 50 für Performance
        foreach (array_chunk($rows, 50) as $chunk) {
            $values       = [];
            $placeholders = [];

            foreach ($chunk as $row) {
                $wirkstoff = $row['wirkstoff'] ?? '';
                $staerke   = $row['staerke'] ?? '';
                $kategorie = $row['kategorie'] ?? $row['form'] ?? '';
                $dosage    = trim($wirkstoff . ($staerke ? ' ' . $staerke : ''));

                $placeholders[] = '(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)';
                $values[]       = $row['name'] ?? '';
                $values[]       = $wirkstoff ?: null;
                $values[]       = $staerke ?: null;
                $values[]       = $dosage ?: null;
                $values[]       = $kategorie ?: null;
                $values[]       = $row['pzn'] ?? '';
                $values[]       = $row['standard_dosierung'] ?? null;
                $values[]       = $row['einnahme_hinweis'] ?? null;
                $values[]       = $kategorie ?: null;
                $values[]       = $now;
            }

            $sql = "INSERT INTO {$table} (name, wirkstoff, staerke, dosage, form, pzn, standard_dosierung, einnahme_hinweis, kategorie, created_at) VALUES "
                . implode(', ', $placeholders);

            $result = $this->wpdb->query(
                $this->wpdb->prepare($sql, $values)
            );

            if ($result) {
                $inserted += (int) $result;
            }
        }

        return $inserted;
    }

    /**
     * Erhöht den Verwendungszähler
     *
     * @param int $id Medikament-ID
     * @return bool
     */
    public function incrementUsage(int $id): bool
    {
        $table = $this->table();
        return $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$table} SET verwendung_count = verwendung_count + 1 WHERE id = %d",
            $id
        )) !== false;
    }

    /**
     * Holt alle Kategorien
     *
     * @return array
     */
    public function getCategories(): array
    {
        $table = $this->table();
        return $this->wpdb->get_col(
            "SELECT DISTINCT kategorie FROM {$table}
             WHERE kategorie IS NOT NULL AND kategorie != '' AND ist_aktiv = 1
             ORDER BY kategorie ASC"
        ) ?: [];
    }

    /**
     * Statistiken zur Datenbank
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $table = $this->table();

        return [
            'total'      => (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'active'     => (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ist_aktiv = 1"),
            'with_pzn'   => (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE pzn IS NOT NULL AND pzn != ''"),
            'categories' => (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT kategorie) FROM {$table} WHERE kategorie IS NOT NULL"),
        ];
    }
}
