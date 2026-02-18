<?php
/**
 * Widget Step – Willkommen (v3-Stil)
 *
 * "Sind Sie bereits Patient bei uns?" → Ja / Nein
 * Klick auf Ja/Nein navigiert DIREKT zum nächsten Step (kein extra Weiter-Button).
 * JS liest data-patient-status und entscheidet:
 *  - Multistandort → Location-Step
 *  - Single        → Services-Step
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 * @updated 4.2.6 – v3-Flow: Direkt-Navigation ohne Weiter-Button
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */

$praxisName  = $renderer->get('praxis_name', get_bloginfo('name'));
$welcomeText = $renderer->get('welcome_text', '');
?>

<div class="pp-welcome-content" style="text-align: center; padding: 20px 10px;">

    <h4 style="margin: 0 0 6px; font-size: 17px; font-weight: 600;">
        <?php echo esc_html(sprintf($renderer->t('Willkommen bei %s'), $praxisName)); ?>
    </h4>

    <?php if (!empty($welcomeText)): ?>
        <p class="pp-welcome-text" style="color: var(--pp-text-light); margin: 0 0 24px; font-size: 14px;">
            <?php echo wp_kses_post($welcomeText); ?>
        </p>
    <?php else: ?>
        <p class="pp-welcome-text" style="color: var(--pp-text-light); margin: 0 0 24px; font-size: 14px;">
            <?php echo esc_html($renderer->t('Wie können wir Ihnen helfen?')); ?>
        </p>
    <?php endif; ?>

    <p style="font-weight: 600; font-size: 15px; margin: 0 0 16px;">
        <?php echo esc_html($renderer->t('Sind Sie bereits Patient bei uns?')); ?>
    </p>

    <div class="pp-patient-choice" style="display: flex; gap: 12px; justify-content: center;">
        <button type="button" class="pp-patient-btn" data-patient-status="bestandspatient">
            ✓ <?php echo esc_html($renderer->t('Ja, bin Patient')); ?>
        </button>
        <button type="button" class="pp-patient-btn" data-patient-status="neupatient">
            <?php echo esc_html($renderer->t('Nein, Neupatient')); ?>
        </button>
    </div>

</div>
