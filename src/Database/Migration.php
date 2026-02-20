<?php
declare(strict_types=1);
/**
 * Migration – Versionsbasierte Datenbank-Migrationen
 *
 * Führt Migrationen schrittweise aus, basierend auf der
 * gespeicherten DB-Version (Option pp_db_version).
 *
 * v4-Migration:
 *  - doc_key  → license_key
 *  - place_id → location_uuid
 *  - Neue Spalten / Indizes
 *  - Idempotent: Kann mehrfach ausgeführt werden
 *
 * @package PraxisPortal\Database
 * @since   4.0.0
 */

namespace PraxisPortal\Database;

if (!defined('ABSPATH')) {
    exit;
}

class Migration
{
    private const DB_VERSION_OPTION = 'pp_db_version';
    private const DB_VERSION        = '4.2.10';

    /** @var \wpdb */
    private $wpdb;

    /** @var string */
    private $prefix;

    /** @var array Log-Einträge */
    private array $log = [];

    /**
     * @param \wpdb $wpdb
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb   = $wpdb;
        $this->prefix = $wpdb->prefix;
    }

    /* =========================================================================
     * MAIN ENTRY
     * ====================================================================== */

    /**
     * Alle ausstehenden Migrationen ausführen
     *
     * @return array Log-Einträge
     */
    public function run(): array
    {
        $currentVersion = get_option(self::DB_VERSION_OPTION, '0');
        $this->log('Migration gestartet – aktuelle DB-Version: ' . $currentVersion);

        // ── v3-Migrationen (für Updates von v3 → v4) ────────────────────

        if (version_compare($currentVersion, '3.0.0', '<')) {
            $this->migrateToV3();
        }

        if (version_compare($currentVersion, '3.2.19', '<')) {
            $this->migrateAddDownloadsService();
        }

        if (version_compare($currentVersion, '3.3.2', '<')) {
            $this->migrateEnsureDocumentsTable();
        }

        if (version_compare($currentVersion, '3.9.40', '<')) {
            $this->migrateAddTerminabsageService();
        }

        if (version_compare($currentVersion, '3.9.60', '<')) {
            $this->migrateExpandEncryptedColumns();
        }

        // ── v4-Migrationen ──────────────────────────────────────────────

        if (version_compare($currentVersion, '4.0.0', '<')) {
            $this->migrateToV4();
        }

        if (version_compare($currentVersion, '4.1.1', '<')) {
            $this->migrateToV41();
        }

        if (version_compare($currentVersion, '4.2.9', '<')) {
            $this->migrateToV429();
        }

        if (version_compare($currentVersion, '4.2.10', '<')) {
            $this->migrateToV4210();
        }

        // Idempotent: UNIQUE-Constraints immer prüfen
        $this->ensureNoDocKeyUnique();

        // Idempotent: Default-Standort sicherstellen (immer, nicht nur bei Migration)
        $this->ensureDefaultLocation();

        // Idempotent: Medikamente importieren wenn Tabelle leer
        $this->ensureMedicationsImported();

        // Version aktualisieren
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        $this->log('Migration abgeschlossen – DB-Version: ' . self::DB_VERSION);

        return $this->log;
    }

    /**
     * Aktuelle DB-Version
     *
     * @return string
     */
    public static function getCurrentVersion(): string
    {
        return get_option(self::DB_VERSION_OPTION, '0');
    }

    /**
     * Ziel-Version
     *
     * @return string
     */
    public static function getTargetVersion(): string
    {
        return self::DB_VERSION;
    }

    /**
     * Ist ein Update nötig?
     *
     * @return bool
     */
    public static function needsUpdate(): bool
    {
        return version_compare(self::getCurrentVersion(), self::DB_VERSION, '<');
    }

    /* =========================================================================
     * v3 MIGRATIONS (Kompatibilität)
     * ====================================================================== */

    /**
     * v3.0.0: Grundtabellen erstellen + Daten migrieren
     */
    private function migrateToV3(): void
    {
        $this->log('Migration → v3.0.0');
        $charset = $this->wpdb->get_charset_collate();

        // Schema kümmert sich um die Tabellenerstellung
        Schema::install();

        // Default-Standort sicherstellen
        $this->ensureDefaultLocation();

        // Submissions dem Default-Standort zuweisen
        $this->assignSubmissionsToDefault();

        // Portal-Credentials migrieren
        $this->migratePortalCredentials();

        // API-Key migrieren
        $this->migrateApiKey();

        $this->log('v3.0.0 abgeschlossen');
    }

    /**
     * v3.2.19: Downloads-Service hinzufügen
     */
    private function migrateAddDownloadsService(): void
    {
        $this->log('Migration → v3.2.19: Downloads-Service');
        $table = $this->prefix . 'pp_services';

        $locations = $this->wpdb->get_col(
            "SELECT id FROM {$this->prefix}pp_locations"
        );

        foreach ($locations as $locationId) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE location_id = %d AND service_key = 'downloads'",
                $locationId
            ));

            if (!$exists) {
                $this->wpdb->insert($table, [
                    'location_id'  => $locationId,
                    'service_key'  => 'downloads',
                    'service_type' => 'builtin',
                    'label'        => 'Downloads',
                    'description'  => 'Formulare und Dokumente zum Herunterladen',
                    'icon'         => '📥',
                    'is_active'    => 0,
                    'sort_order'   => 80,
                ]);
            }
        }
    }

    /**
     * v3.3.2: Documents-Tabelle sicherstellen
     */
    private function migrateEnsureDocumentsTable(): void
    {
        $this->log('Migration → v3.3.2: Documents-Tabelle');
        Schema::install(); // Schema erstellt fehlende Tabellen
    }

    /**
     * v3.9.40: Terminabsage-Service hinzufügen
     */
    private function migrateAddTerminabsageService(): void
    {
        $this->log('Migration → v3.9.40: Terminabsage-Service');
        $table = $this->prefix . 'pp_services';

        $locations = $this->wpdb->get_col(
            "SELECT id FROM {$this->prefix}pp_locations"
        );

        foreach ($locations as $locationId) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE location_id = %d AND service_key = 'terminabsage'",
                $locationId
            ));

            if (!$exists) {
                $this->wpdb->insert($table, [
                    'location_id'        => $locationId,
                    'service_key'        => 'terminabsage',
                    'service_type'       => 'builtin',
                    'label'              => 'Termin absagen',
                    'description'        => 'Sagen Sie einen bestehenden Termin ab',
                    'icon'               => '❌',
                    'is_active'          => 1,
                    'patient_restriction' => 'patients_only',
                    'sort_order'         => 60,
                ]);
            }
        }
    }

    /**
     * v3.9.60: Verschlüsselte Spalten erweitern
     */
    private function migrateExpandEncryptedColumns(): void
    {
        $this->log('Migration → v3.9.60: Spalten erweitern');

        // audit: portal_username → TEXT
        $this->alterColumnType(
            $this->prefix . 'pp_audit_log',
            'portal_username',
            'TEXT DEFAULT NULL'
        );

        // submissions: response_text → LONGTEXT
        $this->alterColumnType(
            $this->prefix . 'pp_submissions',
            'response_text',
            'LONGTEXT DEFAULT NULL'
        );
    }

    /* =========================================================================
     * v4 MIGRATION
     * ====================================================================== */

    /**
     * v4.0.0: Haupt-Migration v3 → v4
     */
    private function migrateToV4(): void
    {
        $this->log('Migration → v4.0.0');

        // 1. Spalten umbenennen: doc_key → license_key, place_id → location_uuid
        $this->renameColumn(
            $this->prefix . 'pp_locations',
            'doc_key',
            'license_key',
            'VARCHAR(25)'
        );

        $this->renameColumn(
            $this->prefix . 'pp_locations',
            'place_id',
            'location_uuid',
            'VARCHAR(50) UNIQUE'
        );

        // 2. license_cache: place_id → location_uuid
        $this->renameColumn(
            $this->prefix . 'pp_license_cache',
            'place_id',
            'location_uuid',
            'VARCHAR(50) NOT NULL'
        );

        // 3. Tabellen erstellen falls fehlend
        Schema::install();

        // 4. Index für location_uuid sicherstellen
        $this->ensureIndex(
            $this->prefix . 'pp_locations',
            'idx_location_uuid',
            'location_uuid'
        );

        $this->log('v4.0.0 abgeschlossen');
    }

    /**
     * Migration v4.1.0: Medikamenten-Tabelle + Standard-Standort
     */
    private function migrateToV41(): void
    {
        $this->log('Migration → v4.1.0');

        // 1. Spalte umbenennen: location_uuid → uuid (VOR Schema::install!)
        $locTable = $this->prefix . 'pp_locations';
        $columns  = $this->wpdb->get_col("SHOW COLUMNS FROM {$locTable}");
        if (in_array('location_uuid', $columns, true) && !in_array('uuid', $columns, true)) {
            $this->wpdb->query(
                "ALTER TABLE {$locTable} CHANGE COLUMN `location_uuid` `uuid` VARCHAR(36) DEFAULT NULL"
            );
            // Index sicher entfernen (MySQL 5.7 kompatibel)
            $indexExists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_location_uuid'",
                DB_NAME,
                $locTable
            ));
            if ($indexExists) {
                $this->wpdb->query("ALTER TABLE {$locTable} DROP INDEX idx_location_uuid");
            }
            $this->ensureIndex($locTable, 'idx_uuid', 'uuid');
            $this->log('Spalte location_uuid → uuid umbenannt');
        }

        // 2. Neue Tabellen (medications) erstellen + Schema-Updates
        Schema::install();

        // 3. Standard-Standort sicherstellen
        $this->ensureDefaultLocation();

        // 4. CSV-Import wenn Tabelle leer
        $medTable = Schema::medications();
        $count    = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$medTable}");
        if ($count === 0) {
            $csvPath = defined('PP_PLUGIN_DIR')
                ? PP_PLUGIN_DIR . 'data/medicationspraxis.csv'
                : dirname(__DIR__, 2) . '/data/medicationspraxis.csv';
            if (file_exists($csvPath)) {
                $this->importMedicationsCsv($csvPath, $medTable);
            }
        }

        $this->log('v4.1.0 abgeschlossen');
    }

    /**
     * v4.2.9: Medikamenten-Schema erweitern (v3-Parität)
     *
     * Fügt klinische Felder hinzu: wirkstoff, staerke, einheit,
     * standard_dosierung, einnahme_hinweis, kategorie, hinweise,
     * ist_aktiv, verwendung_count, updated_at.
     * Migriert bestehende Daten aus dosage → wirkstoff+staerke und form → kategorie.
     */
    private function migrateToV429(): void
    {
        $this->log('v4.2.9 Migration: Medikamenten-Schema erweitern');

        $table = Schema::medications();

        // Spalten nur hinzufügen wenn sie noch nicht existieren
        $existingColumns = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        );

        $columnsToAdd = [
            'wirkstoff'          => 'VARCHAR(255) DEFAULT NULL AFTER name',
            'staerke'            => 'VARCHAR(100) DEFAULT NULL AFTER wirkstoff',
            'einheit'            => 'VARCHAR(50) DEFAULT NULL AFTER staerke',
            'standard_dosierung' => 'VARCHAR(100) DEFAULT NULL AFTER pzn',
            'einnahme_hinweis'   => 'VARCHAR(255) DEFAULT NULL AFTER standard_dosierung',
            'kategorie'          => 'VARCHAR(100) DEFAULT NULL AFTER einnahme_hinweis',
            'hinweise'           => 'TEXT DEFAULT NULL AFTER kategorie',
            'ist_aktiv'          => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER hinweise',
            'verwendung_count'   => 'INT NOT NULL DEFAULT 0 AFTER ist_aktiv',
            'updated_at'         => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ];

        $added = 0;
        foreach ($columnsToAdd as $col => $definition) {
            if (!in_array($col, $existingColumns, true)) {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
                $added++;
            }
        }

        // Indizes hinzufügen (falls fehlend)
        $existingKeys = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        );

        $keysToAdd = [
            'idx_wirkstoff'  => 'wirkstoff(100)',
            'idx_kategorie'  => 'kategorie(50)',
            'idx_ist_aktiv'  => 'ist_aktiv',
            'idx_verwendung' => 'verwendung_count',
        ];

        foreach ($keysToAdd as $keyName => $keyDef) {
            if (!in_array($keyName, $existingKeys, true)) {
                $this->wpdb->query("ALTER TABLE {$table} ADD KEY {$keyName} ({$keyDef})");
            }
        }

        // name-Spalte erweitern (200 → 255) für Konsistenz mit v3
        $nameCol = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'name'",
                $table
            )
        );
        if ($nameCol && (int) $nameCol->CHARACTER_MAXIMUM_LENGTH < 255) {
            $this->wpdb->query("ALTER TABLE {$table} MODIFY COLUMN name VARCHAR(255) NOT NULL");
        }

        // ── Bestehende Daten migrieren: dosage → wirkstoff+staerke, form → kategorie ──
        if ($added > 0) {
            // form → kategorie (einfaches 1:1-Mapping)
            $this->wpdb->query(
                "UPDATE {$table} SET kategorie = form 
                 WHERE kategorie IS NULL AND form IS NOT NULL AND form != ''"
            );

            // dosage → wirkstoff (komplettes dosage als wirkstoff, da Trennung unsicher)
            $this->wpdb->query(
                "UPDATE {$table} SET wirkstoff = dosage 
                 WHERE wirkstoff IS NULL AND dosage IS NOT NULL AND dosage != ''"
            );

            $this->log("Bestehende Daten migriert (dosage→wirkstoff, form→kategorie)");
        }

        // ── CSV-Daten nochmal komplett importieren (mit allen Feldern) ──
        $csvPath = defined('PP_PLUGIN_DIR')
            ? PP_PLUGIN_DIR . 'data/medicationspraxis.csv'
            : dirname(__DIR__, 2) . '/data/medicationspraxis.csv';

        if (file_exists($csvPath)) {
            $this->reimportMedicationsWithFullSchema($csvPath, $table);
        }

        $this->log("v4.2.9 abgeschlossen – {$added} Spalten hinzugefügt");
    }

    /**
     * v4.2.10: Widget-Einstellungen pro Standort
     *
     * Fügt widget_status, widget_pages, widget_disabled_message zur
     * Locations-Tabelle hinzu und übernimmt globale Optionen als Startwert.
     */
    private function migrateToV4210(): void
    {
        $this->log('v4.2.10 Migration: Widget-Spalten pro Standort');

        $table = $this->prefix . 'pp_locations';

        $existingColumns = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        );

        $added = 0;

        if (!in_array('widget_status', $existingColumns, true)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN widget_status VARCHAR(20) DEFAULT 'active'");
            $added++;
        }

        if (!in_array('widget_pages', $existingColumns, true)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN widget_pages TEXT DEFAULT NULL");
            $added++;
        }

        if (!in_array('widget_disabled_message', $existingColumns, true)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN widget_disabled_message TEXT DEFAULT NULL");
            $added++;
        }

        // Globale Optionen in alle bestehenden Standorte übernehmen
        if ($added > 0) {
            $globalStatus  = get_option('pp_widget_status', 'active');
            $globalPages   = get_option('pp_widget_pages', 'all');
            $globalDisabledMsg = get_option('pp_widget_disabled_message', '');

            if ($globalStatus !== 'active') {
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$table} SET widget_status = %s WHERE widget_status = 'active' OR widget_status IS NULL",
                    $globalStatus
                ));
            }

            if (!empty($globalPages) && $globalPages !== 'all') {
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$table} SET widget_pages = %s WHERE widget_pages IS NULL",
                    $globalPages
                ));
            }

            if (!empty($globalDisabledMsg)) {
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$table} SET widget_disabled_message = %s WHERE widget_disabled_message IS NULL",
                    $globalDisabledMsg
                ));
            }
        }

        $this->log("v4.2.10 abgeschlossen – {$added} Spalten hinzugefügt");
    }

    /**
     * Reimport: aktualisiert bestehende Einträge mit vollen v3-Feldern
     * Matched über name+pzn, überschreibt nur NULL-Felder.
     */
    private function reimportMedicationsWithFullSchema(string $csvPath, string $table): void
    {
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return;
        }

        $header = fgetcsv($handle, 0, ',');
        $updated = 0;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $name = trim($row[0] ?? '');
            if ($name === '' || str_starts_with($name, '#')) {
                continue;
            }

            $wirkstoff         = trim($row[1] ?? '');
            $staerke           = trim($row[2] ?? '');
            $pzn               = trim($row[3] ?? '');
            $kategorie         = trim($row[4] ?? '');
            $standardDosierung = trim($row[5] ?? '');
            $einnahmeHinweis   = trim($row[6] ?? '');

            // Match über Name (exakt)
            $existingId = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE name = %s LIMIT 1",
                $name
            ));

            if ($existingId) {
                // Update nur fehlende Felder
                $updateParts = [];
                $updateVals  = [];

                if ($wirkstoff !== '') {
                    $updateParts[] = 'wirkstoff = COALESCE(NULLIF(wirkstoff, \'\'), %s)';
                    $updateVals[]  = $wirkstoff;
                }
                if ($staerke !== '') {
                    $updateParts[] = 'staerke = COALESCE(NULLIF(staerke, \'\'), %s)';
                    $updateVals[]  = $staerke;
                }
                if ($kategorie !== '') {
                    $updateParts[] = 'kategorie = COALESCE(NULLIF(kategorie, \'\'), %s)';
                    $updateVals[]  = $kategorie;
                }
                if ($standardDosierung !== '') {
                    $updateParts[] = 'standard_dosierung = COALESCE(NULLIF(standard_dosierung, \'\'), %s)';
                    $updateVals[]  = $standardDosierung;
                }
                if ($einnahmeHinweis !== '') {
                    $updateParts[] = 'einnahme_hinweis = COALESCE(NULLIF(einnahme_hinweis, \'\'), %s)';
                    $updateVals[]  = $einnahmeHinweis;
                }
                if ($pzn !== '') {
                    $updateParts[] = 'pzn = COALESCE(NULLIF(pzn, \'\'), %s)';
                    $updateVals[]  = $pzn;
                }

                if (!empty($updateParts)) {
                    $updateVals[] = (int) $existingId;
                    $this->wpdb->query($this->wpdb->prepare(
                        "UPDATE {$table} SET " . implode(', ', $updateParts) . " WHERE id = %d",
                        $updateVals
                    ));
                    $updated++;
                }
            }
        }

        fclose($handle);
        if ($updated > 0) {
            $this->log("Medikamente: {$updated} Einträge mit erweiterten Feldern aktualisiert");
        }
    }

    /**
     * CSV-Import für Medikamenten-Datenbank
     */
    private function importMedicationsCsv(string $csvPath, string $table): void
    {
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $this->log('CSV nicht lesbar: ' . $csvPath);
            return;
        }

        // Header lesen
        $header = fgetcsv($handle, 0, ',');
        if (!$header) {
            fclose($handle);
            return;
        }

        $imported = 0;
        $batch    = [];

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            // Kommentarzeilen überspringen
            $first = trim($row[0] ?? '');
            if ($first === '' || str_starts_with($first, '#')) {
                continue;
            }

            // CSV: name(0), wirkstoff(1), staerke(2), pzn(3), kategorie(4),
            //      standard_dosierung(5), einnahme_hinweis(6)
            $wirkstoff         = trim($row[1] ?? '');
            $staerke           = trim($row[2] ?? '');
            $pzn               = trim($row[3] ?? '');
            $kategorie         = trim($row[4] ?? '');
            $standardDosierung = trim($row[5] ?? '');
            $einnahmeHinweis   = trim($row[6] ?? '');

            // Legacy-Felder weiterhin befüllen (Abwärtskompatibilität)
            $dosage = trim($wirkstoff . ($staerke ? ' ' . $staerke : ''));

            $batch[] = $this->wpdb->prepare(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $first,             // name
                $wirkstoff ?: null, // wirkstoff
                $staerke ?: null,   // staerke
                $dosage,            // dosage (Legacy)
                $kategorie,         // form (Legacy) + kategorie
                $pzn ?: null,       // pzn
                $standardDosierung ?: null,  // standard_dosierung
                $einnahmeHinweis ?: null,    // einnahme_hinweis
                $kategorie ?: null  // kategorie
            );

            if (count($batch) >= 500) {
                $this->wpdb->query(
                    "INSERT INTO {$table} (name, wirkstoff, staerke, dosage, form, pzn, standard_dosierung, einnahme_hinweis, kategorie) VALUES "
                    . implode(',', $batch)
                );
                $imported += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->wpdb->query(
                "INSERT INTO {$table} (name, wirkstoff, staerke, dosage, form, pzn, standard_dosierung, einnahme_hinweis, kategorie) VALUES "
                . implode(',', $batch)
            );
            $imported += count($batch);
        }

        fclose($handle);
        $this->log("Medikamenten-Import: {$imported} Einträge importiert");
    }

    /* =========================================================================
     * HELPER: DATA MIGRATIONS
     * ====================================================================== */

    /**
     * Standard-Standort sicherstellen
     */
    private function ensureDefaultLocation(): void
    {
        $table = $this->prefix . 'pp_locations';

        $count = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        if ($count === 0) {
            // Kein Standort → Default erstellen
            $siteName = get_bloginfo('name') ?: 'Praxis';

            $this->wpdb->insert($table, [
                'uuid'       => wp_generate_uuid4(),
                'name'       => $siteName,
                'slug'       => sanitize_title($siteName),
                'is_active'  => 1,
                'is_default' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);

            $this->log('Default-Standort erstellt: ' . $siteName);
            return;
        }

        // Standorte vorhanden → prüfen ob einer default ist
        $hasDefault = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE is_default = 1"
        );

        if ($hasDefault === 0) {
            // Kein Default markiert → ersten aktiven Standort zum Default machen
            $firstId = $this->wpdb->get_var(
                "SELECT id FROM {$table} WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
            );

            if ($firstId) {
                $this->wpdb->update($table, ['is_default' => 1], ['id' => $firstId]);
                $this->log('Bestehender Standort als Default markiert: ID ' . $firstId);
            } else {
                // Kein aktiver Standort → ersten inaktiven aktivieren + default setzen
                $firstId = $this->wpdb->get_var(
                    "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1"
                );
                if ($firstId) {
                    $this->wpdb->update($table, ['is_default' => 1, 'is_active' => 1], ['id' => $firstId]);
                    $this->log('Standort aktiviert und als Default gesetzt: ID ' . $firstId);
                }
            }
        }

        // UUID-Lücken füllen (leere uuid-Felder)
        $emptyUuids = $this->wpdb->get_col(
            "SELECT id FROM {$table} WHERE uuid IS NULL OR uuid = ''"
        );
        foreach ($emptyUuids as $id) {
            $this->wpdb->update($table, ['uuid' => wp_generate_uuid4()], ['id' => $id]);
        }
        if (count($emptyUuids) > 0) {
            $this->log('UUID für ' . count($emptyUuids) . ' Standort(e) generiert');
        }
    }

    /**
     * Medikamente importieren wenn Tabelle leer (idempotent).
     */
    private function ensureMedicationsImported(): void
    {
        $medTable = Schema::medications();

        // Tabelle existiert? (Schema könnte noch nicht gelaufen sein)
        $tableExists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $medTable
            )
        );

        if (!$tableExists) {
            return;
        }

        $count = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$medTable}");
        if ($count > 0) {
            return; // Bereits Daten vorhanden
        }

        $csvPath = defined('PP_PLUGIN_DIR')
            ? PP_PLUGIN_DIR . 'data/medicationspraxis.csv'
            : dirname(__DIR__, 2) . '/data/medicationspraxis.csv';

        if (file_exists($csvPath)) {
            $this->importMedicationsCsv($csvPath, $medTable);
        } else {
            $this->log('Medikamenten-CSV nicht gefunden: ' . $csvPath);
        }
    }

    /**
     * Submissions ohne location_id dem Default zuweisen
     */
    private function assignSubmissionsToDefault(): void
    {
        $locTable = $this->prefix . 'pp_locations';
        $subTable = $this->prefix . 'pp_submissions';

        $defaultId = $this->wpdb->get_var(
            "SELECT id FROM {$locTable} WHERE is_default = 1 LIMIT 1"
        );

        if ($defaultId) {
            $affected = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$subTable} SET location_id = %d WHERE location_id = 0 OR location_id IS NULL",
                $defaultId
            ));

            if ($affected) {
                $this->log("Submissions zugewiesen: {$affected} → location_id={$defaultId}");
            }
        }
    }

    /**
     * Portal-Credentials aus wp_options migrieren
     */
    private function migratePortalCredentials(): void
    {
        $table = $this->prefix . 'pp_portal_users';

        $existingUsers = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($existingUsers > 0) {
            return;
        }

        $username = get_option('pp_portal_username');
        $password = get_option('pp_portal_password');

        if (!empty($username) && !empty($password)) {
            $defaultLocationId = $this->wpdb->get_var(
                "SELECT id FROM {$this->prefix}pp_locations WHERE is_default = 1 LIMIT 1"
            ) ?: 1;

            $this->wpdb->insert($table, [
                'username'      => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name'  => 'Praxis',
                'location_id'   => $defaultLocationId,
                'can_view'      => 1,
                'can_edit'      => 1,
                'can_delete'    => 1,
                'can_export'    => 1,
                'is_active'     => 1,
                'created_at'    => current_time('mysql'),
            ]);

            $this->log('Portal-Credentials migriert');
        }
    }

    /**
     * API-Key aus wp_options migrieren
     */
    private function migrateApiKey(): void
    {
        $table = $this->prefix . 'pp_api_keys';

        $existingKeys = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($existingKeys > 0) {
            return;
        }

        $apiKey = get_option('pp_pvs_api_key');

        if (!empty($apiKey)) {
            $defaultLocationId = $this->wpdb->get_var(
                "SELECT id FROM {$this->prefix}pp_locations WHERE is_default = 1 LIMIT 1"
            ) ?: 1;

            $this->wpdb->insert($table, [
                'location_id'  => $defaultLocationId,
                'api_key_hash' => hash('sha256', $apiKey),
                'name'         => 'Migrierter API-Key',
                'is_active'    => 1,
                'created_at'   => current_time('mysql'),
                'created_by'   => get_current_user_id(),
            ]);

            $this->log('API-Key migriert');
        }
    }

    /* =========================================================================
     * HELPER: SCHEMA CHANGES
     * ====================================================================== */

    /**
     * Spalte umbenennen (falls alte existiert und neue noch nicht)
     */
    private function renameColumn(string $table, string $oldName, string $newName, string $definition): void
    {
        if (!$this->tableExists($table)) {
            return;
        }

        $columns = $this->getColumns($table);

        if (in_array($newName, $columns, true)) {
            return; // Bereits umbenannt
        }

        if (!in_array($oldName, $columns, true)) {
            // Alte Spalte existiert nicht → neue anlegen
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$newName} {$definition}");
            $this->log("Spalte angelegt: {$table}.{$newName}");
            return;
        }

        // Umbenennen
        $this->wpdb->query(
            "ALTER TABLE {$table} CHANGE COLUMN {$oldName} {$newName} {$definition}"
        );
        $this->log("Spalte umbenannt: {$table}.{$oldName} → {$newName}");
    }

    /**
     * Spaltentyp ändern (falls Spalte existiert)
     */
    private function alterColumnType(string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($table)) {
            return;
        }

        $columns = $this->getColumns($table);
        if (!in_array($column, $columns, true)) {
            return;
        }

        $this->wpdb->query("ALTER TABLE {$table} MODIFY COLUMN {$column} {$definition}");
        $this->log("Spaltentyp geändert: {$table}.{$column}");
    }

    /**
     * UNIQUE-Constraint von doc_key/license_key entfernen
     */
    private function ensureNoDocKeyUnique(): void
    {
        $table = $this->prefix . 'pp_locations';

        if (!$this->tableExists($table)) {
            return;
        }

        // Beide möglichen Spaltennamen prüfen
        foreach (['doc_key', 'license_key'] as $col) {
            $indexes = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SHOW INDEX FROM {$table} WHERE Column_name = %s",
                    $col
                )
            );

            foreach ($indexes as $index) {
                if (isset($index->Non_unique) && $index->Non_unique == 0) {
                    $this->wpdb->query("ALTER TABLE {$table} DROP INDEX `{$index->Key_name}`");
                    $this->log("UNIQUE-Constraint entfernt: {$table}.{$col}");
                }
            }
        }
    }

    /**
     * Index sicherstellen (idempotent)
     */
    private function ensureIndex(string $table, string $indexName, string $column): void
    {
        if (!$this->tableExists($table)) {
            return;
        }

        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $table,
            $indexName
        ));

        if (!$exists) {
            $this->wpdb->query("ALTER TABLE {$table} ADD KEY {$indexName} ({$column})");
            $this->log("Index erstellt: {$table}.{$indexName}");
        }
    }

    /* =========================================================================
     * HELPER: INTROSPECTION
     * ====================================================================== */

    private function tableExists(string $table): bool
    {
        return $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    private function getColumns(string $table): array
    {
        // $table kommt aus Schema::table() (prefix + hardcoded Name), nicht aus User-Input
        return $this->wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
    }

    private function log(string $message): void
    {
        $entry = '[PP Migration] ' . $message;
        $this->log[] = $entry;
        error_log($entry);
    }
}
