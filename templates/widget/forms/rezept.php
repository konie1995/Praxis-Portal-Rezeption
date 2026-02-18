<?php
/**
 * Widget Formular – Rezept-Anfrage (v3-Stil)
 *
 * Logik:
 *  - Privat  → Abholung in der Praxis / Versand per Post (+ Versandadresse)
 *  - Gesetzlich → EVN-Checkbox (eVersicherungsnachweis)
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 * @updated 4.2.6 – v3-Logik: Kassenart-basierte Felder
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
?>

<form class="pp-service-form" data-service="rezept" novalidate>

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">💊 <?php echo esc_html($renderer->t('Rezept-Anfrage')); ?></h4>
    <p style="color: var(--pp-text); font-size: var(--pp-text-base); margin: 0 0 20px; line-height: 1.5;">
        <?php echo esc_html($renderer->t('Bitte geben Sie Ihre Daten und die gewünschten Medikamente an.')); ?>
    </p>

    <?php // ── Patientendaten ── ?>
    <?php echo $renderer->renderPatientFields(); ?>

    <?php // ── Versicherung ── ?>
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

    <?php // ── Medikamente (v3-Stil: Name + Art + dynamisch hinzufügen) ── ?>
    <div class="pp-section-header"><?php echo esc_html($renderer->t('Medikamente')); ?></div>

    <div id="pp-medikamente-liste" class="pp-medication-list">
        <div class="pp-medikament-item">
            <div class="pp-medikament-row" style="display: flex; gap: 8px; align-items: flex-end;">
                <div class="pp-form-group" style="flex: 1;">
                    <label><?php echo esc_html($renderer->t('Medikament 1')); ?> <span class="required">*</span></label>
                    <div class="pp-medication-input-wrapper">
                        <input type="text" name="medikamente[]"
                               placeholder="<?php echo esc_attr($renderer->t('Name des Medikaments eingeben...')); ?>"
                               class="pp-medication-search" autocomplete="off"
                               autocorrect="off" autocapitalize="off" spellcheck="false"
                               required>
                        <div class="pp-medication-suggestions"></div>
                    </div>
                </div>
                <div class="pp-form-group" style="width: 140px;">
                    <label><?php echo esc_html($renderer->t('Art')); ?></label>
                    <select name="medikament_art[]" class="pp-medikament-art-select">
                        <option value="augentropfen"><?php echo esc_html($renderer->t('Augentropfen')); ?></option>
                        <option value="augensalbe"><?php echo esc_html($renderer->t('Augensalbe')); ?></option>
                        <option value="tabletten"><?php echo esc_html($renderer->t('Tabletten')); ?></option>
                        <option value="sonstiges"><?php echo esc_html($renderer->t('Sonstiges')); ?></option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <button type="button" id="pp-add-medikament" class="pp-btn-secondary" style="margin: 8px 0 4px;">
        + <?php echo esc_html($renderer->t('Weiteres Medikament')); ?>
    </button>
    <p class="pp-hint" style="font-size: 12px; color: var(--pp-text-light); margin: 2px 0 16px;">
        <?php echo esc_html($renderer->t('Maximal 3 Medikamente pro Anfrage')); ?>
    </p>

    <?php // ── Foto der Medikamentenpackung (v3-Feature) ── ?>
    <div class="pp-field-group">
        <label><?php echo esc_html($renderer->t('Foto der Medikamentenpackung (optional)')); ?></label>
        <div class="pp-file-upload-wrapper">
            <input type="file" id="pp-rezept-datei" data-field-id="rezept_datei"
                   accept="image/*,.pdf" class="pp-file-input"
                   style="display: none;">
            <label for="pp-rezept-datei" class="pp-file-upload-area"
                   style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; border: 2px dashed var(--pp-border, #ddd); border-radius: 8px; cursor: pointer; transition: border-color .2s;">
                <span style="font-size: 24px;">📷</span>
                <span style="font-size: 13px;">
                    <strong><?php echo esc_html($renderer->t('Medikament fotografieren')); ?></strong><br>
                    <span style="color: var(--pp-text-light);"><?php echo esc_html($renderer->t('Tippen zum Auswählen oder Datei hierher ziehen')); ?></span>
                </span>
            </label>
        </div>
        <div class="pp-file-preview" data-for="rezept_datei" style="display: none; margin-top: 6px; font-size: 13px; color: var(--pp-primary);"></div>
    </div>

    <div class="pp-field-group">
        <label for="pp-rezept-hinweis"><?php echo esc_html($renderer->t('Hinweise zum Rezept')); ?></label>
        <textarea id="pp-rezept-hinweis" name="rezept_hinweis" rows="2" maxlength="1000"
                  placeholder="<?php echo esc_attr($renderer->t('z.B. geänderte Dosierung, Dauerrezept gewünscht')); ?>"></textarea>
    </div>

    <?php // ══════════════════════════════════════════════════════════
          // v3-LOGIK: Bedingte Felder je nach Versicherungsart
          // Sichtbarkeit wird per JS gesteuert (handleVersicherungChange)
          // ══════════════════════════════════════════════════════════ ?>

    <?php // ── eVersicherungsnachweis: NUR für gesetzlich Versicherte ── ?>
    <div id="pp-rezept-evn" class="pp-field-group pp-gkv-only" style="display:none;">
        <label class="pp-checkbox-label" style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
            <input type="checkbox" name="evn_erlaubt" value="1" style="margin-top: 3px;">
            <span style="font-size: 13px;">
                <?php echo esc_html($renderer->t('Dürfen wir (falls möglich) einen elektronischen Versicherungsnachweis bei Ihrer Krankenkasse anfordern?')); ?>
            </span>
        </label>
    </div>

    <?php // ── Lieferung: NUR für Privatversicherte ── ?>
    <div id="pp-rezept-lieferung" class="pp-field-group pp-privat-only" style="display:none;">
        <label><?php echo esc_html($renderer->t('Wie möchten Sie das Rezept erhalten?')); ?> <span class="required">*</span></label>
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="rezept_lieferung" value="praxis" checked>
                <span><?php echo esc_html($renderer->t('In der Praxis abholen')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="rezept_lieferung" value="post">
                <span><?php echo esc_html($renderer->t('Per Post zusenden')); ?></span>
            </label>
        </div>
    </div>

    <?php // ── Versandadresse: NUR bei Privat + Post ── ?>
    <div id="pp-versandadresse" class="pp-conditional" style="display:none;">
        <h5 style="margin: 12px 0 8px; font-size: 14px;"><?php echo esc_html($renderer->t('Versandadresse')); ?></h5>
        <div class="pp-field-group">
            <label for="pp-versand-strasse"><?php echo esc_html($renderer->t('Straße & Hausnummer')); ?> <span class="required">*</span></label>
            <input type="text" id="pp-versand-strasse" name="versand_strasse"
                   placeholder="<?php echo esc_attr($renderer->t('Musterstraße 123')); ?>">
        </div>
        <div style="display: flex; gap: 8px;">
            <div class="pp-field-group" style="width: 100px;">
                <label for="pp-versand-plz"><?php echo esc_html($renderer->t('PLZ')); ?> <span class="required">*</span></label>
                <input type="text" id="pp-versand-plz" name="versand_plz"
                       placeholder="12345" maxlength="5">
            </div>
            <div class="pp-field-group" style="flex: 1;">
                <label for="pp-versand-ort"><?php echo esc_html($renderer->t('Ort')); ?> <span class="required">*</span></label>
                <input type="text" id="pp-versand-ort" name="versand_ort"
                       placeholder="<?php echo esc_attr($renderer->t('Musterstadt')); ?>">
            </div>
        </div>
    </div>

    <?php // ── Spam-Schutz + Nonce + Location ── ?>
    <?php echo $renderer->renderSpamFields(); ?>
    <?php echo $renderer->renderNonce(); ?>
    <?php echo $renderer->renderLocationField(); ?>
    <input type="hidden" name="service" value="rezept">

    <?php // ── DSGVO + Submit ── ?>
    <?php echo $renderer->renderDsgvoConsent(); ?>
    <?php echo $renderer->renderSubmitButton($renderer->t('Rezept anfordern')); ?>

</form>
