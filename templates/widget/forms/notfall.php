<?php
/**
 * Widget – Notfall (konfigurierbar)
 *
 * Alle Inhalte sind per custom_fields im Admin-Bereich einstellbar.
 * Multistandort: Jeder Standort kann eigene Notfall-Daten haben.
 *
 * Konfigurierbare Felder (custom_fields JSON):
 *   show_112                  (bool)   – 112-Notruf-Box anzeigen (Standard: ja)
 *   emergency_text            (string) – Eigener Hinweistext oben
 *   practice_emergency_label  (string) – Beschriftung der Praxis-Nummer
 *   show_bereitschaftsdienst  (bool)   – 116 117 anzeigen (Standard: ja)
 *   show_giftnotruf           (bool)   – Giftnotruf anzeigen (Standard: ja)
 *   show_telefonseelsorge     (bool)   – Telefonseelsorge anzeigen (Standard: ja)
 *   custom_numbers            (array)  – Eigene Nummern [{label, phone}]
 *   additional_info           (string) – Zusatzinfo-Text unten
 *
 * @package PraxisPortal\Widget
 * @since   4.2.9
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
    'show_giftnotruf'          => true,
    'show_telefonseelsorge'    => true,
    'custom_numbers'           => [],
    'additional_info'          => '',
]);

// ── Praxis-Daten aus Standort-Kontext ──
$praxisName  = $renderer->get('practice_name', get_option('pp_praxis_name', get_bloginfo('name')));
$praxisPhone = $renderer->get('phone_emergency', $renderer->get('phone', get_option('pp_praxis_telefon', '')));
$praxisAddr  = get_option('pp_praxis_anschrift', '');

// Eigenes Label oder Standard
$praxisLabel = !empty($cfg['practice_emergency_label'])
    ? $cfg['practice_emergency_label']
    : $renderer->t('Praxis-Notfallnummer');

// Prüfen ob "Weitere Nummern"-Box nötig ist
$hasStandardNumbers = $cfg['show_bereitschaftsdienst'] || $cfg['show_giftnotruf'] || $cfg['show_telefonseelsorge'];
$hasCustomNumbers   = !empty($cfg['custom_numbers']) && is_array($cfg['custom_numbers']);
$showNumbersBox     = $hasStandardNumbers || $hasCustomNumbers;
?>

<div class="pp-service-form" data-service="notfall" style="padding: 0;">

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">🚨 <?php echo esc_html($renderer->t('Notfall')); ?></h4>

    <?php // ── Eigener Hinweistext (optional) ── ?>
    <?php if (!empty($cfg['emergency_text'])): ?>
        <p style="color: var(--pp-text-light, #64748b); font-size: 13px; margin: 0 0 16px;">
            <?php echo esc_html($cfg['emergency_text']); ?>
        </p>
    <?php endif; ?>

    <?php // ── 112-Notruf-Box ── ?>
    <?php if ($cfg['show_112']): ?>
        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
            <p style="margin: 0 0 8px; font-weight: 600; color: #991b1b; font-size: 14px;">
                <?php echo esc_html($renderer->t('Bei lebensbedrohlichen Notfällen rufen Sie bitte sofort den Rettungsdienst:')); ?>
            </p>
            <a href="tel:112" style="display: inline-flex; align-items: center; gap: 8px; font-size: 28px; font-weight: 700; color: #dc2626; text-decoration: none;">
                📞 112
            </a>
        </div>
    <?php endif; ?>

    <?php // ── Praxis-Notfallnummer ── ?>
    <?php if (!empty($praxisPhone)): ?>
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 12px;">
            <p style="margin: 0 0 4px; font-size: 13px; color: var(--pp-text-light, #64748b);">
                <?php echo esc_html($praxisLabel); ?>
            </p>
            <p style="margin: 0 0 2px; font-weight: 600; font-size: 14px;">
                <?php echo esc_html($praxisName); ?>
            </p>
            <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $praxisPhone)); ?>"
               style="display: inline-flex; align-items: center; gap: 6px; font-size: 20px; font-weight: 600; color: var(--pp-primary, #2563eb); text-decoration: none; margin-top: 4px;">
                📞 <?php echo esc_html($praxisPhone); ?>
            </a>
            <?php if (!empty($praxisAddr)): ?>
                <p style="margin: 8px 0 0; font-size: 13px; color: var(--pp-text-light, #64748b);">
                    📍 <?php echo esc_html($praxisAddr); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php // ── Weitere Nummern (konfigurierbar) ── ?>
    <?php if ($showNumbersBox): ?>
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 12px;">
            <p style="margin: 0 0 8px; font-size: 13px; font-weight: 600;">
                <?php echo esc_html($renderer->t('Weitere Notfallnummern')); ?>
            </p>
            <div style="display: flex; flex-direction: column; gap: 6px; font-size: 13px;">

                <?php if ($cfg['show_bereitschaftsdienst']): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span><?php echo esc_html($renderer->t('Ärztlicher Bereitschaftsdienst')); ?></span>
                        <a href="tel:116117" style="font-weight: 600; color: var(--pp-primary, #2563eb); text-decoration: none; white-space: nowrap;">116 117</a>
                    </div>
                <?php endif; ?>

                <?php if ($cfg['show_giftnotruf']): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span><?php echo esc_html($renderer->t('Giftnotruf')); ?></span>
                        <a href="tel:03019240" style="font-weight: 600; color: var(--pp-primary, #2563eb); text-decoration: none; white-space: nowrap;">030 19240</a>
                    </div>
                <?php endif; ?>

                <?php if ($cfg['show_telefonseelsorge']): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span><?php echo esc_html($renderer->t('Telefonseelsorge')); ?></span>
                        <a href="tel:08001110111" style="font-weight: 600; color: var(--pp-primary, #2563eb); text-decoration: none; white-space: nowrap;">0800 111 0 111</a>
                    </div>
                <?php endif; ?>

                <?php // ── Eigene Nummern (Admin-konfiguriert) ── ?>
                <?php if ($hasCustomNumbers): ?>
                    <?php foreach ($cfg['custom_numbers'] as $entry):
                        $numLabel = sanitize_text_field($entry['label'] ?? '');
                        $numPhone = sanitize_text_field($entry['phone'] ?? '');
                        if (empty($numLabel) || empty($numPhone)) continue;
                        $numPhoneClean = preg_replace('/[^0-9+]/', '', $numPhone);
                    ?>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span><?php echo esc_html($numLabel); ?></span>
                            <a href="tel:<?php echo esc_attr($numPhoneClean); ?>"
                               style="font-weight: 600; color: var(--pp-primary, #2563eb); text-decoration: none; white-space: nowrap;">
                                <?php echo esc_html($numPhone); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>
    <?php endif; ?>

    <?php // ── Zusatzinfo (optional) ── ?>
    <?php if (!empty($cfg['additional_info'])): ?>
        <div style="background: #f8fafc; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: var(--pp-text-light, #64748b); line-height: 1.5;">
            <?php echo nl2br(esc_html($cfg['additional_info'])); ?>
        </div>
    <?php endif; ?>

</div>
