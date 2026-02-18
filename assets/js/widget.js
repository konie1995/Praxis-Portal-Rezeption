/**
 * Praxis-Portal v4 – Widget Frontend JavaScript
 *
 * Multi-Step Wizard:
 *  1. Location (wenn Multi-Standort)
 *  2. Welcome + Patient-Status
 *  3. Service-Auswahl
 *  4. Formular (dynamisch aus JSON)
 *  5. Datenschutz + Unterschrift
 *  6. Erfolg
 *
 * Globales Objekt: pp_widget (via wp_localize_script)
 *  - ajax_url, nonce, upload_nonce, search_nonce
 *  - max_file_size, min_form_time, vacation_mode
 *  - i18n{}
 *
 * @package PraxisPortal
 * @since   4.0.0
 */
(function () {
    'use strict';

    var W = window.pp_widget || {};
    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); };

    /* =====================================================================
     * 1. STATE
     * ================================================================== */

    var state = {
        open:          false,
        step:          'welcome',     // location | welcome | services | form | consent | success
        locationUuid:  null,
        patientStatus: null,  // bestandspatient | neupatient (null bis Auswahl getroffen)
        service:       null,
        formData:      {},
        uploadedFiles: [],
        formStartTime: 0,
        signatureData: null
    };

    /* =====================================================================
     * 2. TRIGGER BUTTON
     * ================================================================== */

    var trigger   = $('#pp-widget-trigger');
    var container = $('#pp-widget-container');

    if (!trigger || !container) return;

    trigger.addEventListener('click', function () {
        state.open = !state.open;
        container.classList.toggle('pp-open', state.open);
        trigger.classList.toggle('pp-trigger-active', state.open);

        if (state.open && state.step === 'welcome') {
            state.formStartTime = Date.now();
        }
    });

    // Schließen-Button
    on(container, 'click', '.pp-close-btn', function () {
        state.open = false;
        container.classList.remove('pp-open');
        trigger.classList.remove('pp-trigger-active');
    });

    /* =====================================================================
     * 3. STEP NAVIGATION
     * ================================================================== */

    var isMultisite = container.getAttribute('data-multisite') === '1';

    on(container, 'click', '[data-goto-step]', function (e) {
        e.preventDefault();
        var target = this.getAttribute('data-goto-step');
        goToStep(target);
    });

    // v3-Logik: Ja/Nein-Buttons auf Welcome navigieren direkt (kein Weiter-Button)
    // Handler ist in [data-patient-status] click oben

    on(container, 'click', '.pp-back-btn', function (e) {
        e.preventDefault();
        goBack();
    });

    function goToStep(stepName) {
        state.step = stepName;

        // Alle Steps ausblenden
        $$('.pp-step', container).forEach(function (el) {
            el.classList.remove('pp-step-active');
        });

        // Ziel-Step einblenden
        var target = $('.pp-step[data-step="' + stepName + '"]', container);
        if (target) {
            target.classList.add('pp-step-active');
            target.scrollTop = 0;
        }

        // Back-Button: nur auf welcome verstecken
        var backBtn = $('.pp-back-btn', container);
        if (backBtn) {
            backBtn.style.display = (stepName === 'welcome') ? 'none' : '';
        }

        // Progress-Bar aktualisieren
        var steps = isMultisite
            ? ['welcome', 'location', 'services', 'form', 'success']
            : ['welcome', 'services', 'form', 'success'];
        var idx = steps.indexOf(stepName);
        var pct = idx >= 0 ? Math.round(((idx + 1) / steps.length) * 100) : 0;
        var bar = $('.pp-progress-bar', container);
        if (bar) bar.style.width = pct + '%';

        // Sonderfälle
        if (stepName === 'services') {
            filterServicesByPatientStatus();
        }
        if (stepName === 'form') {
            state.formStartTime = Date.now();
            initFormStep();
        }
    }

    function goBack() {
        // v3-Flow: welcome → location → services → form → consent
        var order = isMultisite
            ? ['welcome', 'location', 'services', 'form', 'consent']
            : ['welcome', 'services', 'form', 'consent'];
        var idx   = order.indexOf(state.step);
        if (idx > 0) {
            goToStep(order[idx - 1]);
        }
    }

    /**
     * Widget zurücksetzen für neue Anfrage.
     */
    function resetWidget() {
        state.service       = null;
        state.formData      = {};
        state.uploadedFiles = [];
        state.signatureData = null;
        state.formStartTime = 0;

        // Formular-Container leeren
        var formContainer = $('.pp-form-container', container);
        if (formContainer) formContainer.innerHTML = '';

        // Service-Markierung entfernen
        $$('.pp-service-card', container).forEach(function (el) {
            el.classList.remove('pp-selected');
        });

        goToStep('welcome');
    }

    on(container, 'click', '[data-action="reset"]', function (e) {
        e.preventDefault();
        resetWidget();
    });

    /* =====================================================================
     * 4. LOCATION SELECTION
     * ================================================================== */

    on(container, 'click', '.pp-location-option', function () {
        state.locationUuid = this.getAttribute('data-location-uuid');

        // Visuell markieren
        $$('.pp-location-option', container).forEach(function (el) {
            el.classList.remove('pp-selected');
        });
        this.classList.add('pp-selected');

        // v3-Flow: Nach Standortwahl → Services
        goToStep('services');
    });

    /* =====================================================================
     * 4b. PATIENT STATUS TOGGLE
     * ================================================================== */

    on(container, 'click', '[data-patient-status]', function () {
        state.patientStatus = this.getAttribute('data-patient-status');

        // Visuell markieren
        $$('[data-patient-status]', container).forEach(function (el) {
            el.classList.remove('pp-selected');
        });
        this.classList.add('pp-selected');

        // v3-Logik: DIREKT navigieren (kein extra Weiter-Button)
        filterServicesByPatientStatus();
        if (isMultisite) {
            goToStep('location');
        } else {
            goToStep('services');
        }
    });

    /**
     * Services nach Patientenstatus filtern.
     * Nur Bestandspatienten → alle Services (inkl. patient-only)
     * Neupatienten / keine Auswahl → nur Services mit data-patient-only="0"
     */
    function filterServicesByPatientStatus() {
        $$('.pp-service-card', container).forEach(function (card) {
            var isPatientOnly = card.getAttribute('data-patient-only') === '1';

            // Patient-only Services NUR für Bestandspatienten anzeigen
            if (isPatientOnly && state.patientStatus !== 'bestandspatient') {
                card.style.display = 'none';
            } else {
                card.style.display = '';
            }
        });
    }

    /* =====================================================================
     * 5. SERVICE SELECTION
     * ================================================================== */

    on(container, 'click', '.pp-service-card', function () {
        state.service = this.getAttribute('data-service');

        // Anamnesebogen: Redirect zu konfigurierter URL
        if (state.service === 'anamnesebogen') {
            var url = this.getAttribute('data-url') || W.anamnesebogen_url;
            if (url) {
                window.open(url, '_blank');
                return;
            }
            // Fallback: Kein URL konfiguriert - Hinweis anzeigen
            alert('Bitte kontaktieren Sie die Praxis für den Anamnesebogen.');
            return;
        }

        $$('.pp-service-card', container).forEach(function (el) {
            el.classList.remove('pp-selected');
        });
        this.classList.add('pp-selected');

        goToStep('form');
    });

    /* =====================================================================
     * 6. FORM RENDERING (JSON-basiert)
     * ================================================================== */

    function initFormStep() {
        var formContainer = $('.pp-form-container', container);
        if (!formContainer) return;

        // Formular-Template für gewählten Service laden
        var templates = document.getElementById('pp-form-templates');
        if (templates && state.service) {
            var tpl = templates.querySelector('[data-service="' + state.service + '"]');
            if (!tpl) {
                tpl = templates.querySelector('.pp-service-form[data-service="' + state.service + '"]');
            }
            if (tpl) {
                formContainer.innerHTML = '';
                var clone = tpl.cloneNode(true);
                clone.style.display = '';
                formContainer.appendChild(clone);
            } else {
                // Kein Template gefunden → Fehlermeldung
                formContainer.innerHTML = '<p style="color: #c00; padding: 20px; text-align: center;">'
                    + (W.i18n && W.i18n.error ? W.i18n.error : 'Formular konnte nicht geladen werden.')
                    + '</p>';
            }
        }

        // Conditional Fields: Visibility steuern
        initConditionalFields(formContainer);

        // Medication Autocomplete initialisieren
        initMedicationAutocomplete(formContainer);
    }

    function initConditionalFields(formEl) {
        // ── v3-Logik: Versicherung → EVN / Lieferung / Versandadresse ──
        handleVersicherungChange(formEl);
        handleRezeptLieferungChange(formEl);

        // Bei Versicherungs-Wechsel
        $$('[name="versicherung"]', formEl).forEach(function (input) {
            input.addEventListener('change', function () {
                handleVersicherungChange(formEl);
            });
        });

        // Bei Lieferart-Wechsel
        $$('[name="rezept_lieferung"]', formEl).forEach(function (input) {
            input.addEventListener('change', function () {
                handleRezeptLieferungChange(formEl);
            });
        });

        // Brillen-Lieferung (falls vorhanden)
        $$('[name="brillen_lieferung"]', formEl).forEach(function (input) {
            input.addEventListener('change', function () {
                handleBrillenLieferungChange(formEl);
            });
        });

        // ── Generische data-condition Felder (für andere Formulare) ──
        var conditionalFields = $$('[data-condition-field]', formEl);
        if (!conditionalFields.length) return;

        function evaluateAll() {
            conditionalFields.forEach(function (el) {
                var watchField    = el.getAttribute('data-condition-field');
                var watchValue    = el.getAttribute('data-condition-value');
                var watchContains = el.getAttribute('data-condition-contains');

                // Eltern-Element versteckt? → Kind auch verstecken
                var parent = el.parentNode.closest('[data-condition-field]');
                if (parent && parent.style.display === 'none') {
                    el.style.display = 'none';
                    return;
                }

                updateVisibility(el, watchField, watchValue, watchContains, formEl);
            });
        }

        formEl.addEventListener('change', evaluateAll);
        evaluateAll();
    }

    /**
     * v3-Logik: Versicherung steuert EVN / Lieferung / Versandadresse
     */
    function handleVersicherungChange(formEl) {
        var checked = $('[name="versicherung"]:checked', formEl);
        var v = checked ? checked.value : '';
        var isPrivat = (v === 'privat');
        var isGKV    = (v === 'gesetzlich');

        // Rezept-Felder
        var evnEl       = $('#pp-rezept-evn', formEl);
        var lieferungEl = $('#pp-rezept-lieferung', formEl);
        var versandEl   = $('#pp-versandadresse', formEl);

        if (evnEl) evnEl.style.display       = isGKV ? '' : 'none';
        if (lieferungEl) lieferungEl.style.display = isPrivat ? '' : 'none';

        if (isPrivat) {
            handleRezeptLieferungChange(formEl);
        } else {
            if (versandEl) versandEl.style.display = 'none';
            // Required entfernen bei GKV
            $$('#pp-versand-strasse, #pp-versand-plz, #pp-versand-ort', formEl).forEach(function (el) {
                el.removeAttribute('required');
            });
        }

        // Überweisung-EVN (falls vorhanden)
        var ueberweisungEvn = $('#pp-ueberweisung-evn', formEl);
        if (ueberweisungEvn) ueberweisungEvn.style.display = isGKV ? '' : 'none';

        // Brillen-Felder (falls vorhanden)
        var brillenEvn      = $('#pp-brillen-evn', formEl);
        var brillenLieferung = $('#pp-brillen-lieferung', formEl);
        if (brillenEvn) brillenEvn.style.display = isGKV ? '' : 'none';
        if (brillenLieferung) {
            brillenLieferung.style.display = isPrivat ? '' : 'none';
            if (isPrivat) handleBrillenLieferungChange(formEl);
        }
    }

    /**
     * v3-Logik: Rezept-Lieferung → Versandadresse
     */
    function handleRezeptLieferungChange(formEl) {
        var checked = $('[name="rezept_lieferung"]:checked', formEl);
        var l = checked ? checked.value : 'praxis';
        var versandEl = $('#pp-versandadresse', formEl);
        var isPost = (l === 'post');

        if (versandEl) versandEl.style.display = isPost ? '' : 'none';

        $$('#pp-versand-strasse, #pp-versand-plz, #pp-versand-ort', formEl).forEach(function (el) {
            if (isPost) el.setAttribute('required', 'required');
            else el.removeAttribute('required');
        });
    }

    /**
     * v3-Logik: Brillen-Lieferung → Versandadresse
     */
    function handleBrillenLieferungChange(formEl) {
        var checked = $('[name="brillen_lieferung"]:checked', formEl);
        var l = checked ? checked.value : 'praxis';
        var versandEl = $('#pp-brillen-versandadresse', formEl);
        var isPost = (l === 'post');

        if (versandEl) versandEl.style.display = isPost ? '' : 'none';

        $$('#pp-brillen-versand-strasse, #pp-brillen-versand-plz, #pp-brillen-versand-ort', formEl).forEach(function (el) {
            if (isPost) el.setAttribute('required', 'required');
            else el.removeAttribute('required');
        });
    }

    function updateVisibility(el, watchField, watchValue, watchContains, formEl) {
        var inputs = $$('[name="' + watchField + '"]:checked, [name="' + watchField + '"]', formEl);
        var currentValue = '';

        for (var i = 0; i < inputs.length; i++) {
            var inp = inputs[i];
            if (inp.type === 'radio' || inp.type === 'checkbox') {
                if (inp.checked) { currentValue = inp.value; break; }
            } else {
                currentValue = inp.value;
                break;
            }
        }

        // Checkbox-Gruppen: Mehrere Werte möglich
        if (watchContains) {
            var checked = $$('[name="' + watchField + '"]:checked', formEl);
            var values = checked.map(function (cb) { return cb.value; });
            el.style.display = values.indexOf(watchContains) >= 0 ? '' : 'none';
        } else if (watchValue) {
            el.style.display = currentValue === watchValue ? '' : 'none';
        }
    }

    /* =====================================================================
     * 7. MEDICATION AUTOCOMPLETE
     * ================================================================== */

    function initMedicationAutocomplete(formEl) {
        var medInputs = $$('.pp-medication-search', formEl);

        medInputs.forEach(function (input) {
            var dropdown = input.parentNode.querySelector('.pp-medication-suggestions');
            if (!dropdown) {
                dropdown = document.createElement('div');
                dropdown.className = 'pp-medication-suggestions pp-autocomplete-dropdown';
                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(dropdown);
            }
            dropdown.classList.add('pp-autocomplete-dropdown');

            var timer = null;

            input.addEventListener('input', function () {
                clearTimeout(timer);
                var term = this.value.trim();

                if (term.length < 2) {
                    dropdown.innerHTML = '';
                    dropdown.style.display = 'none';
                    return;
                }

                dropdown.innerHTML = '<div class="pp-ac-loading">' + W.i18n.searching + '</div>';
                dropdown.style.display = 'block';

                timer = setTimeout(function () {
                    searchMedications(term, dropdown, input);
                }, 300);
            });

            // Klick auf Ergebnis → Wert ins v3-Eingabefeld übernehmen
            dropdown.addEventListener('click', function (e) {
                var item = e.target.closest('.pp-ac-item');
                if (!item) return;

                // Display-Name bevorzugen (Name + Stärke)
                var displayName = item.getAttribute('data-name');
                var staerke = item.getAttribute('data-staerke');
                if (staerke) displayName += ' ' + staerke;
                input.value = displayName;
                dropdown.style.display = 'none';
            });

            // Klick außerhalb schließt Dropdown
            document.addEventListener('click', function (e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        });
    }

    function searchMedications(term, dropdown, input) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', W.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                dropdown.style.display = 'none';
                return;
            }

            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch (e) { return; }

            if (!resp.success || !resp.data || !resp.data.results) {
                dropdown.innerHTML = '<div class="pp-ac-empty">' + W.i18n.no_results + '</div>';
                return;
            }

            var html = '';
            resp.data.results.forEach(function (med) {
                var displayName = med.display || med.name;
                var detail = med.wirkstoff || med.dosage || '';
                var dosierung = med.standard_dosierung || '';

                html += '<div class="pp-ac-item" data-name="' + escAttr(med.name)
                    + '" data-dosage="' + escAttr(detail)
                    + '" data-pzn="' + escAttr(med.pzn || '')
                    + '" data-staerke="' + escAttr(med.staerke || '')
                    + '" data-dosierung="' + escAttr(dosierung) + '">'
                    + '<strong>' + escHtml(displayName) + '</strong>'
                    + (detail ? ' <span class="pp-ac-dosage">' + escHtml(detail) + '</span>' : '')
                    + (dosierung ? ' <span class="pp-ac-hint">' + escHtml(dosierung) + '</span>' : '')
                    + '</div>';
            });

            dropdown.innerHTML = html;
            dropdown.style.display = 'block';
        };

        xhr.send(
            'action=pp_medication_search'
            + '&nonce=' + encodeURIComponent(W.search_nonce)
            + '&term=' + encodeURIComponent(term)
            + '&location_uuid=' + encodeURIComponent(state.locationUuid || '')
        );
    }

    /* ── Weiteres Medikament hinzufügen (v3-Stil, max 3) ── */
    on(container, 'click', '#pp-add-medikament', function () {
        var liste = $('#pp-medikamente-liste', container);
        if (!liste) return;

        var items = $$('.pp-medikament-item', liste);
        if (items.length >= 3) {
            this.style.display = 'none';
            return;
        }

        var num = items.length + 1;
        var i = (pp_widget.i18n || {});
        var div = document.createElement('div');
        div.className = 'pp-medikament-item';
        div.innerHTML = '<div class="pp-medikament-row" style="display: flex; gap: 8px; align-items: flex-end;">'
            + '<div class="pp-form-group" style="flex: 1;">'
            + '<label>' + (i.medication || 'Medikament') + ' ' + num + '</label>'
            + '<div class="pp-medication-input-wrapper">'
            + '<input type="text" name="medikamente[]" placeholder="' + (i.med_placeholder || 'Name des Medikaments eingeben...') + '" class="pp-medication-search" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">'
            + '<div class="pp-medication-suggestions"></div>'
            + '</div></div>'
            + '<div class="pp-form-group" style="width: 140px;">'
            + '<label>' + (i.med_type || 'Art') + '</label>'
            + '<select name="medikament_art[]" class="pp-medikament-art-select">'
            + '<option value="augentropfen">' + (i.med_eye_drops || 'Augentropfen') + '</option>'
            + '<option value="augensalbe">' + (i.med_eye_ointment || 'Augensalbe') + '</option>'
            + '<option value="tabletten">' + (i.med_tablets || 'Tabletten') + '</option>'
            + '<option value="sonstiges">' + (i.med_other || 'Sonstiges') + '</option>'
            + '</select></div>'
            + '<button type="button" class="pp-remove-medikament" style="background:none;border:none;color:#d63638;font-size:18px;cursor:pointer;padding:4px 8px;margin-bottom:8px;" title="' + (i.med_remove || 'Entfernen') + '">×</button>'
            + '</div>';
        liste.appendChild(div);

        // Autocomplete für neues Feld initialisieren
        var formEl = liste.closest('form');
        if (formEl) initMedicationAutocomplete(formEl);

        // Button verstecken bei max 3
        if ($$('.pp-medikament-item', liste).length >= 3) {
            this.style.display = 'none';
        }
    });

    /* ── Medikament-Zeile entfernen ── */
    on(container, 'click', '.pp-remove-medikament', function () {
        var item = this.closest('.pp-medikament-item');
        if (item) item.remove();

        // "Weiteres Medikament" Button wieder zeigen
        var btn = $('#pp-add-medikament', container);
        if (btn) btn.style.display = '';
    });

    /* =====================================================================
     * 8. FILE UPLOAD
     * ================================================================== */

    on(container, 'change', '.pp-file-input', function () {
        var file = this.files[0];
        if (!file) return;

        // Größenlimit prüfen
        if (file.size > W.max_file_size) {
            showFieldError(this, W.i18n.file_too_large);
            this.value = '';
            return;
        }

        var self = this;
        var formData = new FormData();
        formData.append('action', 'pp_widget_upload');
        formData.append('nonce', W.upload_nonce);
        formData.append('file', file);
        formData.append('field_id', this.getAttribute('data-field-id') || 'file');

        if (state.locationUuid) {
            formData.append('location_uuid', state.locationUuid);
        }

        var label = this.closest('.pp-field-group');
        if (label) label.classList.add('pp-uploading');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', W.ajax_url, true);

        xhr.onload = function () {
            if (label) label.classList.remove('pp-uploading');

            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch (e) { return; }

            if (resp.success && resp.data) {
                state.uploadedFiles.push({
                    field_id: self.getAttribute('data-field-id') || 'file',
                    file_id:  resp.data.file_id,
                    filename: resp.data.filename
                });

                // Visuelles Feedback
                var preview = self.closest('.pp-field-group').querySelector('.pp-file-preview');
                if (preview) {
                    preview.innerHTML = '✓ ' + escHtml(resp.data.filename);
                    preview.style.display = 'block';
                }
            } else {
                showFieldError(self, (resp.data && resp.data.message) || W.i18n.error);
            }
        };

        xhr.send(formData);
    });

    /* =====================================================================
     * 9. FORM VALIDATION
     * ================================================================== */

    function validateForm(formEl) {
        var valid  = true;
        var errors = [];

        // Nur sichtbare Required-Felder validieren
        $$('[required]', formEl).forEach(function (field) {
            if (field.closest('[style*="display: none"]') || field.closest('[style*="display:none"]')) {
                return; // Versteckt → ignorieren
            }

            clearFieldError(field);

            var val = field.value.trim();
            if (!val) {
                showFieldError(field, W.i18n.required_field);
                valid = false;
                errors.push(field.getAttribute('name') || field.id);
            }
        });

        // Bot-Schutz: Formular zu schnell ausgefüllt?
        var elapsed = (Date.now() - state.formStartTime) / 1000;
        if (elapsed < W.min_form_time) {
            valid = false;
            errors.push('__bot_check');
        }

        // Zum ersten Fehler scrollen
        if (!valid) {
            var firstErr = $('.pp-field-error', formEl);
            if (firstErr) {
                firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        return valid;
    }

    function showFieldError(field, message) {
        var group = field.closest('.pp-field-group') || field.parentNode;
        group.classList.add('pp-has-error');

        var errEl = group.querySelector('.pp-field-error');
        if (!errEl) {
            errEl = document.createElement('div');
            errEl.className = 'pp-field-error';
            group.appendChild(errEl);
        }
        errEl.textContent = message;
    }

    function clearFieldError(field) {
        var group = field.closest('.pp-field-group') || field.parentNode;
        group.classList.remove('pp-has-error');
        var errEl = group.querySelector('.pp-field-error');
        if (errEl) errEl.remove();
    }

    /* =====================================================================
     * 10. FORM SUBMISSION
     * ================================================================== */

    on(container, 'submit', '.pp-service-form', function (e) {
        e.preventDefault();

        var formEl = this;
        if (!validateForm(formEl)) return;

        var submitBtn = $('[type="submit"]', formEl);
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = W.i18n.sending;
        }

        // Formulardaten sammeln
        var fd = new FormData(formEl);
        fd.append('action', 'pp_submit_service_request');
        fd.append('nonce', W.nonce);
        fd.append('service', state.service || '');
        fd.append('location_uuid', state.locationUuid || '');
        fd.append('patient_status', state.patientStatus || 'bestandspatient');

        // Hochgeladene Dateien referenzieren
        state.uploadedFiles.forEach(function (f, idx) {
            fd.append('uploaded_files[' + idx + '][field_id]', f.field_id);
            fd.append('uploaded_files[' + idx + '][file_id]', f.file_id);
        });

        // Signatur (falls vorhanden)
        if (state.signatureData) {
            fd.append('signature', state.signatureData);
        }

        // Bot-Schutz: Zeitstempel
        fd.append('form_start_time', String(state.formStartTime));
        fd.append('form_elapsed', String(Math.floor((Date.now() - state.formStartTime) / 1000)));

        // Honeypot (verstecktes Feld)
        var hp = $('[name="pp_hp"]', formEl);
        if (hp) fd.append('pp_hp', hp.value);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', W.ajax_url, true);

        xhr.onload = function () {
            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch (e) {
                showFormError(formEl, W.i18n.error);
                return;
            }

            if (resp.success) {
                goToStep('success');

                // Downloads anzeigen (falls vorhanden)
                if (resp.data && resp.data.downloads) {
                    var dlArea = $('.pp-downloads-area', container);
                    if (dlArea) {
                        resp.data.downloads.forEach(function (dl) {
                            var a = document.createElement('a');
                            a.href = dl.url;
                            a.className = 'pp-download-link';
                            a.textContent = dl.label || dl.filename;
                            a.download = dl.filename;
                            dlArea.appendChild(a);
                        });
                    }
                }
            } else {
                var msg = (resp.data && resp.data.message) || W.i18n.error;
                showFormError(formEl, msg);
            }

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.getAttribute('data-original-text') || 'Absenden';
            }
        };

        xhr.onerror = function () {
            showFormError(formEl, W.i18n.error);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.getAttribute('data-original-text') || 'Absenden';
            }
        };

        xhr.send(fd);
    });

    function showFormError(formEl, message) {
        var errBox = $('.pp-form-error', formEl);
        if (!errBox) {
            errBox = document.createElement('div');
            errBox.className = 'pp-form-error';
            formEl.prepend(errBox);
        }
        errBox.textContent = message;
        errBox.style.display = 'block';
        errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /* =====================================================================
     * 11. SIGNATURE PAD (einfach)
     * ================================================================== */

    on(container, 'mousedown touchstart', '.pp-signature-pad', function (e) {
        var canvas = this;
        var ctx    = canvas.getContext('2d');
        var rect   = canvas.getBoundingClientRect();
        var drawing = true;

        ctx.strokeStyle = '#000';
        ctx.lineWidth   = 2;
        ctx.lineCap     = 'round';
        ctx.beginPath();

        function getPos(ev) {
            var touch = ev.touches ? ev.touches[0] : ev;
            return {
                x: touch.clientX - rect.left,
                y: touch.clientY - rect.top
            };
        }

        var pos = getPos(e);
        ctx.moveTo(pos.x, pos.y);

        function onMove(ev) {
            if (!drawing) return;
            ev.preventDefault();
            var p = getPos(ev);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
        }

        function onUp() {
            drawing = false;
            state.signatureData = canvas.toDataURL('image/png');
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onUp);
    });

    on(container, 'click', '.pp-signature-clear', function () {
        var canvas = $('.pp-signature-pad', container);
        if (canvas) {
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            state.signatureData = null;
        }
    });

    /* =====================================================================
     * 12. SPINNER CONTROLS (Refraktionswerte +/-)
     * ================================================================== */

    /**
     * Initialisiere Vorzeichen für Refraktions- und Prismenwerte
     */
    function initializeValueSigns() {
        // Refraktionswerte (SPH/CYL)
        var refractionInputs = container.querySelectorAll('.pp-refraction-value');
        refractionInputs.forEach(function(input) {
            updateSignSpan(input);
        });

        // Prismenwerte (immer +)
        var prismInputs = container.querySelectorAll('.pp-prism-value');
        prismInputs.forEach(function(input) {
            updateSignSpan(input);
        });
    }

    // Hilfsfunktion: Aktualisiere Sign-Span basierend auf Input-Wert
    function updateSignSpan(input) {
        var wrapper = input.closest('.pp-spinner-wrapper');
        if (!wrapper) return;

        var signSpan = wrapper.querySelector('.pp-value-sign');
        if (!signSpan) return;

        var value = parseFloat(input.value) || 0;
        var fieldName = input.getAttribute('name') || '';

        // Prismenwerte & Addition: immer +
        if (input.classList.contains('pp-prism-value') || fieldName === 'refraktion_add') {
            signSpan.textContent = '+';
        }
        // Zylinder: immer - (außer bei 0)
        else if (fieldName.includes('_cyl')) {
            if (value === 0) {
                signSpan.textContent = '±';
            } else {
                signSpan.textContent = '−';  // Immer Minus bei CYL
            }
        }
        // SPH: +, -, oder ±
        else {
            if (value > 0) {
                signSpan.textContent = '+';
            } else if (value < 0) {
                signSpan.textContent = '−';
            } else {
                signSpan.textContent = '±';
            }
        }
    }

    // Initialisiere beim Laden
    setTimeout(initializeValueSigns, 100);

    /**
     * Spinner-Buttons für Refraktionswerte
     * Erhöht/Verringert Werte in definierten Schritten (0.25) mit Min/Max
     */
    on(container, 'click', '.pp-spinner-btn', function(e) {
        e.preventDefault();

        var targetName = this.getAttribute('data-target');
        var input = container.querySelector('[name="' + targetName + '"]');

        if (!input) return;

        var currentValue = parseFloat(input.value) || 0;
        var step = parseFloat(input.getAttribute('data-step')) || 0.25;
        var min = parseFloat(input.getAttribute('data-min')) || -16;
        var max = parseFloat(input.getAttribute('data-max')) || 16;

        var newValue;
        if (this.classList.contains('pp-spinner-plus')) {
            newValue = currentValue + step;
        } else {
            newValue = currentValue - step;
        }

        // Runden auf 2 Dezimalstellen um Floating-Point-Fehler zu vermeiden
        newValue = Math.round(newValue * 100) / 100;

        // Spezielle Regeln für bestimmte Felder
        var fieldName = input.getAttribute('name') || '';

        // Zylinder: Max ist 0 (nur negative Werte erlaubt)
        if (fieldName.includes('_cyl')) {
            if (newValue > 0) newValue = 0;
            if (newValue < min) newValue = min;
        }
        // Addition & Prismen: Min ist 0 (nur positive Werte)
        else if (fieldName === 'refraktion_add' || input.classList.contains('pp-prism-value')) {
            if (newValue < 0) newValue = 0;
            if (newValue > max) newValue = max;
        }
        // Normale Min/Max Prüfung
        else {
            if (newValue < min) newValue = min;
            if (newValue > max) newValue = max;
        }

        // Wert setzen - spezielle Formatierung je nach Typ
        if (input.classList.contains('pp-prism-value')) {
            // Prismenwerte: immer mit + und pdpt anzeigen
            input.value = newValue.toFixed(1);
            updateSignSpan(input);
        } else if (input.classList.contains('pp-refraction-value')) {
            // Refraktionswerte (SPH/CYL): mit +/- Vorzeichen und dpt
            var formattedValue = newValue.toFixed(2);
            input.value = formattedValue;
            updateSignSpan(input);
        } else {
            // Normale Werte ohne Formatierung
            input.value = newValue.toFixed(2);
        }

        // Buttons aktivieren/deaktivieren basierend auf Min/Max
        updateSpinnerButtons(input);
    });

    /**
     * Aktualisiert den Status der Spinner-Buttons (aktiviert/deaktiviert)
     */
    function updateSpinnerButtons(input) {
        var value = parseFloat(input.value) || 0;
        var min = parseFloat(input.getAttribute('data-min')) || -16;
        var max = parseFloat(input.getAttribute('data-max')) || 16;
        var targetName = input.getAttribute('name');
        var fieldName = input.getAttribute('name') || '';

        var minusBtn = container.querySelector('.pp-spinner-minus[data-target="' + targetName + '"]');
        var plusBtn = container.querySelector('.pp-spinner-plus[data-target="' + targetName + '"]');

        // Zylinder: Max ist 0
        if (fieldName.includes('_cyl')) {
            if (minusBtn) {
                minusBtn.disabled = (value <= min);
            }
            if (plusBtn) {
                plusBtn.disabled = (value >= 0);  // Bei 0 ist Schluss
            }
        }
        // Addition & Prismen: Min ist 0
        else if (fieldName === 'refraktion_add' || input.classList.contains('pp-prism-value')) {
            if (minusBtn) {
                minusBtn.disabled = (value <= 0);  // Bei 0 ist Schluss
            }
            if (plusBtn) {
                plusBtn.disabled = (value >= max);
            }
        }
        // Normale Felder
        else {
            if (minusBtn) {
                minusBtn.disabled = (value <= min);
            }
            if (plusBtn) {
                plusBtn.disabled = (value >= max);
            }
        }
    }

    // Initial: Alle Spinner-Buttons Status setzen und Vorzeichen für Refraktionswerte
    setTimeout(function() {
        var spinnerInputs = container.querySelectorAll('.pp-spinner-input');
        spinnerInputs.forEach(function(input) {
            updateSpinnerButtons(input);

            // Vorzeichen für Refraktionswerte & Prismenwerte initial setzen
            if (input.classList.contains('pp-refraction-value') || input.classList.contains('pp-prism-value')) {
                updateSignSpan(input);
            }
        });
    }, 100);

    // Event-Listener für manuelle Zahleneingabe
    on(container, 'input', '.pp-spinner-input', function() {
        var input = this;
        var fieldName = input.getAttribute('name') || '';
        var min = parseFloat(input.getAttribute('data-min')) || 0;
        var max = parseFloat(input.getAttribute('data-max')) || 100;
        var step = parseFloat(input.getAttribute('data-step')) || 0.25;
        var value = parseFloat(input.value);

        // Wenn leer oder ungültig, nichts tun (erlaubt Löschen während der Eingabe)
        if (isNaN(value)) return;

        // Zylinder (CYL): Positive Werte zu negativ machen (außer 0)
        if (fieldName.includes('_cyl') && value > 0) {
            value = -value;
        }

        // Prismenwerte & Addition: Negative Werte verhindern (min ist immer 0)
        if ((input.classList.contains('pp-prism-value') || fieldName === 'refraktion_add') && value < 0) {
            value = 0;
        }

        // Auf Min/Max begrenzen
        if (value < min) value = min;
        if (value > max) value = max;

        // Auf Step runden
        value = Math.round(value / step) * step;

        // Wert aktualisieren
        if (input.classList.contains('pp-prism-value')) {
            input.value = value.toFixed(1);
        } else if (input.classList.contains('pp-refraction-value')) {
            input.value = value.toFixed(2);
        } else {
            input.value = value.toFixed(2);
        }

        // Vorzeichen und Buttons aktualisieren
        if (input.classList.contains('pp-refraction-value') || input.classList.contains('pp-prism-value')) {
            updateSignSpan(input);
        }
        updateSpinnerButtons(input);
    });

    // Bei Blur (Verlassen des Feldes) final validieren
    on(container, 'blur', '.pp-spinner-input', function() {
        var input = this;
        var min = parseFloat(input.getAttribute('data-min')) || 0;
        var value = parseFloat(input.value);

        // Wenn leer, auf Minimum setzen
        if (isNaN(value) || input.value === '') {
            if (input.classList.contains('pp-prism-value')) {
                input.value = min.toFixed(1);
            } else if (input.classList.contains('pp-refraction-value')) {
                input.value = min.toFixed(2);
            } else {
                input.value = min.toFixed(2);
            }

            if (input.classList.contains('pp-refraction-value') || input.classList.contains('pp-prism-value')) {
                updateSignSpan(input);
            }
            updateSpinnerButtons(input);
        }
    });

    /* =====================================================================
     * 13. CONDITIONAL FIELDS
     * ================================================================== */

    /**
     * Conditional Field System für dynamisches Ein-/Ausblenden von Feldern
     * Verwendet data-conditional-field und data-conditional-value Attribute
     */
    function updateConditionalFields(triggerElement) {
        var triggerName = triggerElement.name;
        var triggerValue = triggerElement.value;

        // Nur wenn das Trigger-Element checked ist (für Radio/Checkbox)
        if ((triggerElement.type === 'radio' || triggerElement.type === 'checkbox') && !triggerElement.checked) {
            return;
        }

        // Finde alle conditional fields, die von diesem Trigger abhängen
        var conditionalFields = container.querySelectorAll('[data-conditional-field="' + triggerName + '"]');

        conditionalFields.forEach(function(field) {
            var expectedValue = field.getAttribute('data-conditional-value');

            if (triggerValue === expectedValue) {
                // Zeige das Feld
                field.style.display = '';
                // Aktiviere required Felder innerhalb
                var requiredInputs = field.querySelectorAll('input[data-required="true"]');
                requiredInputs.forEach(function(input) {
                    input.required = true;
                });
            } else {
                // Verstecke das Feld
                field.style.display = 'none';
                // Deaktiviere required Felder innerhalb
                var requiredInputs = field.querySelectorAll('input[required]');
                requiredInputs.forEach(function(input) {
                    input.required = false;
                    input.setAttribute('data-required', 'true');
                });
                // Lösche Werte
                var inputs = field.querySelectorAll('input, select, textarea');
                inputs.forEach(function(input) {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        input.checked = false;
                    } else {
                        input.value = '';
                    }
                });
            }
        });
    }

    // Event Handler für Änderungen
    on(container, 'change', '[data-conditional-trigger]', function(e) {
        updateConditionalFields(this);
    });

    // Initialisierung: Verstecke alle conditional fields beim Laden
    setTimeout(function() {
        var allConditionalFields = container.querySelectorAll('[data-conditional-field]');
        allConditionalFields.forEach(function(field) {
            field.style.display = 'none';
        });

        // Dann zeige nur die Felder an, deren Trigger aktiv sind
        var allTriggers = container.querySelectorAll('[data-conditional-trigger]');
        allTriggers.forEach(function(trigger) {
            if ((trigger.type === 'radio' || trigger.type === 'checkbox') && trigger.checked) {
                updateConditionalFields(trigger);
            } else if (trigger.type !== 'radio' && trigger.type !== 'checkbox' && trigger.value) {
                updateConditionalFields(trigger);
            }
        });
    }, 100);

    /* =====================================================================
     * 13. HELPERS
     * ================================================================== */

    /**
     * Delegierter Event-Listener
     */
    function on(root, event, selector, handler) {
        root.addEventListener(event, function (e) {
            var target = e.target.closest(selector);
            if (target && root.contains(target)) {
                handler.call(target, e);
            }
        });
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function escAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;');
    }

})();
