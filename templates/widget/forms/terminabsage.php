<?php
/**
 * Widget Formular – Terminabsage
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
?>

<form class="pp-service-form" data-service="terminabsage" novalidate>

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">❌ <?php echo esc_html($renderer->t('Termin absagen')); ?></h4>
    <p style="color: var(--pp-text); font-size: var(--pp-text-base); margin: 0 0 20px; line-height: 1.5;">
        <?php echo esc_html($renderer->t('Bitte sagen Sie Ihren Termin rechtzeitig ab, damit andere Patienten nachrücken können.')); ?>
    </p>

    <?php echo $renderer->renderPatientFields(); ?>

    <div class="pp-section-header"><?php echo esc_html($renderer->t('Termindetails')); ?></div>

    <div class="pp-field-group">
        <label for="pp-absage-datum"><?php echo esc_html($renderer->t('Datum des Termins')); ?> *</label>
        <input type="date" id="pp-absage-datum" name="absage_datum" required>
    </div>

    <div class="pp-field-group">
        <label for="pp-absage-uhrzeit"><?php echo esc_html($renderer->t('Uhrzeit (ca.)')); ?></label>
        <input type="text" id="pp-absage-uhrzeit" name="absage_uhrzeit" maxlength="10"
               placeholder="<?php echo esc_attr($renderer->t('z.B. 10:30')); ?>">
    </div>

    <div class="pp-field-group">
        <label for="pp-absage-grund"><?php echo esc_html($renderer->t('Grund')); ?></label>
        <textarea id="pp-absage-grund" name="absage_grund" rows="2" maxlength="500"
                  placeholder="<?php echo esc_attr($renderer->t('Optional')); ?>"></textarea>
    </div>

    <div class="pp-field-group">
        <label><?php echo esc_html($renderer->t('Neuen Termin gewünscht?')); ?></label>
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="absage_neuer_termin" value="ja">
                <span><?php echo esc_html($renderer->t('Ja, bitte neuen Termin')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="absage_neuer_termin" value="nein" checked>
                <span><?php echo esc_html($renderer->t('Nein')); ?></span>
            </label>
        </div>
    </div>

    <?php echo $renderer->renderSpamFields(); ?>
    <?php echo $renderer->renderNonce(); ?>
    <?php echo $renderer->renderLocationField(); ?>
    <input type="hidden" name="service" value="terminabsage">

    <?php echo $renderer->renderDsgvoConsent(); ?>
    <?php echo $renderer->renderSubmitButton($renderer->t('Absage senden')); ?>

</form>
