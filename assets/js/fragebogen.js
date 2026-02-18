/**
 * Praxis-Portal: Fragebogen (Anamnesebogen) JavaScript
 *
 * Handles:
 * - Conditional field/section visibility (data-condition)
 * - Info-trigger popups
 * - File upload preview
 * - Signature pad
 * - Form validation hints
 *
 * @package PraxisPortal
 * @since   4.2.9
 */
(function () {
    'use strict';

    // =========================================================================
    // CONDITIONAL VISIBILITY
    // =========================================================================

    /**
     * Aktuellen Wert eines Feldes ermitteln
     */
    function getFieldValue(fieldId) {
        var wrapper = document.querySelector('[data-field-id="' + fieldId + '"]');
        if (!wrapper) return null;

        // Radio-Buttons
        var radios = wrapper.querySelectorAll('input[type="radio"]');
        if (radios.length) {
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) return radios[i].value;
            }
            return '';
        }

        // Checkbox-Group → Array von Werten
        var checkboxes = wrapper.querySelectorAll('input[type="checkbox"][name$="[]"]');
        if (checkboxes.length) {
            var vals = [];
            for (var j = 0; j < checkboxes.length; j++) {
                if (checkboxes[j].checked) vals.push(checkboxes[j].value);
            }
            return vals;
        }

        // Einzelne Checkbox
        var singleCb = wrapper.querySelector('input[type="checkbox"]');
        if (singleCb) {
            return singleCb.checked ? singleCb.value : '';
        }

        // Select
        var sel = wrapper.querySelector('select');
        if (sel) return sel.value;

        // Text / Date / Email / Tel
        var input = wrapper.querySelector('input');
        if (input) return input.value;

        var textarea = wrapper.querySelector('textarea');
        if (textarea) return textarea.value;

        return null;
    }

    /**
     * Prüft ob eine Condition erfüllt ist
     *
     * Formate:
     *   {"field": "kasse", "value": "privat"}            → Exakter Wert
     *   {"field": "privat_art", "contains": "familien"}   → Enthält Wert (checkbox_group)
     *   {"field": "diabetes", "value": "ja"}              → Radio/Select = "ja"
     */
    function checkCondition(condition) {
        if (!condition) return true;

        // NEW: Support for AND/OR operators with multiple conditions
        if (condition.operator && condition.conditions && Array.isArray(condition.conditions)) {
            if (condition.operator === 'AND') {
                // All conditions must be true
                for (var i = 0; i < condition.conditions.length; i++) {
                    if (!checkCondition(condition.conditions[i])) {
                        return false;
                    }
                }
                return true;
            } else if (condition.operator === 'OR') {
                // At least one condition must be true
                for (var i = 0; i < condition.conditions.length; i++) {
                    if (checkCondition(condition.conditions[i])) {
                        return true;
                    }
                }
                return false;
            }
        }

        // Original single-condition logic
        if (!condition.field) return true;

        var val = getFieldValue(condition.field);
        if (val === null) return false;

        // "contains" → Prüfe ob Array den Wert enthält (für checkbox_group)
        if (condition.contains !== undefined) {
            if (Array.isArray(val)) {
                return val.indexOf(condition.contains) !== -1;
            }
            // String: Teilstring-Match
            return String(val).indexOf(condition.contains) !== -1;
        }

        // "value" → Exakter Match
        if (condition.value !== undefined) {
            if (Array.isArray(val)) {
                return val.indexOf(condition.value) !== -1;
            }
            return String(val) === String(condition.value);
        }

        // "not_value" → Nicht gleich
        if (condition.not_value !== undefined) {
            return String(val) !== String(condition.not_value);
        }

        // "not_empty" → Feld hat einen Wert
        if (condition.not_empty) {
            if (Array.isArray(val)) return val.length > 0;
            return val !== '' && val !== null;
        }

        return true;
    }

    /**
     * Alle bedingten Felder und Sektionen evaluieren
     */
    function evaluateAllConditions() {
        var conditionals = document.querySelectorAll('[data-condition]');
        for (var i = 0; i < conditionals.length; i++) {
            var el = conditionals[i];
            var condition;
            try {
                condition = JSON.parse(el.getAttribute('data-condition'));
            } catch (e) {
                continue;
            }

            var show = checkCondition(condition);

            if (show) {
                el.style.display = '';
                el.classList.remove('pp-hidden');
                // Required-Felder reaktivieren
                enableRequiredFields(el, true);
            } else {
                el.style.display = 'none';
                el.classList.add('pp-hidden');
                // Required von versteckten Feldern entfernen (sonst blockiert Submit)
                enableRequiredFields(el, false);
            }
        }
    }

    /**
     * Required-Attribute in versteckten Feldern deaktivieren/aktivieren
     */
    function enableRequiredFields(container, enable) {
        var inputs = container.querySelectorAll('input, select, textarea');
        for (var i = 0; i < inputs.length; i++) {
            if (enable) {
                // Nur wiederherstellen wenn ursprünglich required
                if (inputs[i].dataset.wasRequired === '1') {
                    inputs[i].required = true;
                }
            } else {
                if (inputs[i].required) {
                    inputs[i].dataset.wasRequired = '1';
                    inputs[i].required = false;
                }
            }
        }
    }

    // =========================================================================
    // INFO-TRIGGER POPUPS
    // =========================================================================

    function initInfoTriggers() {
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('.pp-info-trigger');
            if (!trigger) return;

            e.preventDefault();
            var field = trigger.closest('.pp-field') || trigger.parentElement;
            var existing = field.querySelector('.pp-info-popup');

            // Toggle: existierendes Popup entfernen
            if (existing) {
                existing.remove();
                return;
            }

            // Alle anderen Popups schließen
            document.querySelectorAll('.pp-info-popup').forEach(function (p) { p.remove(); });

            var popup = document.createElement('div');
            popup.className = 'pp-info-popup';
            popup.textContent = trigger.getAttribute('data-info');
            field.appendChild(popup);
        });
    }

    // =========================================================================
    // FILE UPLOAD PREVIEW
    // =========================================================================

    function initFileUploads() {
        document.addEventListener('change', function (e) {
            if (!e.target.classList.contains('pp-file-input')) return;

            var input = e.target;
            var previewContainer = input.closest('.pp-file-upload-wrapper')
                .querySelector('.pp-file-preview');
            if (!previewContainer) return;

            previewContainer.innerHTML = '';

            if (!input.files || !input.files.length) return;

            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];
                var size = file.size > 1024 * 1024
                    ? (file.size / (1024 * 1024)).toFixed(1) + ' MB'
                    : Math.round(file.size / 1024) + ' KB';

                var item = document.createElement('div');
                item.className = 'pp-file-preview-item';
                item.innerHTML = '<span>📎 ' + escHtml(file.name) + ' (' + size + ')</span>';
                previewContainer.appendChild(item);
            }
        });
    }

    // =========================================================================
    // SIGNATURE PAD
    // =========================================================================

    function initSignaturePads() {
        var canvases = document.querySelectorAll('.pp-signature-pad');
        canvases.forEach(function (canvas) {
            var ctx = canvas.getContext('2d');
            var drawing = false;
            var hiddenInput = canvas.closest('.pp-signature-wrapper')
                .querySelector('.pp-signature-data');

            // Canvas auf tatsächliche Größe skalieren
            function resizeCanvas() {
                var rect = canvas.getBoundingClientRect();
                canvas.width = rect.width;
                canvas.height = rect.height;
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
            }
            resizeCanvas();

            function getPos(e) {
                var rect = canvas.getBoundingClientRect();
                var touch = e.touches ? e.touches[0] : e;
                return {
                    x: touch.clientX - rect.left,
                    y: touch.clientY - rect.top
                };
            }

            function startDraw(e) {
                drawing = true;
                var pos = getPos(e);
                ctx.beginPath();
                ctx.moveTo(pos.x, pos.y);
                e.preventDefault();
            }

            function draw(e) {
                if (!drawing) return;
                var pos = getPos(e);
                ctx.lineTo(pos.x, pos.y);
                ctx.stroke();
                e.preventDefault();
            }

            function endDraw() {
                if (!drawing) return;
                drawing = false;
                // Signatur als Base64 speichern
                if (hiddenInput) {
                    hiddenInput.value = canvas.toDataURL('image/png');
                }
            }

            canvas.addEventListener('mousedown', startDraw);
            canvas.addEventListener('mousemove', draw);
            canvas.addEventListener('mouseup', endDraw);
            canvas.addEventListener('mouseleave', endDraw);

            canvas.addEventListener('touchstart', startDraw, { passive: false });
            canvas.addEventListener('touchmove', draw, { passive: false });
            canvas.addEventListener('touchend', endDraw);

            // Clear-Button - erstellen falls nicht vorhanden
            var wrapper = canvas.closest('.pp-signature-wrapper');
            var clearBtn = wrapper.querySelector('.pp-signature-clear');

            if (!clearBtn) {
                // Button existiert nicht - erstellen!
                var actionsDiv = wrapper.querySelector('.pp-signature-actions');
                if (!actionsDiv) {
                    // Actions div auch erstellen
                    actionsDiv = document.createElement('div');
                    actionsDiv.className = 'pp-signature-actions';
                    wrapper.appendChild(actionsDiv);
                }

                clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'pp-signature-clear';
                clearBtn.textContent = 'Löschen';
                actionsDiv.appendChild(clearBtn);
            }

            // Event-Listener für Clear-Button
            clearBtn.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                if (hiddenInput) hiddenInput.value = '';
            });

            // Resize-Handler
            window.addEventListener('resize', resizeCanvas);
        });
    }

    // =========================================================================
    // FORM SUBMIT (AJAX)
    // =========================================================================

    function initFormSubmit() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.classList.contains('pp-fragebogen-form')) return;

            // Validierung: Sichtbare Pflichtfelder prüfen
            var invalid = form.querySelector(':invalid:not(.pp-hidden :invalid)');
            if (invalid) {
                // Browser-Validierung zeigt Hinweis
                return;
            }

            // Submit-Button deaktivieren
            var btn = form.querySelector('.pp-fragebogen-submit');
            if (btn) {
                btn.disabled = true;
                btn.textContent = btn.dataset.loadingText || 'Wird gesendet…';
            }

            // Formular wird normal (non-AJAX) submitted – kein e.preventDefault()
        });
    }

    // =========================================================================
    // EVENT-DELEGATION FÜR CONDITIONS
    // =========================================================================

    function initConditionListeners() {
        // Auf alle Input-Änderungen im Formular reagieren
        var wrapper = document.querySelector('.pp-fragebogen-wrapper');
        if (!wrapper) return;

        wrapper.addEventListener('change', function (e) {
            var tag = e.target.tagName.toLowerCase();
            if (tag === 'input' || tag === 'select' || tag === 'textarea') {
                evaluateAllConditions();
            }
        });

        // Initiale Evaluierung
        evaluateAllConditions();
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // =========================================================================
    // INIT
    // =========================================================================

    // =========================================================================
    // FIELD VALIDATION - TOUCHED STATE
    // =========================================================================

    /**
     * Markiert Felder als "touched" nach Interaktion
     * Zeigt Fehler erst nach blur/change, nicht sofort beim Laden
     */
    function initFieldValidation() {
        var form = document.querySelector('.pp-fragebogen-form, .pp-form');
        if (!form) return;

        // Alle Eingabefelder
        var fields = form.querySelectorAll('input, select, textarea');

        fields.forEach(function (field) {
            var wrapper = field.closest('.pp-field');
            if (!wrapper) return;

            // Markiere als "touched" nach erstem Verlassen
            field.addEventListener('blur', function () {
                wrapper.classList.add('pp-touched');
            });

            // Auch bei Änderung markieren (für Checkboxen/Radios)
            field.addEventListener('change', function () {
                wrapper.classList.add('pp-touched');
            });
        });

        // Markiere Form als "submitted" beim Submit-Versuch
        form.addEventListener('submit', function (e) {
            form.classList.add('pp-submitted');

            // Alle Felder als touched markieren
            form.querySelectorAll('.pp-field').forEach(function (wrapper) {
                wrapper.classList.add('pp-touched');
            });
        });
    }

    // =========================================================================
    // BIRTHDATE FIELDS (TT MM JJJJ)
    // =========================================================================

    /**
     * Initialisiert Geburtsdatum-Felder mit Tag/Monat/Jahr Eingaben
     * Kombiniert die 3 Felder automatisch in das versteckte Feld (YYYY-MM-DD)
     */
    function initBirthdateFields() {
        var containers = document.querySelectorAll('.pp-birthdate-inputs');
        if (!containers.length) return;

        containers.forEach(function (container) {
            var tagInput = container.querySelector('input[name$="_tag"]');
            var monatInput = container.querySelector('input[name$="_monat"]');
            var jahrInput = container.querySelector('input[name$="_jahr"]');

            if (!tagInput || !monatInput || !jahrInput) return;

            // Automatischer Focus-Wechsel für bessere UX
            tagInput.addEventListener('input', function () {
                if (this.value.length >= 2) {
                    monatInput.focus();
                }
            });

            monatInput.addEventListener('input', function () {
                if (this.value.length >= 2) {
                    jahrInput.focus();
                }
            });

            // Validierung: Tag 1-31
            tagInput.addEventListener('blur', function () {
                var val = parseInt(this.value, 10);
                if (val < 1) this.value = '1';
                if (val > 31) this.value = '31';
            });

            // Validierung: Monat 1-12
            monatInput.addEventListener('blur', function () {
                var val = parseInt(this.value, 10);
                if (val < 1) this.value = '1';
                if (val > 12) this.value = '12';
            });

            // Validierung: Jahr 1900-aktuelles Jahr
            jahrInput.addEventListener('blur', function () {
                var val = parseInt(this.value, 10);
                var currentYear = new Date().getFullYear();
                if (val < 1900) this.value = '1900';
                if (val > currentYear) this.value = currentYear.toString();
                updateHiddenField();
            });

            // Funktion: Verstecktes Feld aktualisieren (YYYY-MM-DD)
            function updateHiddenField() {
                var tag = tagInput.value.padStart(2, '0');
                var monat = monatInput.value.padStart(2, '0');
                var jahr = jahrInput.value;

                if (tag && monat && jahr) {
                    // Finde das versteckte Feld (ohne _tag/_monat/_jahr Suffix)
                    var baseName = tagInput.name.replace('_tag', '');
                    var hiddenField = document.querySelector('input[name="' + baseName + '"][type="hidden"]');
                    if (hiddenField) {
                        hiddenField.value = jahr + '-' + monat + '-' + tag;
                    }
                }
            }

            // Event Listener für alle 3 Felder
            tagInput.addEventListener('input', updateHiddenField);
            monatInput.addEventListener('input', updateHiddenField);
            jahrInput.addEventListener('input', updateHiddenField);
        });
    }

    // =========================================================================
    // BUTTON ACTIONS
    // =========================================================================

    function initButtonActions() {
        // Use event delegation on document so it works even for conditionally shown buttons
        document.addEventListener('click', function(e) {
            var button = e.target.closest('.pp-action-btn[data-action]');
            if (!button) return;

            e.preventDefault();
            var action = button.getAttribute('data-action');

            if (action === 'copy_patient_address') {
                // Copy address from stammdaten to hauptversicherter
                // Note: Field IDs have 'pp_' prefix
                var strasse = document.getElementById('pp_strasse');
                var plz = document.getElementById('pp_plz');
                var ort = document.getElementById('pp_ort');

                var hvStrasse = document.getElementById('pp_hv_strasse');
                var hvPlz = document.getElementById('pp_hv_plz');
                var hvOrt = document.getElementById('pp_hv_ort');

                // Check if source fields exist and have values
                if (!strasse || !plz || !ort) {
                    console.error('Stammdaten-Felder nicht gefunden');
                    alert('Bitte füllen Sie zuerst die Adressfelder unter Stammdaten aus.');
                    return;
                }

                // Check if target fields exist
                if (!hvStrasse || !hvPlz || !hvOrt) {
                    console.error('Hauptversicherter-Felder nicht gefunden');
                    alert('Hauptversicherter-Felder nicht gefunden. Bitte aktualisieren Sie die Seite.');
                    return;
                }

                // Copy values
                hvStrasse.value = strasse.value;
                hvPlz.value = plz.value;
                hvOrt.value = ort.value;

                // Trigger change events so other validation/listeners are notified
                hvStrasse.dispatchEvent(new Event('change', { bubbles: true }));
                hvPlz.dispatchEvent(new Event('change', { bubbles: true }));
                hvOrt.dispatchEvent(new Event('change', { bubbles: true }));

                // Visual feedback
                var originalText = button.textContent;
                button.textContent = '✓ Adresse übernommen';
                button.style.background = '#28a745';
                button.style.color = '#fff';
                button.style.borderColor = '#28a745';

                setTimeout(function() {
                    button.textContent = originalText;
                    button.style.background = '';
                    button.style.color = '';
                    button.style.borderColor = '';
                }, 2000);
            }
        });
    }

    // =========================================================================
    // SUCCESS MESSAGE SCROLL
    // =========================================================================

    function initSuccessMessage() {
        var success = document.querySelector('.pp-fragebogen-success');
        if (success) {
            // Scroll to success message
            setTimeout(function() {
                success.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);

            // Hide form wrapper
            var wrapper = document.querySelector('.pp-fragebogen-wrapper');
            if (wrapper && wrapper.querySelector('form')) {
                // Success is shown, form is not needed anymore
                var form = wrapper.querySelector('form');
                if (form) {
                    form.style.display = 'none';
                }
            }
        }
    }

    function init() {
        if (!document.querySelector('.pp-fragebogen-wrapper, .pp-form')) return;

        initFieldValidation();
        initConditionListeners();
        initInfoTriggers();
        initFileUploads();
        initSignaturePads();
        initBirthdateFields();
        initButtonActions();
        initFormSubmit();
        initSuccessMessage();
    }

    // DOM-Ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
