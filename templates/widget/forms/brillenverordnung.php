<?php
/**
 * Widget Formular – Brillenverordnung
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
?>

<form class="pp-service-form" data-service="brillenverordnung" novalidate>

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">👓 <?php echo esc_html($renderer->t('Brillenverordnung')); ?></h4>
    <p style="color: var(--pp-text); font-size: var(--pp-text-base); margin: 0 0 20px; line-height: 1.5;">
        <?php echo esc_html($renderer->t('Beantragen Sie eine neue Brillenverordnung.')); ?>
    </p>

    <!-- Stammdaten -->
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

    <!-- Conditional: Privat → Abholung/Versand -->
    <div class="pp-field-group pp-conditional" data-conditional-field="versicherung" data-conditional-value="privat" style="display:none;">
        <label><?php echo esc_html($renderer->t('Zustellung')); ?></label>
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="zustellung" value="abholung" data-conditional-trigger>
                <span><?php echo esc_html($renderer->t('Abholung in der Praxis')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="zustellung" value="versand" data-conditional-trigger>
                <span><?php echo esc_html($renderer->t('Versand per Post')); ?></span>
            </label>
        </div>
    </div>

    <!-- Conditional: Versand → Adresse -->
    <div class="pp-conditional" data-conditional-field="zustellung" data-conditional-value="versand" style="display:none;">
        <div class="pp-field-group">
            <label for="pp-brille-strasse"><?php echo esc_html($renderer->t('Straße + Hausnummer')); ?></label>
            <input type="text" id="pp-brille-strasse" name="strasse" maxlength="200"
                   placeholder="<?php echo esc_attr($renderer->t('z.B. Musterstraße 12')); ?>">
        </div>

        <div class="pp-form-row pp-form-row-2">
            <div class="pp-form-group">
                <label for="pp-brille-plz"><?php echo esc_html($renderer->t('PLZ')); ?></label>
                <input type="text" id="pp-brille-plz" name="plz" maxlength="10"
                       placeholder="<?php echo esc_attr($renderer->t('z.B. 12345')); ?>">
            </div>
            <div class="pp-form-group">
                <label for="pp-brille-ort"><?php echo esc_html($renderer->t('Ort')); ?></label>
                <input type="text" id="pp-brille-ort" name="ort" maxlength="100"
                       placeholder="<?php echo esc_attr($renderer->t('z.B. Berlin')); ?>">
            </div>
        </div>
    </div>

    <!-- Brillenverordnung -->
    <div class="pp-section-header"><?php echo esc_html($renderer->t('Brillenverordnung')); ?></div>

    <div class="pp-field-group">
        <label><?php echo esc_html($renderer->t('Art der Brille')); ?> *</label>
        <div class="pp-checkbox-group">
            <label class="pp-checkbox-option">
                <input type="checkbox" name="brillenart[]" value="fern">
                <span><?php echo esc_html($renderer->t('Fernbrille')); ?></span>
            </label>
            <label class="pp-checkbox-option">
                <input type="checkbox" name="brillenart[]" value="nah">
                <span><?php echo esc_html($renderer->t('Lesebrille')); ?></span>
            </label>
            <label class="pp-checkbox-option">
                <input type="checkbox" name="brillenart[]" value="gleitsicht">
                <span><?php echo esc_html($renderer->t('Gleitsichtbrille')); ?></span>
            </label>
            <label class="pp-checkbox-option">
                <input type="checkbox" name="brillenart[]" value="bildschirm">
                <span><?php echo esc_html($renderer->t('Bildschirmbrille')); ?></span>
            </label>
        </div>
    </div>

    <!-- Refraktionswerte -->
    <div class="pp-field-group">
        <label><?php echo esc_html($renderer->t('Refraktionswerte (falls bekannt)')); ?></label>
        <p style="color: var(--pp-text-light); font-size: 12px; margin: 4px 0 12px;">
            <?php echo esc_html($renderer->t('Wenn Sie Ihre aktuellen Brillenwerte kennen, können Sie diese hier eintragen.')); ?>
        </p>

        <!-- Rechts -->
        <div class="pp-refraction-section">
            <strong class="pp-refraction-eye"><?php echo esc_html($renderer->t('Rechts (R)')); ?></strong>
            <div class="pp-refraction-controls">
                <div class="pp-spinner-control">
                    <label class="pp-spinner-label">SPH</label>
                    <div class="pp-spinner-wrapper">
                        <span class="pp-value-sign">±</span>
                        <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="refraktion_rechts_sph">−</button>
                        <input type="number" step="0.25" name="refraktion_rechts_sph"
                               class="pp-spinner-input pp-refraction-value" value="0.00"
                               data-min="-16" data-max="16" data-step="0.25">
                        <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="refraktion_rechts_sph">+</button>
                        <span class="pp-value-unit">dpt</span>
                    </div>
                </div>

                <div class="pp-spinner-control">
                    <label class="pp-spinner-label">CYL</label>
                    <div class="pp-spinner-wrapper">
                        <span class="pp-value-sign">±</span>
                        <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="refraktion_rechts_cyl">−</button>
                        <input type="number" step="0.25" name="refraktion_rechts_cyl"
                               class="pp-spinner-input pp-refraction-value" value="0.00"
                               data-min="-6" data-max="6" data-step="0.25">
                        <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="refraktion_rechts_cyl">+</button>
                        <span class="pp-value-unit">dpt</span>
                    </div>
                </div>

                <div class="pp-spinner-control">
                    <label class="pp-spinner-label">ACH (°)</label>
                    <input type="number" name="refraktion_rechts_ach"
                           class="pp-spinner-input-simple" placeholder="0-180"
                           min="0" max="180" step="1">
                </div>
            </div>
        </div>

        <!-- Links -->
        <div class="pp-refraction-section">
            <strong class="pp-refraction-eye"><?php echo esc_html($renderer->t('Links (L)')); ?></strong>
            <div class="pp-refraction-controls">
                <div class="pp-spinner-control">
                    <label class="pp-spinner-label">SPH</label>
                    <div class="pp-spinner-wrapper">
                        <span class="pp-value-sign">±</span>
                        <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="refraktion_links_sph">−</button>
                        <input type="number" step="0.25" name="refraktion_links_sph"
                               class="pp-spinner-input pp-refraction-value" value="0.00"
                               data-min="-16" data-max="16" data-step="0.25">
                        <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="refraktion_links_sph">+</button>
                        <span class="pp-value-unit">dpt</span>
                    </div>
                </div>

                <div class="pp-spinner-control">
                    <label class="pp-spinner-label">CYL</label>
                    <div class="pp-spinner-wrapper">
                        <span class="pp-value-sign">±</span>
                        <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="refraktion_links_cyl">−</button>
                        <input type="number" step="0.25" name="refraktion_links_cyl"
                               class="pp-spinner-input pp-refraction-value" value="0.00"
                               data-min="-6" data-max="6" data-step="0.25">
                        <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="refraktion_links_cyl">+</button>
                        <span class="pp-value-unit">dpt</span>
                    </div>
                </div>

                <div class="pp-spinner-control">
                    <label class="pp-spinner-label">ACH (°)</label>
                    <input type="number" name="refraktion_links_ach"
                           class="pp-spinner-input-simple" placeholder="0-180"
                           min="0" max="180" step="1">
                </div>
            </div>
        </div>

        <!-- Addition (gemeinsam für beide Augen) -->
        <div class="pp-field-group" style="margin-top: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                <?php echo esc_html($renderer->t('Addition (ADD) - Leseteil')); ?>
            </label>
            <p style="color: var(--pp-text-light); font-size: 12px; margin: 0 0 12px;">
                <?php echo esc_html($renderer->t('Der Wert ist für beide Augen gleich.')); ?>
            </p>
            <div class="pp-spinner-control" style="max-width: 200px;">
                <div class="pp-spinner-wrapper">
                    <span class="pp-value-sign">+</span>
                    <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="refraktion_add">−</button>
                    <input type="number" step="0.25" name="refraktion_add"
                           class="pp-spinner-input pp-refraction-value" value="0.00"
                           data-min="0" data-max="4" data-step="0.25">
                    <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="refraktion_add">+</button>
                    <span class="pp-value-unit">dpt</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Prismen -->
    <div class="pp-field-group">
        <label><?php echo esc_html($renderer->t('Benötigen Sie Prismenwerte?')); ?></label>
        <div class="pp-radio-group">
            <label class="pp-radio-option">
                <input type="radio" name="prismen_gewuenscht" value="ja" data-conditional-trigger>
                <span><?php echo esc_html($renderer->t('Ja')); ?></span>
            </label>
            <label class="pp-radio-option">
                <input type="radio" name="prismen_gewuenscht" value="nein" data-conditional-trigger>
                <span><?php echo esc_html($renderer->t('Nein')); ?></span>
            </label>
        </div>
    </div>

    <!-- Conditional: Prismenwerte -->
    <div class="pp-conditional" data-conditional-field="prismen_gewuenscht" data-conditional-value="ja" style="display:none;">
        <div class="pp-field-group">
            <label><?php echo esc_html($renderer->t('Prismenwerte')); ?></label>
            <p style="color: var(--pp-text-light); font-size: 12px; margin: 4px 0 12px;">
                <?php echo esc_html($renderer->t('Geben Sie die Prismenwerte an, falls bekannt.')); ?>
            </p>

            <!-- Rechts -->
            <div class="pp-prism-section">
                <strong class="pp-prism-eye"><?php echo esc_html($renderer->t('Rechts (R)')); ?></strong>

                <!-- Horizontal -->
                <div class="pp-prism-row">
                    <label class="pp-prism-direction"><?php echo esc_html($renderer->t('Horizontal')); ?></label>
                    <div class="pp-prism-fields">
                        <div class="pp-spinner-control">
                            <label class="pp-spinner-label"><?php echo esc_html($renderer->t('Wert')); ?></label>
                            <div class="pp-spinner-wrapper">
                                <span class="pp-value-sign">+</span>
                                <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="prisma_rechts_h_wert">−</button>
                                <input type="number" step="0.5" name="prisma_rechts_h_wert"
                                       class="pp-spinner-input pp-prism-value" value="0.0"
                                       data-min="0" data-max="20" data-step="0.5">
                                <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="prisma_rechts_h_wert">+</button>
                                <span class="pp-value-unit">pdpt</span>
                            </div>
                        </div>
                        <div class="pp-prism-input-group">
                            <label class="pp-field-label-small"><?php echo esc_html($renderer->t('Basis')); ?></label>
                            <select name="prisma_rechts_h_basis" class="pp-prism-select">
                                <option value="">-</option>
                                <option value="innen"><?php echo esc_html($renderer->t('Innen')); ?></option>
                                <option value="aussen"><?php echo esc_html($renderer->t('Außen')); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Vertikal -->
                <div class="pp-prism-row">
                    <label class="pp-prism-direction"><?php echo esc_html($renderer->t('Vertikal')); ?></label>
                    <div class="pp-prism-fields">
                        <div class="pp-spinner-control">
                            <label class="pp-spinner-label"><?php echo esc_html($renderer->t('Wert')); ?></label>
                            <div class="pp-spinner-wrapper">
                                <span class="pp-value-sign">+</span>
                                <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="prisma_rechts_v_wert">−</button>
                                <input type="number" step="0.5" name="prisma_rechts_v_wert"
                                       class="pp-spinner-input pp-prism-value" value="0.0"
                                       data-min="0" data-max="20" data-step="0.5">
                                <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="prisma_rechts_v_wert">+</button>
                                <span class="pp-value-unit">pdpt</span>
                            </div>
                        </div>
                        <div class="pp-prism-input-group">
                            <label class="pp-field-label-small"><?php echo esc_html($renderer->t('Basis')); ?></label>
                            <select name="prisma_rechts_v_basis" class="pp-prism-select">
                                <option value="">-</option>
                                <option value="oben"><?php echo esc_html($renderer->t('Oben')); ?></option>
                                <option value="unten"><?php echo esc_html($renderer->t('Unten')); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Links -->
            <div class="pp-prism-section" style="margin-top: 20px;">
                <strong class="pp-prism-eye"><?php echo esc_html($renderer->t('Links (L)')); ?></strong>

                <!-- Horizontal -->
                <div class="pp-prism-row">
                    <label class="pp-prism-direction"><?php echo esc_html($renderer->t('Horizontal')); ?></label>
                    <div class="pp-prism-fields">
                        <div class="pp-spinner-control">
                            <label class="pp-spinner-label"><?php echo esc_html($renderer->t('Wert')); ?></label>
                            <div class="pp-spinner-wrapper">
                                <span class="pp-value-sign">+</span>
                                <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="prisma_links_h_wert">−</button>
                                <input type="number" step="0.5" name="prisma_links_h_wert"
                                       class="pp-spinner-input pp-prism-value" value="0.0"
                                       data-min="0" data-max="20" data-step="0.5">
                                <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="prisma_links_h_wert">+</button>
                                <span class="pp-value-unit">pdpt</span>
                            </div>
                        </div>
                        <div class="pp-prism-input-group">
                            <label class="pp-field-label-small"><?php echo esc_html($renderer->t('Basis')); ?></label>
                            <select name="prisma_links_h_basis" class="pp-prism-select">
                                <option value="">-</option>
                                <option value="innen"><?php echo esc_html($renderer->t('Innen')); ?></option>
                                <option value="aussen"><?php echo esc_html($renderer->t('Außen')); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Vertikal -->
                <div class="pp-prism-row">
                    <label class="pp-prism-direction"><?php echo esc_html($renderer->t('Vertikal')); ?></label>
                    <div class="pp-prism-fields">
                        <div class="pp-spinner-control">
                            <label class="pp-spinner-label"><?php echo esc_html($renderer->t('Wert')); ?></label>
                            <div class="pp-spinner-wrapper">
                                <span class="pp-value-sign">+</span>
                                <button type="button" class="pp-spinner-btn pp-spinner-minus" data-target="prisma_links_v_wert">−</button>
                                <input type="number" step="0.5" name="prisma_links_v_wert"
                                       class="pp-spinner-input pp-prism-value" value="0.0"
                                       data-min="0" data-max="20" data-step="0.5">
                                <button type="button" class="pp-spinner-btn pp-spinner-plus" data-target="prisma_links_v_wert">+</button>
                                <span class="pp-value-unit">pdpt</span>
                            </div>
                        </div>
                        <div class="pp-prism-input-group">
                            <label class="pp-field-label-small"><?php echo esc_html($renderer->t('Basis')); ?></label>
                            <select name="prisma_links_v_basis" class="pp-prism-select">
                                <option value="">-</option>
                                <option value="oben"><?php echo esc_html($renderer->t('Oben')); ?></option>
                                <option value="unten"><?php echo esc_html($renderer->t('Unten')); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Anmerkungen -->
    <div class="pp-field-group">
        <label for="pp-brille-anmerkungen"><?php echo esc_html($renderer->t('Anmerkungen')); ?></label>
        <textarea id="pp-brille-anmerkungen" name="anmerkungen" rows="3" maxlength="1000"
                  placeholder="<?php echo esc_attr($renderer->t('z.B. letzte Messung, besondere Wünsche, Beschwerden')); ?>"></textarea>
    </div>

    <?php echo $renderer->renderSpamFields(); ?>
    <?php echo $renderer->renderNonce(); ?>
    <?php echo $renderer->renderLocationField(); ?>
    <input type="hidden" name="service" value="brillenverordnung">

    <?php echo $renderer->renderDsgvoConsent(); ?>
    <?php echo $renderer->renderSubmitButton($renderer->t('Verordnung anfordern')); ?>

</form>
