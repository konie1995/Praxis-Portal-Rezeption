<?php
/**
 * AdminIcd – Verwaltung der Fragen-ICD-Code-Zuordnungen
 *
 * Admin-Oberfläche zum Zuordnen von Fragebogen-Feldern zu ICD-10-GM Codes.
 * Unterstützt alle drei Architektur-Säulen:
 *   - Multistandort: Zuordnungen pro Standort oder global
 *   - Mehrsprachigkeit: Labels über I18n
 *   - Lizenz-gating: ICD-Export ist Premium-Feature (GDT frei, HL7/FHIR Premium)
 *
 * @package PraxisPortal\Admin
 * @since   4.2.901
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\IcdRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminIcd
{
    private Container $container;
    private IcdRepository $icdRepo;
    private I18n $i18n;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->icdRepo   = $container->get(IcdRepository::class);
        $this->i18n      = $container->get(I18n::class);
    }

    private function t(string $text): string
    {
        return $this->i18n->translate($text);
    }

    /**
     * Hauptseite rendern
     */
    public function renderPage(): void
    {
        $locationRepo = $this->container->get(LocationRepository::class);
        $locations    = $locationRepo->getAll();

        // Aktiver Fragebogen (Tab)
        $activeForm = sanitize_key($_GET['form_id'] ?? 'augenarzt');

        // Aktiver Standort-Filter
        $filterLocation = isset($_GET['location_id']) && $_GET['location_id'] !== ''
            ? (int) $_GET['location_id']
            : null;

        // Verfügbare Fragebögen aus JSON-Dateien
        $availableForms = $this->getAvailableForms();

        // Defaults einfügen wenn nötig
        $this->icdRepo->createDefaults($activeForm);

        // Zuordnungen laden
        $zuordnungen = $this->icdRepo->getByFormId($activeForm, $filterLocation);
        $summary     = $this->icdRepo->getFormSummary();

        // Verfügbare Frage-Felder aus dem Fragebogen
        $formFields = $this->getFormFields($activeForm);

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-editor-code" style="font-size:28px;width:28px;height:28px;margin-right:8px;"></span>
                <?php echo esc_html($this->t('ICD-10 Zuordnungen')); ?>
            </h1>
            <p class="description">
                <?php echo esc_html($this->t('Ordnen Sie Fragebogen-Felder den passenden ICD-10-GM Codes zu. Diese werden beim GDT-, HL7- und FHIR-Export automatisch verwendet.')); ?>
            </p>

            <!-- Fragebogen-Tabs -->
            <nav class="nav-tab-wrapper" style="margin-bottom:15px;">
                <?php foreach ($availableForms as $fId => $fName): ?>
                    <a href="<?php echo esc_url(add_query_arg(['form_id' => $fId, 'location_id' => $filterLocation], admin_url('admin.php?page=pp-icd'))); ?>"
                       class="nav-tab <?php echo $activeForm === $fId ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($fName); ?>
                        <?php if (isset($summary[$fId])): ?>
                            <span class="count" style="font-size:11px;color:#666;">(<?php echo (int) $summary[$fId]['active']; ?>/<?php echo (int) $summary[$fId]['total']; ?>)</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Standort-Filter (Multistandort-Säule) -->
            <?php if (count($locations) > 1): ?>
            <div style="margin-bottom:15px;padding:10px 15px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;">
                <label for="icd-location-filter"><strong><?php echo esc_html($this->t('Standort')); ?>:</strong></label>
                <select id="icd-location-filter" onchange="window.location.href=this.value;" style="margin-left:8px;">
                    <option value="<?php echo esc_url(add_query_arg(['form_id' => $activeForm, 'location_id' => ''], admin_url('admin.php?page=pp-icd'))); ?>"
                            <?php selected($filterLocation, null); ?>>
                        🌐 <?php echo esc_html($this->t('Alle Standorte (Global)')); ?>
                    </option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo esc_url(add_query_arg(['form_id' => $activeForm, 'location_id' => $loc['id']], admin_url('admin.php?page=pp-icd'))); ?>"
                                <?php selected($filterLocation, (int) $loc['id']); ?>>
                            📍 <?php echo esc_html($loc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description" style="margin-left:10px;">
                    <?php echo esc_html($this->t('Standortspezifische Zuordnungen überschreiben die globalen Defaults.')); ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Aktionen-Leiste -->
            <div style="margin-bottom:15px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <button type="button" class="button" id="pp-icd-merge" data-form="<?php echo esc_attr($activeForm); ?>"
                            title="<?php echo esc_attr($this->t('Neue ICD-Codes aus DEFAULT_MAPPINGS importieren ohne bestehende zu löschen')); ?>">
                        🔄 <?php echo esc_html($this->t('Defaults aktualisieren')); ?>
                    </button>
                    <span id="pp-icd-merge-status" style="margin-left:10px;color:#666;font-size:13px;"></span>
                </div>
                <div style="color:#666;font-size:13px;">
                    <?php echo sprintf(
                        $this->t('%d Zuordnungen'),
                        count($zuordnungen)
                    ); ?>
                </div>
            </div>

            <!-- Zuordnungen Tabelle -->
            <table class="wp-list-table widefat fixed striped" style="max-width:1200px;">
                <thead>
                    <tr>
                        <th style="width:50px;"><?php echo esc_html($this->t('Aktiv')); ?></th>
                        <th style="width:160px;"><?php echo esc_html($this->t('Frage-Feld')); ?></th>
                        <th style="width:100px;"><?php echo esc_html($this->t('ICD-10 Code')); ?></th>
                        <th><?php echo esc_html($this->t('Bezeichnung')); ?></th>
                        <th style="width:50px;" title="G=Gesichert, V=Verdacht, Z=Zustand nach, A=Ausgeschlossen"><?php echo esc_html($this->t('Sich.')); ?></th>
                        <th style="width:130px;"><?php echo esc_html($this->t('Seite-Feld')); ?></th>
                        <th style="width:120px;"><?php echo esc_html($this->t('Standort')); ?></th>
                        <th style="width:120px;"><?php echo esc_html($this->t('Aktionen')); ?></th>
                    </tr>
                </thead>
                <tbody id="icd-tbody">
                    <?php if (empty($zuordnungen)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:20px;color:#666;">
                            <?php echo esc_html($this->t('Keine Zuordnungen vorhanden.')); ?>
                            <button type="button" class="button button-small" id="pp-icd-seed" style="margin-left:10px;"
                                    data-form="<?php echo esc_attr($activeForm); ?>">
                                🌱 <?php echo esc_html($this->t('Defaults laden')); ?>
                            </button>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($zuordnungen as $z): ?>
                        <tr data-id="<?php echo (int) $z['id']; ?>">
                            <td>
                                <label class="pp-toggle" title="<?php echo esc_attr($this->t('Zuordnung aktivieren/deaktivieren')); ?>">
                                    <input type="checkbox" class="pp-icd-toggle"
                                           data-id="<?php echo (int) $z['id']; ?>"
                                           <?php checked(!empty($z['is_active'])); ?>>
                                    <span class="pp-toggle-slider"></span>
                                </label>
                            </td>
                            <td>
                                <code style="font-size:12px;background:#e8f4f8;padding:2px 6px;border-radius:3px;">
                                    <?php echo esc_html($z['frage_key']); ?>
                                </code>
                            </td>
                            <td>
                                <strong style="font-family:monospace;font-size:13px;color:#0073aa;">
                                    <?php echo esc_html($z['icd_code']); ?>
                                </strong>
                            </td>
                            <td><?php echo esc_html($z['bezeichnung']); ?></td>
                            <td>
                                <span title="<?php echo esc_attr($this->sicherheitLabel($z['sicherheit'] ?? 'G')); ?>">
                                    <?php echo esc_html($z['sicherheit'] ?? 'G'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($z['seite_field']): ?>
                                    <code style="font-size:11px;"><?php echo esc_html($z['seite_field']); ?></code>
                                <?php else: ?>
                                    <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($z['location_id']): ?>
                                    📍 <?php echo esc_html($z['location_name'] ?? '#' . $z['location_id']); ?>
                                <?php else: ?>
                                    🌐 <em><?php echo esc_html($this->t('Global')); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small pp-icd-edit"
                                        data-id="<?php echo (int) $z['id']; ?>"
                                        title="<?php echo esc_attr($this->t('Bearbeiten')); ?>">✏️</button>
                                <button type="button" class="button button-small pp-icd-delete"
                                        data-id="<?php echo (int) $z['id']; ?>"
                                        title="<?php echo esc_attr($this->t('Löschen')); ?>">🗑️</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Neue Zuordnung hinzufügen -->
            <div style="margin-top:20px;padding:15px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;max-width:1200px;">
                <h3 style="margin-top:0;">➕ <?php echo esc_html($this->t('Neue Zuordnung')); ?></h3>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
                    <div>
                        <label><strong><?php echo esc_html($this->t('Frage-Feld')); ?> *</strong></label><br>
                        <select id="icd-new-frage" style="width:200px;">
                            <option value="">— <?php echo esc_html($this->t('Feld wählen')); ?> —</option>
                            <?php foreach ($formFields as $field): ?>
                                <option value="<?php echo esc_attr($field['name']); ?>">
                                    <?php echo esc_html($field['name']); ?> — <?php echo esc_html($field['label']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__custom">✏️ <?php echo esc_html($this->t('Eigenes Feld...')); ?></option>
                        </select>
                        <input type="text" id="icd-new-frage-custom" placeholder="feldname" style="width:200px;display:none;">
                    </div>
                    <div>
                        <label><strong><?php echo esc_html($this->t('ICD-10 Code')); ?> *</strong></label><br>
                        <input type="text" id="icd-new-code" placeholder="z.B. H40.9" style="width:120px;font-family:monospace;">
                    </div>
                    <div>
                        <label><strong><?php echo esc_html($this->t('Bezeichnung')); ?></strong></label><br>
                        <input type="text" id="icd-new-bezeichnung" placeholder="z.B. Glaukom" style="width:200px;">
                    </div>
                    <div>
                        <label><strong><?php echo esc_html($this->t('Sicherheit')); ?></strong></label><br>
                        <select id="icd-new-sicherheit" style="width:100px;">
                            <option value="G">G — <?php echo esc_html($this->t('Gesichert')); ?></option>
                            <option value="V">V — <?php echo esc_html($this->t('Verdacht')); ?></option>
                            <option value="Z">Z — <?php echo esc_html($this->t('Zustand nach')); ?></option>
                            <option value="A">A — <?php echo esc_html($this->t('Ausgeschlossen')); ?></option>
                        </select>
                    </div>
                    <div>
                        <label><strong><?php echo esc_html($this->t('Seite-Feld')); ?></strong></label><br>
                        <input type="text" id="icd-new-seite" placeholder="z.B. glaukom_seite" style="width:150px;">
                    </div>
                    <div>
                        <label><strong><?php echo esc_html($this->t('Standort')); ?></strong></label><br>
                        <select id="icd-new-location" style="width:180px;">
                            <option value="">🌐 <?php echo esc_html($this->t('Global')); ?></option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo (int) $loc['id']; ?>">📍 <?php echo esc_html($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="button" class="button button-primary" id="pp-icd-add">
                            💾 <?php echo esc_html($this->t('Speichern')); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Info-Box -->
            <div style="margin-top:15px;padding:10px 15px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;max-width:1200px;">
                <strong>💡 <?php echo esc_html($this->t('Hinweis')); ?>:</strong>
                <?php echo esc_html($this->t('Die Zuordnungen werden beim GDT-, HL7- und FHIR-Export automatisch verwendet. GDT-Export ist im Free-Plan enthalten, HL7 und FHIR benötigen Premium.')); ?>
                <?php echo esc_html($this->t('Standortspezifische Zuordnungen überschreiben die globalen Defaults für den jeweiligen Standort.')); ?>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var formId = '<?php echo esc_js($activeForm); ?>';
            var nonce  = '<?php echo wp_create_nonce('pp_admin_nonce'); ?>';

            // Custom field toggle
            $('#icd-new-frage').on('change', function() {
                if ($(this).val() === '__custom') {
                    $('#icd-new-frage-custom').show().focus();
                } else {
                    $('#icd-new-frage-custom').hide();
                }
            });

            // Toggle active
            $(document).on('change', '.pp-icd-toggle', function() {
                var id = $(this).data('id');
                ppAjax('icd_toggle', { id: id });
            });

            // Delete
            $(document).on('click', '.pp-icd-delete', function() {
                if (!confirm('<?php echo esc_js($this->t('Zuordnung wirklich löschen?')); ?>')) return;
                ppAjax('icd_delete', { id: $(this).data('id') }, { reload: true });
            });

            // Add new
            $('#pp-icd-add').on('click', function() {
                var frageKey = $('#icd-new-frage').val();
                if (frageKey === '__custom') {
                    frageKey = $('#icd-new-frage-custom').val().trim();
                }
                var code = $('#icd-new-code').val().trim();

                if (!frageKey || !code) {
                    alert('<?php echo esc_js($this->t('Frage-Feld und ICD-Code sind Pflichtfelder.')); ?>');
                    return;
                }

                ppAjax('icd_save', {
                    form_id:     formId,
                    frage_key:   frageKey,
                    icd_code:    code,
                    bezeichnung: $('#icd-new-bezeichnung').val().trim(),
                    sicherheit:  $('#icd-new-sicherheit').val(),
                    seite_field: $('#icd-new-seite').val().trim(),
                    location_id: $('#icd-new-location').val() || ''
                }, {
                    button: this,
                    reload: true,
                    successMsg: '<?php echo esc_js($this->t('Zuordnung gespeichert')); ?>'
                });
            });

            // Seed defaults
            $(document).on('click', '#pp-icd-seed', function() {
                ppAjax('icd_seed', { form_id: $(this).data('form') }, {
                    button: this,
                    reload: true,
                    successMsg: '<?php echo esc_js($this->t('Default-Zuordnungen geladen')); ?>'
                });
            });

            // Merge defaults (Update ohne Löschen)
            $(document).on('click', '#pp-icd-merge', function() {
                var $btn = $(this);
                var $status = $('#pp-icd-merge-status');

                $btn.prop('disabled', true).text('⏳ <?php echo esc_js($this->t('Wird aktualisiert...')); ?>');
                $status.text('');

                $.post(ajaxurl, {
                    action: 'pp_admin_action',
                    sub_action: 'icd_merge',
                    form_id: $btn.data('form'),
                    nonce: nonce
                })
                .done(function(res) {
                    if (res.success) {
                        var result = res.data.result || {};
                        var msg = '✓ ' + (result.inserted || 0) + ' <?php echo esc_js($this->t('neu')); ?>, '
                                + (result.updated || 0) + ' <?php echo esc_js($this->t('aktualisiert')); ?>, '
                                + (result.skipped || 0) + ' <?php echo esc_js($this->t('übersprungen')); ?>';

                        $status.text(msg).css('color', '#46b450');

                        // Reload if anything changed
                        if (result.inserted > 0 || result.updated > 0) {
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        $status.text('✗ ' + (res.data?.message || '<?php echo esc_js($this->t('Fehler')); ?>')).css('color', '#dc3232');
                    }
                })
                .fail(function() {
                    $status.text('✗ <?php echo esc_js($this->t('Netzwerkfehler')); ?>').css('color', '#dc3232');
                })
                .always(function() {
                    $btn.prop('disabled', false).html('🔄 <?php echo esc_js($this->t('Defaults aktualisieren')); ?>');
                });
            });

            // Edit (inline — setzt Werte in "Neue Zuordnung" Form)
            $(document).on('click', '.pp-icd-edit', function() {
                var $row = $(this).closest('tr');
                var id   = $(this).data('id');

                // TODO: Inline-Editing erweitern — erstmal nur Hinweis
                alert('<?php echo esc_js($this->t('Löschen Sie die Zuordnung und erstellen Sie sie mit den neuen Werten neu.')); ?>');
            });

            // ppAjax helper
            function ppAjax(action, data, opts) {
                opts = opts || {};
                data.nonce       = nonce;
                data.sub_action  = action;

                if (opts.button) $(opts.button).prop('disabled', true).css('opacity', 0.5);

                $.post(ajaxurl, $.extend({ action: 'pp_admin_action' }, data))
                    .done(function(res) {
                        if (res.success) {
                            if (opts.successMsg) {
                                // Short flash
                                var $notice = $('<div class="notice notice-success is-dismissible" style="position:fixed;top:40px;right:20px;z-index:9999;padding:10px 15px;"><p>' + opts.successMsg + '</p></div>');
                                $('body').append($notice);
                                setTimeout(function() { $notice.fadeOut(300, function() { $(this).remove(); }); }, 2000);
                            }
                            if (opts.reload) location.reload();
                        } else {
                            alert(res.data?.message || '<?php echo esc_js($this->t('Fehler aufgetreten')); ?>');
                        }
                    })
                    .fail(function() {
                        alert('<?php echo esc_js($this->t('Netzwerkfehler')); ?>');
                    })
                    .always(function() {
                        if (opts.button) $(opts.button).prop('disabled', false).css('opacity', 1);
                    });
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX: Zuordnung speichern
     */
    public function handleSave(): void
    {
        $this->icdRepo->save($_POST);
        wp_send_json_success(['message' => $this->t('Gespeichert')]);
    }

    /**
     * AJAX: Zuordnung löschen
     */
    public function handleDelete(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $this->icdRepo->delete($id)) {
            wp_send_json_success(['message' => $this->t('Gelöscht')]);
        }
        wp_send_json_error(['message' => $this->t('Fehler beim Löschen')]);
    }

    /**
     * AJAX: Aktiv-Toggle
     */
    public function handleToggle(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $this->icdRepo->toggleActive($id)) {
            wp_send_json_success();
        }
        wp_send_json_error(['message' => $this->t('Fehler')]);
    }

    /**
     * AJAX: Defaults laden (Seed)
     */
    public function handleSeed(): void
    {
        $formId  = sanitize_key($_POST['form_id'] ?? 'augenarzt');
        $count   = $this->icdRepo->createDefaults($formId);
        wp_send_json_success(['message' => sprintf($this->t('%d Zuordnungen geladen'), $count)]);
    }

    /**
     * AJAX: Defaults mergen (Update ohne Löschen)
     */
    public function handleMerge(): void
    {
        $formId = sanitize_key($_POST['form_id'] ?? 'augenarzt');
        $result = $this->icdRepo->mergeDefaults($formId);

        $message = sprintf(
            $this->t('%d neue Zuordnungen eingefügt, %d aktualisiert, %d übersprungen'),
            $result['inserted'],
            $result['updated'],
            $result['skipped']
        );

        wp_send_json_success(['message' => $message, 'result' => $result]);
    }

    /**
     * Verfügbare Fragebogen-Dateien auflisten
     */
    private function getAvailableForms(): array
    {
        $forms = [];
        $dir   = PP_PLUGIN_DIR . 'forms/';

        if (!is_dir($dir)) {
            return $forms;
        }

        foreach (glob($dir . '*_de.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && !empty($data['id'])) {
                $forms[$data['id']] = $data['name'] ?? $data['id'];
            }
        }

        ksort($forms);
        return $forms;
    }

    /**
     * Felder aus einem Fragebogen-JSON extrahieren
     * Gibt nur radio/checkbox/select Felder zurück (die als Ja/Nein-Fragen für ICD relevant sind)
     */
    private function getFormFields(string $formId): array
    {
        $file = PP_PLUGIN_DIR . "forms/{$formId}_de.json";
        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || empty($data['fields'])) {
            return [];
        }

        $fields = [];
        foreach ($data['fields'] as $field) {
            $type = $field['type'] ?? '';
            // Nur Felder die als Ja/Nein-Antwort sinnvoll sind
            if (in_array($type, ['radio', 'checkbox', 'select'], true)) {
                $fields[] = [
                    'name'    => $field['name'] ?? $field['id'] ?? '',
                    'label'   => $field['label'] ?? '',
                    'type'    => $type,
                    'section' => $field['section'] ?? '',
                ];
            }
        }

        return $fields;
    }

    /**
     * Sicherheits-Code Label
     */
    private function sicherheitLabel(string $code): string
    {
        return match ($code) {
            'G' => $this->t('Gesichert'),
            'V' => $this->t('Verdacht'),
            'Z' => $this->t('Zustand nach'),
            'A' => $this->t('Ausgeschlossen'),
            default => $code,
        };
    }
}
