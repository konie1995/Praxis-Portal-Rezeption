<?php
/**
 * AdminLocations – Standort-Verwaltung im Backend
 *
 * Verantwortlich für:
 *  - Standort-Liste + Bearbeiten-Seite (Tabs: Allgemein, Services, Portal-User, API, Lizenz)
 *  - Standort-CRUD (name, slug, address, place_id, license_key, …)
 *  - Service-Verwaltung (Toggle, Custom-Services hinzufügen/bearbeiten/löschen)
 *  - Portal-Benutzer (CRUD + Berechtigungen)
 *  - API-Key-Generierung pro Standort
 *  - Lizenz-Refresh pro Standort
 *
 * v4-Änderungen:
 *  - PLACE-ID + License-Key pro Standort (Multi-Standort-Lizenzen)
 *  - Repository-Pattern (LocationRepository, ServiceRepository, PortalUserRepository, ApiKeyRepository)
 *  - Audit-Logging bei Änderungen
 *  - Validierung: E-Mail Pflicht, Name Pflicht, Slug unique
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Repository\ServiceRepository;
use PraxisPortal\Database\Repository\PortalUserRepository;
use PraxisPortal\Database\Repository\ApiKeyRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\License\LicenseManager;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminLocations
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
     * Standort-Liste rendern
     */
    public function renderListPage(): void
    {
        $locationRepo = $this->container->get(LocationRepository::class);
        $locations    = $locationRepo->getAll();
        $featureGate  = $this->container->get(FeatureGate::class);

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-location" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                Standorte
                <?php if ($featureGate->canAddLocation()): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pp-location-edit&action=new')); ?>"
                   class="page-title-action">
                    Neuen Standort anlegen
                </a>
                <?php endif; ?>
            </h1>

            <?php $this->renderMessages(); ?>

            <?php if (empty($locations)): ?>
                <div style="text-align:center;padding:60px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
                    <span style="font-size:48px;">📍</span>
                    <h2><?php echo esc_html($this->t('Noch kein Standort angelegt')); ?></h2>
                    <p><?php echo esc_html($this->t('Legen Sie Ihren ersten Praxis-Standort an.')); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-location-edit&action=new')); ?>"
                       class="button button-primary button-hero">
                        Standort anlegen
                    </a>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html($this->t('Name')); ?></th>
                            <th>Slug</th>
                            <th>PLACE-ID</th>
                            <th><?php echo esc_html($this->t('Lizenz')); ?></th>
                            <th>Status</th>
                            <th><?php echo esc_html($this->t('Aktionen')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($locations as $loc): ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-location-edit&location_id=' . $loc['id'])); ?>">
                                        <?php echo esc_html($loc['name']); ?>
                                    </a>
                                </strong>
                                <?php if (!empty($loc['is_default'])): ?>
                                    <span class="pp-badge">Standard</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($loc['slug'] ?? '—'); ?></code></td>
                            <td><code style="font-size:11px;"><?php echo esc_html($loc['uuid'] ?? '—'); ?></code></td>
                            <td>
                                <?php
                                $licenseKey = $loc['license_key'] ?? '';
                                if ($licenseKey) {
                                    echo '<span class="pp-badge pp-badge-green">Aktiv</span>';
                                } else {
                                    echo '<span class="pp-badge pp-badge-gray">Keine</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo !empty($loc['is_active']) ? '<span class="pp-badge pp-badge-green">Aktiv</span>' : '<span class="pp-badge pp-badge-gray">Inaktiv</span>'; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=pp-location-edit&location_id=' . $loc['id'])); ?>"
                                   class="button button-small">Bearbeiten</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <style>
        .pp-badge { display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#e2e3e5;color:#383d41; }
        .pp-badge-green { background:#d4edda;color:#155724; }
        .pp-badge-gray { background:#e2e3e5;color:#383d41; }
        .pp-badge-blue { background:#cce5ff;color:#004085; }
        .pp-badge-red { background:#f8d7da;color:#721c24; }
        </style>
        <?php
    }

    /**
     * Standort-Bearbeiten-Seite rendern (Tabs)
     */
    public function renderEditPage(): void
    {
        $locationRepo = $this->container->get(LocationRepository::class);
        $locationId   = (int) ($_GET['location_id'] ?? 0);
        $isNew        = !empty($_GET['action']) && $_GET['action'] === 'new';
        $activeTab    = sanitize_text_field($_GET['tab'] ?? 'general');

        $location = $locationId ? $locationRepo->findById($locationId) : null;

        if ($locationId && !$location) {
            wp_die($this->t('Standort nicht gefunden.'));
        }

        $title = $isNew ? $this->t('Neuer Standort') : ($this->t('Standort') . ': ' . esc_html($location['name'] ?? ''));

        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pp-standorte')); ?>"
                   style="text-decoration:none;">← Standorte</a>
                &nbsp;|&nbsp; <?php echo esc_html($title); ?>
            </h1>

            <?php $this->renderMessages(); ?>

            <?php if (!$isNew && $location): ?>
                <?php $this->renderEditTabs($location, $activeTab); ?>
            <?php else: ?>
                <?php $this->renderNewLocationForm(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * FORM-HANDLER (POST, vor Output)
     * ================================================================== */

    /**
     * Standort speichern (POST-Handler)
     */
    public function handleSaveLocation(): void
    {
        $locationRepo = $this->container->get(LocationRepository::class);
        $auditRepo    = $this->container->get(AuditRepository::class);

        $locationId = (int) ($_POST['location_id'] ?? 0);
        $data       = $this->sanitizeLocationData($_POST);

        // Validierung
        if (empty($data['name'])) {
            $this->redirectWithError($locationId, $this->t('Name ist erforderlich.'));
            return;
        }
        if (empty($data['email'])) {
            $this->redirectWithError($locationId, 'E-Mail ist erforderlich.');
            return;
        }

        if ($locationId) {
            // Update
            $result = $locationRepo->update($locationId, $data);

            // Services speichern
            $this->saveServicesFromPost($locationId);

            $auditRepo->logLocation('location_updated', $locationId, $data);
            $successMsg = 'updated';
        } else {
            // Neuer Standort
            $featureGate = $this->container->get(FeatureGate::class);
            if (!$featureGate->canAddLocation()) {
                wp_redirect(admin_url('admin.php?page=pp-standorte&message=error&error_msg=' . urlencode($this->t('Standort-Limit erreicht.'))));
                exit;
            }

            $locationId = $locationRepo->create($data);
            if ($locationId) {
                // Default-Services
                $serviceRepo = $this->container->get(ServiceRepository::class);
                $serviceRepo->createDefaults($locationId);

                do_action('pp_location_created', $locationId);
                $auditRepo->logLocation('location_created', $locationId, $data);
            }
            $result     = $locationId;
            $successMsg = 'created';
        }

        if ($result !== false) {
            // Setup-Wizard?
            if (!empty($_POST['from_wizard'])) {
                wp_redirect(admin_url('admin.php?page=pp-setup&step=2'));
                exit;
            }

            $tab = sanitize_text_field($_POST['active_tab'] ?? 'general');
            wp_redirect(admin_url('admin.php?page=pp-location-edit&location_id=' . $locationId . '&tab=' . $tab . '&message=' . $successMsg));
        } else {
            $this->redirectWithError($locationId, $this->t('Speichern fehlgeschlagen.'));
        }
        exit;
    }

    /**
     * Dokument hochladen (POST-Handler)
     */
    public function handleDocumentUpload(): void
    {
        // Nonce-Prüfung
        if (!isset($_POST['pp_doc_nonce']) || !wp_verify_nonce($_POST['pp_doc_nonce'], 'pp_upload_document')) {
            wp_die($this->t('Sicherheitsprüfung fehlgeschlagen.'));
        }

        $locationId = (int) ($_POST['pp_location_id'] ?? 0);
        if ($locationId < 1) {
            wp_die($this->t('Ungültige Standort-ID.'));
        }

        $title = sanitize_text_field($_POST['pp_doc_title'] ?? '');

        // Datei-Upload prüfen
        if (empty($_FILES['pp_document']['name'])) {
            wp_redirect(admin_url('admin.php?page=pp-location-edit&location_id=' . $locationId . '&tab=downloads&message=error'));
            exit;
        }

        // Datei validieren (serverseitig, nicht $_FILES['type'] da clientseitig manipulierbar)
        $allowedMimes = [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ];

        $fileInfo = wp_check_filetype_and_ext(
            $_FILES['pp_document']['tmp_name'],
            $_FILES['pp_document']['name'],
            $allowedMimes
        );

        if (!$fileInfo['type'] || !in_array($fileInfo['type'], $allowedMimes, true)) {
            wp_redirect(admin_url('admin.php?page=pp-location-edit&location_id=' . $locationId . '&tab=downloads&message=error&error_msg=' . urlencode($this->t('Dateityp nicht erlaubt.'))));
            exit;
        }

        // WordPress Upload-Handling
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $uploadedFile = $_FILES['pp_document'];
        $upload = wp_handle_upload($uploadedFile, ['test_form' => false]);

        if (isset($upload['error'])) {
            wp_redirect(admin_url('admin.php?page=pp-location-edit&location_id=' . $locationId . '&tab=downloads&message=error&error_msg=' . urlencode($upload['error'])));
            exit;
        }

        // In Datenbank speichern
        $docRepo = $this->container->get(\PraxisPortal\Database\Repository\DocumentRepository::class);

        $data = [
            'location_id' => $locationId,
            'title'       => $title ?: basename($upload['file']),
            'filename'    => basename($upload['file']),
            'file_path'   => $upload['file'],
            'file_url'    => $upload['url'],
            'mime_type'   => $upload['type'],
            'file_size'   => filesize($upload['file']),
        ];

        $result = $docRepo->createDocument($data);

        if ($result) {
            wp_redirect(admin_url('admin.php?page=pp-location-edit&location_id=' . $locationId . '&tab=downloads&message=uploaded'));
        } else {
            wp_redirect(admin_url('admin.php?page=pp-location-edit&location_id=' . $locationId . '&tab=downloads&message=error'));
        }
        exit;
    }

    /* =====================================================================
     * AJAX-HANDLER
     * ================================================================== */

    // ── Standort ──────────────────────────────────────────────

    public function ajaxSave(): void
    {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $data       = $this->sanitizeLocationData($_POST);

        if (empty($data['name'])) {
            wp_send_json_error(['message' => $this->t('Name ist erforderlich.')], 400);
        }

        $locationRepo = $this->container->get(LocationRepository::class);
        $auditRepo    = $this->container->get(AuditRepository::class);

        if ($locationId) {
            $result = $locationRepo->update($locationId, $data);
            $auditRepo->logLocation('location_updated', $locationId);
        } else {
            $result = $locationRepo->create($data);
            if ($result) {
                $locationId = (int) $result;
                $serviceRepo = $this->container->get(ServiceRepository::class);
                $serviceRepo->createDefaults($locationId);
                $auditRepo->logLocation('location_created', $locationId);
            }
        }

        if ($result !== false) {
            wp_send_json_success(['location_id' => $locationId]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }
    }

    public function ajaxDelete(): void
    {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        if ($locationId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $locationRepo = $this->container->get(LocationRepository::class);
        $auditRepo    = $this->container->get(AuditRepository::class);

        $result = $locationRepo->delete($locationId);
        if ($result) {
            $auditRepo->logLocation('location_deleted', $locationId);
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Löschen fehlgeschlagen.')]);
        }
    }

    // ── Services ──────────────────────────────────────────────

    public function ajaxToggleService(): void
    {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $active    = (bool) ($_POST['active'] ?? false);

        $serviceRepo = $this->container->get(ServiceRepository::class);
        $result = $serviceRepo->toggle($serviceId, $active);

        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Umschalten.')]);
        }
    }

    public function ajaxAddService(): void
    {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        if ($locationId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültiger Standort.')], 400);
        }

        $data = [
            'location_id'  => $locationId,
            'service_key'  => sanitize_key($_POST['service_key'] ?? ''),
            'label'        => sanitize_text_field($_POST['label'] ?? ''),
            'icon'         => sanitize_text_field($_POST['icon'] ?? '📋'),
            'description'  => sanitize_text_field($_POST['description'] ?? ''),
            'form_id'      => sanitize_text_field($_POST['form_id'] ?? ''),
            'is_custom'    => 1,
            'is_active'    => 1,
            'sort_order'   => (int) ($_POST['sort_order'] ?? 99),
        ];

        if (empty($data['label'])) {
            wp_send_json_error(['message' => $this->t('Label erforderlich.')], 400);
        }

        if (empty($data['service_key'])) {
            $data['service_key'] = sanitize_key($data['label']);
        }

        $serviceRepo = $this->container->get(ServiceRepository::class);
        $result = $serviceRepo->create($data);

        if ($result) {
            wp_send_json_success(['service_id' => $result]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Anlegen.')]);
        }
    }

    public function ajaxEditService(): void
    {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($serviceId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $data = [
            'label'       => sanitize_text_field($_POST['label'] ?? ''),
            'icon'        => sanitize_text_field($_POST['icon'] ?? '📋'),
            'sort_order'  => (int) ($_POST['sort_order'] ?? 99),
        ];

        // Optional: Externe URL (nur für external/link-Typen)
        if (isset($_POST['external_url'])) {
            $data['external_url'] = esc_url_raw($_POST['external_url']);
        }

        $serviceRepo = $this->container->get(ServiceRepository::class);
        $result = $serviceRepo->update($serviceId, $data);

        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }
    }

    public function ajaxDeleteService(): void
    {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($serviceId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $serviceRepo = $this->container->get(ServiceRepository::class);
        $result = $serviceRepo->delete($serviceId);

        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Löschen fehlgeschlagen.')]);
        }
    }

    public function ajaxDeleteDocument(): void
    {
        $docId = (int) ($_POST['doc_id'] ?? 0);
        if ($docId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $docRepo = $this->container->get(\PraxisPortal\Database\Repository\DocumentRepository::class);

        // Dokument aus DB laden um den Dateipfad zu bekommen
        $doc = $docRepo->findById($docId);
        if (!$doc) {
            wp_send_json_error(['message' => $this->t('Dokument nicht gefunden.')], 404);
        }

        // Datei physisch löschen
        if (!empty($doc['file_path']) && file_exists($doc['file_path'])) {
            @unlink($doc['file_path']);
        }

        // Aus Datenbank löschen
        $result = $docRepo->deleteDocument($docId);

        if ($result) {
            wp_send_json_success(['message' => $this->t('Dokument gelöscht.')]);
        } else {
            wp_send_json_error(['message' => $this->t('Löschen fehlgeschlagen.')]);
        }
    }

    // ── Termin-Konfiguration ────────────────────────────────

    public function ajaxSaveTerminConfig(): void
    {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($serviceId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $mode = sanitize_text_field($_POST['termin_mode'] ?? 'disabled');
        if (!in_array($mode, ['disabled', 'external', 'form'], true)) {
            $mode = 'disabled';
        }

        $serviceRepo = $this->container->get(ServiceRepository::class);

        // Config aus JSON dekodieren
        $config = [];
        if (!empty($_POST['termin_config'])) {
            $decoded = json_decode(stripslashes($_POST['termin_config']), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        $config['mode'] = $mode;

        $data = [
            'is_active'     => ($mode !== 'disabled') ? 1 : 0,
            'custom_fields' => wp_json_encode($config),
        ];

        // Externe URL
        if ($mode === 'external') {
            $data['external_url']    = esc_url_raw($_POST['external_url'] ?? '');
            $data['open_in_new_tab'] = (int) ($_POST['open_in_new_tab'] ?? 1);
        }

        $result = $serviceRepo->update($serviceId, $data);

        if ($result !== false) {
            wp_send_json_success(['mode' => $mode]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }
    }

    /**
     * Notfall-Config speichern (AJAX)
     */
    public function ajaxSaveNotfallConfig(): void
    {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($serviceId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $serviceRepo = $this->container->get(ServiceRepository::class);

        // Config aus JSON dekodieren
        $config = [];
        if (!empty($_POST['notfall_config'])) {
            $decoded = json_decode(stripslashes($_POST['notfall_config']), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        // Sanitization
        $sanitized = [
            'show_112'                 => !empty($config['show_112']),
            'emergency_text'           => sanitize_textarea_field($config['emergency_text'] ?? ''),
            'practice_emergency_label' => sanitize_text_field($config['practice_emergency_label'] ?? ''),
            'show_bereitschaftsdienst' => !empty($config['show_bereitschaftsdienst']),
            'show_giftnotruf'          => !empty($config['show_giftnotruf']),
            'show_telefonseelsorge'    => !empty($config['show_telefonseelsorge']),
            'custom_numbers'           => [],
            'additional_info'          => sanitize_textarea_field($config['additional_info'] ?? ''),
        ];

        // Custom numbers sanitizen (max 10)
        if (!empty($config['custom_numbers']) && is_array($config['custom_numbers'])) {
            $count = 0;
            foreach ($config['custom_numbers'] as $entry) {
                if ($count >= 10) break;
                $label = sanitize_text_field($entry['label'] ?? '');
                $phone = sanitize_text_field($entry['phone'] ?? '');
                if (!empty($label) && !empty($phone)) {
                    $sanitized['custom_numbers'][] = [
                        'label' => $label,
                        'phone' => $phone,
                    ];
                    $count++;
                }
            }
        }

        $result = $serviceRepo->update($serviceId, [
            'custom_fields' => wp_json_encode($sanitized, JSON_UNESCAPED_UNICODE),
        ]);

        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }
    }

    /**
     * Patient Restriction aktualisieren (AJAX)
     */
    public function ajaxUpdatePatientRestriction(): void
    {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($serviceId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $restriction = sanitize_text_field($_POST['patient_restriction'] ?? 'all');

        // Whitelist-Validierung
        if (!in_array($restriction, ['all', 'patients_only'], true)) {
            $restriction = 'all';
        }

        $serviceRepo = $this->container->get(ServiceRepository::class);
        $result = $serviceRepo->update($serviceId, ['patient_restriction' => $restriction]);

        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }
    }

    // ── Portal-Benutzer ───────────────────────────────────────

    public function ajaxSavePortalUser(): void
    {
        $userId     = (int) ($_POST['user_id'] ?? 0);
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $username   = strtolower(sanitize_text_field($_POST['username'] ?? ''));
        $password   = $_POST['password'] ?? '';

        if ($locationId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültiger Standort.')], 400);
        }
        if (empty($username)) {
            wp_send_json_error(['message' => $this->t('Benutzername erforderlich.')], 400);
        }

        $portalUserRepo = $this->container->get(PortalUserRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $data = [
            'location_id'  => $locationId,
            'username'     => $username,
            'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
            'email'        => sanitize_email($_POST['email'] ?? ''),
            'can_view'     => (int) ($_POST['can_view'] ?? 0),
            'can_edit'     => (int) ($_POST['can_edit'] ?? 0),
            'can_delete'   => (int) ($_POST['can_delete'] ?? 0),
            'can_export'   => (int) ($_POST['can_export'] ?? 0),
            'is_active'    => (int) ($_POST['is_active'] ?? 1),
        ];

        if ($userId) {
            // Update
            if (!empty($password)) {
                if (strlen($password) < 8) {
                    wp_send_json_error(['message' => $this->t('Passwort muss mindestens 8 Zeichen haben.')], 400);
                }
                $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $result = $portalUserRepo->update($userId, $data);
            if ($result !== false) {
                $auditRepo->logAuth('portal_user_updated', ['user_id' => $userId]);
                wp_send_json_success(['message' => $this->t('Benutzer aktualisiert.')]);
            } else {
                wp_send_json_error(['message' => $this->t('Fehler beim Aktualisieren.')]);
            }
        } else {
            // Neu
            if (empty($password) || strlen($password) < 8) {
                wp_send_json_error(['message' => $this->t('Passwort muss mindestens 8 Zeichen haben.')], 400);
            }

            if ($portalUserRepo->usernameExists($username)) {
                wp_send_json_error(['message' => $this->t('Benutzername bereits vergeben.')], 409);
            }

            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $result = $portalUserRepo->create($data);

            if ($result) {
                $auditRepo->logAuth('portal_user_created', ['username' => $username]);
                wp_send_json_success(['message' => $this->t('Benutzer angelegt.'), 'user_id' => $result]);
            } else {
                wp_send_json_error(['message' => $this->t('Fehler beim Anlegen.')]);
            }
        }
    }

    public function ajaxDeletePortalUser(): void
    {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $portalUserRepo = $this->container->get(PortalUserRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $result = $portalUserRepo->delete($userId);
        if ($result) {
            $auditRepo->logAuth('portal_user_deleted', ['user_id' => $userId]);
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Löschen fehlgeschlagen.')]);
        }
    }

    // ── Lizenz / API-Key ──────────────────────────────────────

    public function ajaxRefreshLicense(): void
    {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        if ($locationId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültiger Standort.')], 400);
        }

        $licenseManager = $this->container->get(LicenseManager::class);
        $result = $licenseManager->refreshForLocation($locationId);

        if ($result['success'] ?? false) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? $this->t('Lizenz-Refresh fehlgeschlagen.')]);
        }
    }

    public function ajaxGenerateApiKey(): void
    {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $label      = sanitize_text_field($_POST['label'] ?? 'API-Key');

        if ($locationId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültiger Standort.')], 400);
        }

        $apiKeyRepo = $this->container->get(ApiKeyRepository::class);
        $auditRepo  = $this->container->get(AuditRepository::class);

        $apiKey = $apiKeyRepo->create($locationId, $label);

        if ($apiKey) {
            $auditRepo->logSecurity('api_key_generated', ['location_id' => $locationId]);
            wp_send_json_success([
                'api_key' => $apiKey,
                'message' => 'API-Key generiert. Bitte sofort kopieren — er wird nur einmal angezeigt.',
            ]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Generieren.')]);
        }
    }

    /**
     * AJAX: API-Key widerrufen (deaktivieren + löschen)
     */
    public function ajaxRevokeApiKey(): void
    {
        $keyId = (int) ($_POST['api_key_id'] ?? 0);
        if ($keyId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige API-Key-ID.')], 400);
        }

        $apiKeyRepo = $this->container->get(ApiKeyRepository::class);
        $auditRepo  = $this->container->get(AuditRepository::class);

        $result = $apiKeyRepo->delete($keyId);
        if ($result) {
            $auditRepo->logSecurity('api_key_revoked', ['api_key_id' => $keyId]);
            wp_send_json_success(['message' => 'API-Key widerrufen.']);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Widerrufen.')]);
        }
    }

    /* =====================================================================
     * PRIVATE: RENDERING-HELPER
     * ================================================================== */

    /**
     * Tab-Navigation + Inhalte für Standort-Bearbeitung
     */
    private function renderEditTabs(array $location, string $activeTab): void
    {
        $tabs = [
            'general'   => '⚙️ Allgemein',
            'services'  => '📋 Services',
            'downloads' => '📥 Downloads',
            'users'     => '👥 Portal-Benutzer',
            'api'       => '🔌 API',
            'license'   => '🔑 Lizenz',
        ];

        $locationId = (int) $location['id'];
        $baseUrl    = admin_url('admin.php?page=pp-location-edit&location_id=' . $locationId);

        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <a href="<?php echo esc_url($baseUrl . '&tab=' . $tabKey); ?>"
                   class="nav-tab <?php echo esc_attr($activeTab === $tabKey ? 'nav-tab-active' : ''); ?>">
                    <?php echo esc_html($tabLabel); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php
        switch ($activeTab) {
            case 'services':
                $this->renderServicesTab($location);
                break;
            case 'downloads':
                $this->renderDownloadsTab($location);
                break;
            case 'users':
                $this->renderUsersTab($location);
                break;
            case 'api':
                $this->renderApiTab($location);
                break;
            case 'license':
                $this->renderLicenseTab($location);
                break;
            default:
                $this->renderGeneralTab($location);
                break;
        }
    }

    /**
     * Tab: Allgemeine Standort-Daten
     */
    private function renderGeneralTab(array $location): void
    {
        $locationId = (int) $location['id'];
        ?>
        <form method="post">
            <?php wp_nonce_field('pp_save_location'); ?>
            <input type="hidden" name="location_id" value="<?php echo (int) $locationId; ?>">
            <input type="hidden" name="active_tab" value="general">
            <input type="hidden" name="pp_save_location" value="1">

            <table class="form-table">
                <tr>
                    <th><label for="name">Name *</label></th>
                    <td><input type="text" id="name" name="name" value="<?php echo esc_attr($location['name'] ?? ''); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="slug">Slug</label></th>
                    <td>
                        <input type="text" id="slug" name="slug" value="<?php echo esc_attr($location['slug'] ?? ''); ?>" class="regular-text">
                        <p class="description">URL-freundlicher Name (wird automatisch generiert wenn leer)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="email">E-Mail *</label></th>
                    <td><input type="email" id="email" name="email" value="<?php echo esc_attr($location['email'] ?? ''); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="telefon"><?php echo esc_html($this->t('Telefon')); ?></label></th>
                    <td><input type="tel" id="telefon" name="telefon" value="<?php echo esc_attr($location['phone'] ?? ''); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="strasse"><?php echo esc_html($this->t('Straße + Nr.')); ?></label></th>
                    <td><input type="text" id="strasse" name="strasse" value="<?php echo esc_attr($location['street'] ?? ''); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="plz">PLZ / Ort</label></th>
                    <td>
                        <input type="text" id="plz" name="plz" value="<?php echo esc_attr($location['postal_code'] ?? ''); ?>" style="width:80px;">
                        <input type="text" id="ort" name="ort" value="<?php echo esc_attr($location['city'] ?? ''); ?>" style="width:200px;">
                    </td>
                </tr>
                <tr>
                    <th><label for="place_id">PLACE-ID</label></th>
                    <td>
                        <input type="text" id="place_id" name="place_id" value="<?php echo esc_attr($location['uuid'] ?? ''); ?>" class="regular-text" style="font-family:monospace;">
                        <p class="description">Eindeutige Standort-ID für Lizenzierung und Multi-Standort-Zuordnung</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="color_primary"><?php echo esc_html($this->t('Primärfarbe')); ?></label></th>
                    <td><input type="color" id="color_primary" name="color_primary" value="<?php echo esc_attr($location['color_primary'] ?? '#2271b1'); ?>"></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <label><input type="checkbox" name="is_active" value="1" <?php checked(!empty($location['is_active'])); ?>> Aktiv</label>&nbsp;&nbsp;
                        <label><input type="checkbox" name="is_default" value="1" <?php checked(!empty($location['is_default'])); ?>> Standard-Standort</label>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:25px;">🌴 <?php echo esc_html($this->t('Urlaubsmodus')); ?></h3>
            <table class="form-table">
                <tr>
                    <th><?php echo esc_html($this->t('Urlaubsmodus')); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="vacation_mode" value="1" <?php checked(!empty($location['vacation_mode'])); ?>>
                            <?php echo esc_html($this->t('Urlaubsmodus für diesen Standort aktivieren')); ?>
                        </label>
                        <p class="description"><?php echo esc_html($this->t('Wenn aktiv, wird Patienten ein Urlaubshinweis angezeigt statt der Services.')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vacation_message"><?php echo esc_html($this->t('Urlaubshinweis')); ?></label></th>
                    <td>
                        <textarea id="vacation_message" name="vacation_message" rows="3" class="large-text"
                                  placeholder="<?php echo esc_attr($this->t('Unsere Praxis ist derzeit im Urlaub. Wir sind ab dem XX.XX. wieder für Sie da.')); ?>"><?php echo esc_textarea($location['vacation_message'] ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th><?php echo esc_html($this->t('Zeitraum (optional)')); ?></th>
                    <td>
                        <label><?php echo esc_html($this->t('Von')); ?>:
                            <input type="date" name="vacation_start" value="<?php echo esc_attr($location['vacation_start'] ?? ''); ?>" style="width:160px;">
                        </label>
                        &nbsp;&nbsp;
                        <label><?php echo esc_html($this->t('Bis')); ?>:
                            <input type="date" name="vacation_end" value="<?php echo esc_attr($location['vacation_end'] ?? ''); ?>" style="width:160px;">
                        </label>
                        <p class="description"><?php echo esc_html($this->t('Wenn ein Zeitraum gesetzt ist, wird der Urlaubsmodus nur in diesem Zeitfenster aktiv.')); ?></p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:25px;">🔗 Links</h3>
            <table class="form-table">
                <tr>
                    <th><label for="termin_url">Termin-URL</label></th>
                    <td>
                        <input type="url" id="termin_url" name="termin_url" class="large-text"
                               value="<?php echo esc_attr($location['termin_url'] ?? ''); ?>"
                               placeholder="https://doctolib.de/...">
                        <p class="description">Link zu Doctolib, Jameda, samedi etc. Wird als Fallback für externe Terminbuchung verwendet.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="termin_button_text">Button-Text</label></th>
                    <td>
                        <input type="text" id="termin_button_text" name="termin_button_text" class="regular-text"
                               value="<?php echo esc_attr($location['termin_button_text'] ?? $this->t('Termin vereinbaren')); ?>"
                               placeholder="Termin vereinbaren">
                    </td>
                </tr>
            </table>

            <?php submit_button($this->t('Standort speichern')); ?>
        </form>
        <?php
    }

    /**
     * Tab: Services-Verwaltung
     */
    private function renderServicesTab(array $location): void
    {
        $serviceRepo = $this->container->get(ServiceRepository::class);

        // Sicherstellen dass alle Default-Services existieren (idempotent).
        // Notwendig wenn Location vor einem Update erstellt wurde, das neue
        // Defaults hinzufügt (z.B. Brillenverordnung, Downloads, Dokument).
        $serviceRepo->createDefaults((int) $location['id']);

        $services    = $serviceRepo->getByLocation((int) $location['id'], false);

        ?>
        <h2><?php echo esc_html($this->t('Services für')); ?> <?php echo esc_html($location['name']); ?></h2>
        <p class="description">Aktivieren oder deaktivieren Sie Services für diesen Standort. Sie können Label und Icon für jeden Service anpassen.</p>

        <table class="wp-list-table widefat fixed striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:50px;"><?php echo esc_html($this->t('Aktiv')); ?></th>
                    <th style="width:50px;">Icon</th>
                    <th>Service</th>
                    <th style="width:100px;">Key</th>
                    <th style="width:80px;"><?php echo esc_html($this->t('Typ')); ?></th>
                    <th style="width:140px;"><?php echo esc_html($this->t('Verfügbar für')); ?></th>
                    <th style="width:60px;"><?php echo esc_html($this->t('Reihenfolge')); ?></th>
                    <th style="width:180px;"><?php echo esc_html($this->t('Aktionen')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($services as $svc):
                // Termin-Config aus custom_fields laden
                $terminConfig = [];
                $terminMode   = 'disabled';
                if ($svc['service_key'] === 'termin') {
                    if (!empty($svc['custom_fields'])) {
                        $terminConfig = json_decode($svc['custom_fields'], true) ?: [];
                    }
                    if (!empty($svc['external_url'])) {
                        $terminMode = 'external';
                    } elseif (!empty($svc['is_active'])) {
                        $terminMode = $terminConfig['mode'] ?? 'form';
                    }
                    $terminConfig['mode'] = $terminMode;
                }

                // Notfall-Config aus custom_fields laden
                $notfallConfig = [];
                if ($svc['service_key'] === 'notfall') {
                    if (!empty($svc['custom_fields'])) {
                        $notfallConfig = json_decode($svc['custom_fields'], true) ?: [];
                    }
                    $notfallConfig = wp_parse_args($notfallConfig, [
                        'show_112'                 => true,
                        'emergency_text'           => '',
                        'practice_emergency_label' => '',
                        'show_bereitschaftsdienst' => true,
                        'show_giftnotruf'          => true,
                        'show_telefonseelsorge'    => true,
                        'custom_numbers'           => [],
                        'additional_info'          => '',
                    ]);
                }
            ?>
                <tr data-service-id="<?php echo esc_attr($svc['id']); ?>">
                    <td>
                        <?php if ($svc['service_key'] === 'termin'): ?>
                            <!-- Termin hat keinen Toggle, sondern eigenen Modus-Dropdown -->
                            <span style="color:<?php echo $terminMode !== 'disabled' ? '#28a745' : '#999'; ?>;font-size:18px;">
                                <?php echo $terminMode !== 'disabled' ? '●' : '○'; ?>
                            </span>
                        <?php else: ?>
                            <label class="pp-toggle" title="Service aktivieren/deaktivieren">
                                <input type="checkbox" class="pp-toggle-service"
                                       data-id="<?php echo esc_attr($svc['id']); ?>"
                                       <?php checked(!empty($svc['is_active'])); ?>>
                                <span class="pp-toggle-slider"></span>
                            </label>
                        <?php endif; ?>
                    </td>
                    <td><span class="pp-service-icon-display"><?php echo esc_html($svc['icon'] ?? '📋'); ?></span></td>
                    <td>
                        <strong class="pp-service-label-display"><?php echo esc_html($svc['label']); ?></strong>

                        <?php if ($svc['service_key'] === 'termin'): ?>
                        <!-- Termin-Modus Auswahl -->
                        <div style="margin-top:6px;">
                            <select class="pp-termin-mode-dropdown" data-id="<?php echo esc_attr($svc['id']); ?>" style="padding:4px 8px;font-size:12px;border-radius:4px;">
                                <option value="disabled" <?php selected($terminMode, 'disabled'); ?>>⛔ Deaktiviert</option>
                                <option value="external" <?php selected($terminMode, 'external'); ?>>🔗 Externes System (URL)</option>
                                <option value="form" <?php selected($terminMode, 'form'); ?>>📝 Internes Formular</option>
                            </select>
                        </div>

                        <!-- Externe URL (nur bei mode=external) -->
                        <div class="pp-termin-external-fields" style="margin-top:6px;<?php echo $terminMode !== 'external' ? 'display:none;' : ''; ?>">
                            <input type="url" class="pp-termin-url regular-text" data-id="<?php echo esc_attr($svc['id']); ?>"
                                   value="<?php echo esc_attr($svc['external_url'] ?? ''); ?>"
                                   placeholder="https://doctolib.de/..." style="width:100%;max-width:350px;">
                            <label style="display:block;margin-top:4px;font-size:12px;">
                                <input type="checkbox" class="pp-termin-newtab" data-id="<?php echo esc_attr($svc['id']); ?>"
                                       <?php checked($svc['open_in_new_tab'] ?? 1, 1); ?>>
                                In neuem Tab öffnen
                            </label>
                        </div>

                        <!-- Formular-Optionen (nur bei mode=form) -->
                        <div class="pp-termin-form-fields" style="margin-top:6px;<?php echo $terminMode !== 'form' ? 'display:none;' : ''; ?>">
                            <button type="button" class="button button-small pp-termin-config-btn" data-service-id="<?php echo esc_attr($svc['id']); ?>">
                                ⚙️ Formular konfigurieren
                            </button>
                        </div>

                        <!-- Hidden: Termin-Config JSON -->
                        <input type="hidden" class="pp-termin-config-json" id="termin-config-<?php echo esc_attr($svc['id']); ?>"
                               value="<?php echo esc_attr(wp_json_encode($terminConfig)); ?>">
                        <?php endif; ?>

                        <?php if ($svc['service_key'] === 'notfall'): ?>
                        <!-- Notfall-Konfiguration -->
                        <div style="margin-top:6px;">
                            <button type="button" class="button button-small pp-notfall-config-btn" data-service-id="<?php echo esc_attr($svc['id']); ?>">
                                ⚙️ Notfall konfigurieren
                            </button>
                        </div>
                        <input type="hidden" class="pp-notfall-config-json" id="notfall-config-<?php echo esc_attr($svc['id']); ?>"
                               value="<?php echo esc_attr(wp_json_encode($notfallConfig)); ?>">
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($svc['service_key']); ?></code></td>
                    <td>
                        <?php
                        $type = $svc['service_type'] ?? 'builtin';
                        $typeLabels = ['builtin' => '🔧 Intern', 'external' => '🔗 Extern', 'link' => '↗ Link'];
                        echo esc_html($typeLabels[$type] ?? $type);
                        ?>
                    </td>
                    <td>
                        <!-- Patient Restriction Dropdown -->
                        <select class="pp-patient-restriction-dropdown"
                                data-id="<?php echo esc_attr($svc['id']); ?>"
                                style="padding:4px 8px;font-size:12px;border-radius:4px;width:100%;">
                            <option value="all" <?php selected($svc['patient_restriction'] ?? 'all', 'all'); ?>>
                                👥 <?php echo esc_html($this->t('Alle')); ?>
                            </option>
                            <option value="patients_only" <?php selected($svc['patient_restriction'] ?? 'all', 'patients_only'); ?>>
                                🏥 <?php echo esc_html($this->t('Nur Bestandspatienten')); ?>
                            </option>
                        </select>
                    </td>
                    <td style="text-align:center;"><?php echo (int) ($svc['sort_order'] ?? 0); ?></td>
                    <td>
                        <button type="button" class="button button-small pp-edit-service" data-id="<?php echo esc_attr($svc['id']); ?>"
                                data-label="<?php echo esc_attr($svc['label']); ?>"
                                data-icon="<?php echo esc_attr($svc['icon'] ?? '📋'); ?>"
                                data-order="<?php echo esc_attr($svc['sort_order'] ?? 0); ?>"
                                data-type="<?php echo esc_attr($svc['service_type'] ?? 'builtin'); ?>"
                                data-url="<?php echo esc_attr($svc['external_url'] ?? ''); ?>"
                                title="Label, Icon und Reihenfolge bearbeiten">✏️ Bearbeiten</button>
                        <?php if (!empty($svc['is_custom'])): ?>
                            <button type="button" class="button button-small pp-delete-service" data-id="<?php echo esc_attr($svc['id']); ?>" title="Custom Service löschen">🗑️</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:15px;">
            <button type="button" class="button pp-add-service" data-location-id="<?php echo esc_attr($location['id']); ?>">
                + Custom Service hinzufügen
            </button>
        </p>

        <!-- ── Termin-Konfigurations-Modal ── -->
        <div id="pp-termin-config-overlay" class="pp-modal-overlay" style="display:none;">
            <div class="pp-modal" style="max-width:560px;">
                <div class="pp-modal-header">
                    <h3>📅 Termin-Formular konfigurieren</h3>
                    <button type="button" class="pp-modal-close">&times;</button>
                </div>
                <div class="pp-modal-body">
                    <p class="description">Wählen Sie, welche Felder im Terminanfrage-Formular angezeigt werden sollen.</p>

                    <div style="margin-bottom:18px;">
                        <h4 style="margin:12px 0 8px;"><?php echo esc_html($this->t('Formular-Felder')); ?></h4>
                        <p class="description" style="margin-bottom:10px;font-style:italic;">
                            💡 Patient-Status wird automatisch aus der Einstiegsfrage übernommen.
                        </p>
                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" id="tc-show-anliegen" checked>
                            <strong><?php echo esc_html($this->t('Anliegen / Grund abfragen')); ?></strong> <small>(<?php echo esc_html($this->t('Pflichtfeld')); ?>)</small>
                        </label>
                        <div style="margin-left:28px;margin-top:8px;">
                            <label><?php echo esc_html($this->t('Grund-Optionen (eine pro Zeile)')); ?>:</label>
                            <textarea id="tc-grund-options" rows="6" class="large-text" style="width:100%;margin-top:4px;font-family:monospace;font-size:12px;">vorsorge|Vorsorgeuntersuchung
kontrolle|Kontrolltermin
akut|Akute Beschwerden
op_vorbereitung|OP-Vorbereitung
nachsorge|Nachsorge
sonstiges|Sonstiges</textarea>
                            <p class="description" style="margin-top:4px;">Format: <code>wert|Anzeigename</code></p>
                        </div>
                        <label style="display:block;margin:6px 0;margin-top:12px;">
                            <input type="checkbox" id="tc-show-beschwerden" checked>
                            <strong><?php echo esc_html($this->t('Beschwerden abfragen')); ?></strong> <small>(<?php echo esc_html($this->t('Optional')); ?>)</small>
                        </label>
                    </div>

                    <div style="margin-bottom:18px;">
                        <h4 style="margin:12px 0 8px;"><?php echo esc_html($this->t('Terminwünsche')); ?></h4>
                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" id="tc-show-time-pref" checked>
                            <strong><?php echo esc_html($this->t('Uhrzeit-Präferenz')); ?></strong> <small>(<?php echo esc_html($this->t('Vormittags / Nachmittags / Egal')); ?>)</small>
                        </label>
                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" id="tc-show-day-pref" checked>
                            <strong><?php echo esc_html($this->t('Wochentag-Präferenz')); ?></strong>
                        </label>
                        <div style="margin-left:28px;margin-top:8px;">
                            <label><?php echo esc_html($this->t('Verfügbare Tage')); ?>:</label>
                            <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap;">
                                <label><input type="checkbox" class="tc-day-checkbox" value="mo" checked> Mo</label>
                                <label><input type="checkbox" class="tc-day-checkbox" value="di" checked> Di</label>
                                <label><input type="checkbox" class="tc-day-checkbox" value="mi" checked> Mi</label>
                                <label><input type="checkbox" class="tc-day-checkbox" value="do" checked> Do</label>
                                <label><input type="checkbox" class="tc-day-checkbox" value="fr" checked> Fr</label>
                                <label><input type="checkbox" class="tc-day-checkbox" value="sa"> Sa</label>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom:10px;">
                        <h4 style="margin:12px 0 8px;"><?php echo esc_html($this->t('Dringlichkeits-Hinweis')); ?></h4>
                        <textarea id="tc-urgent-hint" rows="2" class="large-text" style="width:100%;">In dringenden Fällen rufen Sie uns bitte direkt an!</textarea>
                        <p class="description">Leer lassen um keinen Hinweis anzuzeigen.</p>
                    </div>
                </div>
                <div class="pp-modal-footer">
                    <button type="button" class="button pp-modal-close">Abbrechen</button>
                    <button type="button" class="button button-primary" id="tc-save">✅ Übernehmen</button>
                </div>
            </div>
        </div>

        <!-- ── Notfall-Konfigurations-Modal ── -->
        <div id="pp-notfall-config-overlay" class="pp-modal-overlay" style="display:none;">
            <div class="pp-modal" style="max-width:600px;">
                <div class="pp-modal-header">
                    <h3>🚨 Notfall-Seite konfigurieren</h3>
                    <button type="button" class="pp-modal-close">&times;</button>
                </div>
                <div class="pp-modal-body">
                    <p class="description">Konfigurieren Sie, welche Informationen auf der Notfall-Seite im Widget angezeigt werden.</p>

                    <!-- Hinweistext -->
                    <div style="margin-bottom:18px;">
                        <h4 style="margin:12px 0 8px;">📝 Eigener Hinweistext</h4>
                        <textarea id="nc-emergency-text" rows="2" class="large-text" style="width:100%;" placeholder="z.B. 'Bitte rufen Sie bei Beschwerden außerhalb der Sprechzeiten den Bereitschaftsdienst an.'"></textarea>
                        <p class="description">Optional. Wird ganz oben angezeigt.</p>
                    </div>

                    <!-- 112 Notruf -->
                    <div style="margin-bottom:18px;">
                        <h4 style="margin:12px 0 8px;">📞 Notruf 112</h4>
                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" id="nc-show-112" checked>
                            <strong>112-Notruf-Box anzeigen</strong>
                            <small>(Lebensbedrohliche Notfälle)</small>
                        </label>
                    </div>

                    <!-- Praxis-Notfallnummer -->
                    <div style="margin-bottom:18px;">
                        <h4 style="margin:12px 0 8px;">🏥 Praxis-Notfallnummer</h4>
                        <p class="description" style="margin-bottom:8px;">
                            Die Telefonnummer wird aus den Standort-Einstellungen übernommen (Feld „Notfall-Telefon" oder „Telefon").
                        </p>
                        <label style="display:block;margin:6px 0;">
                            Beschriftung:
                            <input type="text" id="nc-practice-label" class="regular-text" style="width:100%;max-width:350px;margin-top:4px;"
                                   placeholder="Praxis-Notfallnummer (Standard)">
                        </label>
                    </div>

                    <!-- Standard-Nummern -->
                    <div style="margin-bottom:18px;">
                        <h4 style="margin:12px 0 8px;">📋 Standard-Notfallnummern</h4>
                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" id="nc-show-bereitschaft" checked>
                            <strong>Ärztlicher Bereitschaftsdienst</strong> <small>(116 117)</small>
                        </label>
                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" id="nc-show-giftnotruf" checked>
                            <strong>Giftnotruf</strong> <small>(030 19240)</small>
                        </label>
                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" id="nc-show-seelsorge" checked>
                            <strong>Telefonseelsorge</strong> <small>(0800 111 0 111)</small>
                        </label>
                    </div>

                    <!-- Eigene Nummern -->
                    <div style="margin-bottom:18px;">
                        <h4 style="margin:12px 0 8px;">➕ Eigene Telefonnummern</h4>
                        <div id="nc-custom-numbers">
                            <!-- Dynamisch gefüllt per JS -->
                        </div>
                        <button type="button" class="button button-small" id="nc-add-number" style="margin-top:8px;">
                            + Nummer hinzufügen
                        </button>
                    </div>

                    <!-- Zusatzinfo -->
                    <div style="margin-bottom:10px;">
                        <h4 style="margin:12px 0 8px;">ℹ️ Zusätzliche Informationen</h4>
                        <textarea id="nc-additional-info" rows="3" class="large-text" style="width:100%;"
                                  placeholder="z.B. Öffnungszeiten der Notaufnahme, Anfahrtsbeschreibung, etc."></textarea>
                        <p class="description">Optional. Wird unten als Info-Box angezeigt.</p>
                    </div>
                </div>
                <div class="pp-modal-footer">
                    <button type="button" class="button pp-modal-close">Abbrechen</button>
                    <button type="button" class="button button-primary" id="nc-save">✅ Übernehmen</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Downloads konfigurieren
     */
    private function renderDownloadsTab(array $location): void
    {
        $locationId = (int) $location['id'];

        // Tabelle sicherstellen (könnte bei Updates von älteren Versionen fehlen)
        \PraxisPortal\Database\Schema::install();

        ?>
        <h3><?php echo esc_html($this->t('Downloads für diesen Standort')); ?></h3>
        <p class="description">
            <?php echo esc_html($this->t('Dokumente verwalten, die Patienten über das Widget herunterladen können.')); ?>
        </p>

        <table class="widefat striped" id="pp-downloads-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html($this->t('Dokument')); ?></th>
                        <th><?php echo esc_html($this->t('Typ')); ?></th>
                        <th><?php echo esc_html($this->t('Hochgeladen')); ?></th>
                        <th><?php echo esc_html($this->t('Aktionen')); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Dokumente für diesen Standort laden
                $docRepo = $this->container->get(\PraxisPortal\Database\Repository\DocumentRepository::class);
                $documents = $docRepo->getAllByLocation($locationId);

                if (empty($documents)) {
                    echo '<tr><td colspan="4">'
                        . esc_html($this->t('Noch keine Dokumente hochgeladen.'))
                        . '</td></tr>';
                } else {
                    foreach ($documents as $doc) {
                        ?>
                        <tr data-doc-id="<?php echo esc_attr($doc['id']); ?>">
                            <td><?php echo esc_html($doc['title'] ?? $doc['filename'] ?? '—'); ?></td>
                            <td><?php echo esc_html(strtoupper($doc['mime_type'] ?? '—')); ?></td>
                            <td><?php echo esc_html($doc['created_at'] ?? '—'); ?></td>
                            <td>
                                <button type="button" class="button button-small pp-delete-doc"
                                        data-id="<?php echo esc_attr($doc['id']); ?>">
                                    <?php echo esc_html($this->t('Löschen')); ?>
                                </button>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
                </tbody>
            </table>

            <div class="pp-upload-area" style="margin-top:16px;">
                <h4><?php echo esc_html($this->t('Neues Dokument hochladen')); ?></h4>
                <form method="post" enctype="multipart/form-data" class="pp-download-upload-form">
                    <?php wp_nonce_field('pp_upload_document', 'pp_doc_nonce'); ?>
                    <input type="hidden" name="pp_location_id" value="<?php echo esc_attr($locationId); ?>">
                    <p>
                        <label><?php echo esc_html($this->t('Titel')); ?>:
                            <input type="text" name="pp_doc_title" class="regular-text">
                        </label>
                    </p>
                    <p>
                        <input type="file" name="pp_document" accept=".pdf,.doc,.docx,.jpg,.png">
                    </p>
                    <p>
                        <button type="submit" name="pp_upload_doc" class="button button-primary">
                            <?php echo esc_html($this->t('Hochladen')); ?>
                        </button>
                    </p>
                </form>
            </div>
        <?php
    }

    /**
     * Tab: Portal-Benutzer
     */
    private function renderUsersTab(array $location): void
    {
        $portalUserRepo = $this->container->get(PortalUserRepository::class);
        $users          = $portalUserRepo->getAll((int) $location['id']);

        ?>
        <h2><?php echo esc_html($this->t('Portal-Benutzer für')); ?> <?php echo esc_html($location['name']); ?></h2>
        <p class="description">Benutzer die sich ins Praxis-Portal einloggen können.</p>

        <table class="wp-list-table widefat fixed striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th><?php echo esc_html($this->t('Benutzer')); ?></th>
                    <th>E-Mail</th>
                    <th><?php echo esc_html($this->t('Rechte')); ?></th>
                    <th>Status</th>
                    <th><?php echo esc_html($this->t('Letzter Login')); ?></th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="6" style="text-align:center;padding:20px;"><?php echo esc_html($this->t('Noch keine Portal-Benutzer.')); ?></td></tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?php echo esc_html($user['username']); ?></strong>
                        <?php if (!empty($user['display_name'])): ?>
                            <br><small><?php echo esc_html($user['display_name']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($user['email'] ?? '—'); ?></td>
                    <td>
                        <?php
                        $rights = [];
                        if (!empty($user['can_view']))   $rights[] = $this->t('Lesen');
                        if (!empty($user['can_edit']))   $rights[] = $this->t('Bearbeiten');
                        if (!empty($user['can_delete'])) $rights[] = $this->t('Löschen');
                        if (!empty($user['can_export'])) $rights[] = $this->t('Export');
                        echo esc_html(implode(', ', $rights) ?: $this->t('Keine'));
                        ?>
                    </td>
                    <td><?php echo !empty($user['is_active']) ? '✅' : '❌'; ?></td>
                    <td><?php echo esc_html($user['last_login'] ? date_i18n('d.m.Y H:i', strtotime($user['last_login'])) : '—'); ?></td>
                    <td>
                        <button type="button" class="button button-small pp-edit-portal-user" data-user='<?php echo esc_attr(wp_json_encode($user)); ?>'>Bearbeiten</button>
                        <button type="button" class="button button-small pp-delete-portal-user" data-id="<?php echo esc_attr($user['id']); ?>">Löschen</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <p style="margin-top:15px;">
            <button type="button" class="button button-primary pp-add-portal-user"
                    data-location-id="<?php echo esc_attr($location['id']); ?>">
                + Neuer Portal-Benutzer
            </button>
        </p>
        <?php
    }

    /**
     * Tab: API-Einstellungen
     */
    private function renderApiTab(array $location): void
    {
        $apiKeyRepo = $this->container->get(ApiKeyRepository::class);
        $apiKeys    = $apiKeyRepo->getByLocation((int) $location['id']);

        ?>
        <h2>API-Zugang für <?php echo esc_html($location['name']); ?></h2>
        <p class="description">API-Keys für PVS-Integration (GDT, FHIR, etc.).</p>

        <table class="wp-list-table widefat fixed striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Key (<?php echo esc_html($this->t('gekürzt')); ?>)</th>
                    <th><?php echo esc_html($this->t('Erstellt')); ?></th>
                    <th><?php echo esc_html($this->t('Letzter Zugriff')); ?></th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($apiKeys)): ?>
                <tr><td colspan="5" style="text-align:center;padding:20px;"><?php echo esc_html($this->t('Noch keine API-Keys.')); ?></td></tr>
            <?php else: ?>
                <?php foreach ($apiKeys as $key): ?>
                <tr>
                    <td><?php echo esc_html($key['label'] ?? $key['name'] ?? 'API-Key'); ?></td>
                    <td><code><?php echo esc_html($key['key_prefix'] ?? substr($key['api_key_hash'], 0, 8)); ?>…</code></td>
                    <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($key['created_at']))); ?></td>
                    <td><?php echo esc_html($key['last_used_at'] ? date_i18n('d.m.Y H:i', strtotime($key['last_used_at'])) : '—'); ?></td>
                    <td>
                        <button type="button" class="button button-small pp-revoke-api-key" data-id="<?php echo esc_attr($key['id']); ?>">Widerrufen</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <p style="margin-top:15px;">
            <button type="button" class="button button-primary pp-generate-api-key"
                    data-location-id="<?php echo esc_attr($location['id']); ?>">
                🔑 Neuen API-Key generieren
            </button>
        </p>

        <!-- API-Key Anzeige (wird nach Generierung sichtbar) -->
        <div id="pp-api-key-display" style="display:none; margin-top:16px; padding:16px; background:#d4edda; border:1px solid #c3e6cb; border-radius:4px;">
            <p style="margin:0 0 8px;"><strong>⚠️ <?php echo esc_html($this->t('API-Key wurde generiert — bitte JETZT kopieren!')); ?></strong></p>
            <p style="margin:0 0 8px; color:#555; font-size:12px;">
                <?php echo esc_html($this->t('Der Key wird nur einmal im Klartext angezeigt. Nach dem Schließen ist er nicht mehr abrufbar.')); ?>
            </p>
            <div style="display:flex; gap:8px; align-items:center;">
                <input type="text" id="pp-api-key-value" readonly
                       style="font-family:monospace; font-size:13px; width:100%; padding:8px; background:#fff; border:1px solid #8c8f94;">
                <button type="button" class="button" onclick="
                    var el = document.getElementById('pp-api-key-value');
                    el.select();
                    document.execCommand('copy');
                    this.textContent = '✅ Kopiert!';
                    setTimeout(function(){ location.reload(); }, 1500);
                ">📋 Kopieren</button>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Lizenz
     */
    private function renderLicenseTab(array $location): void
    {
        $locationId = (int) $location['id'];
        $licenseKey = $location['license_key'] ?? '';
        $placeId    = $location['uuid'] ?? '';

        ?>
        <h2><?php echo esc_html($this->t('Lizenz für')); ?> <?php echo esc_html($location['name']); ?></h2>

        <table class="form-table">
            <tr>
                <th>PLACE-ID</th>
                <td><code style="font-size:14px;"><?php echo esc_html($placeId ?: '— nicht gesetzt —'); ?></code></td>
            </tr>
            <tr>
                <th><?php echo esc_html($this->t('Lizenzschlüssel')); ?></th>
                <td>
                    <?php if ($licenseKey): ?>
                        <code style="font-size:14px;"><?php echo esc_html(substr($licenseKey, 0, 8) . '…' . substr($licenseKey, -4)); ?></code>
                        <span class="pp-badge pp-badge-green" style="margin-left:10px;">Aktiv</span>
                    <?php else: ?>
                        <span class="pp-badge pp-badge-gray">Keine Lizenz</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <p>
            <button type="button" class="button pp-refresh-license"
                    data-location-id="<?php echo esc_attr($locationId); ?>">
                🔄 Lizenz-Status aktualisieren
            </button>
        </p>
        <?php
    }

    /**
     * Neues Standort-Formular (minimale Felder)
     */
    private function renderNewLocationForm(): void
    {
        ?>
        <form method="post">
            <?php wp_nonce_field('pp_save_location'); ?>
            <input type="hidden" name="location_id" value="0">
            <input type="hidden" name="pp_save_location" value="1">
            <?php if (!empty($_GET['from_wizard'])): ?>
                <input type="hidden" name="from_wizard" value="1">
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th><label for="name">Praxis-Name *</label></th>
                    <td><input type="text" id="name" name="name" class="regular-text" required autofocus></td>
                </tr>
                <tr>
                    <th><label for="email">E-Mail *</label></th>
                    <td><input type="email" id="email" name="email" class="regular-text" required value="<?php echo esc_attr(get_option('admin_email')); ?>"></td>
                </tr>
                <tr>
                    <th><label for="telefon"><?php echo esc_html($this->t('Telefon')); ?></label></th>
                    <td><input type="tel" id="telefon" name="telefon" class="regular-text"></td>
                </tr>
            </table>

            <?php submit_button($this->t('Standort anlegen')); ?>
        </form>
        <?php
    }

    /* =====================================================================
     * PRIVATE: DATEN-HELPER
     * ================================================================== */

    /**
     * Location-POST-Daten sanitizen
     */
    private function sanitizeLocationData(array $post): array
    {
        return [
            'name'               => sanitize_text_field($post['name'] ?? ''),
            'slug'               => sanitize_title($post['slug'] ?? $post['name'] ?? ''),
            'email'              => sanitize_email($post['email'] ?? ''),
            'phone'              => sanitize_text_field($post['telefon'] ?? ''),
            'street'             => sanitize_text_field($post['strasse'] ?? ''),
            'postal_code'        => sanitize_text_field($post['plz'] ?? ''),
            'city'               => sanitize_text_field($post['ort'] ?? ''),
            'uuid'               => sanitize_text_field($post['place_id'] ?? ''),
            'license_key'        => sanitize_text_field($post['license_key'] ?? ''),
            'color_primary'      => sanitize_hex_color($post['color_primary'] ?? '#2271b1'),
            'is_active'          => (int) ($post['is_active'] ?? 0),
            'is_default'         => (int) ($post['is_default'] ?? 0),
            'termin_url'         => esc_url_raw($post['termin_url'] ?? ''),
            'termin_button_text' => sanitize_text_field($post['termin_button_text'] ?? '') ?: $this->t('Termin vereinbaren'),
            // Urlaubsmodus (Multistandort-Säule: pro Location steuerbar)
            'vacation_mode'      => (int) ($post['vacation_mode'] ?? 0),
            'vacation_message'   => sanitize_textarea_field($post['vacation_message'] ?? ''),
            'vacation_start'     => sanitize_text_field($post['vacation_start'] ?? ''),
            'vacation_end'       => sanitize_text_field($post['vacation_end'] ?? ''),
        ];
    }

    /**
     * Services aus POST-Daten speichern (Batch-Update)
     */
    private function saveServicesFromPost(int $locationId): void
    {
        if (empty($_POST['services']) || !is_array($_POST['services'])) {
            return;
        }

        $serviceRepo = $this->container->get(ServiceRepository::class);

        foreach ($_POST['services'] as $serviceId => $svcData) {
            $serviceRepo->update((int) $serviceId, [
                'is_active'  => (int) ($svcData['active'] ?? 0),
                'label'      => sanitize_text_field($svcData['label'] ?? ''),
                'icon'       => sanitize_text_field($svcData['icon'] ?? ''),
                'sort_order' => (int) ($svcData['sort_order'] ?? 99),
            ]);
        }
    }

    /**
     * Redirect mit Fehlermeldung
     */
    private function redirectWithError(int $locationId, string $message): void
    {
        $url = admin_url('admin.php?page=pp-location-edit');
        if ($locationId) {
            $url .= '&location_id=' . $locationId;
        } else {
            $url .= '&action=new';
        }
        $url .= '&message=error&error_msg=' . urlencode($message);
        wp_redirect($url);
        exit;
    }

    /**
     * Admin-Notices rendern (basierend auf ?message=…)
     */
    private function renderMessages(): void
    {
        $msg = sanitize_text_field($_GET['message'] ?? '');
        if ($msg === 'created') {
            echo '<div class="notice notice-success is-dismissible"><p>Standort erfolgreich angelegt.</p></div>';
        } elseif ($msg === 'updated') {
            echo '<div class="notice notice-success is-dismissible"><p>Standort gespeichert.</p></div>';
        } elseif ($msg === 'error') {
            $errorMsg = sanitize_text_field($_GET['error_msg'] ?? $this->t('Ein Fehler ist aufgetreten.'));
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($errorMsg) . '</p></div>';
        }
    }
}
