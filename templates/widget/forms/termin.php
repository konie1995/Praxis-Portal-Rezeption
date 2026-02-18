<?php
/**
 * Widget Formular – Terminanfrage
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
?>

<form class="pp-service-form" data-service="termin" novalidate>

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">📅 <?php echo esc_html($renderer->t('Termin anfragen')); ?></h4>
    <p style="color: var(--pp-text); font-size: var(--pp-text-base); margin: 0 0 20px; line-height: 1.5;">
        <?php echo esc_html($renderer->t('Bitte beachten Sie: Dies ist eine Terminanfrage, keine verbindliche Buchung.')); ?>
    </p>

    <?php echo $renderer->renderPatientFields(); ?>

    <div class="pp-section-header"><?php echo esc_html($renderer->t('Terminwunsch')); ?></div>

    <div class="pp-field-group">
        <label for="pp-termin-grund"><?php echo esc_html($renderer->t('Grund des Termins')); ?> *</label>
        <select id="pp-termin-grund" name="termin_grund" required>
            <option value=""><?php echo esc_html($renderer->t('Bitte wählen')); ?></option>
            <?php
            $grundOptions = $renderer->getTerminGrundOptions();
            foreach ($grundOptions as $option):
            ?>
                <option value="<?php echo esc_attr($option['value']); ?>">
                    <?php echo esc_html($option['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="pp-field-group">
        <label><?php echo esc_html($renderer->t('Bevorzugte Tageszeit')); ?></label>
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="termin_tageszeit" value="morgens">
                <span><?php echo esc_html($renderer->t('Morgens')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="termin_tageszeit" value="mittags">
                <span><?php echo esc_html($renderer->t('Mittags')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="termin_tageszeit" value="nachmittags">
                <span><?php echo esc_html($renderer->t('Nachmittags')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="termin_tageszeit" value="egal" checked>
                <span><?php echo esc_html($renderer->t('Egal')); ?></span>
            </label>
        </div>
    </div>

    <div class="pp-field-group">
        <label for="pp-termin-hinweis"><?php echo esc_html($renderer->t('Anmerkungen')); ?></label>
        <textarea id="pp-termin-hinweis" name="termin_hinweis" rows="2" maxlength="1000"
                  placeholder="<?php echo esc_attr($renderer->t('z.B. Beschwerden, Dringlichkeit')); ?>"></textarea>
    </div>

    <?php echo $renderer->renderSpamFields(); ?>
    <?php echo $renderer->renderNonce(); ?>
    <?php echo $renderer->renderLocationField(); ?>
    <input type="hidden" name="service" value="termin">

    <?php echo $renderer->renderDsgvoConsent(); ?>
    <?php echo $renderer->renderSubmitButton($renderer->t('Termin anfragen')); ?>

</form>
