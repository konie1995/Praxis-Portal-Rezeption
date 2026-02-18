<?php
/**
 * Widget – Urlaubsmodus
 *
 * Zeigt eine Urlaubsnachricht anstelle des normalen Widgets.
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */

$praxisName   = $renderer->esc($renderer->get('praxis_name', get_bloginfo('name')), 'attr');
$vacationText = $renderer->get('vacation_text', '');
$vacationEnd  = $renderer->get('vacation_end', '');
?>

<?php echo $renderer->renderStyles(); ?>

<button id="pp-widget-trigger"
        type="button"
        aria-label="<?php echo esc_attr($renderer->t('Praxis-Portal')); ?>">
    <span class="pp-trigger-icon">✉</span>
</button>

<div id="pp-widget-container" role="dialog" aria-label="<?php echo esc_attr($praxisName); ?>">

    <div class="pp-widget-header">
        <h3><?php echo esc_html($praxisName); ?></h3>
        <button type="button" class="pp-close-btn" aria-label="<?php echo esc_attr($renderer->t('Schließen')); ?>">✕</button>
    </div>

    <div class="pp-widget-body">
        <div class="pp-vacation-mode">
            <div class="pp-vacation-icon">🏖️</div>

            <h3><?php echo esc_html($renderer->t('Urlaubsmodus aktiv')); ?></h3>

            <?php if (!empty($vacationText)): ?>
                <p class="pp-vacation-text">
                    <?php echo wp_kses_post($vacationText); ?>
                </p>
            <?php else: ?>
                <p class="pp-vacation-text">
                    <?php echo esc_html($renderer->t('Die Praxis befindet sich derzeit im Urlaub. Online-Anfragen sind aktuell nicht möglich.')); ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($vacationEnd)): ?>
                <p class="pp-vacation-text" style="margin-top: 12px; font-weight: 600;">
                    <?php echo esc_html($renderer->t('Voraussichtlich wieder erreichbar ab:')); ?>
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($vacationEnd))); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="pp-widget-footer">
        <a href="https://praxis-portal.de" target="_blank" rel="noopener">Praxis-Portal</a>
    </div>

</div>
