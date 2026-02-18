<?php
/**
 * Widget Step – Standort-Auswahl
 *
 * Wird nur bei Multi-Location angezeigt.
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */

$locations = $renderer->get('locations', []);
?>

<h4 style="margin: 0 0 12px; font-size: 15px;"><?php echo esc_html($renderer->t('Standort wählen')); ?></h4>
<p style="color: var(--pp-text-light); font-size: 13px; margin: 0 0 16px;">
    <?php echo esc_html($renderer->t('Bitte wählen Sie den gewünschten Standort:')); ?>
</p>

<div class="pp-location-list">
    <?php foreach ($locations as $loc): ?>
        <div class="pp-location-option"
             data-location-uuid="<?php echo esc_attr($loc['uuid'] ?? ''); ?>"
             role="button"
             tabindex="0">
            <div class="pp-location-icon">📍</div>
            <div>
                <div class="pp-location-name"><?php echo esc_html($loc['name'] ?? ''); ?></div>
                <?php if (!empty($loc['address'])): ?>
                    <div class="pp-location-address"><?php echo esc_html($loc['address']); ?></div>
                <?php endif; ?>
                <?php if (!empty($loc['city'])): ?>
                    <div class="pp-location-address"><?php echo esc_html($loc['zip'] ?? ''); ?> <?php echo esc_html($loc['city']); ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
