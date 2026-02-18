<?php
/**
 * Widget Partial – Erfolgs-Anzeige
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
?>

<div class="pp-success-step">
    <div class="pp-success-icon">✅</div>
    <h3 class="pp-success-title"><?php echo esc_html($renderer->t('Vielen Dank!')); ?></h3>
    <p class="pp-success-text">
        <?php echo esc_html($renderer->t('Ihre Anfrage wurde erfolgreich gesendet. Wir melden uns schnellstmöglich bei Ihnen.')); ?>
    </p>

    <div class="pp-downloads-area" style="display: none;">
        <?php // Downloads werden per JS eingefügt ?>
    </div>

    <button type="button" class="pp-continue-btn" data-action="reset" style="margin-top: 20px;">
        <?php echo esc_html($renderer->t('Neue Anfrage stellen')); ?>
    </button>
</div>
