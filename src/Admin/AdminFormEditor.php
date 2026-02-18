<?php
/**
 * AdminFormEditor – Formular-Editor (JSON) im Backend
 *
 * Verantwortlich für:
 *  - Formular-Übersicht (gespeicherte Fragebögen)
 *  - JSON-Editor (Felder, Sektionen, Bedingungen)
 *  - Clone / Import / Export von Formularen
 *  - Vorschau
 *
 * v4-Änderungen:
 *  - Formulare in DB statt Dateisystem
 *  - Versionierung (version-Feld im JSON)
 *  - Multi-Standort: Formular-Zuweisung pro Standort
 *  - Audit-Logging
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\FormRepository;
use PraxisPortal\Database\Repository\FormLocationRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminFormEditor
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * i18n-Shortcut
     */
    private function t(string $text): string
    {
        return I18n::translate($text);
    }

    /* =====================================================================
     * SEITEN-RENDERING
     * ================================================================== */

    /**
     * Formular-Übersicht (Liste aller Formulare)
     */
    public function renderListPage(): void
    {
        $formRepo     = $this->container->get(FormRepository::class);
        $formLocRepo  = $this->container->get(FormLocationRepository::class);
        $locationRepo = $this->container->get(LocationRepository::class);

        $forms      = $formRepo->getAll();
        $locations  = $locationRepo->getAll();
        $summary    = $formLocRepo->getAssignmentSummary();

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-edit-page" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                <?php echo esc_html($this->t('Fragebögen')); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pp-form-editor&action=new')); ?>" class="page-title-action"><?php echo esc_html($this->t('Neuen Fragebogen anlegen')); ?></a>
                <button type="button" class="page-title-action pp-import-form">📥 <?php echo esc_html($this->t('Importieren')); ?></button>
            </h1>

            <?php if (!empty($locations)): ?>
            <p class="description">
                💡 <?php echo esc_html($this->t('Shortcode-Beispiel')); ?>:
                <code>[pp_fragebogen standort="<?php echo esc_attr($locations[0]['slug'] ?? 'main'); ?>"]</code>
            </p>
            <?php endif; ?>

            <?php $this->renderMessages(); ?>

            <?php if (empty($forms)): ?>
                <div style="text-align:center;padding:60px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
                    <span style="font-size:48px;">📝</span>
                    <h2><?php echo esc_html($this->t('Keine Fragebögen')); ?></h2>
                    <p><?php echo esc_html($this->t('Erstellen Sie Ihren ersten Fragebogen oder importieren Sie einen vorhandenen.')); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" id="pp-forms-table">
                    <thead>
                        <tr>
                            <th style="width:22%;"><?php echo esc_html($this->t('Name')); ?></th>
                            <th style="width:10%;">ID</th>
                            <th style="width:6%;"><?php echo esc_html($this->t('Felder')); ?></th>
                            <th style="width:25%;">📍 <?php echo esc_html($this->t('Standorte')); ?></th>
                            <th style="width:12%;"><?php echo esc_html($this->t('Zuletzt geändert')); ?></th>
                            <th style="width:25%;"><?php echo esc_html($this->t('Aktionen')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($forms as $form):
                        if (isset($form['config_json'])) {
                            $config = json_decode($form['config_json'], true) ?: [];
                        } else {
                            $config = $form;
                        }
                        $formId   = $form['id'] ?? $config['id'] ?? '';
                        $fields   = count($config['fields'] ?? []);
                        $sections = count($config['sections'] ?? []);
                        $stats    = $summary[$formId] ?? ['total' => 0, 'active' => 0];
                    ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-form-editor&form_id=' . $formId)); ?>">
                                        <?php echo esc_html($form['name'] ?? $config['name'] ?? $this->t('Ohne Name')); ?>
                                    </a>
                                </strong>
                                <?php if (!empty($form['is_default'])): ?>
                                    <span style="background:#d4edda;color:#155724;padding:2px 6px;border-radius:3px;font-size:11px;"><?php echo esc_html($this->t('Standard')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:11px;"><?php echo esc_html($form['form_key'] ?? $config['id'] ?? '—'); ?></code></td>
                            <td><?php echo (int) $fields; ?> / <?php echo (int) $sections; ?></td>
                            <td>
                                <?php $this->renderLocationAssignments($formId, $locations, $formLocRepo); ?>
                            </td>
                            <td><?php
                                $dateStr = $form['updated_at'] ?? $form['created_at'] ?? '';
                                echo $dateStr ? esc_html(date_i18n('d.m.Y H:i', strtotime($dateStr))) : '—';
                            ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=pp-form-editor&form_id=' . $formId)); ?>" class="button button-small"><?php echo esc_html($this->t('Bearbeiten')); ?></a>
                                <button type="button" class="button button-small pp-clone-form" data-id="<?php echo esc_attr($formId); ?>"><?php echo esc_html($this->t('Klonen')); ?></button>
                                <button type="button" class="button button-small pp-export-form" data-id="<?php echo esc_attr($formId); ?>">Export</button>
                                <?php if (empty($form['is_default'])): ?>
                                <button type="button" class="button button-small pp-delete-form" data-id="<?php echo esc_attr($formId); ?>" style="color:#dc3232;"><?php echo esc_html($this->t('Löschen')); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Standort-Zuordnungen inline rendern (Checkboxen mit Toggle)
     */
    private function renderLocationAssignments(string $formId, array $locations, FormLocationRepository $formLocRepo): void
    {
        $assignments = $formLocRepo->getByFormId($formId);
        $assignedMap = [];
        foreach ($assignments as $a) {
            $assignedMap[(int) $a['location_id']] = (bool) $a['is_active'];
        }

        if (empty($locations)) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        ?>
        <div class="pp-form-locations" data-form-id="<?php echo esc_attr($formId); ?>" style="display:flex;flex-wrap:wrap;gap:4px;">
            <?php foreach ($locations as $loc):
                $locId    = (int) $loc['id'];
                $assigned = array_key_exists($locId, $assignedMap);
                $active   = $assigned && $assignedMap[$locId];
            ?>
                <label style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:4px;font-size:12px;cursor:pointer;
                    background:<?php echo $active ? '#d4edda' : ($assigned ? '#fff3cd' : '#f0f0f0'); ?>;
                    border:1px solid <?php echo $active ? '#28a745' : ($assigned ? '#ffc107' : '#ccc'); ?>;
                    color:<?php echo $active ? '#155724' : ($assigned ? '#856404' : '#666'); ?>;"
                    title="<?php echo esc_attr($loc['name']); ?>">
                    <input type="checkbox"
                           class="pp-form-loc-toggle"
                           data-form-id="<?php echo esc_attr($formId); ?>"
                           data-location-id="<?php echo esc_attr($locId); ?>"
                           <?php checked($active); ?>
                           style="margin:0;">
                    <?php echo esc_html($loc['name']); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Formular-Editor-Seite (JSON-Editor)
     */
    public function renderEditorPage(): void
    {
        $formRepo = $this->container->get(FormRepository::class);
        $formId   = sanitize_text_field($_GET['form_id'] ?? '');
        $isNew    = !empty($_GET['action']) && $_GET['action'] === 'new';

        $form = $formId ? $formRepo->findById($formId) : null;

        // Dual-Format: JSON-Dateien liefern direkte Arrays, DB-Einträge haben config_json
        if ($form && isset($form['config_json'])) {
            $configJson = $form['config_json'];
            $config     = json_decode($configJson, true) ?: [];
        } elseif ($form) {
            $config     = $form;
            $configJson = wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $config     = [];
            $configJson = '{}';
        }

        $title = $isNew ? $this->t('Neuer Fragebogen') : ($this->t('Bearbeiten') . ': ' . esc_html($config['name'] ?? $this->t('Fragebogen')));

        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pp-forms')); ?>" style="text-decoration:none;">← <?php echo esc_html($this->t('Fragebögen')); ?></a>
                &nbsp;|&nbsp; <?php echo esc_html($title); ?>
            </h1>

            <?php $this->renderMessages(); ?>

            <div id="pp-form-editor-app"
                 data-form-id="<?php echo esc_attr($formId); ?>"
                 data-config="<?php echo esc_attr($configJson); ?>"
                 style="margin-top:20px;">

                <!-- Meta-Daten -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin-bottom:20px;">
                    <h3 style="margin-top:0;"><?php echo esc_html($this->t('Meta-Daten')); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="form_name"><?php echo esc_html($this->t('Name')); ?></label></th>
                            <td><input type="text" id="form_name" value="<?php echo esc_attr($config['name'] ?? ''); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="form_key">ID / Key</label></th>
                            <td><input type="text" id="form_key" value="<?php echo esc_attr($config['id'] ?? ''); ?>" class="regular-text" style="font-family:monospace;"></td>
                        </tr>
                        <tr>
                            <th><label for="form_description"><?php echo esc_html($this->t('Beschreibung')); ?></label></th>
                            <td><input type="text" id="form_description" value="<?php echo esc_attr($config['description'] ?? ''); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="form_version">Version</label></th>
                            <td><input type="text" id="form_version" value="<?php echo esc_attr($config['version'] ?? '1.0.0'); ?>" style="width:120px;font-family:monospace;"></td>
                        </tr>
                    </table>
                </div>

                <!-- JSON-Editor -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin-bottom:20px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <h3 style="margin:0;">JSON-Editor</h3>
                        <div>
                            <button type="button" class="button pp-format-json"><?php echo esc_html($this->t('Formatieren')); ?></button>
                            <button type="button" class="button pp-validate-json">✓ <?php echo esc_html($this->t('Validieren')); ?></button>
                        </div>
                    </div>
                    <textarea id="pp-form-json-editor"
                              style="width:100%;height:500px;font-family:'Courier New',monospace;font-size:13px;line-height:1.4;tab-size:2;"
                    ><?php echo esc_textarea(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
                    <div id="pp-json-status" style="margin-top:5px;font-size:12px;"></div>
                </div>

                <!-- Aktionen -->
                <div style="display:flex;gap:10px;">
                    <button type="button" class="button button-primary button-hero pp-save-form">
                        💾 Fragebogen speichern
                    </button>
                    <button type="button" class="button button-hero pp-preview-form">
                        👁️ Vorschau
                    </button>
                </div>
            </div>
        </div>

        <style>
        #pp-form-json-editor { border:1px solid #ddd;border-radius:3px;padding:10px;resize:vertical; }
        #pp-form-json-editor:focus { border-color:#2271b1;box-shadow:0 0 0 1px #2271b1; }
        </style>
        <?php
    }

    /* =====================================================================
     * AJAX-HANDLER
     *
     * FormRepository nutzt String-IDs (z.B. 'augenarzt', 'zahnarzt_de')
     * und speichert Formulare als JSON-Dateien + wp_options.
     * ================================================================== */

    /**
     * Formular speichern
     */
    public function ajaxSave(): void
    {
        $jsonRaw = wp_unslash($_POST['config_json'] ?? '');

        $config = json_decode($jsonRaw, true);
        if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => $this->t('Ungültiges JSON') . ': ' . json_last_error_msg()], 400);
        }

        $formId = sanitize_key($config['id'] ?? '');
        if (empty($formId)) {
            wp_send_json_error(['message' => $this->t('Formular-ID fehlt im JSON.')], 400);
        }

        $formRepo  = $this->container->get(FormRepository::class);
        $auditRepo = $this->container->get(AuditRepository::class);

        $isNew  = !$formRepo->exists($formId);
        $result = $formRepo->save($formId, $config);

        if ($result) {
            $action = $isNew ? 'form_created' : 'form_updated';
            $auditRepo->logSettings($action, ['form_id' => $formId, 'name' => $config['name'] ?? '']);
            wp_send_json_success(['form_id' => $formId, 'message' => $this->t('Gespeichert.')]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }
    }

    /**
     * Formular löschen
     */
    public function ajaxDelete(): void
    {
        $formId = sanitize_key($_POST['form_id'] ?? '');
        if (empty($formId)) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $formRepo  = $this->container->get(FormRepository::class);
        $auditRepo = $this->container->get(AuditRepository::class);

        $result = $formRepo->delete($formId);
        if ($result) {
            $auditRepo->logSettings('form_deleted', ['form_id' => $formId]);
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Löschen fehlgeschlagen.')]);
        }
    }

    /**
     * Formular klonen
     */
    public function ajaxClone(): void
    {
        $formId  = sanitize_key($_POST['form_id'] ?? '');
        $newName = sanitize_text_field($_POST['new_name'] ?? '');

        if (empty($formId)) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $formRepo  = $this->container->get(FormRepository::class);
        $auditRepo = $this->container->get(AuditRepository::class);

        if (!$formRepo->exists($formId)) {
            wp_send_json_error(['message' => $this->t('Formular nicht gefunden.')], 404);
        }

        $targetId = $formId . '_copy_' . time();
        $result   = $formRepo->cloneForm($formId, $targetId, $newName);

        if ($result) {
            $auditRepo->logSettings('form_cloned', ['original' => $formId, 'new' => $targetId]);
            wp_send_json_success(['form_id' => $targetId, 'message' => $this->t('Formular geklont.')]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Klonen.')]);
        }
    }

    /**
     * Formular als JSON exportieren
     */
    public function ajaxExport(): void
    {
        $formId = sanitize_key($_GET['form_id'] ?? $_POST['form_id'] ?? '');
        if (empty($formId)) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $formRepo = $this->container->get(FormRepository::class);
        $json     = $formRepo->exportJson($formId);

        if ($json === null) {
            wp_send_json_error(['message' => $this->t('Formular nicht gefunden.')], 404);
        }

        $config   = json_decode($json, true) ?: [];
        $filename = sanitize_file_name(($config['id'] ?? 'form') . '_' . ($config['version'] ?? '1'));

        wp_send_json_success([
            'json'     => $json,
            'filename' => $filename,
        ]);
    }

    /**
     * Formular importieren (JSON via POST)
     */
    public function ajaxImport(): void
    {
        $jsonRaw = wp_unslash($_POST['json'] ?? '');
        if (empty($jsonRaw)) {
            wp_send_json_error(['message' => $this->t('Keine JSON-Daten empfangen.')], 400);
        }

        $config = json_decode($jsonRaw, true);
        if ($config === null) {
            wp_send_json_error(['message' => $this->t('Ungültiges JSON') . ': ' . json_last_error_msg()], 400);
        }

        if (empty($config['id']) || empty($config['fields'])) {
            wp_send_json_error(['message' => $this->t('JSON muss mindestens "id" und "fields" enthalten.')], 400);
        }

        $formRepo  = $this->container->get(FormRepository::class);
        $auditRepo = $this->container->get(AuditRepository::class);

        $formId = $formRepo->importJson($jsonRaw);

        if ($formId !== false) {
            $auditRepo->logSettings('form_imported', ['form_id' => $formId, 'name' => $config['name'] ?? '']);
            wp_send_json_success(['form_id' => $formId, 'message' => $this->t('Formular importiert.')]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Importieren.')]);
        }
    }

    /**
     * Fragebogen-Standort-Zuordnung umschalten (AJAX)
     *
     * Checkbox an = assign + active
     * Checkbox aus = unassign
     */
    public function ajaxToggleLocation(): void
    {
        $formId     = sanitize_key($_POST['form_id'] ?? '');
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $active     = !empty($_POST['active']);

        if (empty($formId) || $locationId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige Parameter.')], 400);
        }

        $formLocRepo = $this->container->get(FormLocationRepository::class);
        $auditRepo   = $this->container->get(AuditRepository::class);

        if ($active) {
            $result = $formLocRepo->assign($formId, $locationId, true);
        } else {
            $result = $formLocRepo->unassign($formId, $locationId);
        }

        if ($result !== false) {
            $auditRepo->logSettings('form_location_' . ($active ? 'assigned' : 'unassigned'), [
                'form_id'     => $formId,
                'location_id' => $locationId,
            ]);
            wp_send_json_success([
                'active'  => $active,
                'message' => $active
                    ? $this->t('Fragebogen zugeordnet.')
                    : $this->t('Zuordnung entfernt.'),
            ]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }
    }

    /* =====================================================================
     * PRIVATE: HELPER
     * ================================================================== */

    private function renderMessages(): void
    {
        $msg = sanitize_text_field($_GET['message'] ?? '');
        if ($msg === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Fragebogen gespeichert.</p></div>';
        } elseif ($msg === 'deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>Fragebogen gelöscht.</p></div>';
        } elseif ($msg === 'error') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($_GET['error_msg'] ?? $this->t('Ein Fehler ist aufgetreten.')) . '</p></div>';
        }
    }
}
