<?php
/**
 * Widget Template Renderer (v4)
 * 
 * Rendert Widget-Templates mit Kontext-Variablen.
 * 
 * Template-Verzeichnis: templates/widget/
 * 
 * Verfügbare Templates:
 * - main.php        → Haupt-Widget (Header, Steps, Formulare)
 * - vacation.php    → Urlaubsmodus (Button + Modal)
 * - steps/location.php  → Standort-Auswahl (Multi-Location)
 * - steps/welcome.php   → Begrüßung + Patientenstatus
 * - steps/services.php  → Service-Auswahl
 * - forms/rezept.php          → Rezept-Formular
 * - forms/ueberweisung.php    → Überweisungs-Formular
 * - forms/brillenverordnung.php → Brillenverordnung
 * - forms/dokument.php        → Dokument-Upload
 * - forms/termin.php          → Terminanfrage
 * - forms/terminabsage.php    → Terminabsage
 * - forms/notfall.php         → Notfall-Kontakt
 * - forms/downloads.php       → Praxis-Downloads
 * - partials/patient-fields.php → Gemeinsame Patientendaten
 * - partials/spam-fields.php    → Honeypot + Token
 * - partials/dsgvo-consent.php  → DSGVO-Einwilligung
 * - partials/success.php        → Erfolgs-Anzeige
 * 
 * v4-Änderungen:
 * - Namespace statt globale Klasse
 * - Strict Types
 * - location_uuid statt location_id
 * - Custom Template-Filter: pp_widget_forms
 * - Escape-Helper mit Typen
 * 
 * @package    PraxisPortal\Widget
 * @since      4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Widget;

if (!defined('ABSPATH')) {
    exit;
}

class WidgetRenderer
{
    /** @var Widget Parent-Widget */
    private Widget $widget;

    /** @var array Template-Kontext (Variablen) */
    private array $context = [];

    /** @var string Template-Verzeichnis */
    private string $templateDir;

    /** @var array Registrierte Service-Formulare */
    private static array $registeredForms = [
        'rezept',
        'ueberweisung',
        'brillenverordnung',
        'dokument',
        'downloads',
        'termin',
        'terminabsage',
        'notfall',
    ];

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(Widget $widget)
    {
        $this->widget      = $widget;
        $this->templateDir = PP_PLUGIN_DIR . 'templates/widget/';
    }

    // =========================================================================
    // KONTEXT
    // =========================================================================

    /**
     * Kompletten Kontext setzen
     * 
     * @param array $context Alle Template-Variablen
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * Einzelne Variable zum Kontext hinzufügen
     */
    public function addToContext(string $key, $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * Kontext-Variable lesen
     * 
     * @param string $key     Schlüssel
     * @param mixed  $default Fallback
     * @return mixed
     */
    public function get(string $key, $default = '')
    {
        return $this->context[$key] ?? $default;
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    /**
     * Template rendern und als String zurückgeben
     * 
     * @param string $template  Template-Name (ohne .php)
     * @param array  $extraVars Zusätzliche Variablen
     * @return string HTML
     */
    public function render(string $template, array $extraVars = []): string
    {
        $file = $this->resolveTemplatePath($template);
        if ($file === null) {
            return '<!-- PP4 Widget: Template "' . esc_attr($template) . '" nicht gefunden -->';
        }

        // Kontext + Extra-Variablen als lokale Variablen verfügbar machen
        $vars = array_merge($this->context, $extraVars, [
            'renderer' => $this,
            'widget'   => $this->widget,
        ]);

        ob_start();
        // Variablen extrahieren (sicher: nur im Template-Scope)
        // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        extract($vars, EXTR_SKIP);
        include $file;
        return ob_get_clean();
    }

    /**
     * Template rendern und direkt ausgeben
     */
    public function display(string $template, array $extraVars = []): void
    {
        echo $this->render($template, $extraVars);
    }

    // =========================================================================
    // SPEZIELLE RENDER-METHODEN
    // =========================================================================

    /**
     * CSS-Style-Block rendern (Inline-Farben)
     */
    public function renderStyles(): string
    {
        $color          = $this->esc($this->get('widget_color', '#2563eb'), 'attr');
        $colorSecondary = $this->esc($this->get('widget_color_secondary', '#28a745'), 'attr');
        $position       = $this->get('widget_position', 'right');

        // Output-Validierung: Nur gültige Hex-Farben durchlassen (Defense-in-Depth)
        $color          = preg_match('/^#[0-9a-fA-F]{3,6}$/', $color) ? $color : '#2563eb';
        $colorSecondary = preg_match('/^#[0-9a-fA-F]{3,6}$/', $colorSecondary) ? $colorSecondary : '#28a745';

        ob_start();
        ?>
        <style id="pp-widget-vars">
            :root {
                --pp-primary: <?php echo $color; ?>;
                --pp-secondary: <?php echo $colorSecondary; ?>;
                --pp-position: <?php echo $position === 'left' ? 'left' : 'right'; ?>;
            }
            #pp-widget-trigger {
                <?php echo $position === 'left' ? 'left: 20px;' : 'right: 20px;'; ?>
            }
            #pp-widget-container {
                <?php echo $position === 'left' ? 'left: 20px;' : 'right: 20px;'; ?>
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Urlaubsmodus rendern
     */
    public function renderVacationMode(): string
    {
        return $this->render('vacation');
    }

    /**
     * Haupt-Widget rendern
     */
    public function renderMainWidget(): string
    {
        return $this->render('main');
    }

    /**
     * Step rendern (location, welcome, services)
     */
    public function renderStep(string $step): string
    {
        return $this->render('steps/' . $step);
    }

    /**
     * Service-Formular rendern
     */
    public function renderForm(string $service): string
    {
        return $this->render('forms/' . $service);
    }

    /**
     * Partial rendern (wiederverwendbare Fragmente)
     */
    public function renderPartial(string $partial, array $extraVars = []): string
    {
        return $this->render('partials/' . $partial, $extraVars);
    }

    /**
     * Alle registrierten Service-Formulare rendern
     * 
     * @return string HTML aller Formulare (versteckt, via JS eingeblendet)
     */
    public function renderAllForms(): string
    {
        $forms    = $this->getRegisteredForms();
        $services = $this->get('services', []);
        $html     = '';

        foreach ($forms as $form) {
            if ($this->templateExists('forms/' . $form)) {
                // Service-Daten für dieses Formular finden (für custom_fields)
                $serviceData = [];
                foreach ($services as $svc) {
                    if (($svc['service_key'] ?? '') === $form) {
                        $serviceData = $svc;
                        break;
                    }
                }
                $html .= $this->render('forms/' . $form, [
                    'service_data'   => $serviceData,
                    'service_config' => json_decode($serviceData['custom_fields'] ?? '{}', true) ?: [],
                ]);
            }
        }

        return $html;
    }

    /**
     * Service-Buttons rendern (delegiert an Widget)
     */
    public function renderServiceButtons(): string
    {
        $services     = $this->get('services', []);
        $locationUuid = $this->get('location_uuid', '');
        $settings     = $this->get('settings', []);

        return $this->widget->renderServicesHtml($services, $locationUuid, $settings);
    }

    // =========================================================================
    // FORMULAR-REGISTRIERUNG
    // =========================================================================

    /**
     * Registrierte Service-Formulare abrufen
     * 
     * Erlaubt Erweiterung via Filter pp_widget_forms
     * 
     * @return array Service-Keys
     */
    public function getRegisteredForms(): array
    {
        return apply_filters('pp_widget_forms', self::$registeredForms);
    }

    // =========================================================================
    // TEMPLATE-AUFLÖSUNG
    // =========================================================================

    /**
     * Template-Pfad auflösen
     * 
     * Reihenfolge:
     * 1. Theme Override: theme/pp-widget/{template}.php
     * 2. Plugin: templates/widget/{template}.php
     * 
     * @param string $template Template-Name (ohne .php)
     * @return string|null Absoluter Pfad oder null
     */
    private function resolveTemplatePath(string $template): ?string
    {
        // Sicherheit: Nur alphanumerisch + Slash + Bindestrich + Unterstrich
        $template = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $template);

        // 1. Theme Override (Kind-Theme > Eltern-Theme)
        $themeFile = locate_template('pp-widget/' . $template . '.php');
        if (!empty($themeFile)) {
            return $themeFile;
        }

        // 2. Plugin-Template
        $pluginFile = $this->templateDir . $template . '.php';
        if (file_exists($pluginFile)) {
            return $pluginFile;
        }

        return null;
    }

    /**
     * Prüft ob ein Template existiert
     */
    public function templateExists(string $template): bool
    {
        return $this->resolveTemplatePath($template) !== null;
    }

    // =========================================================================
    // ESCAPE-HELPER
    // =========================================================================

    /**
     * Wert escapen
     * 
     * @param mixed  $value Wert
     * @param string $type  Escape-Typ: html, attr, url, js, textarea
     * @return string Escaped-Wert
     */
    public function esc($value, string $type = 'html'): string
    {
        $value = (string) $value;

        switch ($type) {
            case 'attr':
                return esc_attr($value);
            case 'url':
                return esc_url($value);
            case 'js':
                return esc_js($value);
            case 'textarea':
                return esc_textarea($value);
            case 'html':
            default:
                return esc_html($value);
        }
    }

    /**
     * Übersetzten Text ausgeben (escaped)
     */
    public function t(string $text): string
    {
        return esc_html($this->widget->getI18n()->t($text));
    }

    // =========================================================================
    // FORMULAR-HELPER
    // =========================================================================

    /**
     * Nonce-Feld für Widget-Formular
     */
    public function renderNonce(): string
    {
        return wp_nonce_field('pp_widget_nonce', 'pp_nonce', true, false);
    }

    /**
     * Spam-Schutz-Felder rendern (Honeypot + Token)
     */
    public function renderSpamFields(): string
    {
        $token = base64_encode(time() . '_' . wp_generate_password(12, false));

        ob_start();
        ?>
        <!-- Spam-Schutz -->
        <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true" tabindex="-1">
            <input type="text" name="website_url" value="" autocomplete="off" tabindex="-1">
            <input type="email" name="email_confirm" value="" autocomplete="off" tabindex="-1">
        </div>
        <input type="hidden" name="form_token" value="<?php echo esc_attr($token); ?>">
        <?php
        return ob_get_clean();
    }

    /**
     * Location-UUID als Hidden-Feld
     */
    public function renderLocationField(): string
    {
        $uuid = $this->get('location_uuid', '');
        return sprintf(
            '<input type="hidden" name="location_uuid" value="%s">',
            esc_attr($uuid)
        );
    }

    /**
     * DSGVO-Consent-Checkbox rendern
     */
    public function renderDsgvoConsent(): string
    {
        $privacyUrl = $this->get('privacy_url', '');

        ob_start();
        ?>
        <div class="pp-form-group pp-dsgvo-consent">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="dsgvo_consent" value="1" required>
                <span>
                    <?php echo $this->t('Ich stimme der'); ?>
                    <?php if (!empty($privacyUrl)): ?>
                        <a href="<?php echo esc_url($privacyUrl); ?>" target="_blank" rel="noopener">
                            <?php echo $this->t('Datenschutzerklärung'); ?>
                        </a>
                    <?php else: ?>
                        <?php echo $this->t('Datenschutzerklärung'); ?>
                    <?php endif; ?>
                    <?php echo $this->t('zu.'); ?>
                </span>
            </label>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Patientendaten-Felder rendern (Vorname, Nachname, Geburtsdatum, etc.)
     * 
     * Werden in allen Service-Formularen benötigt.
     */
    public function renderPatientFields(): string
    {
        if ($this->templateExists('partials/patient-fields')) {
            return $this->renderPartial('patient-fields');
        }

        // Fallback: Inline-Rendering
        $i18n = $this->widget->getI18n();

        ob_start();
        ?>
        <div class="pp-patient-fields">
            <div class="pp-form-row pp-form-row-2">
                <div class="pp-form-group">
                    <label for="pp-vorname"><?php echo esc_html($i18n->t('Vorname')); ?> *</label>
                    <input type="text" id="pp-vorname" name="vorname" required
                           autocomplete="given-name" maxlength="100">
                </div>
                <div class="pp-form-group">
                    <label for="pp-nachname"><?php echo esc_html($i18n->t('Nachname')); ?> *</label>
                    <input type="text" id="pp-nachname" name="nachname" required
                           autocomplete="family-name" maxlength="100">
                </div>
            </div>

            <div class="pp-form-group">
                <label><?php echo esc_html($i18n->t('Geburtsdatum')); ?> *</label>
                <div class="pp-date-fields">
                    <input type="number" name="geburtsdatum_tag" placeholder="TT"
                           min="1" max="31" required autocomplete="bday-day" class="pp-date-day">
                    <span class="pp-date-sep">.</span>
                    <input type="number" name="geburtsdatum_monat" placeholder="MM"
                           min="1" max="12" required autocomplete="bday-month" class="pp-date-month">
                    <span class="pp-date-sep">.</span>
                    <input type="number" name="geburtsdatum_jahr" placeholder="JJJJ"
                           min="1900" max="<?php echo (int) date('Y'); ?>" required
                           autocomplete="bday-year" class="pp-date-year">
                </div>
            </div>

            <div class="pp-form-row pp-form-row-2">
                <div class="pp-form-group">
                    <label for="pp-telefon"><?php echo esc_html($i18n->t('Telefon')); ?> *</label>
                    <input type="tel" id="pp-telefon" name="telefon" required
                           autocomplete="tel" maxlength="30">
                </div>
                <div class="pp-form-group">
                    <label for="pp-email"><?php echo esc_html($i18n->t('E-Mail')); ?> *</label>
                    <input type="email" id="pp-email" name="email" required
                           autocomplete="email" maxlength="200">
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Submit-Button rendern
     * 
     * @param string $label Button-Text
     */
    public function renderSubmitButton(string $label = ''): string
    {
        if (empty($label)) {
            $label = $this->widget->getI18n()->t('Absenden');
        }

        return sprintf(
            '<div class="pp-form-group pp-submit-group">'
            . '<button type="submit" class="pp-btn pp-btn-primary pp-submit-btn">'
            . '<span class="pp-btn-text">%s</span>'
            . '<span class="pp-btn-loading" style="display:none;">%s</span>'
            . '</button>'
            . '</div>',
            esc_html($label),
            esc_html($this->widget->getI18n()->t('Wird gesendet...'))
        );
    }

    /**
     * Datei-Upload-Feld rendern
     * 
     * @param string $name  Feldname
     * @param string $label Label
     * @param bool   $required Pflichtfeld
     */
    public function renderFileUpload(string $name, string $label, bool $required = false): string
    {
        $i18n     = $this->widget->getI18n();
        $reqAttr  = $required ? 'required' : '';
        $reqLabel = $required ? ' *' : '';

        ob_start();
        ?>
        <div class="pp-form-group pp-file-upload-group" data-field="<?php echo esc_attr($name); ?>">
            <label><?php echo esc_html($label . $reqLabel); ?></label>
            <div class="pp-file-upload-wrapper" data-field="<?php echo esc_attr($name); ?>">
                <div class="pp-file-dropzone" <?php echo $reqAttr; ?>>
                    <span class="pp-file-icon">📎</span>
                    <span class="pp-file-text">
                        <?php echo esc_html($i18n->t('Datei hierher ziehen oder klicken')); ?>
                    </span>
                    <span class="pp-file-hint">
                        <?php echo esc_html($i18n->t('JPG, PNG, PDF – max. 10 MB')); ?>
                    </span>
                    <input type="file" name="<?php echo esc_attr($name); ?>" class="pp-file-input"
                           accept="image/*,.pdf" style="display:none;">
                </div>
                <div class="pp-file-preview" style="display:none;">
                    <span class="pp-file-preview-name"></span>
                    <button type="button" class="pp-file-remove" title="<?php echo esc_attr($i18n->t('Entfernen')); ?>">✕</button>
                </div>
                <div class="pp-file-progress" style="display:none;">
                    <div class="pp-file-progress-bar"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Medikamenten-Eingabefeld mit Autocomplete
     * 
     * @param int $index Medikament-Index (0-2)
     */
    public function renderMedicationField(int $index): string
    {
        $i18n  = $this->widget->getI18n();
        $req   = $index === 0 ? 'required' : '';
        $label = sprintf($i18n->t('Medikament %d'), $index + 1);
        if ($index === 0) {
            $label .= ' *';
        }

        ob_start();
        ?>
        <div class="pp-medication-row" data-index="<?php echo $index; ?>">
            <div class="pp-form-group pp-medication-input-wrapper">
                <label><?php echo esc_html($label); ?></label>
                <input type="text" name="medikamente[<?php echo $index; ?>]"
                       class="pp-medication-search" <?php echo $req; ?>
                       placeholder="<?php echo esc_attr($i18n->t('Medikamentenname eingeben...')); ?>"
                       autocomplete="off" maxlength="200">
                <div class="pp-medication-suggestions" style="display:none;"></div>
            </div>
            <div class="pp-form-group pp-medication-art">
                <label><?php echo esc_html($i18n->t('Art')); ?></label>
                <select name="medikament_art[<?php echo $index; ?>]">
                    <option value="augentropfen"><?php echo esc_html($i18n->t('Augentropfen')); ?></option>
                    <option value="augensalbe"><?php echo esc_html($i18n->t('Augensalbe')); ?></option>
                    <option value="tabletten"><?php echo esc_html($i18n->t('Tabletten')); ?></option>
                    <option value="sonstiges"><?php echo esc_html($i18n->t('Sonstiges')); ?></option>
                </select>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Termingrund-Optionen aus Service-Config holen
     *
     * @return array [['value' => 'vorsorge', 'label' => 'Vorsorgeuntersuchung'], ...]
     */
    public function getTerminGrundOptions(): array
    {
        // Service-Config aus Template-Kontext holen
        $config = $this->get('service_config', []);

        $grundOptions = $config['grund_options'] ?? '';

        // Default-Optionen
        if (empty($grundOptions)) {
            $grundOptions = "vorsorge|Vorsorgeuntersuchung\nkontrolle|Kontrolltermin\nakut|Akute Beschwerden\nop_vorbereitung|OP-Vorbereitung\nnachsorge|Nachsorge\nsonstiges|Sonstiges";
        }

        $options = [];
        $lines = array_filter(array_map('trim', explode("\n", $grundOptions)));

        foreach ($lines as $line) {
            if (strpos($line, '|') !== false) {
                list($value, $label) = explode('|', $line, 2);
                $options[] = [
                    'value' => trim($value),
                    'label' => trim($label)
                ];
            }
        }

        return $options;
    }
}
