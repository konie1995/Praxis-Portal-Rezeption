<?php
/**
 * IcdRepository – Fragen-ICD-Code-Zuordnung
 *
 * Verwaltet die Zuordnung von Fragebogen-Feldern (frage_key) zu ICD-10-GM Codes.
 * Unterstützt Multistandort: location_id NULL = globaler Default, sonst standortspezifisch.
 *
 * Erwartetes Schema (pp_icd_zuordnungen):
 *   form_id, frage_key, icd_code, bezeichnung, sicherheit, seite_field, location_id, is_active
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.2.901
 */

declare(strict_types=1);

namespace PraxisPortal\Database\Repository;

use PraxisPortal\Database\Schema;

if (!defined('ABSPATH')) {
    exit;
}

class IcdRepository
{
    /**
     * Standard-ICD-Zuordnungen für Augenarzt-Fragebogen (Seed-Daten).
     * Werden bei createDefaults() eingefügt wenn Tabelle leer ist.
     *
     * v4.2.902: Bereinigt – nur Felder die als Checkbox im Formular existieren.
     * Dropdown-Werte (z.B. leber_art, schilddruese_art) werden nicht als separate
     * ICD-Zuordnungen gespeichert, sondern über Export-Logik aufgelöst.
     */
    private const DEFAULT_MAPPINGS = [
        // Augen-Vorerkrankungen
        ['augenarzt', 'glaukom',           'H40.9',  'Glaukom (Grüner Star)',           'G', 'glaukom_seite'],
        ['augenarzt', 'katarakt',          'H25.9',  'Katarakt (Grauer Star)',          'G', 'katarakt_seite'],
        ['augenarzt', 'keratokonus',       'H18.6',  'Keratokonus',                     'G', 'keratokonus_seite'],
        ['augenarzt', 'netzhautabloesung', 'H33.0',  'Netzhautablösung',                'G', 'netzhaut_seite'],
        ['augenarzt', 'sicca',             'H04.1',  'Sicca-Syndrom (Trockene Augen)',  'G', null],
        ['augenarzt', 'schielen',          'H50.9',  'Strabismus',                      'G', null],
        // AMD – Spezialfall: trocken/feucht wird im Export differenziert
        ['augenarzt', 'amd',               'H35.30', 'Makuladegeneration (AMD)',        'G', 'amd_seite'],

        // Herz-Kreislauf
        ['augenarzt', 'bluthochdruck',     'I10',    'Hypertonie',                      'G', null],
        ['augenarzt', 'herzinsuffizienz',  'I50.9',  'Herzinsuffizienz',                'G', null],
        ['augenarzt', 'herzinfarkt',       'I21.9',  'Akuter Myokardinfarkt',           'G', null],
        ['augenarzt', 'herzrhythmus',      'I49.9',  'Herzrhythmusstörung',             'G', null],
        ['augenarzt', 'khk',               'I25.9',  'Koronare Herzkrankheit',          'G', null],
        ['augenarzt', 'schlaganfall',      'I64',    'Schlaganfall',                    'G', null],
        ['augenarzt', 'thrombose',         'I82.9',  'Thrombose',                       'G', null],
        ['augenarzt', 'lungenembolie',     'I26.9',  'Lungenembolie',                   'G', null],

        // Stoffwechsel
        ['augenarzt', 'diabetes',          'E11.3-', 'Diabetes mit Augenkomplikation',  'G', null],
        ['augenarzt', 'schilddruese',      'E07.9',  'Schilddrüsenerkrankung',          'G', null],

        // Leber & Nieren
        ['augenarzt', 'leber',             'K76.9',  'Leberkrankheit',                  'G', null],
        ['augenarzt', 'nieren',            'N28.9',  'Nierenerkrankung',                'G', null],

        // Atemwege
        ['augenarzt', 'copd',              'J44.9',  'COPD',                            'G', null],
        ['augenarzt', 'asthma',            'J45.9',  'Asthma bronchiale',               'G', null],
        ['augenarzt', 'rauchen',           'Z72.0',  'Tabakkonsum',                     'G', null],
        ['augenarzt', 'bronchitis',        'J42',    'Chronische Bronchitis',           'G', null],

        // Neurologisch
        ['augenarzt', 'ms',                'G35',    'Multiple Sklerose',               'G', null],
        ['augenarzt', 'migraene',          'G43.9',  'Migräne',                         'G', null],
        ['augenarzt', 'myasthenia',        'G70.0',  'Myasthenia gravis',               'G', null],
        ['augenarzt', 'epilepsie',         'G40.9',  'Epilepsie',                       'G', null],

        // Autoimmunerkrankungen
        ['augenarzt', 'autoimmun_rheuma',        'M06.9',  'Rheumatoide Arthritis',            'G', null],
        ['augenarzt', 'autoimmun_crohn',         'K50.9',  'Morbus Crohn',                     'G', null],
        ['augenarzt', 'autoimmun_colitis',       'K51.9',  'Colitis ulcerosa',                 'G', null],
        ['augenarzt', 'autoimmun_sjogren',       'M35.0',  'Sjögren-Syndrom',                  'G', null],
        ['augenarzt', 'autoimmun_lupus',         'M32.9',  'Systemischer Lupus erythematodes', 'G', null],
        ['augenarzt', 'autoimmun_psoriasis',     'L40.9',  'Psoriasis',                        'G', null],
        ['augenarzt', 'autoimmun_rosacea',       'L71.9',  'Rosazea',                          'G', null],
        ['augenarzt', 'autoimmun_neurodermitis', 'L20.9',  'Atopisches Ekzem',                 'G', null],

        // Infektionen
        ['augenarzt', 'hepatitis_b',       'B18.1',  'Chronische Hepatitis B',          'G', null],
        ['augenarzt', 'hepatitis_c',       'B18.2',  'Chronische Hepatitis C',          'G', null],
        ['augenarzt', 'hiv',               'B24',    'HIV-Erkrankung',                  'G', null],
        ['augenarzt', 'tuberkulose',       'A16.9',  'Tuberkulose',                     'G', null],

        // ── ALLGEMEINARZT ──────────────────────────────────────────────────
        // Herz-Kreislauf (repliziert von allgemein Section)
        ['allgemeinarzt', 'bluthochdruck',     'I10',    'Hypertonie',                      'G', null],
        ['allgemeinarzt', 'herzinsuffizienz',  'I50.9',  'Herzinsuffizienz',                'G', null],
        ['allgemeinarzt', 'herzinfarkt',       'I21.9',  'Akuter Myokardinfarkt',           'G', null],
        ['allgemeinarzt', 'herzrhythmus',      'I49.9',  'Herzrhythmusstörung',             'G', null],
        ['allgemeinarzt', 'khk',               'I25.9',  'Koronare Herzkrankheit',          'G', null],
        ['allgemeinarzt', 'schlaganfall',      'I64',    'Schlaganfall',                    'G', null],
        ['allgemeinarzt', 'thrombose',         'I82.9',  'Thrombose',                       'G', null],
        ['allgemeinarzt', 'lungenembolie',     'I26.9',  'Lungenembolie',                   'G', null],

        // Stoffwechsel
        ['allgemeinarzt', 'diabetes',          'E14.9',  'Diabetes mellitus',               'G', null],
        ['allgemeinarzt', 'schilddruese',      'E07.9',  'Schilddrüsenerkrankung',          'G', null],

        // Leber & Nieren
        ['allgemeinarzt', 'leber',             'K76.9',  'Leberkrankheit',                  'G', null],
        ['allgemeinarzt', 'nieren',            'N28.9',  'Nierenerkrankung',                'G', null],

        // Atemwege
        ['allgemeinarzt', 'copd',              'J44.9',  'COPD',                            'G', null],
        ['allgemeinarzt', 'asthma',            'J45.9',  'Asthma bronchiale',               'G', null],
        ['allgemeinarzt', 'rauchen',           'Z72.0',  'Tabakkonsum',                     'G', null],
        ['allgemeinarzt', 'bronchitis',        'J42',    'Chronische Bronchitis',           'G', null],

        // Neurologisch
        ['allgemeinarzt', 'ms',                'G35',    'Multiple Sklerose',               'G', null],
        ['allgemeinarzt', 'migraene',          'G43.9',  'Migräne',                         'G', null],
        ['allgemeinarzt', 'myasthenia',        'G70.0',  'Myasthenia gravis',               'G', null],

        // Autoimmunerkrankungen
        ['allgemeinarzt', 'autoimmun_rheuma',        'M06.9',  'Rheumatoide Arthritis',            'G', null],
        ['allgemeinarzt', 'autoimmun_crohn',         'K50.9',  'Morbus Crohn',                     'G', null],
        ['allgemeinarzt', 'autoimmun_colitis',       'K51.9',  'Colitis ulcerosa',                 'G', null],
        ['allgemeinarzt', 'autoimmun_sjogren',       'M35.0',  'Sjögren-Syndrom',                  'G', null],
        ['allgemeinarzt', 'autoimmun_lupus',         'M32.9',  'Systemischer Lupus erythematodes', 'G', null],
        ['allgemeinarzt', 'autoimmun_psoriasis',     'L40.9',  'Psoriasis',                        'G', null],
        ['allgemeinarzt', 'autoimmun_rosacea',       'L71.9',  'Rosazea',                          'G', null],
        ['allgemeinarzt', 'autoimmun_neurodermitis', 'L20.9',  'Atopisches Ekzem',                 'G', null],

        // Infektionen
        ['allgemeinarzt', 'hepatitis_b',       'B18.1',  'Chronische Hepatitis B',          'G', null],
        ['allgemeinarzt', 'hepatitis_c',       'B18.2',  'Chronische Hepatitis C',          'G', null],
        ['allgemeinarzt', 'hiv',               'B24',    'HIV-Erkrankung',                  'G', null],
        ['allgemeinarzt', 'tuberkulose',       'A16.9',  'Tuberkulose',                     'G', null],

        // Allgemeinarzt-spezifisch
        ['allgemeinarzt', 'schlafapnoe',       'G47.3',  'Schlafapnoe',                     'G', null],
        ['allgemeinarzt', 'adipositas',        'E66.9',  'Adipositas',                      'G', null],
        ['allgemeinarzt', 'depression',        'F32.9',  'Depression',                      'G', null],
        ['allgemeinarzt', 'angststoerung',     'F41.9',  'Angststörung',                    'G', null],

        // ── DERMATOLOGE ────────────────────────────────────────────────────
        // Hauterkrankungen
        ['dermatologe', 'neurodermitis',       'L20.9',  'Atopisches Ekzem',                'G', null],
        ['dermatologe', 'psoriasis',           'L40.9',  'Psoriasis',                       'G', null],
        ['dermatologe', 'akne',                'L70.0',  'Akne vulgaris',                   'G', null],
        ['dermatologe', 'rosacea',             'L71.9',  'Rosazea',                         'G', null],

        // Hautkrebs
        ['dermatologe', 'melanom',             'C43.9',  'Malignes Melanom',                'G', null],
        ['dermatologe', 'basaliom',            'C44.9',  'Basalzellkarzinom',               'G', null],

        // Allgemeine Erkrankungen (repliziert)
        ['dermatologe', 'diabetes',            'E14.9',  'Diabetes mellitus',               'G', null],
        ['dermatologe', 'bluthochdruck',       'I10',    'Hypertonie',                      'G', null],
        ['dermatologe', 'autoimmun_rheuma',    'M06.9',  'Rheumatoide Arthritis',           'G', null],
        ['dermatologe', 'autoimmun_lupus',     'M32.9',  'Systemischer Lupus erythematodes','G', null],

        // ── HNO ────────────────────────────────────────────────────────────
        // HNO-spezifisch
        ['hno', 'hoerverlust',                 'H91.9',  'Hörverlust',                      'G', null],
        ['hno', 'tinnitus',                    'H93.1',  'Tinnitus aurium',                 'G', null],
        ['hno', 'schwindel',                   'R42',    'Schwindel und Taumel',            'G', null],
        ['hno', 'sinusitis',                   'J32.9',  'Chronische Sinusitis',            'G', null],
        ['hno', 'schlafapnoe',                 'G47.3',  'Schlafapnoe',                     'G', null],

        // Allgemeine Erkrankungen (repliziert)
        ['hno', 'diabetes',                    'E14.9',  'Diabetes mellitus',               'G', null],
        ['hno', 'bluthochdruck',               'I10',    'Hypertonie',                      'G', null],
        ['hno', 'asthma',                      'J45.9',  'Asthma bronchiale',               'G', null],
        ['hno', 'copd',                        'J44.9',  'COPD',                            'G', null],

        // ── ORTHOPÄDE ──────────────────────────────────────────────────────
        // Orthopädie-spezifisch
        ['orthopaede', 'rueckenschmerzen',     'M54.5',  'Rückenschmerzen',                 'G', null],
        ['orthopaede', 'bandscheibenvorfall',  'M51.1',  'Bandscheibenvorfall mit Radikulopathie', 'G', null],
        ['orthopaede', 'skoliose',             'M41.9',  'Skoliose',                        'G', null],
        ['orthopaede', 'osteoporose',          'M81.9',  'Osteoporose',                     'G', null],
        ['orthopaede', 'gicht',                'M10.9',  'Gicht',                           'G', null],
        ['orthopaede', 'karpaltunnel',         'G56.0',  'Karpaltunnelsyndrom',             'G', null],

        // Allgemeine Erkrankungen (repliziert)
        ['orthopaede', 'diabetes',             'E14.9',  'Diabetes mellitus',               'G', null],
        ['orthopaede', 'bluthochdruck',        'I10',    'Hypertonie',                      'G', null],
        ['orthopaede', 'autoimmun_rheuma',     'M06.9',  'Rheumatoide Arthritis',           'G', null],

        // ── ZAHNARZT ───────────────────────────────────────────────────────
        // Zahnmedizin-spezifisch
        ['zahnarzt', 'karies',                 'K02.9',  'Zahnkaries',                      'G', null],
        ['zahnarzt', 'parodontitis',           'K05.3',  'Chronische Parodontitis',         'G', null],
        ['zahnarzt', 'cmd',                    'K07.6',  'Kiefergelenkerkrankung (CMD)',    'G', null],
        ['zahnarzt', 'bruxismus',              'F45.8',  'Bruxismus',                       'G', null],

        // Allgemeine Erkrankungen (repliziert)
        ['zahnarzt', 'diabetes',               'E14.9',  'Diabetes mellitus',               'G', null],
        ['zahnarzt', 'bluthochdruck',          'I10',    'Hypertonie',                      'G', null],
        ['zahnarzt', 'hepatitis_b',            'B18.1',  'Chronische Hepatitis B',          'G', null],
        ['zahnarzt', 'hepatitis_c',            'B18.2',  'Chronische Hepatitis C',          'G', null],
        ['zahnarzt', 'hiv',                    'B24',    'HIV-Erkrankung',                  'G', null],
    ];

    /**
     * Alle aktiven Zuordnungen für ein Formular abrufen.
     *
     * Multistandort-Logik:
     *   1. Standortspezifische Zuordnungen (location_id = $locationId)
     *   2. Globale Zuordnungen (location_id IS NULL) als Fallback
     *   Wenn ein frage_key standortspezifisch existiert, überschreibt er den globalen.
     *
     * @param bool        $activeOnly   Nur aktive Zuordnungen?
     * @param string      $formId       Fragebogen-ID (z.B. 'augenarzt')
     * @param string|null $locationUuid Standort-UUID (optional)
     * @return array<array{frage_key:string, icd_code:string, bezeichnung:string, sicherheit:string, seite_field:?string}>
     */
    public function getAll(bool $activeOnly = true, string $formId = 'augenarzt', ?string $locationUuid = null): array
    {
        global $wpdb;
        $table    = Schema::icdZuordnungen();
        $locTable = Schema::locations();

        // Location-ID aus UUID auflösen
        $locationId = null;
        if ($locationUuid) {
            $locationId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$locTable} WHERE uuid = %s LIMIT 1",
                $locationUuid
            ));
            if ($locationId === 0) {
                $locationId = null;
            }
        }

        // Alle relevanten Zuordnungen laden (global + standortspezifisch)
        $where = "iz.form_id = %s";
        $params = [$formId];

        if ($activeOnly) {
            $where .= " AND iz.is_active = 1";
        }

        if ($locationId) {
            $where .= " AND (iz.location_id IS NULL OR iz.location_id = %d)";
            $params[] = $locationId;
        } else {
            $where .= " AND iz.location_id IS NULL";
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT iz.*, l.name AS location_name
                 FROM {$table} iz
                 LEFT JOIN {$locTable} l ON l.id = iz.location_id
                 WHERE {$where}
                 ORDER BY iz.sort_order ASC, iz.frage_key ASC",
                ...$params
            ),
            ARRAY_A
        ) ?: [];

        // Standortspezifische Zuordnungen überschreiben globale
        if ($locationId) {
            $merged = [];
            foreach ($rows as $row) {
                $key = $row['frage_key'];
                if (!isset($merged[$key]) || $row['location_id'] !== null) {
                    $merged[$key] = $row;
                }
            }
            return array_values($merged);
        }

        return $rows;
    }

    /**
     * Alle Zuordnungen für ein Formular (Admin-Ansicht, inkl. inaktive)
     */
    public function getByFormId(string $formId, ?int $locationId = null): array
    {
        global $wpdb;
        $table    = Schema::icdZuordnungen();
        $locTable = Schema::locations();

        $where  = "iz.form_id = %s";
        $params = [$formId];

        if ($locationId !== null) {
            $where .= " AND (iz.location_id IS NULL OR iz.location_id = %d)";
            $params[] = $locationId;
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT iz.*, l.name AS location_name
                 FROM {$table} iz
                 LEFT JOIN {$locTable} l ON l.id = iz.location_id
                 WHERE {$where}
                 ORDER BY iz.sort_order ASC, iz.frage_key ASC",
                ...$params
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Einzelne Zuordnung per ID
     */
    public function getById(int $id): ?array
    {
        global $wpdb;
        $table = Schema::icdZuordnungen();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Zuordnung speichern (Insert oder Update)
     */
    public function save(array $data): int
    {
        global $wpdb;
        $table = Schema::icdZuordnungen();

        $row = [
            'form_id'     => sanitize_key($data['form_id'] ?? 'augenarzt'),
            'frage_key'   => sanitize_key($data['frage_key'] ?? ''),
            'icd_code'    => strtoupper(sanitize_text_field($data['icd_code'] ?? '')),
            'bezeichnung' => sanitize_text_field($data['bezeichnung'] ?? ''),
            'sicherheit'  => $this->validateSicherheit($data['sicherheit'] ?? 'G'),
            'seite_field' => !empty($data['seite_field']) ? sanitize_key($data['seite_field']) : null,
            'location_id' => !empty($data['location_id']) ? (int) $data['location_id'] : null,
            'is_active'   => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
        ];

        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d'];

        // Handle nullable location_id
        if ($row['location_id'] === null) {
            $formats[6] = null; // wird als NULL eingefügt
        }

        if (!empty($data['id'])) {
            $wpdb->update($table, $row, ['id' => (int) $data['id']]);
            return (int) $data['id'];
        }

        // Upsert: Prüfe ob Kombination form_id + frage_key + location_id schon existiert
        $existingId = $this->findExisting($row['form_id'], $row['frage_key'], $row['location_id']);
        if ($existingId) {
            $wpdb->update($table, $row, ['id' => $existingId]);
            return $existingId;
        }

        $wpdb->insert($table, $row);
        return (int) $wpdb->insert_id;
    }

    /**
     * Zuordnung löschen
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(
            Schema::icdZuordnungen(),
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Aktiv-Status umschalten
     */
    public function toggleActive(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . Schema::icdZuordnungen() . " SET is_active = 1 - is_active WHERE id = %d",
                $id
            )
        );
    }

    /**
     * Default-Zuordnungen einfügen (Seed)
     * Nur wenn Tabelle leer ist für den jeweiligen Fragebogen.
     */
    public function createDefaults(string $formId = 'augenarzt'): int
    {
        global $wpdb;
        $table = Schema::icdZuordnungen();

        // Prüfe ob bereits Einträge existieren
        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE form_id = %s", $formId)
        );

        if ($count > 0) {
            return 0;
        }

        $inserted = 0;
        $order = 0;
        foreach (self::DEFAULT_MAPPINGS as $mapping) {
            if ($mapping[0] !== $formId) {
                continue;
            }

            $wpdb->insert($table, [
                'form_id'     => $mapping[0],
                'frage_key'   => $mapping[1],
                'icd_code'    => $mapping[2],
                'bezeichnung' => $mapping[3],
                'sicherheit'  => $mapping[4],
                'seite_field' => $mapping[5],
                'location_id' => null,
                'is_active'   => 1,
                'sort_order'  => $order++,
            ]);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Merge Defaults: Fügt neue Zuordnungen hinzu ohne bestehende zu löschen
     *
     * Ideal für Updates: Neue Codes werden eingefügt, bestehende bleiben erhalten.
     *
     * @param string $formId Fragebogen-ID
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function mergeDefaults(string $formId = 'augenarzt'): array
    {
        global $wpdb;
        $table = Schema::icdZuordnungen();

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        // Hole maximale sort_order für neue Einträge
        $maxOrder = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order), -1) FROM {$table} WHERE form_id = %s",
            $formId
        ));
        $nextOrder = $maxOrder + 1;

        foreach (self::DEFAULT_MAPPINGS as $mapping) {
            if ($mapping[0] !== $formId) {
                continue;
            }

            $frageKey = $mapping[1];
            $icdCode  = $mapping[2];

            // Prüfe ob Zuordnung bereits existiert (form_id + frage_key)
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, icd_code, bezeichnung, sicherheit, seite_field
                 FROM {$table}
                 WHERE form_id = %s AND frage_key = %s AND location_id IS NULL
                 LIMIT 1",
                $formId,
                $frageKey
            ), ARRAY_A);

            if ($existing) {
                // Existiert bereits - prüfe ob Update nötig
                $needsUpdate = (
                    $existing['icd_code'] !== $icdCode ||
                    $existing['bezeichnung'] !== $mapping[3] ||
                    $existing['sicherheit'] !== $mapping[4] ||
                    $existing['seite_field'] !== $mapping[5]
                );

                if ($needsUpdate) {
                    $wpdb->update(
                        $table,
                        [
                            'icd_code'    => $icdCode,
                            'bezeichnung' => $mapping[3],
                            'sicherheit'  => $mapping[4],
                            'seite_field' => $mapping[5],
                        ],
                        ['id' => $existing['id']]
                    );
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                // Neu - einfügen
                $wpdb->insert($table, [
                    'form_id'     => $mapping[0],
                    'frage_key'   => $frageKey,
                    'icd_code'    => $icdCode,
                    'bezeichnung' => $mapping[3],
                    'sicherheit'  => $mapping[4],
                    'seite_field' => $mapping[5],
                    'location_id' => null,
                    'is_active'   => 1,
                    'sort_order'  => $nextOrder++,
                ]);
                $inserted++;
            }
        }

        return [
            'inserted' => $inserted,
            'updated'  => $updated,
            'skipped'  => $skipped,
        ];
    }

    /**
     * Alle verfügbaren Formulare mit Zuordnungs-Statistik
     */
    public function getFormSummary(): array
    {
        global $wpdb;
        $table = Schema::icdZuordnungen();

        $rows = $wpdb->get_results(
            "SELECT form_id,
                    COUNT(*) AS total,
                    SUM(is_active) AS active
             FROM {$table}
             GROUP BY form_id
             ORDER BY form_id",
            ARRAY_A
        ) ?: [];

        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['form_id']] = [
                'total'  => (int) $row['total'],
                'active' => (int) $row['active'],
            ];
        }

        return $summary;
    }

    /**
     * Prüfe ob Kombination bereits existiert
     */
    private function findExisting(string $formId, string $frageKey, ?int $locationId): ?int
    {
        global $wpdb;
        $table = Schema::icdZuordnungen();

        if ($locationId === null) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE form_id = %s AND frage_key = %s AND location_id IS NULL",
                $formId, $frageKey
            ));
        } else {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE form_id = %s AND frage_key = %s AND location_id = %d",
                $formId, $frageKey, $locationId
            ));
        }

        return $id ? (int) $id : null;
    }

    /**
     * Sicherheits-Code validieren
     */
    private function validateSicherheit(string $value): string
    {
        $valid = ['G', 'V', 'Z', 'A'];
        $value = strtoupper(trim($value));
        return in_array($value, $valid, true) ? $value : 'G';
    }
}
