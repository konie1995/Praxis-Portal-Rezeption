/**
 * Praxis-Portal v4 – Admin JavaScript
 *
 * Globales Objekt: ppAdmin (via wp_localize_script)
 *  - ajaxUrl, nonce, version, i18n
 *
 * Alle AJAX-Aufrufe gehen über eine einzige WP-Action (pp_admin_action)
 * mit einem sub_action-Parameter (→ Admin.php Dispatch-Map).
 *
 * @package PraxisPortal
 * @since   4.0.0
 */
(function ($) {
    'use strict';

    /* =====================================================================
     * 1. AJAX UTILITY
     * ================================================================== */

    /**
     * Zentraler AJAX-Aufruf an das Admin-Backend
     *
     * @param {string}   subAction  - Sub-Action (z.B. 'view_submission')
     * @param {Object}   data       - Zusätzliche POST-Daten
     * @param {Object}   opts       - Optionen: { onSuccess, onError, confirm, button }
     * @returns {jqXHR}
     */
    function ppAjax(subAction, data, opts) {
        opts = opts || {};

        // Bestätigungsdialog?
        if (opts.confirm && !confirm(opts.confirm)) {
            return $.Deferred().reject();
        }

        // Button deaktivieren
        var $btn = opts.button ? $(opts.button) : null;
        if ($btn) {
            $btn.prop('disabled', true).addClass('pp-loading');
        }

        var payload = $.extend({
            action:     'pp_admin_action',
            sub_action: subAction,
            nonce:      ppAdmin.nonce
        }, data || {});

        return $.post(ppAdmin.ajaxUrl, payload)
            .done(function (resp) {
                if (resp.success) {
                    if (opts.onSuccess) opts.onSuccess(resp.data);
                    if (opts.reload) location.reload();
                    if (opts.successMsg) ppNotice(opts.successMsg, 'success');
                } else {
                    var msg = (resp.data && resp.data.message) || ppAdmin.i18n.error;
                    if (opts.onError) opts.onError(msg);
                    else ppNotice(msg, 'error');
                }
            })
            .fail(function () {
                ppNotice(ppAdmin.i18n.error, 'error');
            })
            .always(function () {
                if ($btn) {
                    $btn.prop('disabled', false).removeClass('pp-loading');
                }
            });
    }

    // Global verfügbar machen
    window.ppAjax = ppAjax;

    /* =====================================================================
     * 2. NOTICE / TOAST
     * ================================================================== */

    function ppNotice(message, type) {
        type = type || 'info';
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible pp-notice-toast"><p>' + escHtml(message) + '</p></div>');
        $('.pp-admin-wrap .wrap, .wrap').first().prepend($notice);

        // Auto-dismiss
        setTimeout(function () {
            $notice.fadeOut(300, function () { $(this).remove(); });
        }, 5000);
    }

    window.ppNotice = ppNotice;

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* =====================================================================
     * 3. SUBMISSIONS
     * ================================================================== */

    $(document).on('click', '.pp-view-submission', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        ppAjax('view_submission', { submission_id: id }, {
            button: this,
            onSuccess: function (data) {
                $('#pp-detail-modal .pp-modal-body').html(data.html);
                $('#pp-detail-modal').addClass('pp-modal-open');
            }
        });
    });

    $(document).on('click', '.pp-close-modal, .pp-modal-overlay', function () {
        $(this).closest('.pp-modal').removeClass('pp-modal-open');
    });

    $(document).on('change', '.pp-status-select', function () {
        var $sel = $(this);
        var id   = $sel.data('id');
        ppAjax('update_status', {
            submission_id: id,
            status:        $sel.val()
        }, {
            onSuccess: function () {
                ppNotice('Status aktualisiert', 'success');
            }
        });
    });

    $(document).on('click', '.pp-delete-submission', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        ppAjax('delete_submission', { submission_id: id }, {
            confirm: ppAdmin.i18n.confirm_delete,
            button:  this,
            reload:  true
        });
    });

    $(document).on('click', '.pp-export-csv', function (e) {
        e.preventDefault();
        var params = $(this).closest('form').serialize();
        window.location.href = ppAdmin.ajaxUrl + '?' + params
            + '&action=pp_admin_action&sub_action=export_csv&nonce=' + ppAdmin.nonce;
    });

    $(document).on('click', '.pp-export-pdf', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        window.location.href = ppAdmin.ajaxUrl
            + '?action=pp_admin_action&sub_action=export_pdf&submission_id=' + id
            + '&nonce=' + ppAdmin.nonce;
    });

    /* =====================================================================
     * 4. LOCATIONS
     * ================================================================== */

    $(document).on('submit', '#pp-location-form', function (e) {
        e.preventDefault();
        var formData = $(this).serializeArray();
        var data     = {};
        $.each(formData, function (_, field) {
            data[field.name] = field.value;
        });

        ppAjax('save_location', data, {
            button: $(this).find('[type="submit"]'),
            onSuccess: function (resp) {
                ppNotice('Standort gespeichert', 'success');
                if (resp.redirect) {
                    window.location.href = resp.redirect;
                }
            }
        });
    });

    $(document).on('click', '.pp-delete-location', function (e) {
        e.preventDefault();
        ppAjax('delete_location', { location_id: $(this).data('id') }, {
            confirm: ppAdmin.i18n.confirm_delete,
            button:  this,
            reload:  true
        });
    });

    // ── Services (in Location-Detail) ──
    $(document).on('change', '.pp-toggle-service', function () {
        var $cb = $(this);
        ppAjax('toggle_service', {
            service_id: $cb.data('id'),
            active:     $cb.is(':checked') ? 1 : 0
        }, {
            onSuccess: function () {
                ppNotice('Service aktualisiert', 'success');
            },
            onError: function (msg) {
                // Checkbox zurücksetzen bei Fehler
                $cb.prop('checked', !$cb.is(':checked'));
                ppNotice(msg, 'error');
            }
        });
    });

    $(document).on('submit', '#pp-add-service-form', function (e) {
        e.preventDefault();
        var data = {};
        $(this).serializeArray().forEach(function (f) { data[f.name] = f.value; });

        ppAjax('add_custom_service', data, {
            button: $(this).find('[type="submit"]'),
            reload: true
        });
    });

    $(document).on('click', '.pp-delete-service', function (e) {
        e.preventDefault();
        ppAjax('delete_custom_service', {
            service_id: $(this).data('id')
        }, {
            confirm: ppAdmin.i18n.confirm_delete,
            button:  this,
            reload:  true
        });
    });

    // ── Downloads/Dokumente ──
    $(document).on('click', '.pp-delete-doc', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var docId = $btn.data('id');

        ppAjax('delete_document', {
            doc_id: docId
        }, {
            confirm: ppAdmin.i18n.confirm_delete || 'Dokument wirklich löschen?',
            button:  this,
            onSuccess: function(data) {
                // Zeile entfernen
                $btn.closest('tr').fadeOut(300, function() {
                    $(this).remove();

                    // Wenn keine Dokumente mehr da sind, Tabelle aktualisieren
                    if ($('#pp-downloads-table tbody tr').length === 0) {
                        location.reload();
                    }
                });

                if (data.message) {
                    ppNotice(data.message, 'success');
                }
            }
        });
    });

    // ── Portal-User ──
    $(document).on('submit', '#pp-portal-user-form', function (e) {
        e.preventDefault();
        var data = {};
        $(this).serializeArray().forEach(function (f) { data[f.name] = f.value; });

        ppAjax('save_portal_user', data, {
            button: $(this).find('[type="submit"]'),
            onSuccess: function () {
                ppNotice('Portal-Benutzer gespeichert', 'success');
                location.reload();
            }
        });
    });

    $(document).on('click', '.pp-delete-portal-user', function (e) {
        e.preventDefault();
        ppAjax('delete_portal_user', {
            user_id: $(this).data('id')
        }, {
            confirm: ppAdmin.i18n.confirm_delete,
            button:  this,
            reload:  true
        });
    });

    // ── API-Keys & Lizenz ──
    $(document).on('click', '.pp-generate-api-key', function (e) {
        e.preventDefault();
        ppAjax('generate_api_key', {
            location_id: $(this).data('location-id')
        }, {
            button: this,
            onSuccess: function (resp) {
                if (resp.api_key) {
                    ppNotice('API-Key generiert', 'success');
                    $('#pp-api-key-value').val(resp.api_key);
                    $('#pp-api-key-display').slideDown();
                    // Kein automatisches Reload — User soll Key erst kopieren
                }
            }
        });
    });

    $(document).on('click', '.pp-revoke-api-key', function (e) {
        e.preventDefault();
        ppAjax('revoke_api_key', { api_key_id: $(this).data('id') }, {
            confirm: 'API-Key wirklich widerrufen?',
            button:  this,
            reload:  true,
            successMsg: 'API-Key widerrufen'
        });
    });

    // ── Custom Services (Modal via Prompt) ──
    $(document).on('click', '.pp-add-service', function (e) {
        e.preventDefault();
        var locId = $(this).data('location-id');
        var label = prompt('Name des neuen Services:');
        if (!label) return;
        var icon  = prompt('Emoji-Icon (optional):', '📋') || '📋';
        var key   = label.toLowerCase().replace(/[^a-z0-9]+/g, '_');

        ppAjax('add_custom_service', {
            location_id: locId,
            label:       label,
            icon:        icon,
            service_key: key
        }, {
            button: this,
            reload: true,
            successMsg: 'Service hinzugefügt'
        });
    });

    $(document).on('click', '.pp-edit-service', function (e) {
        e.preventDefault();
        var $btn   = $(this);
        var svcId  = $btn.data('id');
        var label  = $btn.data('label') || '';
        var icon   = $btn.data('icon') || '📋';
        var order  = $btn.data('order') || 0;
        var svcType = $btn.data('type') || 'builtin';
        var extUrl = $btn.data('url') || '';

        var $overlay = $('#pp-service-edit-overlay');
        if (!$overlay.length) {
            $overlay = $('<div id="pp-service-edit-overlay" class="pp-modal-overlay">'
                + '<div class="pp-modal" style="max-width:500px;">'
                + '<div class="pp-modal-header"><h3>Service bearbeiten</h3><button type="button" class="pp-modal-close">&times;</button></div>'
                + '<div class="pp-modal-body"></div>'
                + '<div class="pp-modal-footer">'
                + '<button type="button" class="button pp-modal-close">Abbrechen</button> '
                + '<button type="button" class="button button-primary" id="pp-save-service-edit">💾 Speichern</button>'
                + '</div></div></div>');
            $('body').append($overlay);
            $overlay.on('click', '.pp-modal-close', function () { $overlay.removeClass('active'); });
            $overlay.on('click', function (ev) { if ($(ev.target).hasClass('pp-modal-overlay')) $overlay.removeClass('active'); });
        }

        var emojiPicker = ['📋','💊','📄','👓','📎','📥','📅','❌','🚨','📝','🔬','💉','🩺','🏥','📞','✉️','📊','🔑','⚕️','🩹'];
        var emojiHtml = emojiPicker.map(function(em) {
            return '<span class="pp-emoji-pick' + (em === icon ? ' selected' : '') + '" data-emoji="' + em + '" '
                + 'style="cursor:pointer;font-size:22px;padding:4px;border:2px solid ' + (em === icon ? '#2271b1' : 'transparent') + ';border-radius:4px;margin:2px;">'
                + em + '</span>';
        }).join('');

        var urlRow = (svcType === 'external' || svcType === 'link')
            ? '<tr><th style="text-align:left;padding:8px 0;">URL</th><td><input type="url" id="pp-svc-url" value="' + escHtml(extUrl) + '" class="regular-text" style="width:100%;"></td></tr>'
            : '';

        $overlay.find('.pp-modal-body').html(
            '<table style="width:100%;border-collapse:collapse;">'
            + '<tr><th style="text-align:left;padding:8px 0;width:100px;">Label</th><td><input type="text" id="pp-svc-label" value="' + escHtml(label) + '" class="regular-text" style="width:100%;"></td></tr>'
            + '<tr><th style="text-align:left;padding:8px 0;">Icon</th><td>'
            + '<input type="hidden" id="pp-svc-icon" value="' + escHtml(icon) + '">'
            + '<div style="display:flex;flex-wrap:wrap;gap:2px;">' + emojiHtml + '</div>'
            + '</td></tr>'
            + '<tr><th style="text-align:left;padding:8px 0;">Reihenfolge</th><td><input type="number" id="pp-svc-order" value="' + order + '" min="0" max="99" style="width:80px;"></td></tr>'
            + urlRow
            + '</table>'
        );

        $overlay.find('#pp-save-service-edit').off('click').on('click', function () {
            var data = {
                service_id:  svcId,
                label:       $overlay.find('#pp-svc-label').val(),
                icon:        $overlay.find('#pp-svc-icon').val(),
                sort_order:  $overlay.find('#pp-svc-order').val()
            };
            var $urlField = $overlay.find('#pp-svc-url');
            if ($urlField.length) data.external_url = $urlField.val();

            ppAjax('edit_custom_service', data, {
                button: this,
                reload: true,
                successMsg: 'Service aktualisiert'
            });
            $overlay.removeClass('active');
        });

        // Emoji picker click
        $overlay.off('click', '.pp-emoji-pick').on('click', '.pp-emoji-pick', function () {
            $overlay.find('.pp-emoji-pick').css('border-color', 'transparent');
            $(this).css('border-color', '#2271b1');
            $overlay.find('#pp-svc-icon').val($(this).data('emoji'));
        });

        $overlay.addClass('active');
    });

    // ── Portal-User: Add (inline form) ──
    $(document).on('click', '.pp-add-portal-user', function (e) {
        e.preventDefault();
        var locId = $(this).data('location-id');
        var $existing = $('#pp-portal-user-form');
        if ($existing.length) {
            $existing.slideToggle();
            return;
        }
        var html = '<form id="pp-portal-user-form" style="background:#f9f9f9;border:1px solid #ccd0d4;border-radius:4px;padding:15px;margin-top:10px;">'
            + '<h4 style="margin-top:0;">Neuer Portal-Benutzer</h4>'
            + '<input type="hidden" name="location_id" value="' + locId + '">'
            + '<p><label>Benutzername *</label><br><input type="text" name="username" class="regular-text" required></p>'
            + '<p><label>Passwort *</label><br><input type="password" name="password" class="regular-text" required></p>'
            + '<p><label>Anzeigename</label><br><input type="text" name="display_name" class="regular-text"></p>'
            + '<p><label>E-Mail</label><br><input type="email" name="email" class="regular-text"></p>'
            + '<p><button type="submit" class="button button-primary">💾 Speichern</button> '
            + '<button type="button" class="button pp-cancel-add-user">Abbrechen</button></p>'
            + '</form>';
        $(this).after(html);
    });

    $(document).on('click', '.pp-cancel-add-user', function () {
        $('#pp-portal-user-form').slideUp(200, function () { $(this).remove(); });
    });

    // ── Portal-User: Edit (populate form) ──
    $(document).on('click', '.pp-edit-portal-user', function (e) {
        e.preventDefault();
        var user  = $(this).data('user');
        var locId = $(this).closest('[data-location-id]').data('location-id')
                 || $(this).closest('.pp-tab-panel').find('.pp-add-portal-user').data('location-id');

        // Remove existing form, create pre-filled
        $('#pp-portal-user-form').remove();
        var $btn = $(this).closest('table').next('.pp-add-portal-user').length
            ? $(this).closest('table').next()
            : $(this).closest('table').parent().find('.pp-add-portal-user');

        var html = '<form id="pp-portal-user-form" style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:15px;margin-top:10px;">'
            + '<h4 style="margin-top:0;">Benutzer bearbeiten: ' + escHtml(user.username) + '</h4>'
            + '<input type="hidden" name="location_id" value="' + (locId || user.location_id || '') + '">'
            + '<input type="hidden" name="user_id" value="' + user.id + '">'
            + '<p><label>Benutzername *</label><br><input type="text" name="username" value="' + escHtml(user.username || '') + '" class="regular-text"></p>'
            + '<p><label>Neues Passwort (leer = unverändert)</label><br><input type="password" name="password" class="regular-text"></p>'
            + '<p><label>Anzeigename</label><br><input type="text" name="display_name" value="' + escHtml(user.display_name || '') + '" class="regular-text"></p>'
            + '<p><label>E-Mail</label><br><input type="email" name="email" value="' + escHtml(user.email || '') + '" class="regular-text"></p>'
            + '<p><button type="submit" class="button button-primary">💾 Aktualisieren</button> '
            + '<button type="button" class="button pp-cancel-add-user">Abbrechen</button></p>'
            + '</form>';
        $(this).closest('tr').after('<tr><td colspan="6">' + html + '</td></tr>');
    });

    $(document).on('click', '.pp-refresh-license', function (e) {
        e.preventDefault();
        ppAjax('refresh_license', {
            location_id: $(this).data('location-id')
        }, {
            button: this,
            reload: true,
            successMsg: 'Lizenz aktualisiert'
        });
    });

    /* =====================================================================
     * 5. SETTINGS
     * ================================================================== */

    // Settings-Formulare nutzen normalen POST (kein AJAX), daher kein JS nötig.
    // Die Tabs-Navigation ist rein link-basiert (?tab=xxx).

    /* =====================================================================
     * 6. SYSTEM
     * ================================================================== */

    $(document).on('click', '.pp-send-test-email', function (e) {
        e.preventDefault();
        ppAjax('send_test_email', {
            email: $('#pp-test-email-input').val()
        }, {
            button: this,
            successMsg: 'Test-E-Mail gesendet'
        });
    });

    $(document).on('click', '.pp-run-cleanup', function (e) {
        e.preventDefault();
        ppAjax('run_cleanup', {}, {
            confirm: ppAdmin.i18n.confirm_cleanup,
            button:  this,
            successMsg: 'Bereinigung abgeschlossen'
        });
    });

    $(document).on('click', '.pp-create-backup', function (e) {
        e.preventDefault();
        ppAjax('create_backup', {}, {
            button: this,
            onSuccess: function (data) {
                ppNotice(data.message || 'Backup erstellt', 'success');
                location.reload();
            }
        });
    });

    $(document).on('click', '.pp-delete-backup', function (e) {
        e.preventDefault();
        ppAjax('delete_backup', { filename: $(this).data('filename') }, {
            confirm: ppAdmin.i18n.confirm_delete,
            button:  this,
            reload:  true
        });
    });

    $(document).on('click', '.pp-restore-backup', function (e) {
        e.preventDefault();
        ppAjax('restore_backup', { filename: $(this).data('filename') }, {
            confirm: 'Backup wirklich wiederherstellen? Aktuelle Daten werden überschrieben!',
            button:  this,
            onSuccess: function (data) {
                ppNotice(data.message || 'Backup wiederhergestellt', 'success');
                location.reload();
            }
        });
    });

    $(document).on('click', '.pp-reset-encryption', function (e) {
        e.preventDefault();
        ppAjax('reset_encryption', {}, {
            confirm: '⚠️ ACHTUNG: Verschlüsselungsschlüssel neu generieren? Bereits verschlüsselte Daten werden UNLESBAR!',
            button:  this,
            successMsg: 'Schlüssel neu generiert'
        });
    });

    /* =====================================================================
     * 7. DSGVO
     * ================================================================== */

    $(document).on('submit', '#pp-dsgvo-search', function (e) {
        e.preventDefault();
        var term = $(this).find('[name="search_term"]').val();

        ppAjax('search_patient', { search_term: term }, {
            button: $(this).find('[type="submit"]'),
            onSuccess: function (data) {
                $('#pp-dsgvo-results').html(data.html);
            }
        });
    });

    $(document).on('click', '.pp-dsgvo-export', function (e) {
        e.preventDefault();
        ppAjax('export_patient', {
            patient_name: $(this).data('name'),
            patient_dob:  $(this).data('dob')
        }, {
            button: this,
            onSuccess: function (data) {
                if (data.download_url) {
                    window.location.href = data.download_url;
                }
            }
        });
    });

    $(document).on('click', '.pp-dsgvo-delete', function (e) {
        e.preventDefault();
        ppAjax('delete_patient_data', {
            patient_name: $(this).data('name'),
            patient_dob:  $(this).data('dob')
        }, {
            confirm: '⚠️ Alle Daten dieses Patienten unwiderruflich löschen? (DSGVO Art. 17)',
            button:  this,
            reload:  true
        });
    });

    /* =====================================================================
     * 8. FORM EDITOR
     * ================================================================== */

    $(document).on('click', '.pp-clone-form', function (e) {
        e.preventDefault();
        var newName = prompt('Name für die Kopie:', $(this).data('name') + ' (Kopie)');
        if (!newName) return;

        ppAjax('clone_form', {
            form_id:   $(this).data('id'),
            new_name:  newName
        }, {
            button: this,
            reload: true
        });
    });

    $(document).on('click', '.pp-delete-form', function (e) {
        e.preventDefault();
        ppAjax('delete_form', { form_id: $(this).data('id') }, {
            confirm: ppAdmin.i18n.confirm_delete,
            button:  this,
            reload:  true
        });
    });

    // ── Fragebogen ↔ Standort Zuordnung ──────────────────────────
    $(document).on('change', '.pp-form-loc-toggle', function () {
        var $cb   = $(this);
        var $label = $cb.closest('label');
        ppAjax('form_toggle_location', {
            form_id:     $cb.data('form-id'),
            location_id: $cb.data('location-id'),
            active:      $cb.is(':checked') ? 1 : 0
        }, {
            onSuccess: function () {
                if ($cb.is(':checked')) {
                    $label.css({ background: '#d4edda', borderColor: '#28a745', color: '#155724' });
                } else {
                    $label.css({ background: '#f0f0f0', borderColor: '#ccc', color: '#666' });
                }
            },
            onError: function () {
                // Revert checkbox
                $cb.prop('checked', !$cb.is(':checked'));
            }
        });
    });

    $(document).on('click', '.pp-export-form', function (e) {
        e.preventDefault();
        ppAjax('export_form', { form_id: $(this).data('id') }, {
            button: this,
            onSuccess: function (data) {
                // JSON-Datei zum Download anbieten
                if (data.json) {
                    var blob = new Blob([data.json], { type: 'application/json' });
                    var url  = URL.createObjectURL(blob);
                    var a    = document.createElement('a');
                    a.href     = url;
                    a.download = (data.filename || 'form') + '.json';
                    a.click();
                    URL.revokeObjectURL(url);
                }
            }
        });
    });

    $(document).on('change', '#pp-form-import-file', function () {
        var file = this.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function (e) {
            ppAjax('import_form', { json: e.target.result }, {
                onSuccess: function () {
                    ppNotice('Formular importiert', 'success');
                    location.reload();
                }
            });
        };
        reader.readAsText(file);
    });

    // ── Import-Button: Datei-Dialog öffnen ──
    $(document).on('click', '.pp-import-form', function (e) {
        e.preventDefault();
        var $fileInput = $('#pp-form-import-file');
        if (!$fileInput.length) {
            $fileInput = $('<input type="file" id="pp-form-import-file" accept=".json" style="display:none;">');
            $('body').append($fileInput);
        }
        $fileInput.trigger('click');
    });

    // ── Save Form (JSON-Editor) ──
    $(document).on('click', '.pp-save-form', function (e) {
        e.preventDefault();
        var $editor = $('#pp-form-json-editor');
        var jsonStr = $editor.val();

        try {
            var config = JSON.parse(jsonStr);
        } catch (err) {
            ppNotice('Ungültiges JSON: ' + err.message, 'error');
            return;
        }

        // Meta-Daten aus Formular-Feldern übernehmen
        var name = $('#form_name').val();
        var key  = $('#form_key').val();
        var desc = $('#form_description').val();
        var ver  = $('#form_version').val();

        if (name) config.name        = name;
        if (key)  config.id          = key;
        if (desc) config.description = desc;
        if (ver)  config.version     = ver;

        ppAjax('save_form', {
            config_json: JSON.stringify(config)
        }, {
            button: this,
            onSuccess: function (data) {
                ppNotice('Fragebogen gespeichert', 'success');
                if (data.form_id && !$('#pp-form-editor-app').data('form-id')) {
                    // Neue Form → zur Editor-Seite weiterleiten
                    window.location.href = ppAdmin.ajaxUrl.replace('admin-ajax.php', 'admin.php')
                        + '?page=pp-form-editor&form_id=' + data.form_id;
                }
            }
        });
    });

    // ── Validate JSON ──
    $(document).on('click', '.pp-validate-json', function (e) {
        e.preventDefault();
        var $editor = $('#pp-form-json-editor');
        var $status = $('#pp-json-status');
        try {
            var config = JSON.parse($editor.val());
            var errors = [];
            if (!config.id)     errors.push('Feld "id" fehlt');
            if (!config.fields) errors.push('Array "fields" fehlt');
            if (!config.name)   errors.push('Feld "name" fehlt');

            if (errors.length) {
                $status.html('<span style="color:#dc3232;">⚠️ ' + errors.join(', ') + '</span>');
            } else {
                $status.html('<span style="color:#46b450;">✅ JSON gültig – ' + (config.fields || []).length + ' Felder, ' + (config.sections || []).length + ' Sektionen</span>');
            }
        } catch (err) {
            $status.html('<span style="color:#dc3232;">❌ JSON-Syntaxfehler: ' + escHtml(err.message) + '</span>');
        }
    });

    // ── Format JSON ──
    $(document).on('click', '.pp-format-json', function (e) {
        e.preventDefault();
        var $editor = $('#pp-form-json-editor');
        try {
            var obj = JSON.parse($editor.val());
            $editor.val(JSON.stringify(obj, null, 2));
            $('#pp-json-status').html('<span style="color:#46b450;">✅ Formatiert</span>');
        } catch (err) {
            ppNotice('JSON kann nicht formatiert werden: ' + err.message, 'error');
        }
    });

    // ── Preview Form ──
    $(document).on('click', '.pp-preview-form', function (e) {
        e.preventDefault();
        var $editor = $('#pp-form-json-editor');
        try {
            var config = JSON.parse($editor.val());
        } catch (err) {
            ppNotice('JSON ungültig – Vorschau nicht möglich', 'error');
            return;
        }

        var previewHtml = '<div style="max-width:600px;margin:auto;">';
        previewHtml += '<h2>' + escHtml(config.name || 'Vorschau') + '</h2>';
        if (config.description) {
            previewHtml += '<p style="color:#666;">' + escHtml(config.description) + '</p>';
        }

        // Sections sammeln
        var sections = {};
        (config.fields || []).forEach(function (field) {
            var sec = field.section || 'Allgemein';
            if (!sections[sec]) sections[sec] = [];
            sections[sec].push(field);
        });

        Object.keys(sections).forEach(function(secName) {
            previewHtml += '<fieldset style="border:1px solid #ddd;padding:15px;margin:0 0 15px;border-radius:6px;">';
            previewHtml += '<legend style="font-weight:600;padding:0 8px;">' + escHtml(secName) + '</legend>';

            sections[secName].forEach(function (field) {
                if (!field.enabled && field.enabled !== undefined) return;
                previewHtml += '<div style="margin-bottom:12px;">';
                previewHtml += '<label><strong>' + escHtml(field.label || field.id || '') + '</strong>';
                if (field.required) previewHtml += ' <span style="color:red;">*</span>';
                previewHtml += '</label><br>';
                var type = field.type || 'text';
                if (type === 'textarea') {
                    previewHtml += '<textarea style="width:100%;height:80px;" disabled></textarea>';
                } else if (type === 'select' || type === 'dropdown') {
                    previewHtml += '<select disabled style="width:100%;">';
                    (field.options || []).forEach(function (opt) {
                        var lbl = typeof opt === 'string' ? opt : (opt.label || opt.value || '');
                        previewHtml += '<option>' + escHtml(lbl) + '</option>';
                    });
                    previewHtml += '</select>';
                } else if (type === 'checkbox') {
                    previewHtml += '<input type="checkbox" disabled> ' + escHtml(field.placeholder || field.label || '');
                } else if (type === 'checkbox_group') {
                    (field.options || []).forEach(function (opt) {
                        var lbl = typeof opt === 'string' ? opt : (opt.label || opt.value || '');
                        previewHtml += '<label style="display:block;margin:3px 0;"><input type="checkbox" disabled> ' + escHtml(lbl) + '</label>';
                    });
                } else if (type === 'radio') {
                    (field.options || []).forEach(function (opt) {
                        var lbl = typeof opt === 'string' ? opt : (opt.label || opt.value || '');
                        previewHtml += '<label style="display:block;margin:3px 0;"><input type="radio" disabled> ' + escHtml(lbl) + '</label>';
                    });
                } else {
                    previewHtml += '<input type="' + type + '" style="width:100%;" disabled placeholder="' + escHtml(field.placeholder || '') + '">';
                }
                previewHtml += '</div>';
            });

            previewHtml += '</fieldset>';
        });

        previewHtml += '</div>';

        // Show in modal overlay
        var $overlay = $('#pp-preview-overlay');
        if (!$overlay.length) {
            $overlay = $('<div id="pp-preview-overlay" class="pp-modal-overlay">'
                + '<div class="pp-modal">'
                + '<div class="pp-modal-header"><h3>Vorschau</h3><button type="button" class="pp-modal-close">&times;</button></div>'
                + '<div class="pp-modal-body"></div>'
                + '</div></div>');
            $('body').append($overlay);

            // Close handlers
            $overlay.on('click', '.pp-modal-close', function () {
                $overlay.removeClass('active');
            });
            $overlay.on('click', function (ev) {
                if ($(ev.target).hasClass('pp-modal-overlay')) {
                    $overlay.removeClass('active');
                }
            });
        }
        $overlay.find('.pp-modal-body').html(previewHtml);
        $overlay.addClass('active');
    });

    /* =====================================================================
     * 9. LICENSE
     * ================================================================== */

    $(document).on('submit', '#pp-license-form', function (e) {
        e.preventDefault();
        var key = $(this).find('[name="license_key"]').val();
        ppAjax('save_license_key', { license_key: key }, {
            button: $(this).find('[type="submit"]'),
            successMsg: 'Lizenzschlüssel gespeichert'
        });
    });

    $(document).on('click', '#pp-activate-license', function (e) {
        e.preventDefault();
        ppAjax('activate_license', {}, {
            button: this,
            reload: true,
            successMsg: 'Lizenz aktiviert'
        });
    });

    /* =====================================================================
     * 10. UI HELPERS
     * ================================================================== */

    // Tab-Navigation
    $(document).on('click', '.pp-tabs a', function (e) {
        if ($(this).attr('href').indexOf('#') === 0) {
            e.preventDefault();
            var tab = $(this).attr('href').substring(1);

            // Tab-Links aktualisieren
            $(this).closest('.pp-tabs').find('a').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            // Tab-Content anzeigen
            $(this).closest('.pp-tab-wrapper').find('.pp-tab-panel').hide();
            $('#pp-tab-' + tab).show();
        }
    });

    // Kopierfunktion
    $(document).on('click', '.pp-copy-btn', function () {
        var text = $(this).data('copy') || $(this).prev('code, input').text() || $(this).prev('code, input').val();
        navigator.clipboard.writeText(text).then(function () {
            ppNotice('In Zwischenablage kopiert', 'success');
        });
    });

    // Toggle-Sektionen
    $(document).on('click', '.pp-toggle-section', function () {
        $(this).next('.pp-section-content').slideToggle(200);
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
    });

    // Keyboard: Escape schließt Modals
    $(document).on('keydown', function (e) {
        if (e.keyCode === 27) {
            $('.pp-modal').removeClass('pp-modal-open');
        }
    });

    // =========================================
    // TERMIN-SERVICE MODUS
    // =========================================

    // Termin-Modus wechseln (show/hide external/form fields)
    $(document).on('change', '.pp-termin-mode-dropdown', function () {
        var mode  = $(this).val();
        var $row  = $(this).closest('tr');
        $row.find('.pp-termin-external-fields').toggle(mode === 'external');
        $row.find('.pp-termin-form-fields').toggle(mode === 'form');

        // Status-Indikator aktualisieren
        var $indicator = $row.find('td:first span');
        if (mode !== 'disabled') {
            $indicator.css('color', '#28a745').text('●');
        } else {
            $indicator.css('color', '#999').text('○');
        }

        // Modus per AJAX sofort speichern
        var serviceId   = $(this).data('id');
        var configJson  = $('#termin-config-' + serviceId).val() || '{}';
        var externalUrl = $row.find('.pp-termin-url').val() || '';
        var newTab      = $row.find('.pp-termin-newtab').is(':checked') ? 1 : 0;

        $.post(ajaxurl, {
            action:        'pp_admin_action',
            sub_action:      'save_termin_config',
            nonce:          ppAdmin.nonce,
            service_id:      serviceId,
            termin_mode:     mode,
            termin_config:   configJson,
            external_url:    externalUrl,
            open_in_new_tab: newTab
        }).done(function (res) {
            if (res.success) {
                ppNotice('Termin-Modus gespeichert', 'success');
            }
        });
    });

    // Externe URL bei Blur speichern
    $(document).on('blur', '.pp-termin-url', function () {
        var serviceId = $(this).data('id');
        var $row      = $(this).closest('tr');
        var mode      = $row.find('.pp-termin-mode-dropdown').val();
        if (mode !== 'external') return;

        $.post(ajaxurl, {
            action:        'pp_admin_action',
            sub_action:      'save_termin_config',
            nonce:          ppAdmin.nonce,
            service_id:      serviceId,
            termin_mode:     'external',
            termin_config:   $('#termin-config-' + serviceId).val() || '{}',
            external_url:    $(this).val(),
            open_in_new_tab: $row.find('.pp-termin-newtab').is(':checked') ? 1 : 0
        });
    });

    // Patient-Restriction Dropdown Change Handler
    $(document).on('change', '.pp-patient-restriction-dropdown', function() {
        var $select = $(this);
        var serviceId = $select.data('id');
        var restriction = $select.val();

        $.post(ajaxurl, {
            action: 'pp_admin_action',
            sub_action: 'update_patient_restriction',
            nonce: ppAdmin.nonce,
            service_id: serviceId,
            patient_restriction: restriction
        })
        .done(function(resp) {
            if (resp.success) {
                // Success feedback (optional - silent update)
                console.log('Patient restriction updated');
            } else {
                alert(resp.data.message || 'Fehler beim Speichern');
                // Revert dropdown on error
                location.reload();
            }
        })
        .fail(function() {
            alert('Verbindungsfehler');
            location.reload();
        });
    });

    // Termin-Konfigurations-Modal öffnen
    var currentTerminServiceId = null;

    $(document).on('click', '.pp-termin-config-btn', function () {
        currentTerminServiceId = $(this).data('service-id');
        var configJson = $('#termin-config-' + currentTerminServiceId).val();
        var config = {};
        try { config = JSON.parse(configJson) || {}; } catch(e) {}

        // Modal befüllen
        $('#tc-show-anliegen').prop('checked', config.show_anliegen !== false);
        $('#tc-show-beschwerden').prop('checked', config.show_beschwerden !== false);
        $('#tc-show-time-pref').prop('checked', config.show_time_preference !== false);
        $('#tc-show-day-pref').prop('checked', config.show_day_preference !== false);
        $('#tc-urgent-hint').val(config.urgent_hint || 'In dringenden Fällen rufen Sie uns bitte direkt an!');

        // Grund-Optionen
        var defaultOptions = 'vorsorge|Vorsorgeuntersuchung\nkontrolle|Kontrolltermin\nakut|Akute Beschwerden\nop_vorbereitung|OP-Vorbereitung\nnachsorge|Nachsorge\nsonstiges|Sonstiges';
        $('#tc-grund-options').val(config.grund_options || defaultOptions);

        // Tage
        var days = config.days || ['mo','di','mi','do','fr'];
        $('.tc-day-checkbox').each(function () {
            $(this).prop('checked', days.indexOf($(this).val()) > -1);
        });

        // Modal zeigen
        $('#pp-termin-config-overlay').addClass('active');
    });

    // Modal schließen
    $(document).on('click', '#pp-termin-config-overlay .pp-modal-close', function () {
        $('#pp-termin-config-overlay').removeClass('active');
    });
    $(document).on('click', '#pp-termin-config-overlay', function (e) {
        if ($(e.target).hasClass('pp-modal-overlay')) {
            $(this).removeClass('active');
        }
    });

    // Termin-Config speichern
    $(document).on('click', '#tc-save', function () {
        var config = {
            mode: 'form',
            show_anliegen: $('#tc-show-anliegen').is(':checked'),
            show_beschwerden: $('#tc-show-beschwerden').is(':checked'),
            show_time_preference: $('#tc-show-time-pref').is(':checked'),
            show_day_preference: $('#tc-show-day-pref').is(':checked'),
            urgent_hint: $('#tc-urgent-hint').val(),
            grund_options: $('#tc-grund-options').val(),
            days: []
        };

        $('.tc-day-checkbox:checked').each(function () {
            config.days.push($(this).val());
        });

        // In hidden field speichern
        $('#termin-config-' + currentTerminServiceId).val(JSON.stringify(config));

        // Per AJAX speichern
        var $row        = $('[data-service-id="' + currentTerminServiceId + '"]');
        var externalUrl = $row.find('.pp-termin-url').val() || '';

        $.post(ajaxurl, {
            action:        'pp_admin_action',
            sub_action:    'save_termin_config',
            nonce:          ppAdmin.nonce,
            service_id:    currentTerminServiceId,
            termin_mode:   'form',
            termin_config: JSON.stringify(config),
            external_url:  externalUrl
        }).done(function (res) {
            if (res.success) {
                ppNotice('Termin-Formular konfiguriert', 'success');
            }
        });

        // Modal schließen
        $('#pp-termin-config-overlay').removeClass('active');
    });

    // Datum-Felder: Heute als Default
    $('input[type="date"][data-default-today]').each(function () {
        if (!$(this).val()) {
            $(this).val(new Date().toISOString().split('T')[0]);
        }
    });

    // =========================================================================
    // NOTFALL-KONFIGURATION
    // =========================================================================

    var currentNotfallServiceId = null;

    // Eigene Nummer-Zeile erstellen
    function ncAddNumberRow(label, phone) {
        var html = '<div class="nc-number-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">' +
            '<input type="text" class="nc-num-label regular-text" value="' + (label || '').replace(/"/g, '&quot;') + '" placeholder="Bezeichnung" style="flex:1;">' +
            '<input type="text" class="nc-num-phone regular-text" value="' + (phone || '').replace(/"/g, '&quot;') + '" placeholder="Telefonnummer" style="flex:1;">' +
            '<button type="button" class="button button-small nc-remove-number" title="Entfernen">✕</button>' +
            '</div>';
        $('#nc-custom-numbers').append(html);
    }

    // Notfall-Config Modal öffnen
    $(document).on('click', '.pp-notfall-config-btn', function () {
        currentNotfallServiceId = $(this).data('service-id');
        var configJson = $('#notfall-config-' + currentNotfallServiceId).val();
        var config = {};
        try { config = JSON.parse(configJson); } catch (e) { config = {}; }

        // Felder befüllen
        $('#nc-show-112').prop('checked', config.show_112 !== false);
        $('#nc-emergency-text').val(config.emergency_text || '');
        $('#nc-practice-label').val(config.practice_emergency_label || '');
        $('#nc-show-bereitschaft').prop('checked', config.show_bereitschaftsdienst !== false);
        $('#nc-show-giftnotruf').prop('checked', config.show_giftnotruf !== false);
        $('#nc-show-seelsorge').prop('checked', config.show_telefonseelsorge !== false);
        $('#nc-additional-info').val(config.additional_info || '');

        // Eigene Nummern
        $('#nc-custom-numbers').empty();
        if (config.custom_numbers && config.custom_numbers.length) {
            config.custom_numbers.forEach(function (n) {
                ncAddNumberRow(n.label, n.phone);
            });
        }

        $('#pp-notfall-config-overlay').addClass('active');
    });

    // Modal schließen
    $(document).on('click', '#pp-notfall-config-overlay .pp-modal-close', function () {
        $('#pp-notfall-config-overlay').removeClass('active');
    });
    $(document).on('click', '#pp-notfall-config-overlay', function (e) {
        if ($(e.target).hasClass('pp-modal-overlay')) {
            $(this).removeClass('active');
        }
    });

    // Nummer hinzufügen
    $(document).on('click', '#nc-add-number', function () {
        if ($('#nc-custom-numbers .nc-number-row').length >= 10) {
            ppNotice('Maximal 10 eigene Nummern', 'warning');
            return;
        }
        ncAddNumberRow('', '');
    });

    // Nummer entfernen
    $(document).on('click', '.nc-remove-number', function () {
        $(this).closest('.nc-number-row').remove();
    });

    // Notfall-Config speichern
    $(document).on('click', '#nc-save', function () {
        var config = {
            show_112:                 $('#nc-show-112').is(':checked'),
            emergency_text:           $('#nc-emergency-text').val(),
            practice_emergency_label: $('#nc-practice-label').val(),
            show_bereitschaftsdienst: $('#nc-show-bereitschaft').is(':checked'),
            show_giftnotruf:          $('#nc-show-giftnotruf').is(':checked'),
            show_telefonseelsorge:    $('#nc-show-seelsorge').is(':checked'),
            custom_numbers:           [],
            additional_info:          $('#nc-additional-info').val()
        };

        // Eigene Nummern sammeln
        $('#nc-custom-numbers .nc-number-row').each(function () {
            var label = $(this).find('.nc-num-label').val().trim();
            var phone = $(this).find('.nc-num-phone').val().trim();
            if (label && phone) {
                config.custom_numbers.push({ label: label, phone: phone });
            }
        });

        // In hidden field speichern
        $('#notfall-config-' + currentNotfallServiceId).val(JSON.stringify(config));

        // Per AJAX speichern
        $.post(ajaxurl, {
            action:        'pp_admin_action',
            sub_action:     'save_notfall_config',
            nonce:          ppAdmin.nonce,
            service_id:     currentNotfallServiceId,
            notfall_config: JSON.stringify(config)
        }).done(function (res) {
            if (res.success) {
                ppNotice('Notfall-Konfiguration gespeichert', 'success');
            } else {
                ppNotice('Fehler beim Speichern', 'error');
            }
        });

        // Modal schließen
        $('#pp-notfall-config-overlay').removeClass('active');
    });

    // ═══════════════════════════════════════════════════════════
    // Brillenverordnung bearbeiten
    // ═══════════════════════════════════════════════════════════

    // Bearbeiten-Button
    $(document).on('click', '.pp-edit-brille-btn', function() {
        $(this).hide();
        $('.pp-brille-edit-form').slideDown(300);
    });

    // Abbrechen-Button
    $(document).on('click', '.pp-cancel-brille-btn', function() {
        $('.pp-brille-edit-form').slideUp(300);
        $('.pp-edit-brille-btn').show();
    });

    // Speichern-Button
    $(document).on('click', '.pp-save-brille-btn', function() {
        var $form = $('.pp-brille-edit-form');
        var submissionId = $('#pp-detail-modal').data('submission-id');

        // Daten sammeln
        var brilleData = {
            refraktion: {
                rechts: {
                    sph: $form.find('[name="refraktion_rechts_sph"]').val(),
                    zyl: $form.find('[name="refraktion_rechts_zyl"]').val(),
                    ach: $form.find('[name="refraktion_rechts_ach"]').val(),
                    add: $form.find('[name="refraktion_rechts_add"]').val()
                },
                links: {
                    sph: $form.find('[name="refraktion_links_sph"]').val(),
                    zyl: $form.find('[name="refraktion_links_zyl"]').val(),
                    ach: $form.find('[name="refraktion_links_ach"]').val(),
                    add: $form.find('[name="refraktion_links_add"]').val()
                }
            },
            prismen: {
                rechts: {
                    horizontal: {
                        wert: $form.find('[name="prisma_rechts_h_wert"]').val(),
                        basis: $form.find('[name="prisma_rechts_h_basis"]').val()
                    },
                    vertikal: {
                        wert: $form.find('[name="prisma_rechts_v_wert"]').val(),
                        basis: $form.find('[name="prisma_rechts_v_basis"]').val()
                    }
                },
                links: {
                    horizontal: {
                        wert: $form.find('[name="prisma_links_h_wert"]').val(),
                        basis: $form.find('[name="prisma_links_h_basis"]').val()
                    },
                    vertikal: {
                        wert: $form.find('[name="prisma_links_v_wert"]').val(),
                        basis: $form.find('[name="prisma_links_v_basis"]').val()
                    }
                }
            },
            hsa: $form.find('[name="hsa"]').val(),
            pd_rechts: $form.find('[name="pd_rechts"]').val(),
            pd_links: $form.find('[name="pd_links"]').val(),
            pd_gesamt: $form.find('[name="pd_gesamt"]').val()
        };

        // AJAX Request
        $.post(ajaxurl, {
            action: 'pp_admin_action',
            sub_action: 'update_brille_values',
            nonce: ppAdmin.nonce,
            submission_id: submissionId,
            brille_data: JSON.stringify(brilleData)
        })
        .done(function(resp) {
            if (resp.success) {
                ppNotice('Werte gespeichert', 'success');
                $form.slideUp(300);
                $('.pp-edit-brille-btn').show();

                // Modal neu laden
                $.post(ajaxurl, {
                    action: 'pp_admin_action',
                    sub_action: 'view_submission',
                    nonce: ppAdmin.nonce,
                    submission_id: submissionId
                }).done(function(data) {
                    if (data.success) {
                        $('#pp-detail-modal .pp-modal-body').html(data.data.html);
                    }
                });
            } else {
                alert(resp.data.message || 'Fehler beim Speichern');
            }
        })
        .fail(function() {
            alert('Verbindungsfehler');
        });
    });

})(jQuery);
