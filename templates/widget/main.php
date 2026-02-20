<?php
/**
 * Widget – Haupt-Template
 *
 * Container-Struktur mit Header, Progress-Bar, Steps und Footer.
 * Wird von WidgetRenderer::renderMainWidget() geladen.
 *
 * Verfügbare Variablen (via extract):
 *   $renderer       – WidgetRenderer
 *   $widget         – Widget
 *   $location_uuid  – Aktueller Standort
 *   $locations      – Alle Standorte
 *   $services       – Aktive Services
 *   $settings       – Widget-Einstellungen
 *   $is_multisite   – Multi-Location aktiv
 *   $praxis_name    – Praxis-Name
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
/** @var \PraxisPortal\Widget\Widget $widget */

$praxisName   = $renderer->esc($renderer->get('praxis_name', get_bloginfo('name')), 'attr');
$isMultisite  = (bool) $renderer->get('is_multisite', false);
$locations    = $renderer->get('locations', []);
$services     = $renderer->get('services', []);
?>

<?php // ── Inline CSS-Variablen ── ?>
<?php echo $renderer->renderStyles(); ?>

<?php // ── Trigger-Button (v3-Stil: Pill mit Label) ── ?>
<?php
$widgetPosition = $renderer->get('widget_position', 'right');
$widgetSubtitle = $renderer->get('widget_subtitle', $renderer->t('Nutzen Sie unseren'));
$widgetTitle    = $renderer->get('widget_title', $renderer->t('Online-Service'));
?>
<button id="pp-widget-trigger"
        type="button"
        class="pp-widget-<?php echo esc_attr($widgetPosition); ?>"
        aria-label="<?php echo esc_attr($renderer->t('Praxis-Portal öffnen')); ?>"
        title="<?php echo esc_attr($praxisName); ?>">
    <span class="pp-trigger-icon-box">✚</span>
    <span class="pp-trigger-label">
        <small><?php echo esc_html($widgetSubtitle); ?></small>
        <strong><?php echo esc_html($widgetTitle); ?></strong>
    </span>
</button>

<?php // ── Widget-Container ── ?>
<div id="pp-widget-container" role="dialog" aria-label="<?php echo esc_attr($praxisName); ?>"
     data-multisite="<?php echo ($isMultisite && count($locations) > 1) ? '1' : '0'; ?>">

    <?php // ── Header (v3-Stil: weiß mit Border) ── ?>
    <div class="pp-widget-header">
        <button type="button" class="pp-back-btn" style="display:none;" aria-label="<?php echo esc_attr($renderer->t('Zurück')); ?>">‹</button>
        <?php
        $logoUrl = $renderer->get('logo_url', '');
        if (!empty($logoUrl)): ?>
            <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php echo esc_attr($praxisName); ?>" class="pp-widget-logo">
        <?php endif; ?>
        <h3><?php echo esc_html($praxisName); ?></h3>
        <button type="button" class="pp-close-btn" aria-label="<?php echo esc_attr($renderer->t('Schließen')); ?>">✕</button>
    </div>

    <?php // ── Progress-Bar ── ?>
    <div class="pp-progress">
        <div class="pp-progress-bar" style="width: 0%;"></div>
    </div>

    <?php // ── Body (scrollbar) ── ?>
    <div class="pp-widget-body">

        <?php // ── Step 1: Willkommen / Patientenstatus (IMMER erster Step) ── ?>
        <div class="pp-step pp-step-active" data-step="welcome">
            <?php echo $renderer->renderStep('welcome'); ?>
        </div>

        <?php // ── Step 2: Standort-Auswahl (nur bei Multi-Location) ── ?>
        <?php if ($isMultisite && count($locations) > 1): ?>
            <div class="pp-step" data-step="location">
                <?php echo $renderer->renderStep('location'); ?>
            </div>
        <?php endif; ?>

        <?php // ── Step 3: Service-Auswahl ── ?>
        <div class="pp-step" data-step="services">
            <?php echo $renderer->renderStep('services'); ?>
        </div>

        <?php // ── Step 4: Formular ── ?>
        <div class="pp-step" data-step="form">
            <div class="pp-form-container">
                <?php // Wird dynamisch per JS befüllt ?>
            </div>
        </div>

        <?php // ── Step 5: Erfolg ── ?>
        <div class="pp-step" data-step="success">
            <?php echo $renderer->renderPartial('success'); ?>
        </div>

    </div>

    <?php // ── Alle Service-Formulare (versteckt, werden per JS aktiviert) ── ?>
    <div id="pp-form-templates" style="display: none;" aria-hidden="true">
        <?php echo $renderer->renderAllForms(); ?>
    </div>


</div>
