<?php
/**
 * Widget Formular – Dokument-Upload
 *
 * Patienten können Befunde, Bilder oder andere Dokumente hochladen.
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
?>

<form class="pp-service-form" data-service="dokument" novalidate>

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">📎 <?php echo esc_html($renderer->t('Dokument senden')); ?></h4>
    <p style="color: var(--pp-text); font-size: var(--pp-text-base); margin: 0 0 20px; line-height: 1.5;">
        <?php echo esc_html($renderer->t('Senden Sie uns Befunde, Bilder oder andere Dokumente.')); ?>
    </p>

    <?php echo $renderer->renderPatientFields(); ?>

    <div class="pp-section-header"><?php echo esc_html($renderer->t('Dokument')); ?></div>

    <div class="pp-field-group">
        <label for="pp-dok-art"><?php echo esc_html($renderer->t('Art des Dokuments')); ?></label>
        <select id="pp-dok-art" name="dokument_art">
            <option value="befund"><?php echo esc_html($renderer->t('Befund / Arztbrief')); ?></option>
            <option value="bild"><?php echo esc_html($renderer->t('Foto / Bild')); ?></option>
            <option value="medikamentenplan"><?php echo esc_html($renderer->t('Medikamentenplan')); ?></option>
            <option value="sonstiges"><?php echo esc_html($renderer->t('Sonstiges')); ?></option>
        </select>
    </div>

    <?php echo $renderer->renderFileUpload('dokument_datei', $renderer->t('Datei hochladen'), true); ?>

    <div class="pp-field-group">
        <label for="pp-dok-hinweis"><?php echo esc_html($renderer->t('Beschreibung')); ?></label>
        <textarea id="pp-dok-hinweis" name="dokument_hinweis" rows="3" maxlength="1000"
                  placeholder="<?php echo esc_attr($renderer->t('Was enthält das Dokument?')); ?>"></textarea>
    </div>

    <?php echo $renderer->renderSpamFields(); ?>
    <?php echo $renderer->renderNonce(); ?>
    <?php echo $renderer->renderLocationField(); ?>
    <input type="hidden" name="service" value="dokument">

    <?php echo $renderer->renderDsgvoConsent(); ?>
    <?php echo $renderer->renderSubmitButton($renderer->t('Dokument senden')); ?>

</form>
