<?php
/**
 * Widget Formular – Überweisungsanfrage
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
?>

<form class="pp-service-form" data-service="ueberweisung" novalidate>

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">📋 <?php echo esc_html($renderer->t('Überweisung anfordern')); ?></h4>
    <p style="color: var(--pp-text); font-size: var(--pp-text-base); margin: 0 0 20px; line-height: 1.5;">
        <?php echo esc_html($renderer->t('Bitte geben Sie an, zu welchem Facharzt Sie überwiesen werden möchten.')); ?>
    </p>

    <?php echo $renderer->renderPatientFields(); ?>

    <!-- Versicherung -->
    <div class="pp-section-header"><?php echo esc_html($renderer->t('Versicherung')); ?></div>

    <div class="pp-field-group">
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="versicherung" value="gesetzlich" required data-conditional-trigger>
                <span><?php echo esc_html($renderer->t('Gesetzlich')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="versicherung" value="privat" required data-conditional-trigger>
                <span><?php echo esc_html($renderer->t('Privat')); ?></span>
            </label>
        </div>
    </div>

    <!-- Conditional: Gesetzlich → Versichertennachweis -->
    <div class="pp-field-group pp-conditional" data-conditional-field="versicherung" data-conditional-value="gesetzlich" style="display:none;">
        <label><?php echo esc_html($renderer->t('Dürfen wir einen elektronischen Versichertennachweis anfordern?')); ?></label>
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="versichertennachweis" value="ja">
                <span><?php echo esc_html($renderer->t('Ja')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="versichertennachweis" value="nein">
                <span><?php echo esc_html($renderer->t('Nein')); ?></span>
            </label>
        </div>
    </div>

    <div class="pp-section-header"><?php echo esc_html($renderer->t('Überweisung')); ?></div>

    <div class="pp-field-group">
        <label for="pp-ueberw-fachrichtung"><?php echo esc_html($renderer->t('Fachrichtung')); ?> *</label>
        <select id="pp-ueberw-fachrichtung" name="fachrichtung" required>
            <option value=""><?php echo esc_html($renderer->t('Bitte wählen')); ?></option>
            <option value="augenarzt"><?php echo esc_html($renderer->t('Augenheilkunde')); ?></option>
            <option value="chirurgie"><?php echo esc_html($renderer->t('Chirurgie')); ?></option>
            <option value="dermatologie"><?php echo esc_html($renderer->t('Dermatologie (Haut)')); ?></option>
            <option value="gastroenterologie"><?php echo esc_html($renderer->t('Gastroenterologie (Magen/Darm)')); ?></option>
            <option value="gynaekologie"><?php echo esc_html($renderer->t('Gynäkologie')); ?></option>
            <option value="hno"><?php echo esc_html($renderer->t('HNO')); ?></option>
            <option value="kardiologie"><?php echo esc_html($renderer->t('Kardiologie (Herz)')); ?></option>
            <option value="neurologie"><?php echo esc_html($renderer->t('Neurologie')); ?></option>
            <option value="onkologie"><?php echo esc_html($renderer->t('Onkologie')); ?></option>
            <option value="orthopaedie"><?php echo esc_html($renderer->t('Orthopädie')); ?></option>
            <option value="radiologie"><?php echo esc_html($renderer->t('Radiologie (MRT/CT/Röntgen)')); ?></option>
            <option value="urologie"><?php echo esc_html($renderer->t('Urologie')); ?></option>
            <option value="sonstige"><?php echo esc_html($renderer->t('Sonstige')); ?></option>
        </select>
    </div>

    <div class="pp-field-group">
        <label for="pp-ueberw-arzt"><?php echo esc_html($renderer->t('Gewünschter Arzt/Praxis')); ?></label>
        <input type="text" id="pp-ueberw-arzt" name="ueberw_arzt" maxlength="200"
               placeholder="<?php echo esc_attr($renderer->t('Optional: Name des Facharztes')); ?>">
    </div>

    <div class="pp-field-group">
        <label for="pp-ueberw-grund"><?php echo esc_html($renderer->t('Grund der Überweisung')); ?></label>
        <textarea id="pp-ueberw-grund" name="ueberw_grund" rows="3" maxlength="1000"
                  placeholder="<?php echo esc_attr($renderer->t('Kurze Beschreibung der Beschwerden')); ?>"></textarea>
    </div>

    <div class="pp-field-group">
        <label><?php echo esc_html($renderer->t('Dringlichkeit')); ?></label>
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="ueberw_dringlichkeit" value="normal" checked>
                <span><?php echo esc_html($renderer->t('Normal')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="ueberw_dringlichkeit" value="dringend">
                <span><?php echo esc_html($renderer->t('Dringend')); ?></span>
            </label>
        </div>
    </div>

    <?php echo $renderer->renderSpamFields(); ?>
    <?php echo $renderer->renderNonce(); ?>
    <?php echo $renderer->renderLocationField(); ?>
    <input type="hidden" name="service" value="ueberweisung">

    <?php echo $renderer->renderDsgvoConsent(); ?>
    <?php echo $renderer->renderSubmitButton($renderer->t('Überweisung anfordern')); ?>

</form>
