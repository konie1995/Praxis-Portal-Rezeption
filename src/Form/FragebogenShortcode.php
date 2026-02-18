<?php
/**
 * FragebogenShortcode – [pp_fragebogen] Shortcode
 *
 * Rendert den Anamnesebogen auf einer eigenständigen Seite.
 * Der Standort wird per Attribut angegeben:
 *
 *   [pp_fragebogen standort="berlin"]
 *
 * Hat ein Standort mehrere aktive Fragebögen, sieht der Patient
 * zuerst eine Auswahl. Bei nur einem aktiven Bogen wird dieser
 * direkt angezeigt.
 *
 * Die Zuordnung Fragebogen → Standort erfolgt in der Admin-Oberfläche
 * unter Praxis-Portal → Fragebögen (Checkbox-Matrix).
 *
 * @package PraxisPortal\Form
 * @since   4.2.7
 */

declare(strict_types=1);

namespace PraxisPortal\Form;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\FormLocationRepository;
use PraxisPortal\Database\Repository\FormRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class FragebogenShortcode
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    private function t(string $text): string
    {
        return I18n::translate($text);
    }

    /**
     * CSS + JS für Fragebögen laden
     */
    private function enqueueAssets(): void
    {
        if (wp_style_is('pp-fragebogen', 'enqueued')) {
            return;
        }

        wp_enqueue_style(
            'pp-fragebogen',
            PP_PLUGIN_URL . 'assets/css/fragebogen.css',
            [],
            PP_VERSION
        );

        wp_enqueue_script(
            'pp-fragebogen',
            PP_PLUGIN_URL . 'assets/js/fragebogen.js',
            [],
            PP_VERSION,
            true // In Footer laden
        );
    }

    /**
     * Shortcode-Callback
     *
     * @param array|string $atts  Shortcode-Attribute
     * @return string HTML
     */
    public function render($atts = []): string
    {
        // Assets einbinden (nur wenn Shortcode tatsächlich genutzt wird)
        $this->enqueueAssets();

        $atts = shortcode_atts([
            'standort' => '',
            'location' => '',  // Alias (English)
        ], $atts, 'pp_fragebogen');

        $slug = $atts['standort'] ?: $atts['location'];

        if (empty($slug)) {
            return $this->renderError($this->t('Bitte Standort angeben.') . ' '
                . '<code>[pp_fragebogen standort="..."]</code>');
        }

        // Standort via Slug auflösen
        $locationRepo = $this->container->get(LocationRepository::class);
        $location     = $locationRepo->findBySlug($slug);

        if (!$location) {
            return $this->renderError(
                $this->t('Standort nicht gefunden') . ': <code>' . esc_html($slug) . '</code>'
            );
        }

        $locationId = (int) $location['id'];

        // ── POST-Verarbeitung ──────────────────────────────────
        if (!empty($_POST['pp_fragebogen_submit'])) {
            return $this->handleSubmit($location);
        }

        // Aktive Fragebögen für diesen Standort
        $formLocRepo = $this->container->get(FormLocationRepository::class);
        $assignments = $formLocRepo->getActiveByLocationId($locationId);

        if (empty($assignments)) {
            return $this->renderError($this->t('Für diesen Standort sind keine Fragebögen aktiviert.'));
        }

        // Formulare laden
        $formRepo = $this->container->get(FormRepository::class);
        $forms    = [];
        foreach ($assignments as $a) {
            $form = $formRepo->findById($a['form_id']);
            if ($form) {
                // Normalize: config may be nested
                if (isset($form['config_json'])) {
                    $config = json_decode($form['config_json'], true) ?: [];
                } else {
                    $config = $form;
                }
                $forms[] = [
                    'id'          => $a['form_id'],
                    'name'        => $config['name'] ?? $a['form_id'],
                    'description' => $config['description'] ?? '',
                    'sort_order'  => (int) $a['sort_order'],
                ];
            }
        }

        if (empty($forms)) {
            return $this->renderError($this->t('Keine gültigen Fragebögen gefunden.'));
        }

        // Wurde ein bestimmter Bogen gewählt? (via URL-Parameter)
        $selectedFormId = sanitize_key($_GET['bogen'] ?? '');

        // Nur ein Bogen → direkt rendern
        if (count($forms) === 1) {
            $selectedFormId = $forms[0]['id'];
        }

        // Bogen ausgewählt → rendern
        if ($selectedFormId) {
            // Prüfen, ob der Bogen zu den zugewiesenen gehört
            $validIds = array_column($forms, 'id');
            if (!in_array($selectedFormId, $validIds, true)) {
                return $this->renderError($this->t('Ungültiger Fragebogen.'));
            }

            return $this->renderForm($selectedFormId, $location, $forms);
        }

        // Mehrere Bögen → Auswahl anzeigen
        return $this->renderSelection($forms, $location);
    }

    /**
     * Fragebogen-Auswahl (wenn mehrere aktiv)
     */
    private function renderSelection(array $forms, array $location): string
    {
        $html = '<div class="pp-fragebogen-selection" style="max-width:700px;margin:0 auto;">';
        $html .= '<h2>' . esc_html($this->t('Fragebogen auswählen')) . '</h2>';
        $html .= '<p>' . esc_html(sprintf(
            $this->t('Bitte wählen Sie den passenden Fragebogen für %s:'),
            $location['name']
        )) . '</p>';

        $html .= '<div style="display:grid;gap:12px;margin-top:20px;">';

        foreach ($forms as $form) {
            $url = add_query_arg('bogen', $form['id']);
            $html .= '<a href="' . esc_url($url) . '" '
                . 'style="display:block;padding:20px;background:#fff;border:2px solid #ddd;border-radius:8px;'
                . 'text-decoration:none;color:#333;transition:border-color .2s,box-shadow .2s;" '
                . 'onmouseover="this.style.borderColor=\'#0073aa\';this.style.boxShadow=\'0 2px 8px rgba(0,0,0,.1)\';" '
                . 'onmouseout="this.style.borderColor=\'#ddd\';this.style.boxShadow=\'none\';">';
            $html .= '<strong style="font-size:16px;">' . esc_html($form['name']) . '</strong>';
            if (!empty($form['description'])) {
                $html .= '<br><span style="color:#666;font-size:14px;">'
                    . esc_html($form['description']) . '</span>';
            }
            $html .= '</a>';
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Formular rendern (vollständig mit Sektionen)
     */
    private function renderForm(string $formId, array $location, array $allForms): string
    {
        $formLoader = $this->container->get(FormLoader::class);
        $formHtml   = $formLoader->renderForm($formId);

        if (empty($formHtml)) {
            return $this->renderError($this->t('Formular konnte nicht geladen werden.'));
        }

        $html = '<div class="pp-fragebogen-wrapper" data-location-id="' . esc_attr($location['id']) . '"'
            . ' data-location-slug="' . esc_attr($location['slug']) . '"'
            . ' data-form-id="' . esc_attr($formId) . '">';

        // Zurück-Link bei mehreren Bögen
        if (count($allForms) > 1) {
            $backUrl = remove_query_arg('bogen');
            $html .= '<p><a href="' . esc_url($backUrl) . '">'
                . '← ' . esc_html($this->t('Andere Fragebögen'))
                . '</a></p>';
        }

        // Praxis-Info Header
        $html .= '<div class="pp-fragebogen-header">';
        $html .= '<strong>' . esc_html($location['name']) . '</strong>';
        if (!empty($location['strasse']) || !empty($location['ort'])) {
            $addr = trim(($location['strasse'] ?? '') . ', ' . ($location['plz'] ?? '') . ' ' . ($location['ort'] ?? ''), ', ');
            $html .= '<br><span style="color:#666;">' . esc_html($addr) . '</span>';
        }
        $html .= '</div>';

        // Formular mit Submit
        $html .= '<form method="post" class="pp-fragebogen-form" enctype="multipart/form-data">';
        $html .= wp_nonce_field('pp_fragebogen_submit', 'pp_fragebogen_nonce', true, false);
        $html .= '<input type="hidden" name="pp_form_id" value="' . esc_attr($formId) . '">';
        $html .= '<input type="hidden" name="pp_location_id" value="' . esc_attr($location['id']) . '">';
        $html .= '<input type="hidden" name="pp_location_uuid" value="' . esc_attr($location['uuid'] ?? '') . '">';
        $html .= '<input type="hidden" name="pp_fragebogen_submit" value="1">';

        $html .= $formHtml;        // Submit
        $html .= '<button type="submit" '
            . 'class="pp-fragebogen-submit" '
            . 'data-loading-text="' . esc_attr($this->t('Wird gesendet…')) . '">'
            . esc_html($this->t('Fragebogen absenden'))
            . '</button>';

        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    /**
     * POST-Verarbeitung: Fragebogen speichern
     */
    private function handleSubmit(array $location): string
    {
        // Nonce prüfen
        if (!wp_verify_nonce($_POST['pp_fragebogen_nonce'] ?? '', 'pp_fragebogen_submit')) {
            return $this->renderError($this->t('Sicherheitsprüfung fehlgeschlagen. Bitte versuchen Sie es erneut.'));
        }

        // DSGVO-Einwilligung (now handled by form field datenschutz_einwilligung)
        // Field is in pp_data array
        if (empty($_POST['pp_data']['datenschutz_einwilligung'])) {
            return $this->renderError($this->t('Bitte stimmen Sie der Datenschutzerklärung zu.'));
        }

        // Unterschrift prüfen
        if (empty($_POST['pp_data']['unterschrift'])) {
            return $this->renderError($this->t('Bitte unterschreiben Sie den Fragebogen.'));
        }

        $formId     = sanitize_key($_POST['pp_form_id'] ?? '');
        $locationId = (int) ($_POST['pp_location_id'] ?? 0);

        if (empty($formId) || $locationId < 1) {
            return $this->renderError($this->t('Ungültige Formulardaten.'));
        }

        // Formulardaten sammeln
        $rawData = $_POST['pp_data'] ?? [];
        if (!is_array($rawData)) {
            return $this->renderError($this->t('Ungültige Formulardaten.'));
        }

        // Felder sanitizen
        $formData = [];
        foreach ($rawData as $key => $value) {
            $cleanKey = sanitize_key($key);
            if (is_array($value)) {
                $formData[$cleanKey] = array_map('sanitize_text_field', $value);
            } else {
                $formData[$cleanKey] = sanitize_text_field($value);
            }
        }

        // Filter: Nur positive Antworten bei Erkrankungen speichern
        // Entferne "nein"-Antworten und leere Checkbox-Groups
        foreach ($formData as $key => $value) {
            // "nein"-Antworten nicht speichern (nur "ja" ist relevant)
            if ($value === 'nein') {
                unset($formData[$key]);
                continue;
            }

            // Leere Arrays (Checkbox-Groups ohne Auswahl) nicht speichern
            if (is_array($value) && empty($value)) {
                unset($formData[$key]);
                continue;
            }
        }

        // Formular-ID + Standort-Info hinzufügen
        $formData['_form_id']     = $formId;
        $formData['_form_source'] = 'fragebogen';

        // Meta-Daten
        $meta = [
            'location_id'   => $locationId,
            'location_uuid' => $location['uuid'] ?? '',
            'service_type'  => 'anamnese',
            'service_key'   => 'fragebogen_' . $formId,
            'source'        => 'fragebogen_shortcode',
        ];

        // Signatur (falls vorhanden)
        $signature = null;
        if (!empty($formData['unterschrift'])) {
            $signature = $formData['unterschrift'];
            unset($formData['unterschrift']);
        }

        // Speichern
        try {
            $repo   = $this->container->get(\PraxisPortal\Database\Repository\SubmissionRepository::class);
            $result = $repo->create($formData, $meta, $signature);

            if (!empty($result['id'])) {
                // Audit-Log
                if ($this->container->has(\PraxisPortal\Database\Repository\AuditRepository::class)) {
                    $audit = $this->container->get(\PraxisPortal\Database\Repository\AuditRepository::class);
                    $audit->log('fragebogen_submitted', (int) $result['id'], [
                        'form_id'     => $formId,
                        'location_id' => $locationId,
                    ]);
                }

                return '<div class="pp-fragebogen-success">'
                    . '<div class="pp-success-icon">✓</div>'
                    . '<h3>' . esc_html($this->t('Vielen Dank!')) . '</h3>'
                    . '<p>' . esc_html($this->t('Ihr Fragebogen wurde erfolgreich übermittelt und gespeichert.')) . '</p>'
                    . '<p>' . esc_html($this->t('Wir haben Ihre Angaben erhalten und werden diese vor Ihrem Termin sorgfältig prüfen.')) . '</p>'
                    . '<p class="pp-success-note">' . esc_html($this->t('Sie können dieses Fenster jetzt schließen.')) . '</p>'
                    . '</div>';
            }
        } catch (\Exception $e) {
            // Fehler nicht dem User zeigen (Sicherheit)
            // Aber produktiv loggen für Monitoring
            error_log('PraxisPortal Submission Error: ' . $e->getMessage());
        }

        return $this->renderError(
            $this->t('Beim Speichern ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.')
        );
    }

    /**
     * Fehlermeldung
     */
    private function renderError(string $message): string
    {
        return '<div class="pp-fragebogen-error">'
            . $message . '</div>';
    }
}
