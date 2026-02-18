<?php
/**
 * FormRepository – JSON-Fragebogen-Speicher
 *
 * Verwaltet Fragebögen als JSON-Dateien:
 *  - Primär: forms/ Verzeichnis im Plugin
 *  - Eigene / angepasste Formulare: wp_options (pp_form_{id})
 *  - Suche, Laden, Speichern, Löschen, Klonen
 *
 * Jedes Formular hat die Struktur:
 *   { id, name, description, version, sections[], fields[] }
 *
 * @package PraxisPortal\Forms
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Database\Repository;

if (!defined('ABSPATH')) {
    exit;
}

class FormRepository
{
    /** Verzeichnis für Standard-Formulare (Plugin-Root) */
    private string $formsDir;

    /** Verzeichnis für benutzerdefinierte Formulare (wp-content) */
    private string $customDir;

    /** Option-Prefix für DB-gespeicherte Formulare */
    private const OPTION_PREFIX = 'pp_form_';

    /** Option mit Liste aller benutzerdefinierten Form-IDs */
    private const INDEX_OPTION = 'pp_form_index';

    public function __construct()
    {
        $this->formsDir  = defined('PP_PLUGIN_DIR') ? PP_PLUGIN_DIR . 'forms/' : '';
        $this->customDir = WP_CONTENT_DIR . '/pp-forms/';
    }

    /* -----------------------------------------------------------------
     * READ
     * -------------------------------------------------------------- */

    /**
     * Alle verfügbaren Formulare (Standard + Custom)
     *
     * @return array Liste von Form-Metadaten [{id, name, description, version, sections_count, fields_count}]
     */
    public function getAll(): array
    {
        $forms = [];

        // 1. Standard-Formulare aus dem Plugin-Verzeichnis
        if ($this->formsDir && is_dir($this->formsDir)) {
            foreach (glob($this->formsDir . '*.json') as $file) {
                $data = $this->loadJsonFile($file);
                if ($data && !empty($data['id'])) {
                    $data['_source'] = 'builtin';
                    $forms[$data['id']] = $data;
                }
            }
        }

        // 2. Custom-Formulare aus dem Dateisystem
        if (is_dir($this->customDir)) {
            foreach (glob($this->customDir . '*.json') as $file) {
                $data = $this->loadJsonFile($file);
                if ($data && !empty($data['id'])) {
                    $data['_source'] = 'custom_file';
                    $forms[$data['id']] = $data;  // Überschreibt built-in
                }
            }
        }

        // 3. Custom-Formulare aus der Datenbank (höchste Priorität)
        $index = get_option(self::INDEX_OPTION, []);
        if (is_array($index)) {
            foreach ($index as $formId) {
                $data = get_option(self::OPTION_PREFIX . $formId, null);
                if ($data && is_array($data)) {
                    $data['_source'] = 'database';
                    $forms[$formId] = $data;
                }
            }
        }

        return array_values($forms);
    }

    /**
     * Einzelnes Formular nach ID laden
     *
     * Priorität: DB → Custom-Datei → Built-in-Datei
     *
     * @param string $id  Formular-ID (z.B. "augenarzt")
     * @return array|null
     */
    public function findById(string $id): ?array
    {
        // 1. Datenbank (höchste Priorität — User-Override)
        $data = get_option(self::OPTION_PREFIX . $id, null);
        if ($data && is_array($data)) {
            $data['_source'] = 'database';
            return $data;
        }

        // 2. Custom-Datei
        $customFile = $this->customDir . sanitize_file_name($id) . '.json';
        if (file_exists($customFile)) {
            $data = $this->loadJsonFile($customFile);
            if ($data) {
                $data['_source'] = 'custom_file';
                return $data;
            }
        }

        // 3. Built-in Plugin-Datei
        if ($this->formsDir) {
            // Versuche exakter Name
            $builtinFile = $this->formsDir . sanitize_file_name($id) . '.json';
            if (file_exists($builtinFile)) {
                $data = $this->loadJsonFile($builtinFile);
                if ($data) {
                    $data['_source'] = 'builtin';
                    return $data;
                }
            }

            // Versuche mit Sprach-Suffix (z.B. augenarzt_de.json)
            foreach (glob($this->formsDir . sanitize_file_name($id) . '*.json') as $file) {
                $data = $this->loadJsonFile($file);
                if ($data && ($data['id'] ?? '') === $id) {
                    $data['_source'] = 'builtin';
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * Prüft ob Formular existiert
     */
    public function exists(string $id): bool
    {
        return $this->findById($id) !== null;
    }

    /* -----------------------------------------------------------------
     * WRITE
     * -------------------------------------------------------------- */

    /**
     * Formular speichern (immer in DB, nie in Plugin-Dateien)
     *
     * @param string $id   Formular-ID
     * @param array  $data Vollständige Formular-Daten
     * @return bool
     */
    public function save(string $id, array $data): bool
    {
        // Sicherstellen dass ID gesetzt ist
        $data['id'] = $id;

        // In wp_options speichern
        $result = update_option(self::OPTION_PREFIX . $id, $data, false);

        // Index aktualisieren
        $this->addToIndex($id);

        return $result !== false;
    }

    /**
     * Formular als JSON-Datei exportieren (in custom-dir)
     *
     * @param string $id Formular-ID
     * @return string|false Dateipfad oder false
     */
    public function saveToFile(string $id): string|false
    {
        $data = $this->findById($id);
        if (!$data) {
            return false;
        }

        // _source Feld entfernen
        unset($data['_source']);

        // Custom-Dir erstellen falls nötig
        if (!is_dir($this->customDir)) {
            wp_mkdir_p($this->customDir);

            // .htaccess für Schutz
            $htaccess = $this->customDir . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }

        $filepath = $this->customDir . sanitize_file_name($id) . '.json';
        $json     = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return file_put_contents($filepath, $json) !== false ? $filepath : false;
    }

    /* -----------------------------------------------------------------
     * DELETE
     * -------------------------------------------------------------- */

    /**
     * Benutzerdefiniertes Formular löschen
     *
     * Löscht nur DB-Eintrag + Custom-Datei,
     * NICHT die Built-in Plugin-Datei.
     *
     * @param string $id Formular-ID
     * @return bool
     */
    public function delete(string $id): bool
    {
        // Aus DB löschen
        delete_option(self::OPTION_PREFIX . $id);

        // Aus Index entfernen
        $this->removeFromIndex($id);

        // Custom-Datei löschen (falls vorhanden)
        $customFile = $this->customDir . sanitize_file_name($id) . '.json';
        if (file_exists($customFile)) {
            if (file_exists($customFile)) { unlink($customFile); }
        }

        return true;
    }

    /* -----------------------------------------------------------------
     * CLONE
     * -------------------------------------------------------------- */

    /**
     * Formular klonen
     *
     * @param string $sourceId   Quell-ID
     * @param string $targetId   Ziel-ID
     * @param string $targetName Neuer Name
     * @return bool
     */
    public function cloneForm(string $sourceId, string $targetId, string $targetName = ''): bool
    {
        $data = $this->findById($sourceId);
        if (!$data) {
            return false;
        }

        unset($data['_source']);
        $data['id']   = $targetId;
        $data['name']  = $targetName ?: ($data['name'] ?? $sourceId) . ' (Kopie)';

        return $this->save($targetId, $data);
    }

    /* -----------------------------------------------------------------
     * JSON Export / Import Helpers
     * -------------------------------------------------------------- */

    /**
     * Formular als JSON-String exportieren
     */
    public function exportJson(string $id): ?string
    {
        $data = $this->findById($id);
        if (!$data) {
            return null;
        }

        unset($data['_source']);
        return wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Formular aus JSON-String importieren
     *
     * @param string $json  JSON-String
     * @param bool   $force Überschreiben falls existiert
     * @return string|false  Formular-ID oder false
     */
    public function importJson(string $json, bool $force = false): string|false
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['id'])) {
            return false;
        }

        $id = $data['id'];

        // Duplikat-Check
        if (!$force && $this->exists($id)) {
            $id .= '_import_' . time();
            $data['id'] = $id;
        }

        return $this->save($id, $data) ? $id : false;
    }

    /* -----------------------------------------------------------------
     * PRIVATE HELPERS
     * -------------------------------------------------------------- */

    /**
     * JSON-Datei laden und parsen
     */
    private function loadJsonFile(string $filepath): ?array
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return null;
        }

        $content = file_get_contents($filepath);
        if (!$content) {
            return null;
        }

        $data = json_decode($content, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : null;
    }

    /**
     * Formular-ID zum Index hinzufügen
     */
    private function addToIndex(string $id): void
    {
        $index = get_option(self::INDEX_OPTION, []);
        if (!is_array($index)) {
            $index = [];
        }

        if (!in_array($id, $index, true)) {
            $index[] = $id;
            update_option(self::INDEX_OPTION, $index, false);
        }
    }

    /**
     * Formular-ID aus Index entfernen
     */
    private function removeFromIndex(string $id): void
    {
        $index = get_option(self::INDEX_OPTION, []);
        if (!is_array($index)) {
            return;
        }

        $index = array_values(array_filter($index, fn($v) => $v !== $id));
        update_option(self::INDEX_OPTION, $index, false);
    }
}
