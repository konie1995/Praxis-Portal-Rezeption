<?php
/**
 * Formular-Konfiguration (v4)
 * 
 * Zentrale Stelle für alle Formularfelder mit Labels, Pflichtfeld-Status und Aktivierung.
 * Multi-Location-fähig: Jeder Standort kann eigene Feld-Overrides haben.
 * Kann über den Admin-Bereich bearbeitet werden.
 * 
 * v4-Änderungen:
 * - Multi-Location: Option-Keys enthalten location_uuid
 * - JSON-basiert: Defaults kommen aus JSON-Dateien, nicht mehr aus PHP-Arrays
 * - Custom Fields pro Standort
 * - Keine Singleton mehr (DI über Container)
 * 
 * @package PraxisPortal\Form
 * @since 4.0.0
 */

namespace PraxisPortal\Form;

use PraxisPortal\Location\LocationContext;

if (!defined('ABSPATH')) {
    exit;
}

class FormConfig
{
    /**
     * Option-Name Prefix für gespeicherte Overrides
     * Format: pp_form_config_{location_uuid}
     */
    const OPTION_PREFIX = 'pp_form_config_';

    /**
     * Option-Name für globale Custom Fields
     * Format: pp_custom_fields_{form_id}_{location_uuid}
     */
    const CUSTOM_FIELDS_PREFIX = 'pp_custom_fields_';

    /**
     * Option für gespeicherte Info-Texte
     * Format: pp_form_info_{form_id}_{location_uuid}
     */
    const INFO_PREFIX = 'pp_form_info_';

    /**
     * Geladene Konfiguration (Cache pro Location)
     * @var array<string, array>
     */
    private array $configCache = [];

    /**
     * FormLoader-Instanz
     */
    private FormLoader $loader;

    /**
     * Constructor
     */
    public function __construct(FormLoader $loader)
    {
        $this->loader = $loader;
    }

    // ------------------------------------------------------------------
    //  Konfiguration laden / speichern
    // ------------------------------------------------------------------

    /**
     * Holt die gemergete Konfiguration: JSON-Defaults + DB-Overrides
     *
     * @param string      $formId  Formular-ID (z.B. "augenarzt")
     * @param string      $lang    Sprache (2-stellig)
     * @param string|null $locUuid Location-UUID (null = global)
     * @return array Komplette Feld-Map  [field_id => field_def, ...]
     */
    public function getConfig(string $formId, string $lang = 'de', ?string $locUuid = null): array
    {
        $cacheKey = $formId . '_' . $lang . '_' . ($locUuid ?? 'global');

        if (isset($this->configCache[$cacheKey])) {
            return $this->configCache[$cacheKey];
        }

        // 1. JSON-Defaults laden
        $form = $this->loader->loadForm($formId, $lang);
        if (!$form || empty($form['fields'])) {
            return [];
        }

        // Felder als Map aufbauen  (field_id => definition)
        $config = [];
        foreach ($form['fields'] as $field) {
            $config[$field['id']] = $field;
        }

        // 2. DB-Overrides anwenden (Label, enabled, required, order)
        $overrides = $this->loadOverrides($formId, $locUuid);
        foreach ($overrides as $fieldId => $changes) {
            if (isset($config[$fieldId])) {
                $config[$fieldId] = array_merge($config[$fieldId], $changes);
            }
        }

        // 3. Custom Fields anhängen
        $customFields = $this->getCustomFields($formId, $locUuid);
        foreach ($customFields as $cfId => $cf) {
            $config[$cfId] = $cf;
        }

        // 4. Info-Text Overrides
        $infoOverrides = $this->loadInfoOverrides($formId, $locUuid);
        foreach ($infoOverrides as $fieldId => $infoText) {
            if (isset($config[$fieldId])) {
                $config[$fieldId]['info'] = $infoText;
            }
        }

        $this->configCache[$cacheKey] = $config;
        return $config;
    }

    /**
     * Speichert Overrides für eine Location (nur Deltas zu JSON-Defaults)
     */
    public function saveConfig(string $formId, array $config, ?string $locUuid = null): bool
    {
        $form = $this->loader->loadForm($formId, 'de');
        if (!$form) {
            return false;
        }

        // Defaults als Map
        $defaults = [];
        foreach ($form['fields'] as $f) {
            $defaults[$f['id']] = $f;
        }

        // Nur Änderungen speichern
        $toSave = [];
        foreach ($config as $fieldId => $field) {
            if (!isset($defaults[$fieldId])) {
                continue; // Custom fields separat
            }
            $changes = [];

            if (isset($field['label']) && $field['label'] !== ($defaults[$fieldId]['label'] ?? '')) {
                $changes['label'] = sanitize_text_field($field['label']);
            }
            if (isset($field['enabled'])) {
                $enabled = (bool) $field['enabled'];
                if ($enabled !== ($defaults[$fieldId]['enabled'] ?? true)) {
                    $changes['enabled'] = $enabled;
                }
            }
            if (isset($field['required']) && isset($defaults[$fieldId]['required'])) {
                $required = (bool) $field['required'];
                if ($required !== $defaults[$fieldId]['required']) {
                    $changes['required'] = $required;
                }
            }
            if (isset($field['order'])) {
                $order = (int) $field['order'];
                if ($order !== ($defaults[$fieldId]['order'] ?? 0)) {
                    $changes['order'] = $order;
                }
            }

            if (!empty($changes)) {
                $toSave[$fieldId] = $changes;
            }
        }

        $optionKey = self::OPTION_PREFIX . $formId . '_' . ($locUuid ?? 'global');
        update_option($optionKey, $toSave, false);

        // Cache invalidieren
        $this->clearCache();
        return true;
    }

    /**
     * Speichert Info-Text Overrides
     */
    public function saveInfoOverrides(string $formId, array $infoTexts, ?string $locUuid = null): bool
    {
        $optionKey = self::INFO_PREFIX . $formId . '_' . ($locUuid ?? 'global');
        $sanitized = array_map('sanitize_text_field', $infoTexts);
        update_option($optionKey, $sanitized, false);
        $this->clearCache();
        return true;
    }

    // ------------------------------------------------------------------
    //  Feld-Abfragen
    // ------------------------------------------------------------------

    /**
     * Holt ein einzelnes Feld
     */
    public function getField(string $formId, string $fieldId, string $lang = 'de', ?string $locUuid = null): ?array
    {
        $config = $this->getConfig($formId, $lang, $locUuid);
        return $config[$fieldId] ?? null;
    }

    /**
     * Prüft ob ein Feld aktiviert ist
     */
    public function isFieldEnabled(string $formId, string $fieldId, ?string $locUuid = null): bool
    {
        $field = $this->getField($formId, $fieldId, 'de', $locUuid);
        return $field !== null && ($field['enabled'] ?? true);
    }

    /**
     * Prüft ob ein Feld ein Pflichtfeld ist
     */
    public function isFieldRequired(string $formId, string $fieldId, ?string $locUuid = null): bool
    {
        $field = $this->getField($formId, $fieldId, 'de', $locUuid);
        return $field !== null && ($field['required'] ?? false);
    }

    /**
     * Holt das Label eines Feldes
     */
    public function getFieldLabel(string $formId, string $fieldId, string $lang = 'de', ?string $locUuid = null): string
    {
        $field = $this->getField($formId, $fieldId, $lang, $locUuid);
        return $field['label'] ?? '';
    }

    /**
     * Holt alle Felder einer Sektion (sortiert nach order)
     */
    public function getFieldsBySection(string $formId, string $sectionId, string $lang = 'de', ?string $locUuid = null): array
    {
        $config = $this->getConfig($formId, $lang, $locUuid);
        $fields = [];

        foreach ($config as $field) {
            if (($field['section'] ?? '') === $sectionId && ($field['enabled'] ?? true)) {
                $fields[] = $field;
            }
        }

        usort($fields, fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
        return $fields;
    }

    /**
     * Holt alle Sektionen eines Formulars
     */
    public function getSections(string $formId, string $lang = 'de'): array
    {
        $form = $this->loader->loadForm($formId, $lang);
        if (!$form || empty($form['sections'])) {
            return [];
        }

        $sections = $form['sections'];
        usort($sections, fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
        return $sections;
    }

    // ------------------------------------------------------------------
    //  Custom Fields
    // ------------------------------------------------------------------

    /**
     * Holt Custom Fields für ein Formular + Location
     */
    public function getCustomFields(string $formId, ?string $locUuid = null): array
    {
        $optionKey = self::CUSTOM_FIELDS_PREFIX . $formId . '_' . ($locUuid ?? 'global');
        $custom = get_option($optionKey, []);

        if (!is_array($custom)) {
            return [];
        }

        $fields = [];
        foreach ($custom as $cf) {
            if (!empty($cf['id'])) {
                $cf['is_custom'] = true;
                $fields[$cf['id']] = $cf;
            }
        }

        uasort($fields, fn($a, $b) => ($a['order'] ?? 999) - ($b['order'] ?? 999));
        return $fields;
    }

    /**
     * Custom Fields für eine Section holen
     */
    public function getCustomFieldsBySection(string $formId, string $sectionId, ?string $locUuid = null): array
    {
        $custom = $this->getCustomFields($formId, $locUuid);
        $result = [];

        foreach ($custom as $id => $field) {
            if (($field['section'] ?? 'custom') === $sectionId && ($field['enabled'] ?? true)) {
                $result[$id] = $field;
            }
        }

        return $result;
    }

    /**
     * Fügt ein Custom Field hinzu
     */
    public function addCustomField(string $formId, string $fieldId, array $fieldData, ?string $locUuid = null): string
    {
        // Prefix sicherstellen
        if (strpos($fieldId, 'custom_') !== 0) {
            $fieldId = 'custom_' . $fieldId;
        }

        $fieldData = array_merge([
            'id'        => $fieldId,
            'enabled'   => true,
            'required'  => false,
            'type'      => 'text',
            'section'   => 'custom',
            'order'     => 999,
            'is_custom' => true,
        ], $fieldData);

        $fieldData['id'] = $fieldId;

        $optionKey = self::CUSTOM_FIELDS_PREFIX . $formId . '_' . ($locUuid ?? 'global');
        $existing  = get_option($optionKey, []);

        if (!is_array($existing)) {
            $existing = [];
        }

        // Bestehend ersetzen oder anhängen
        $found = false;
        foreach ($existing as &$cf) {
            if (($cf['id'] ?? '') === $fieldId) {
                $cf    = $fieldData;
                $found = true;
                break;
            }
        }
        unset($cf);

        if (!$found) {
            $existing[] = $fieldData;
        }

        update_option($optionKey, $existing, false);
        $this->clearCache();

        return $fieldId;
    }

    /**
     * Entfernt ein Custom Field
     */
    public function deleteCustomField(string $formId, string $fieldId, ?string $locUuid = null): bool
    {
        if (strpos($fieldId, 'custom_') !== 0) {
            return false;
        }

        $optionKey = self::CUSTOM_FIELDS_PREFIX . $formId . '_' . ($locUuid ?? 'global');
        $existing  = get_option($optionKey, []);

        if (!is_array($existing)) {
            return false;
        }

        $newList = array_filter($existing, fn($cf) => ($cf['id'] ?? '') !== $fieldId);

        if (count($newList) === count($existing)) {
            return false; // Nicht gefunden
        }

        update_option($optionKey, array_values($newList), false);
        $this->clearCache();
        return true;
    }

    // ------------------------------------------------------------------
    //  Reset / Cache
    // ------------------------------------------------------------------

    /**
     * Setzt Overrides für ein Formular zurück
     */
    public function resetToDefaults(string $formId, ?string $locUuid = null): bool
    {
        $suffix = $formId . '_' . ($locUuid ?? 'global');
        delete_option(self::OPTION_PREFIX . $suffix);
        delete_option(self::INFO_PREFIX . $suffix);
        $this->clearCache();
        return true;
    }

    /**
     * Cache leeren
     */
    public function clearCache(): void
    {
        $this->configCache = [];
    }

    // ------------------------------------------------------------------
    //  Private
    // ------------------------------------------------------------------

    /**
     * Lädt Overrides aus der DB
     */
    private function loadOverrides(string $formId, ?string $locUuid): array
    {
        $optionKey = self::OPTION_PREFIX . $formId . '_' . ($locUuid ?? 'global');
        $overrides = get_option($optionKey, []);
        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Lädt Info-Text Overrides
     */
    private function loadInfoOverrides(string $formId, ?string $locUuid): array
    {
        $optionKey = self::INFO_PREFIX . $formId . '_' . ($locUuid ?? 'global');
        $info = get_option($optionKey, []);
        return is_array($info) ? $info : [];
    }
}
