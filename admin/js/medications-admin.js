/**
 * Praxis-Portal v4 – Medikamenten-Datenbank Admin JS
 *
 * Nutzt ppAjax() aus admin.js mit sub_action Pattern.
 * i18n-Strings werden über wp_localize_script als ppMedI18n bereitgestellt.
 *
 * @package PraxisPortal
 * @since   4.0.0
 * @updated 4.2.7 – Inline-Scripts hierher verschoben, i18n via ppMedI18n
 */
(function($) {
    'use strict';

    var i18n = window.ppMedI18n || {};

    /* =====================================================================
     * MEDICATION CRUD
     * ================================================================== */

    // Medikament hinzufügen (Formular im Datenbank-Tab)
    $(document).on('click', '#pp-medication-save', function(e) {
        e.preventDefault();

        var $form = $('#pp-medication-form');
        var name  = $form.find('[name="name"]').val();

        if (!name || !name.trim()) {
            ppNotice(i18n.enter_name || 'Bitte Bezeichnung eingeben.', 'warning');
            $form.find('[name="name"]').focus();
            return;
        }

        var data = {};
        $form.serializeArray().forEach(function(f) {
            data[f.name] = f.value;
        });

        ppAjax('medication_create', data, {
            button: this,
            onSuccess: function() {
                ppNotice(i18n.added || 'Medikament hinzugefügt', 'success');
                $form[0].reset();
                location.reload();
            }
        });
    });

    // Medikament löschen
    $(document).on('click', '.pp-delete-medication', function(e) {
        e.preventDefault();

        ppAjax('medication_delete', {
            medication_id: $(this).data('id')
        }, {
            confirm: i18n.delete_confirm || 'Medikament wirklich löschen?',
            button: this,
            reload: true
        });
    });

    // Inline-Edit: Bearbeitung starten
    $(document).on('click', '.pp-edit-medication', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        $row.find('.pp-med-display').hide();
        $row.find('.pp-med-edit').show();
        $row.find('.pp-med-edit input:first').focus();
    });

    // Inline-Edit: Speichern
    $(document).on('click', '.pp-save-medication', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var id   = $row.data('id');

        var data = {
            medication_id: id,
            name:    $row.find('[name="name"]').val(),
            dosage:  $row.find('[name="dosage"]').val(),
            form:    $row.find('[name="form"]').val(),
            pzn:     $row.find('[name="pzn"]').val()
        };

        ppAjax('medication_update', data, {
            button: this,
            onSuccess: function() {
                ppNotice(i18n.saved || 'Gespeichert', 'success');
                location.reload();
            }
        });
    });

    // Inline-Edit: Abbrechen
    $(document).on('click', '.pp-cancel-edit', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        $row.find('.pp-med-display').show();
        $row.find('.pp-med-edit').hide();
    });

    /* =====================================================================
     * ALLE LÖSCHEN
     * ================================================================== */

    $(document).on('click', '#pp-delete-all-medications', function(e) {
        e.preventDefault();

        ppAjax('medication_delete_all', {}, {
            confirm: '⚠️ ' + (i18n.delete_all_confirm || 'ALLE Medikamente löschen? Dies kann nicht rückgängig gemacht werden!'),
            button: this,
            reload: true,
            successMsg: i18n.all_deleted || 'Alle Medikamente gelöscht'
        });
    });

    /* =====================================================================
     * REPARIEREN / BEREINIGEN / STANDARD-IMPORT
     * (vorher inline in Admin.php, jetzt hier mit i18n)
     * ================================================================== */

    // Standard-Medikamente importieren (Empty-State + Datenbank-Tab)
    $(document).on('click', '#pp-import-standard-meds, #pp-seed-standard-meds', function(e) {
        e.preventDefault();
        var isSeed = this.id === 'pp-seed-standard-meds';

        if (isSeed && !confirm(i18n.seed_confirm || 'Standard-Medikamente aus der mitgelieferten CSV importieren?')) {
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true).text('⏳ ' + (i18n.importing || 'Importiere...'));

        ppAjax('medication_import_standard', {}, {
            button: this,
            reload: true,
            successMsg: i18n.standard_imported || 'Standard-Medikamente importiert'
        });
    });

    // Fehlerhafte Einträge reparieren
    $(document).on('click', '#pp-repair-medications', function(e) {
        e.preventDefault();
        if (!confirm(i18n.repair_confirm || 'Fehlerhafte Einträge automatisch reparieren?')) return;
        ppAjax('medication_repair_broken', {}, {
            button: this,
            reload: true,
            successMsg: i18n.repaired || 'Einträge repariert'
        });
    });

    // Fehlerhafte Einträge löschen
    $(document).on('click', '#pp-delete-all-broken', function(e) {
        e.preventDefault();
        if (!confirm(i18n.delete_broken_confirm || 'ALLE fehlerhaften Einträge löschen?')) return;
        ppAjax('medication_delete_broken', {}, {
            button: this,
            reload: true,
            successMsg: i18n.deleted || 'Gelöscht'
        });
    });

    // Kommentarzeilen bereinigen
    $(document).on('click', '#pp-cleanup-comments', function(e) {
        e.preventDefault();
        if (!confirm(i18n.cleanup_confirm || 'Kommentarzeilen löschen?')) return;
        ppAjax('medication_cleanup_comments', {}, {
            button: this,
            reload: true,
            successMsg: i18n.cleaned || 'Bereinigt'
        });
    });

    /* =====================================================================
     * CSV-IMPORT (Drag & Drop)
     * ================================================================== */

    var $importZone = $('.pp-import-zone');
    var $fileInput  = $('#pp-import-file');
    var $progress   = $('.pp-import-progress');

    $importZone.on('click', function() {
        $fileInput.trigger('click');
    });

    $importZone.on('dragover dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('pp-drag-over');
    });

    $importZone.on('dragleave drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('pp-drag-over');
    });

    $importZone.on('drop', function(e) {
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) {
            processImportFile(files[0]);
        }
    });

    $fileInput.on('change', function() {
        if (this.files.length) {
            processImportFile(this.files[0]);
        }
    });

    function processImportFile(file) {
        if (!file.name.match(/\.(csv|txt)$/i)) {
            ppNotice(i18n.select_csv || 'Bitte eine CSV-Datei auswählen.', 'error');
            return;
        }

        var reader = new FileReader();
        reader.onload = function(e) {
            var content = e.target.result;
            var lines   = content.split('\n').filter(function(l) {
                var t = l.trim();
                return t && !t.startsWith('#');
            });

            if (lines.length < 2) {
                ppNotice(i18n.csv_empty || 'CSV-Datei ist leer oder ungültig.', 'error');
                return;
            }

            $progress.addClass('active');
            updateProgress(0, lines.length - 1);

            var rows    = parseCSV(lines);
            var batches = chunk(rows, 50);
            var total   = rows.length;
            var done    = 0;

            function sendBatch(idx) {
                if (idx >= batches.length) {
                    updateProgress(total, total);
                    ppNotice(total + ' ' + (i18n.medications_imported || 'Medikamente importiert'), 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                    return;
                }

                ppAjax('medication_import_batch', {
                    rows: JSON.stringify(batches[idx])
                }, {
                    onSuccess: function() {
                        done += batches[idx].length;
                        updateProgress(done, total);
                        sendBatch(idx + 1);
                    },
                    onError: function(msg) {
                        ppNotice((i18n.import_error || 'Import-Fehler bei Batch') + ' ' + (idx + 1) + ': ' + msg, 'error');
                        sendBatch(idx + 1);
                    }
                });
            }

            sendBatch(0);
        };

        reader.readAsText(file);
    }

    function parseCSV(lines) {
        var rows = [];
        var dataLines = lines.filter(function(l) {
            var trimmed = l.trim();
            return trimmed && !trimmed.startsWith('#');
        });

        if (dataLines.length < 2) return rows;

        var firstLine = dataLines[0];
        var delimiter = firstLine.indexOf(';') > -1 ? ';' : ',';

        var headers = dataLines[0].split(delimiter).map(function(h) {
            return h.trim().toLowerCase().replace(/\r/g, '');
        });

        for (var i = 1; i < dataLines.length; i++) {
            var line = dataLines[i].trim().replace(/\r/g, '');
            if (!line || line.startsWith('#')) continue;

            var cols = line.split(delimiter);
            if (cols.length < 1 || !cols[0].trim()) continue;

            var row = {};
            headers.forEach(function(h, idx) {
                row[h] = (cols[idx] || '').trim();
            });

            var name = row.name || row.bezeichnung || row.medication || cols[0] || '';
            var dosage = '';
            if (row.wirkstoff || row.staerke) {
                dosage = [row.wirkstoff, row.staerke].filter(Boolean).join(' ');
            } else {
                dosage = row.dosage || row.dosierung || row.dosis || row.standard_dosierung || '';
            }
            var form = row.kategorie || row.form || row.darreichungsform || row.kategory || '';
            var pzn = row.pzn || row.pharmazentralnummer || '';

            if (!name) continue;

            rows.push({ name: name, dosage: dosage, form: form, pzn: pzn });
        }

        return rows;
    }

    function updateProgress(current, total) {
        var pct = total > 0 ? Math.round((current / total) * 100) : 0;
        $('.pp-progress-bar-fill').css('width', pct + '%').text(pct + '%');
    }

    function chunk(arr, size) {
        var result = [];
        for (var i = 0; i < arr.length; i += size) {
            result.push(arr.slice(i, i + size));
        }
        return result;
    }

    /* =====================================================================
     * LIVE-SUCHE IN MEDIKAMENTEN-TABELLE
     * ================================================================== */

    var searchTimeout;
    $(document).on('input', '#pp-medication-search', function() {
        var query = $(this).val().toLowerCase();

        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            $('.pp-medications-table tbody tr').each(function() {
                var text = $(this).text().toLowerCase();
                $(this).toggle(text.indexOf(query) > -1);
            });
        }, 200);
    });

})(jQuery);
