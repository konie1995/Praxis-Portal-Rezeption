<?php
/**
 * Form Loader (v4)
 * 
 * Lädt und verarbeitet JSON-Fragebögen mit Multilang-Support.
 * 
 * Formate:
 * - Multilang (v4-Standard): formname_de.json, formname_en.json, ...
 * - Legacy:  formname.json mit inline-Übersetzungen {de: "...", en: "..."}
 * - Custom:  Hochgeladene Formulare in wp-content/pp-forms/
 * 
 * v4-Änderungen:
 * - Kein Singleton mehr (DI über Container)
 * - Multi-Location: Custom-Formulare pro location_uuid
 * - Render-Methoden für JSON-definierte Felder
 * - medication_autocomplete, file_upload, signature Rendering
 * - Checkbox-Group und group_toggle Support
 * - ICD-Mapping Unterstützung
 * 
 * @package PraxisPortal\Form
 * @since 4.0.0
 */

namespace PraxisPortal\Form;

use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class FormLoader
{
    /**
     * Unterstützte Sprachen
     */
    const SUPPORTED_LANGUAGES = ['de', 'en', 'fr', 'nl', 'it', 'tr', 'ru', 'ar'];

    /**
     * Cache für geladene Formulare
     * @var array<string, array>
     */
    private array $formsCache = [];

    /**
     * Cache für Übersetzungen
     * @var array<string, array>
     */
    private array $translationsCache = [];

    /**
     * Plugin-Verzeichnis für Form-Dateien
     */
    private string $formsDir;

    /**
     * Custom-Verzeichnis (wp-content/pp-forms/)
     */
    private string $customDir;

    /**
     * I18n-Instanz
     */
    private I18n $i18n;

    /**
     * Constructor
     */
    public function __construct(I18n $i18n)
    {
        $this->i18n     = $i18n;
        $this->formsDir = PP_PLUGIN_DIR . 'forms/';
        $this->customDir = WP_CONTENT_DIR . '/pp-forms/';
    }

    // ------------------------------------------------------------------
    //  Formular laden
    // ------------------------------------------------------------------

    /**
     * Lädt ein Formular aus JSON
     *
     * @param string      $formId Formular-ID (z.B. "augenarzt")
     * @param string|null $lang   Sprache (null = de)
     * @return array|null Formular-Daten oder null
     */
    public function loadForm(string $formId, ?string $lang = null): ?array
    {
        $lang     = $lang ?: 'de';
        $cacheKey = $formId . '_' . $lang;

        if (isset($this->formsCache[$cacheKey])) {
            return $this->formsCache[$cacheKey];
        }

        $file = $this->resolveFormFile($formId, $lang);

        if (!$file || !file_exists($file)) {
            return null;
        }

        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[PP4] FormLoader: JSON-Fehler in ' . $file . ': ' . json_last_error_msg());
            }
            return null;
        }

        $this->formsCache[$cacheKey] = $data;
        return $data;
    }

    /**
     * Gibt ein lokalisiertes, aufbereitetes Formular zurück
     *
     * @param string      $formId Formular-ID
     * @param string|null $lang   Sprache (null = aktuelle)
     * @return array|null Lokalisiertes Formular mit sortierten sections + fields
     */
    public function getLocalizedForm(string $formId, ?string $lang = null): ?array
    {
        $lang = $lang ?: substr($this->i18n->getLocale(), 0, 2);

        // Multilang: Direkt Sprachdatei laden
        if ($this->isMultilangFormat($formId)) {
            $form = $this->loadForm($formId, $lang);
            if (!$form) {
                return null;
            }

            $localized = [
                'id'          => $form['id'] ?? $formId,
                'name'        => $form['name'] ?? $formId,
                'description' => $form['description'] ?? '',
                'version'     => $form['version'] ?? '1.0',
                'sections'    => $form['sections'] ?? [],
                'fields'      => $form['fields'] ?? [],
            ];
        } else {
            // Legacy-Format mit Inline-Übersetzungen
            $form = $this->loadForm($formId);
            if (!$form) {
                return null;
            }

            $localized = [
                'id'          => $form['id'] ?? $formId,
                'name'        => $this->getLocalizedText($form['name'] ?? $formId, $lang),
                'description' => $this->getLocalizedText($form['description'] ?? '', $lang),
                'version'     => $form['version'] ?? '1.0',
                'sections'    => [],
                'fields'      => [],
            ];

            // Sections lokalisieren
            foreach ($form['sections'] ?? [] as $section) {
                $locSection = [
                    'id'    => $section['id'],
                    'label' => $this->getLocalizedText($section['label'], $lang),
                    'order' => $section['order'] ?? 0,
                ];
                if (isset($section['condition'])) {
                    $locSection['condition'] = $section['condition'];
                }
                $localized['sections'][] = $locSection;
            }

            // Felder lokalisieren
            foreach ($form['fields'] ?? [] as $field) {
                $localized['fields'][] = $this->localizeFieldLegacy($field, $lang, $formId);
            }
        }

        // Sortieren
        usort($localized['sections'], fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
        usort($localized['fields'], fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));

        return $localized;
    }

    // ------------------------------------------------------------------
    //  Verfügbare Formulare
    // ------------------------------------------------------------------

    /**
     * Gibt alle verfügbaren Formulare zurück
     *
     * @return array Liste der Formulare [{id, name, description, source, version, format, languages}, ...]
     */
    public function getAvailableForms(): array
    {
        $forms = [];
        $lang  = substr($this->i18n->getLocale(), 0, 2);

        // 1. Multilang-Formulare (name_de.json)
        if (is_dir($this->formsDir)) {
            $deFiles = glob($this->formsDir . '*_de.json');
            foreach ($deFiles as $file) {
                $basename = basename($file, '.json');
                $formId   = preg_replace('/_de$/', '', $basename);
                $formData = $this->loadForm($formId, $lang);

                if ($formData) {
                    $forms[$formId] = [
                        'id'          => $formId,
                        'name'        => $formData['name'] ?? $formId,
                        'description' => $formData['description'] ?? '',
                        'source'      => 'plugin',
                        'version'     => $formData['version'] ?? '1.0',
                        'format'      => 'multilang',
                        'languages'   => $this->getAvailableLanguages($formId),
                    ];
                }
            }

            // 2. Legacy-Formulare (name.json ohne _XX Suffix)
            $allFiles = glob($this->formsDir . '*.json');
            foreach ($allFiles as $file) {
                $basename = basename($file, '.json');
                if (preg_match('/_[a-z]{2}$/', $basename)) {
                    continue;
                }
                if (!isset($forms[$basename])) {
                    $formData = $this->loadForm($basename);
                    if ($formData) {
                        $forms[$basename] = [
                            'id'          => $basename,
                            'name'        => $this->getLocalizedText($formData['name'] ?? $basename, $lang),
                            'description' => $this->getLocalizedText($formData['description'] ?? '', $lang),
                            'source'      => 'plugin',
                            'version'     => $formData['version'] ?? '1.0',
                            'format'      => 'legacy',
                            'languages'   => ['de'],
                        ];
                    }
                }
            }
        }

        // 3. Custom-Formulare aus wp-content/pp-forms/
        if (is_dir($this->customDir)) {
            $customFiles = glob($this->customDir . '*.json');
            foreach ($customFiles as $file) {
                $formId   = 'custom_' . basename($file, '.json');
                $formData = $this->loadForm($formId);
                if ($formData) {
                    $forms[$formId] = [
                        'id'          => $formId,
                        'name'        => $this->getLocalizedText($formData['name'] ?? $formId, $lang),
                        'description' => $this->getLocalizedText($formData['description'] ?? '', $lang),
                        'source'      => 'custom',
                        'version'     => $formData['version'] ?? '1.0',
                        'format'      => 'legacy',
                        'languages'   => ['de'],
                    ];
                }
            }
        }

        return $forms;
    }

    /**
     * Gibt verfügbare Sprachen für ein Multilang-Formular zurück
     */
    public function getAvailableLanguages(string $formId): array
    {
        if (!$this->isMultilangFormat($formId)) {
            return ['de'];
        }

        $languages = [];
        $pattern   = $this->formsDir . $formId . '_*.json';
        $files     = glob($pattern);

        foreach ($files as $file) {
            $basename = basename($file, '.json');
            if (preg_match('/_([a-z]{2})$/', $basename, $m)) {
                $languages[] = $m[1];
            }
        }

        return $languages ?: ['de'];
    }

    /**
     * Prüft ob ein Formular das Multilang-Format verwendet
     */
    public function isMultilangFormat(string $formId): bool
    {
        if (strpos($formId, 'custom_') === 0) {
            return false;
        }
        return file_exists($this->formsDir . $formId . '_de.json');
    }

    // ------------------------------------------------------------------
    //  Feld-Abfragen
    // ------------------------------------------------------------------

    /**
     * Holt Felder einer Section
     */
    public function getSectionFields(string $formId, string $sectionId, ?string $lang = null): array
    {
        $form = $this->getLocalizedForm($formId, $lang);
        if (!$form) {
            return [];
        }

        $fields = [];
        foreach ($form['fields'] as $field) {
            if ($field['section'] === $sectionId && ($field['enabled'] ?? true)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Holt ein einzelnes Feld
     */
    public function getField(string $formId, string $fieldId, ?string $lang = null): ?array
    {
        $form = $this->getLocalizedForm($formId, $lang);
        if (!$form) {
            return null;
        }

        foreach ($form['fields'] as $field) {
            if ($field['id'] === $fieldId) {
                return $field;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    //  HTML Rendering
    // ------------------------------------------------------------------

    /**
     * Rendert ein einzelnes Feld als HTML
     *
     * @param array       $field Feld-Definition aus JSON
     * @param mixed       $value Aktueller Wert
     * @param string|null $namePrefix Prefix für name-Attribut (Standard: pp_data)
     * @return string HTML
     */
    public function renderField(array $field, $value = null, ?string $namePrefix = null): string
    {
        $namePrefix = $namePrefix ?? 'pp_data';
        $id       = 'pp_' . esc_attr($field['id']);
        $name     = esc_attr($namePrefix . '[' . $field['id'] . ']');
        $required = !empty($field['required']) ? 'required' : '';
        $label    = esc_html($field['label']);
        $hasInfo  = !empty($field['info']);

        $html = '<div class="pp-field pp-field-' . esc_attr($field['type']) . '"'
              . ' data-field-id="' . esc_attr($field['id']) . '"';

        // Bedingte Felder
        if (!empty($field['condition'])) {
            $html .= ' data-condition=\'' . esc_attr(wp_json_encode($field['condition'])) . '\'';
            $html .= ' style="display:none;"';
        }
        $html .= '>';

        // Label-Zeile mit optionalem Info-Icon
        if ($field['type'] !== 'checkbox' && $field['type'] !== 'button') {
            $html .= '<div class="pp-field-label-row">';
            $html .= '<label for="' . $id . '">';
            $html .= '<span class="pp-field-label-text">' . $label . '</span>';
            if (!empty($field['required'])) {
                $html .= ' <span class="pp-required" aria-hidden="true">*</span>';
            }
            $html .= '</label>';
            if ($hasInfo) {
                $html .= '<button type="button" class="pp-info-trigger" '
                        . 'data-info="' . esc_attr($field['info']) . '" '
                        . 'aria-label="Information" title="Information">i</button>';
            }
            $html .= '</div>';
        }

        // Eingabe nach Typ
        switch ($field['type']) {
            case 'text':
            case 'email':
            case 'tel':
                $ph = isset($field['placeholder']) ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';
                $html .= '<input type="' . esc_attr($field['type']) . '" id="' . $id . '" name="' . $name . '"'
                        . ' value="' . esc_attr($value ?? '') . '" ' . $required . $ph . '>';
                break;

            case 'date':
                // Spezialfall: Geburtsdatum als TT MM JJJJ Felder
                if ($field['id'] === 'geburtsdatum') {
                    // Verstecktes Hauptfeld (wird vom JS befüllt)
                    $def = ($field['default'] ?? '') === 'today' ? wp_date('Y-m-d') : '';
                    $html .= '<input type="hidden" id="' . $id . '" name="' . $name . '"'
                            . ' value="' . esc_attr($value ?? $def) . '" ' . $required . '>';

                    // 3 separate Eingabefelder: TT MM JJJJ (konsistent mit anderen Formularen)
                    $html .= '<div class="pp-date-fields">';
                    $html .= '<input type="number" name="' . $name . '_tag" placeholder="TT" min="1" max="31"'
                            . ' class="pp-date-day" ' . $required . '>';
                    $html .= '<span class="pp-date-sep">.</span>';
                    $html .= '<input type="number" name="' . $name . '_monat" placeholder="MM" min="1" max="12"'
                            . ' class="pp-date-month" ' . $required . '>';
                    $html .= '<span class="pp-date-sep">.</span>';
                    $html .= '<input type="number" name="' . $name . '_jahr" placeholder="JJJJ" min="1900" max="' . date('Y') . '"'
                            . ' class="pp-date-year" ' . $required . '>';
                    $html .= '</div>';
                } else {
                    // Normale Datumsfelder
                    $def = ($field['default'] ?? '') === 'today' ? wp_date('Y-m-d') : '';
                    $html .= '<input type="date" id="' . $id . '" name="' . $name . '"'
                            . ' value="' . esc_attr($value ?? $def) . '" ' . $required . '>';
                }
                break;

            case 'textarea':
                $ph = isset($field['placeholder']) ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';
                $html .= '<textarea id="' . $id . '" name="' . $name . '" rows="3" ' . $required . $ph . '>'
                        . esc_textarea($value ?? '') . '</textarea>';
                break;

            case 'select':
                $html .= '<select id="' . $id . '" name="' . $name . '" ' . $required . '>';
                $html .= '<option value="">' . esc_html($this->i18n->translate('Bitte wählen')) . '</option>';
                foreach ($field['options'] ?? [] as $opt) {
                    $sel = ($value === ($opt['value'] ?? '')) ? ' selected' : '';
                    $html .= '<option value="' . esc_attr($opt['value']) . '"' . $sel . '>'
                            . esc_html($opt['label']) . '</option>';
                }
                $html .= '</select>';
                break;

            case 'radio':
                $html .= '<div class="pp-radio-group">';
                foreach ($field['options'] ?? [] as $opt) {
                    $chk = ($value === ($opt['value'] ?? '')) ? ' checked' : '';
                    $html .= '<label class="pp-radio-option">'
                            . '<input type="radio" name="' . $name . '" value="' . esc_attr($opt['value']) . '"' . $chk . ' ' . $required . '>'
                            . '<span>' . esc_html($opt['label']) . '</span></label>';
                }
                $html .= '</div>';
                break;

            case 'checkbox':
                $chk = $value ? ' checked' : '';
                $html .= '<label class="pp-checkbox-option">'
                        . '<input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $chk . ' ' . $required . '>'
                        . '<span>' . $label . '</span></label>';
                if ($hasInfo) {
                    $html .= '<button type="button" class="pp-info-trigger" data-info="' . esc_attr($field['info']) . '">i</button>';
                }
                break;

            case 'checkbox_group':
                $values = is_array($value) ? $value : [];
                $options = $field['options'] ?? [];

                // Filter insurance options based on mode (de vs international)
                if ($field['id'] === 'privat_art') {
                    $insuranceMode = get_option('pp_insurance_mode', 'de');
                    if ($insuranceMode === 'international') {
                        // International mode: remove German-specific options
                        $germanSpecific = ['beihilfe', 'post_b', 'selbststaendig', 'standardtarif'];
                        $options = array_filter($options, function($opt) use ($germanSpecific) {
                            return !in_array($opt['value'], $germanSpecific, true);
                        });
                    }
                }

                $html .= '<div class="pp-checkbox-group">';
                foreach ($options as $opt) {
                    $chk = in_array($opt['value'], $values, true) ? ' checked' : '';
                    $html .= '<label class="pp-checkbox-option">'
                            . '<input type="checkbox" name="' . $name . '[]" value="' . esc_attr($opt['value']) . '"' . $chk . '>'
                            . '<span>' . esc_html($opt['label']) . '</span></label>';
                }
                $html .= '</div>';
                break;

            case 'file':
                $accept = $field['accept'] ?? 'image/*,.pdf';
                $html .= '<div class="pp-file-upload-wrapper">'
                        . '<input type="file" id="' . $id . '" name="' . $name . '" accept="' . esc_attr($accept) . '" class="pp-file-input">'
                        . '<label for="' . $id . '" class="pp-file-upload-area">'
                        . '<div class="pp-file-upload-icon">📷</div>'
                        . '<div class="pp-file-upload-text"><strong>' . esc_html($this->i18n->translate('Datei auswählen oder hierher ziehen')) . '</strong>'
                        . '<span>' . esc_html($this->i18n->translate('JPG, PNG oder PDF (max. 10MB)')) . '</span></div>'
                        . '</label>'
                        . '<div class="pp-file-preview" data-for="' . $id . '"></div>'
                        . '</div>';
                break;

            case 'medication_autocomplete':
                $html .= '<div class="pp-medication-input-wrapper">'
                        . '<div class="pp-medication-list" id="' . $id . '_list"></div>'
                        . '<div class="pp-medication-add">'
                        . '<input type="text" class="pp-medication-search" id="' . $id . '_search"'
                        . ' placeholder="' . esc_attr($this->i18n->translate('Medikament eingeben...')) . '" autocomplete="off">'
                        . '<div class="pp-medication-suggestions" style="display:none;"></div>'
                        . '</div>'
                        . '<input type="hidden" name="' . $name . '" id="' . $id . '" class="pp-medication-hidden">'
                        . '</div>';
                break;

            case 'signature':
                $html .= '<div class="pp-signature-wrapper">'
                        . '<canvas id="' . $id . '_canvas" class="pp-signature-pad" width="400" height="150"></canvas>'
                        . '<input type="hidden" name="' . $name . '" id="' . $id . '" class="pp-signature-data">'
                        . '<div class="pp-signature-actions">'
                        . '<button type="button" class="pp-signature-clear">'
                        . esc_html($this->i18n->translate('Löschen')) . '</button>'
                        . '</div></div>';
                break;

            case 'button':
                $action = $field['action'] ?? '';
                $html .= '<button type="button" class="pp-action-btn" data-action="' . esc_attr($action) . '">'
                        . esc_html($label) . '</button>';
                break;
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Rendert eine komplette Section als HTML
     */
    public function renderSection(string $formId, string $sectionId, array $values = [], ?string $lang = null): string
    {
        $form = $this->getLocalizedForm($formId, $lang);
        if (!$form) {
            return '';
        }

        // Section-Label finden
        $sectionLabel = '';
        $sectionCond  = null;
        foreach ($form['sections'] as $section) {
            if ($section['id'] === $sectionId) {
                $sectionLabel = $section['label'];
                $sectionCond  = $section['condition'] ?? null;
                break;
            }
        }

        $fields = $this->getSectionFields($formId, $sectionId, $lang);
        if (empty($fields)) {
            return '';
        }

        $html = '<div class="pp-section" data-section-id="' . esc_attr($sectionId) . '"';
        if ($sectionCond) {
            $html .= ' data-condition=\'' . esc_attr(wp_json_encode($sectionCond)) . '\' style="display:none;"';
        }
        $html .= '>';
        $html .= '<h3 class="pp-section-title">' . esc_html($sectionLabel) . '</h3>';
        $html .= '<div class="pp-section-fields">';

        foreach ($fields as $field) {
            $val = $values[$field['id']] ?? null;
            $html .= $this->renderField($field, $val);
        }

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Rendert das komplette Formular als HTML
     */
    public function renderForm(string $formId, array $values = [], ?string $lang = null): string
    {
        $form = $this->getLocalizedForm($formId, $lang);
        if (!$form) {
            return '<p>' . esc_html($this->i18n->translate('Formular nicht gefunden.')) . '</p>';
        }

        $html = '<div class="pp-form" data-form-id="' . esc_attr($formId) . '"'
              . ' data-form-version="' . esc_attr($form['version'] ?? '1.0') . '">';

        foreach ($form['sections'] as $section) {
            $html .= $this->renderSection($formId, $section['id'], $values, $lang);
        }

        $html .= '</div>';
        return $html;
    }

    // ------------------------------------------------------------------
    //  Import / Export
    // ------------------------------------------------------------------

    /**
     * Exportiert ein Formular als JSON
     */
    public function exportForm(string $formId): string
    {
        $form = $this->loadForm($formId);
        if (!$form) {
            return '';
        }
        return wp_json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Importiert ein Formular aus JSON (als Custom-Formular)
     */
    public function importForm(string $json, string $formId): bool
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (empty($data['id']) || empty($data['fields'])) {
            return false;
        }

        // Custom-Verzeichnis erstellen
        if (!is_dir($this->customDir)) {
            wp_mkdir_p($this->customDir);
            file_put_contents($this->customDir . '.htaccess', "Options -Indexes\nDeny from all\n");
            file_put_contents($this->customDir . 'index.php', '<?php // Silence');
        }

        $file   = $this->customDir . sanitize_file_name($formId) . '.json';
        $result = file_put_contents(
            $file,
            wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // Cache leeren
        unset($this->formsCache['custom_' . $formId]);

        return $result !== false;
    }

    // ------------------------------------------------------------------
    //  Validierung (einfach – Details in FormValidator)
    // ------------------------------------------------------------------

    /**
     * Einfache Pflichtfeld-Prüfung gegen JSON-Definition
     */
    public function validate(string $formId, array $data): array
    {
        $form = $this->loadForm($formId);
        if (!$form) {
            return ['valid' => false, 'errors' => ['form' => 'Formular nicht gefunden']];
        }

        $errors = [];

        foreach ($form['fields'] ?? [] as $field) {
            if (!($field['enabled'] ?? true)) {
                continue;
            }

            $fieldId  = $field['id'];
            $val      = $data[$fieldId] ?? '';
            $required = $field['required'] ?? false;

            if ($required && empty($val)) {
                $errors[$fieldId] = $this->i18n->translate('Dieses Feld ist erforderlich.');
                continue;
            }

            if (!empty($val)) {
                switch ($field['type']) {
                    case 'email':
                        if (!is_email($val)) {
                            $errors[$fieldId] = $this->i18n->translate('Bitte geben Sie eine gültige E-Mail-Adresse ein.');
                        }
                        break;
                    case 'date':
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                            $errors[$fieldId] = $this->i18n->translate('Bitte geben Sie ein gültiges Datum ein.');
                        }
                        break;
                }
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    // ------------------------------------------------------------------
    //  Cache
    // ------------------------------------------------------------------

    /**
     * Cache leeren
     */
    public function clearCache(): void
    {
        $this->formsCache        = [];
        $this->translationsCache = [];
    }

    // ------------------------------------------------------------------
    //  Private Helpers
    // ------------------------------------------------------------------

    /**
     * Löst den Dateipfad für ein Formular auf
     */
    private function resolveFormFile(string $formId, string $lang): ?string
    {
        // 1. Custom-Formular
        if (strpos($formId, 'custom_') === 0) {
            $realId = substr($formId, 7);
            $file   = $this->customDir . $realId . '.json';
            return file_exists($file) ? $file : null;
        }

        // 2. Multilang (formname_XX.json)
        if ($this->isMultilangFormat($formId)) {
            $file = $this->formsDir . $formId . '_' . $lang . '.json';
            if (file_exists($file)) {
                return $file;
            }
            // Fallback auf Deutsch
            $file = $this->formsDir . $formId . '_de.json';
            return file_exists($file) ? $file : null;
        }

        // 3. Legacy (formname.json)
        $file = $this->formsDir . $formId . '.json';
        return file_exists($file) ? $file : null;
    }

    /**
     * Lokalisiert ein Feld im Legacy-Format
     */
    private function localizeFieldLegacy(array $field, string $lang, string $formId): array
    {
        $fieldId = $field['id'];

        // Info-Overrides aus DB
        $savedInfo = get_option('pp_form_info_' . $formId . '_global', []);

        $loc = [
            'id'       => $fieldId,
            'section'  => $field['section'],
            'type'     => $field['type'],
            'order'    => $field['order'] ?? 0,
            'required' => $field['required'] ?? false,
            'enabled'  => $field['enabled'] ?? true,
            'label'    => $this->getLocalizedText($field['label'], $lang),
        ];

        // Info-Text
        if (isset($savedInfo[$fieldId])) {
            $loc['info'] = $savedInfo[$fieldId];
        } elseif (isset($field['info'])) {
            $loc['info'] = $this->getLocalizedText($field['info'], $lang);
        }

        // Placeholder
        if (isset($field['placeholder'])) {
            $loc['placeholder'] = $this->getLocalizedText($field['placeholder'], $lang);
        }

        // Default, Action, Accept
        foreach (['default', 'action', 'accept'] as $key) {
            if (isset($field[$key])) {
                $loc[$key] = $field[$key];
            }
        }

        // Optionen lokalisieren
        if (isset($field['options'])) {
            $loc['options'] = [];
            foreach ($field['options'] as $opt) {
                $loc['options'][] = [
                    'value' => $opt['value'],
                    'label' => $this->getLocalizedText($opt['label'], $lang),
                ];
            }
        }

        // Condition
        if (isset($field['condition'])) {
            $loc['condition'] = $field['condition'];
        }

        return $loc;
    }

    /**
     * Extrahiert lokalisierten Text (Legacy inline-Übersetzungen)
     *
     * @param mixed  $text String oder Array mit {de: "...", en: "...", ...}
     * @param string $lang Sprachcode
     * @return string
     */
    private function getLocalizedText($text, string $lang = 'de'): string
    {
        if (is_string($text)) {
            return $text;
        }

        if (is_array($text)) {
            return $text[$lang] ?? $text['de'] ?? (string) reset($text);
        }

        return '';
    }
}
