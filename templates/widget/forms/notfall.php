<?php
/**
 * Widget – Notfall (Karten-Layout, v3-Stil)
 *
 * Konfigurierbare Felder (custom_fields JSON):
 *   show_112                  (bool)   – 112-Notruf-Karte anzeigen (Standard: ja)
 *   emergency_text            (string) – Eigener Hinweistext oben
 *   practice_emergency_label  (string) – Beschriftung der Praxis-Nummer
 *   show_bereitschaftsdienst  (bool)   – 116 117 anzeigen (Standard: ja)
 *   custom_numbers            (array)  – Eigene Nummern [{label, phone}]
 *   additional_info           (string) – Zusatzinfo-Text unten
 *
 * @package PraxisPortal\Widget
 * @since   4.2.9
 * @updated 4.2.908 – v3-Karten-Layout
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
/** @var array $service_config  custom_fields aus dem Service (automatisch übergeben) */

// ── Konfiguration mit Defaults ──
$cfg = wp_parse_args($service_config ?? [], [
    'show_112'                 => true,
    'emergency_text'           => '',
    'practice_emergency_label' => '',
    'show_bereitschaftsdienst' => true,
    'custom_numbers'           => [],
    'additional_info'          => '',
]);

// ── Praxis-Daten aus Standort-Kontext ──
$praxisPhone = $renderer->get('phone_emergency', $renderer->get('phone', get_option('pp_praxis_telefon', '')));

$praxisLabel = !empty($cfg['practice_emergency_label'])
    ? $cfg['practice_emergency_label']
    : $renderer->t('Praxis-Notfallnummer');

$hasCustomNumbers = !empty($cfg['custom_numbers']) && is_array($cfg['custom_numbers']);
?>

<div class="pp-service-form" data-service="notfall" style="padding: 0;">

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">
        🚨 <?php echo esc_html($renderer->t('Notfall - Hilfe')); ?>
    </h4>

    <?php if (!empty($cfg['emergency_text'])): ?>
        <p style="color: var(--pp-text-light, #64748b); font-size: 13px; margin: 0 0 12px;">
            <?php echo esc_html($cfg['emergency_text']); ?>
        </p>
    <?php endif; ?>

    <div class="pp-notfall-options">

        <?php // ── Praxis-Telefon ── ?>
        <?php if (!empty($praxisPhone)): ?>
            <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $praxisPhone)); ?>"
               class="pp-notfall-card pp-notfall-praxis">
                <div class="pp-notfall-icon">📞</div>
                <div class="pp-notfall-content">
                    <strong><?php echo esc_html($renderer->t('Während unserer Sprechzeiten')); ?></strong>
                    <span>
                        <?php echo esc_html($renderer->t('Rufen Sie uns direkt an unter:')); ?>
                        <b><?php echo esc_html($praxisPhone); ?></b>
                    </span>
                </div>
                <span class="pp-notfall-chevron">›</span>
            </a>
        <?php endif; ?>

        <?php // ── Ärztlicher Bereitschaftsdienst 116 117 ── ?>
        <?php if ($cfg['show_bereitschaftsdienst']): ?>
            <a href="tel:116117" class="pp-notfall-card pp-notfall-bereitschaft">
                <div class="pp-notfall-icon">ℹ️</div>
                <div class="pp-notfall-content">
                    <strong><?php echo esc_html($renderer->t('Außerhalb der Sprechzeiten')); ?></strong>
                    <span>
                        <?php echo esc_html($renderer->t('Der ärztliche Bereitschaftsdienst ist unter')); ?>
                        <b>116 117</b>
                        <?php echo esc_html($renderer->t('erreichbar.')); ?>
                    </span>
                </div>
                <span class="pp-notfall-chevron">›</span>
            </a>
        <?php endif; ?>

        <?php // ── Eigene Nummern (Admin-konfiguriert) ── ?>
        <?php if ($hasCustomNumbers): ?>
            <?php foreach ($cfg['custom_numbers'] as $entry):
                $numLabel = sanitize_text_field($entry['label'] ?? '');
                $numPhone = sanitize_text_field($entry['phone'] ?? '');
                if (empty($numLabel) || empty($numPhone)) continue;
                $numPhoneClean = preg_replace('/[^0-9+]/', '', $numPhone);
            ?>
                <a href="tel:<?php echo esc_attr($numPhoneClean); ?>"
                   class="pp-notfall-card pp-notfall-bereitschaft">
                    <div class="pp-notfall-icon">📞</div>
                    <div class="pp-notfall-content">
                        <strong><?php echo esc_html($numLabel); ?></strong>
                        <span><b><?php echo esc_html($numPhone); ?></b></span>
                    </div>
                    <span class="pp-notfall-chevron">›</span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php // ── 112 Notruf ── ?>
        <?php if ($cfg['show_112']): ?>
            <a href="tel:112" class="pp-notfall-card pp-notfall-notruf">
                <div class="pp-notfall-icon">🚨</div>
                <div class="pp-notfall-content">
                    <strong><?php echo esc_html($renderer->t('Notfall')); ?></strong>
                    <span>
                        <?php echo esc_html($renderer->t('In lebensbedrohlichen Notfällen wählen Sie bitte die')); ?>
                        <b>112</b>
                    </span>
                </div>
                <span class="pp-notfall-chevron">›</span>
            </a>
        <?php endif; ?>

    </div>

    <?php if (!empty($cfg['additional_info'])): ?>
        <p style="font-size: 13px; color: var(--pp-text-light, #64748b); line-height: 1.5; margin-top: 12px;">
            <?php echo nl2br(esc_html($cfg['additional_info'])); ?>
        </p>
    <?php endif; ?>

</div>
