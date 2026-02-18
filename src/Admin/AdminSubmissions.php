<?php
/**
 * AdminSubmissions – Eingänge-Verwaltung im Backend
 *
 * Verantwortlich für:
 *  - Listenansicht (mit Filter nach Standort, Status, Service)
 *  - Detailansicht (Modal via AJAX)
 *  - Status-Änderungen
 *  - CSV/BDT/PDF-Export
 *  - Datei-Download (Entschlüsselung)
 *
 * v4-Änderungen:
 *  - Durchgängige Multi-Location-Filterung
 *  - Repository-Pattern statt direkter DB-Zugriffe
 *  - AES-256 Entschlüsselung über Encryption-Service
 *  - Audit-Logging bei allen Aktionen
 *  - Lizenz-Gate für Premium-Exports
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Security\Encryption;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Export\GdtExport;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminSubmissions
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
     * Eingänge-Listenseite rendern
     */
    public function renderPage(): void
    {
        $locationRepo   = $this->container->get(LocationRepository::class);
        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $encryption     = $this->container->get(Encryption::class);

        $locations        = $locationRepo->getAll();
        $selectedLocation = (int) ($_GET['location_id'] ?? 0);

        // Wenn nur 1 Standort: automatisch dessen ID verwenden
        if ($selectedLocation === 0 && count($locations) === 1) {
            $selectedLocation = (int) $locations[0]['id'];
        }

        $selectedStatus   = sanitize_text_field($_GET['status'] ?? '');
        $selectedService  = sanitize_text_field($_GET['service_key'] ?? '');
        $perPage          = 50;

        // Submissions laden
        $result = $submissionRepo->listForLocation(
            $selectedLocation,
            1,
            $perPage,
            $selectedStatus
        );
        $submissions = $result['items'] ?? [];

        // Statistik
        $totalThisMonth = $submissionRepo->countForLocation($selectedLocation);

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-list-view" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                Eingänge
            </h1>

            <?php $this->renderFilterBar($locations, $selectedLocation, $selectedStatus, $selectedService, count($submissions), $totalThisMonth); ?>

            <?php if (empty($submissions)): ?>
                <div class="pp-no-data" style="text-align:center;padding:60px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
                    <span style="font-size:48px;">📭</span>
                    <h2><?php echo esc_html($this->t('Keine Eingänge')); ?></h2>
                    <p><?php echo esc_html($this->t('Es sind keine Einreichungen vorhanden.')); ?></p>
                </div>
            <?php else: ?>
                <?php $this->renderTable($submissions, $locations, $encryption); ?>
            <?php endif; ?>
        </div>

        <?php $this->renderModal(); ?>
        <?php $this->renderStyles(); ?>
        <?php
    }

    /* =====================================================================
     * AJAX-HANDLER
     * ================================================================== */

    /**
     * Einzelne Einreichung anzeigen (AJAX)
     */
    public function ajaxView(): void
    {
        $id = (int) ($_POST['submission_id'] ?? $_POST['id'] ?? 0);
        if ($id < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $fileRepo       = $this->container->get(FileRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        // Entschlüsselt laden
        $submission = $submissionRepo->findDecrypted($id);
        if (!$submission) {
            wp_send_json_error(['message' => $this->t('Einreichung nicht gefunden.')], 404);
        }

        $files = $fileRepo->findBySubmission($id);

        // Audit
        $auditRepo->logSubmission('admin_view', $id);

        // HTML generieren
        $html = $this->generateDetailHtml($submission, $files);

        wp_send_json_success([
            'html'       => $html,
            'submission' => $submission,
            'files'      => $files,
        ]);
    }

    /**
     * Detail-HTML für Modal generieren
     */
    private function generateDetailHtml(array $submission, array $files): string
    {
        $data = $submission['form_data'] ?? $submission['data'] ?? [];

        $html = '<div class="pp-detail-grid">';

        // Basis-Informationen
        $html .= '<div class="pp-detail-item">';
        $html .= '<div class="pp-detail-label">' . esc_html($this->t('ID')) . '</div>';
        $html .= '<div class="pp-detail-value">#' . esc_html($submission['id']) . '</div>';
        $html .= '</div>';

        $html .= '<div class="pp-detail-item">';
        $html .= '<div class="pp-detail-label">' . esc_html($this->t('Eingang')) . '</div>';
        $html .= '<div class="pp-detail-value">' . esc_html($submission['created_at'] ?? '-') . '</div>';
        $html .= '</div>';

        $html .= '<div class="pp-detail-item">';
        $html .= '<div class="pp-detail-label">' . esc_html($this->t('Status')) . '</div>';
        $html .= '<div class="pp-detail-value">' . $this->getStatusBadge($submission['status'] ?? 'pending') . '</div>';
        $html .= '</div>';

        $html .= '<div class="pp-detail-item">';
        $html .= '<div class="pp-detail-label">' . esc_html($this->t('Service')) . '</div>';
        $html .= '<div class="pp-detail-value">' . esc_html($submission['service_key'] ?? '-') . '</div>';
        $html .= '</div>';

        // Name
        if (!empty($data['vorname']) || !empty($data['nachname'])) {
            $html .= '<div class="pp-detail-item">';
            $html .= '<div class="pp-detail-label">' . esc_html($this->t('Name')) . '</div>';
            $html .= '<div class="pp-detail-value">' . esc_html(trim(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''))) . '</div>';
            $html .= '</div>';
        }

        // Geburtsdatum
        if (!empty($data['geburtsdatum'])) {
            $html .= '<div class="pp-detail-item">';
            $html .= '<div class="pp-detail-label">' . esc_html($this->t('Geburtsdatum')) . '</div>';
            $html .= '<div class="pp-detail-value">' . esc_html($data['geburtsdatum']) . '</div>';
            $html .= '</div>';
        }

        // Email
        if (!empty($data['email'])) {
            $html .= '<div class="pp-detail-item">';
            $html .= '<div class="pp-detail-label">' . esc_html($this->t('E-Mail')) . '</div>';
            $html .= '<div class="pp-detail-value"><a href="mailto:' . esc_attr($data['email']) . '">' . esc_html($data['email']) . '</a></div>';
            $html .= '</div>';
        }

        // Telefon
        if (!empty($data['telefon'])) {
            $html .= '<div class="pp-detail-item">';
            $html .= '<div class="pp-detail-label">' . esc_html($this->t('Telefon')) . '</div>';
            $html .= '<div class="pp-detail-value"><a href="tel:' . esc_attr($data['telefon']) . '">' . esc_html($data['telefon']) . '</a></div>';
            $html .= '</div>';
        }

        $html .= '</div>'; // End grid

        // Alle weiteren Felder
        $skipKeys = ['vorname', 'nachname', 'geburtsdatum', 'email', 'telefon', '_form_id', '_form_source'];
        $otherData = array_diff_key($data, array_flip($skipKeys));

        if (!empty($otherData)) {
            $html .= '<h3 style="margin-top:20px;border-top:1px solid #ddd;padding-top:15px;">' . esc_html($this->t('Weitere Angaben')) . '</h3>';
            $html .= '<div class="pp-detail-grid">';

            foreach ($otherData as $key => $value) {
                if (empty($value) || $value === 'nein') {
                    continue;
                }

                $label = ucfirst(str_replace('_', ' ', $key));
                $displayValue = is_array($value) ? implode(', ', $value) : $value;

                if ($displayValue === '1') {
                    $displayValue = '✓';
                } elseif ($displayValue === 'ja') {
                    $displayValue = '✓ Ja';
                }

                $html .= '<div class="pp-detail-item">';
                $html .= '<div class="pp-detail-label">' . esc_html($label) . '</div>';
                $html .= '<div class="pp-detail-value">' . esc_html($displayValue) . '</div>';
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // Dateien
        if (!empty($files)) {
            $html .= '<h3 style="margin-top:20px;border-top:1px solid #ddd;padding-top:15px;">' . esc_html($this->t('Anhänge')) . '</h3>';
            $html .= '<ul>';
            foreach ($files as $file) {
                $html .= '<li><a href="#" class="pp-download-file" data-id="' . esc_attr($file['id']) . '">';
                $html .= esc_html($file['original_name'] ?? 'Datei');
                $html .= '</a> (' . esc_html($this->formatFileSize($file['file_size'] ?? 0)) . ')</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * Dateigröße formatieren
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }

    /**
     * Status aktualisieren (AJAX)
     */
    public function ajaxUpdateStatus(): void
    {
        $id     = (int) ($_POST['submission_id'] ?? $_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        $allowed = ['pending', 'processed', 'exported', 'archived'];
        if ($id < 1 || !in_array($status, $allowed, true)) {
            wp_send_json_error(['message' => $this->t('Ungültige Parameter.')], 400);
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $result = $submissionRepo->updateStatus($id, $status);
        if (!$result) {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }

        $auditRepo->logSubmission('status_changed', $id, ['new_status' => $status]);

        wp_send_json_success(['status' => $status]);
    }

    /**
     * Brillenverordnungs-Werte aktualisieren (AJAX)
     */
    public function ajaxUpdateBrilleValues(): void
    {
        $id = (int) ($_POST['submission_id'] ?? 0);
        if ($id < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $encryption = $this->container->get(Encryption::class);
        $auditRepo = $this->container->get(AuditRepository::class);

        // Submission laden
        $submission = $submissionRepo->findDecrypted($id);
        if (!$submission || $submission['service_key'] !== 'brillenverordnung') {
            wp_send_json_error(['message' => $this->t('Nicht gefunden.')], 404);
        }

        // Neue Daten parsen
        $jsonData = $_POST['brille_data'] ?? '{}';
        $newData = json_decode($jsonData, true);

        if (!is_array($newData)) {
            wp_send_json_error(['message' => $this->t('Ungültige Daten.')], 400);
        }

        // Validierung
        $errors = $this->validateBrilleData($newData);
        if (!empty($errors)) {
            wp_send_json_error(['message' => implode('; ', $errors)], 400);
        }

        // Daten mergen
        $formData = $submission['form_data'] ?? [];
        if (isset($newData['refraktion'])) $formData['refraktion'] = $newData['refraktion'];
        if (isset($newData['prismen'])) $formData['prismen'] = $newData['prismen'];
        if (isset($newData['hsa'])) $formData['hsa'] = $newData['hsa'];
        if (isset($newData['pd_rechts'])) $formData['pd_rechts'] = $newData['pd_rechts'];
        if (isset($newData['pd_links'])) $formData['pd_links'] = $newData['pd_links'];
        if (isset($newData['pd_gesamt'])) $formData['pd_gesamt'] = $newData['pd_gesamt'];

        // Re-verschlüsseln
        $encryptedData = $encryption->encrypt($formData);
        $result = $submissionRepo->update($id, ['encrypted_data' => $encryptedData]);

        if (!$result) {
            wp_send_json_error(['message' => $this->t('Fehler beim Speichern.')]);
        }

        // Audit Log
        $auditRepo->logSubmission('brille_values_updated', $id, []);

        wp_send_json_success(['message' => $this->t('Werte aktualisiert')]);
    }

    /**
     * Brillenverordnungs-Daten validieren
     */
    private function validateBrilleData(array $data): array
    {
        $errors = [];

        // Refraktionswerte prüfen
        if (!empty($data['refraktion'])) {
            foreach (['rechts', 'links'] as $side) {
                if (empty($data['refraktion'][$side])) continue;
                $r = $data['refraktion'][$side];
                if (!empty($r['sph']) && (!is_numeric($r['sph']) || $r['sph'] < -30 || $r['sph'] > 30)) {
                    $errors[] = 'SPH ' . $side . ' ungültig';
                }
                if (!empty($r['ach']) && (!is_numeric($r['ach']) || $r['ach'] < 0 || $r['ach'] > 180)) {
                    $errors[] = 'ACH ' . $side . ' ungültig';
                }
            }
        }

        // HSA prüfen
        if (!empty($data['hsa']) && (!is_numeric($data['hsa']) || $data['hsa'] < 10 || $data['hsa'] > 18)) {
            $errors[] = 'HSA ungültig (10-18mm)';
        }

        return $errors;
    }

    /**
     * Einreichung löschen (Soft-Delete, AJAX)
     */
    public function ajaxDelete(): void
    {
        $id = (int) ($_POST['submission_id'] ?? $_POST['id'] ?? 0);
        if ($id < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $result = $submissionRepo->softDelete($id);
        if (!$result) {
            wp_send_json_error(['message' => $this->t('Fehler beim Löschen.')]);
        }

        $auditRepo->logSubmission('submission_deleted', $id);

        wp_send_json_success();
    }

    /**
     * Verschlüsselte Datei herunterladen (AJAX)
     */
    public function ajaxDownloadFile(): void
    {
        $fileId = sanitize_text_field($_GET['file_id'] ?? $_POST['file_id'] ?? '');
        if (empty($fileId)) {
            wp_die($this->t('Ungültige Datei-ID.'));
        }

        $fileRepo = $this->container->get(FileRepository::class);
        $fileData = $fileRepo->getDecryptedFile($fileId);

        if (!$fileData) {
            wp_die($this->t('Datei nicht gefunden oder Entschlüsselung fehlgeschlagen.'));
        }

        // Audit
        $auditRepo = $this->container->get(AuditRepository::class);
        $auditRepo->logSubmission('file_downloaded', (int) ($fileData['submission_id'] ?? 0), [
            'file_id' => $fileId,
        ]);

        // Header-Injection verhindern
        $safeName = sanitize_file_name($fileData['original_name'] ?? 'download');
        $mimeType = $fileData['mime_type'] ?? 'application/octet-stream';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . strlen($fileData['content']));
        header('X-Content-Type-Options: nosniff');
        // @codingStandardsIgnoreLine
        echo $fileData['content'];
        exit;
    }

    /**
     * CSV-Export (AJAX / admin-post)
     */
    public function ajaxExportCsv(): void
    {
        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $encryption     = $this->container->get(Encryption::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $locationId = (int) ($_GET['location_id'] ?? 0);
        $result = $submissionRepo->listForLocation($locationId, 1, 1000);
        $submissions = $result['items'] ?? [];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="eingaenge-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        // BOM für Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'ID', $this->t('Datum'), 'Service', $this->t('Vorname'), $this->t('Nachname'),
            $this->t('Geburtsdatum'), $this->t('E-Mail'), $this->t('Telefon'), 'Status',
        ], ';');

        foreach ($submissions as $sub) {
            $data = $this->decryptSubmissionData($sub, $encryption);
            if ($data === null) {
                continue;
            }

            fputcsv($output, [
                $sub['id'] ?? '',
                date('d.m.Y H:i', strtotime($sub['created_at'] ?? 'now')),
                $sub['service_key'] ?? 'anamnesebogen',
                $data['vorname'] ?? '',
                $data['nachname'] ?? '',
                $data['geburtsdatum'] ?? '',
                $data['email'] ?? '',
                $data['telefon'] ?? '',
                $sub['status'],
            ], ';');
        }

        fclose($output);
        $auditRepo->logExport('csv', 0, ['count' => count($submissions)]);
        exit;
    }

    /**
     * BDT/GDT-Export (AJAX)
     */
    public function ajaxExportBdt(): void
    {
        // Lizenz prüfen
        $featureGate = $this->container->get(FeatureGate::class);
        if (!$featureGate->hasFeature('gdt_export')) {
            wp_send_json_error(['message' => $this->t('Diese Funktion erfordert PREMIUM+.')], 403);
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $encryption     = $this->container->get(Encryption::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $submission = $submissionRepo->findDecrypted($id);
        if (!$submission) {
            wp_send_json_error(['message' => $this->t('Nicht gefunden.')], 404);
        }

        $gdtExport = $this->container->get(GdtExport::class);
        
        // export() erwartet entschlüsselte Formulardaten, nicht die ganze Row
        $formData = $submission['form_data'] ?? [];
        if (empty($formData)) {
            wp_send_json_error(['message' => $this->t('Keine Formulardaten vorhanden.')]);
        }
        
        // Location-UUID für Multistandort-Kontext
        if (!empty($submission['location_uuid'])) {
            $formData['_location_uuid'] = $submission['location_uuid'];
        }
        
        $content = $gdtExport->export($formData, (object) $submission);

        if (!$content) {
            wp_send_json_error(['message' => $this->t('Export fehlgeschlagen.')]);
        }

        // Status auf "exported" setzen
        $submissionRepo->updateStatus($id, 'exported');
        $auditRepo->logExport('bdt', $id);

        wp_send_json_success([
            'content'  => $content,
            'filename' => 'patient-' . $id . '.bdt',
        ]);
    }

    /**
     * PDF-Export / Druckansicht (AJAX)
     */
    public function ajaxExportPdf(): void
    {
        $id = (int) ($_GET['submission_id'] ?? $_GET['id'] ?? $_POST['id'] ?? $_POST['submission_id'] ?? 0);
        if ($id < 1) {
            wp_die($this->t('Ungültige ID.'));
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $submission = $submissionRepo->findDecrypted($id);
        if (!$submission) {
            wp_die($this->t('Einreichung nicht gefunden.'));
        }

        $auditRepo->logExport('pdf_print', $id);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->generatePrintHtml($submission);
        exit;
    }

    /* =====================================================================
     * PRIVATE: RENDERING-HELPER
     * ================================================================== */

    /**
     * Filter-Leiste rendern
     */
    private function renderFilterBar(
        array $locations,
        int   $selectedLocation,
        string $selectedStatus,
        string $selectedService,
        int   $count,
        int   $totalThisMonth
    ): void {
        ?>
        <div class="pp-filter-bar" style="margin:20px 0;display:flex;gap:10px;align-items:center;">
            <form method="get" style="display:flex;gap:10px;align-items:center;">
                <input type="hidden" name="page" value="praxis-portal">

                <?php if (count($locations) > 1): ?>
                <select name="location_id" onchange="this.form.submit()">
                    <option value=""><?php echo esc_html($this->t('Alle Standorte')); ?></option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo esc_attr($loc['id']); ?>"
                        <?php selected($selectedLocation, (int) $loc['id']); ?>>
                        <?php echo esc_html($loc['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <select name="status" onchange="this.form.submit()">
                    <option value=""><?php echo esc_html($this->t('Alle Status')); ?></option>
                    <option value="pending" <?php selected($selectedStatus, 'pending'); ?>><?php echo esc_html($this->t('Offen')); ?></option>
                    <option value="processed" <?php selected($selectedStatus, 'processed'); ?>><?php echo esc_html($this->t('Bearbeitet')); ?></option>
                    <option value="exported" <?php selected($selectedStatus, 'exported'); ?>><?php echo esc_html($this->t('Exportiert')); ?></option>
                </select>

                <select name="service_key" onchange="this.form.submit()">
                    <option value=""><?php echo esc_html($this->t('Alle Services')); ?></option>
                    <option value="anamnesebogen" <?php selected($selectedService, 'anamnesebogen'); ?>><?php echo esc_html($this->t('Anamnese')); ?></option>
                    <option value="rezept" <?php selected($selectedService, 'rezept'); ?>><?php echo esc_html($this->t('Rezepte')); ?></option>
                    <option value="ueberweisung" <?php selected($selectedService, 'ueberweisung'); ?>>Überweisung</option>
                    <option value="brillenverordnung" <?php selected($selectedService, 'brillenverordnung'); ?>><?php echo esc_html($this->t('Brillenverordnung')); ?></option>
                    <option value="dokument" <?php selected($selectedService, 'dokument'); ?>><?php echo esc_html($this->t('Dokumente')); ?></option>
                    <option value="termin" <?php selected($selectedService, 'termin'); ?>><?php echo esc_html($this->t('Termine')); ?></option>
                </select>
            </form>

            <span style="margin-left:auto;color:#666;">
                <?php echo (int) $count; ?> Einträge | Diesen Monat: <?php echo (int) $totalThisMonth; ?>
            </span>
        </div>
        <?php
    }

    /**
     * Submissions-Tabelle rendern
     */
    private function renderTable(array $submissions, array $locations, Encryption $encryption): void
    {
        $multiLocation = count($locations) > 1;
        $locationMap   = [];
        foreach ($locations as $loc) {
            $locationMap[(int) $loc['id']] = $loc['name'];
        }

        $serviceIcons = [
            'rezept'             => '💊',
            'ueberweisung'       => '📋',
            'brillenverordnung'  => '👓',
            'dokument'           => '📄',
            'termin'             => '📅',
            'terminabsage'       => '❌',
            'anamnesebogen'      => '📝',
        ];

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th><?php echo esc_html($this->t('Datum')); ?></th>
                    <th>Service</th>
                    <th><?php echo esc_html($this->t('Name')); ?></th>
                    <th>Status</th>
                    <?php if ($multiLocation): ?><th><?php echo esc_html($this->t('Standort')); ?></th><?php endif; ?>
                    <th><?php echo esc_html($this->t('Aktionen')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($submissions as $sub):
                $data = $this->decryptSubmissionData($sub, $encryption);
                $name = trim(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''));
                if (empty($name)) {
                    $name = '(Unbekannt)';
                }

                $serviceKey  = $sub['service_key'] ?? 'anamnesebogen';
                $serviceIcon = $serviceIcons[$serviceKey] ?? '📋';
                $statusHtml  = $this->getStatusBadge($sub['status'] ?? 'pending');
                $locName     = $locationMap[(int) ($sub['location_id'] ?? 0)] ?? '';
            ?>
                <tr data-id="<?php echo esc_attr($sub['id'] ?? ''); ?>">
                    <td><?php echo esc_html($sub['id'] ?? ''); ?></td>
                    <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($sub['created_at'] ?? 'now'))); ?></td>
                    <td><?php echo $serviceIcon; ?> <?php echo esc_html(ucfirst($serviceKey)); ?></td>
                    <td><strong><?php echo esc_html($name); ?></strong></td>
                    <td><?php echo $statusHtml; // phpcs:ignore ?></td>
                    <?php if ($multiLocation): ?>
                    <td><?php echo esc_html($locName); ?></td>
                    <?php endif; ?>
                    <td>
                        <button type="button" class="button button-small pp-view-submission"
                                data-id="<?php echo esc_attr($sub['id']); ?>" title="Anzeigen">
                            <span class="dashicons dashicons-visibility" style="margin-top:3px;"></span>
                        </button>
                        <button type="button" class="button button-small pp-export-pdf"
                                data-id="<?php echo esc_attr($sub['id']); ?>" title="PDF">
                            <span class="dashicons dashicons-pdf" style="margin-top:3px;"></span>
                        </button>
                        <button type="button" class="button button-small pp-delete-submission"
                                data-id="<?php echo esc_attr($sub['id']); ?>" title="Löschen">
                            <span class="dashicons dashicons-trash" style="margin-top:3px;"></span>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Detail-Modal HTML
     */
    private function renderModal(): void
    {
        ?>
        <div id="pp-detail-modal" class="pp-modal">
            <div class="pp-modal-header">
                <h2><?php echo esc_html($this->t('Einreichungs-Details')); ?></h2>
                <button type="button" class="pp-close-modal" style="background:none;border:none;font-size:24px;cursor:pointer;padding:0;line-height:1;color:#666;">&times;</button>
            </div>
            <div class="pp-modal-body">
                <!-- Wird via AJAX gefüllt -->
            </div>
        </div>
        <div class="pp-modal-overlay"></div>
        <script>
        jQuery(document).ready(function($) {
            // Modal schließen
            $(document).on('click', '.pp-close-modal', function() {
                $('#pp-detail-modal').removeClass('pp-modal-open');
            });
            $(document).on('click', '.pp-modal-overlay', function() {
                $('#pp-detail-modal').removeClass('pp-modal-open');
            });
            // ESC-Taste schließt Modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    $('#pp-detail-modal').removeClass('pp-modal-open');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Status-Badge HTML
     */
    private function getStatusBadge(string $status): string
    {
        $map = [
            'pending'   => [$this->t('Offen'), 'pp-status-pending'],
            'processed' => [$this->t('Bearbeitet'), 'pp-status-processed'],
            'exported'  => [$this->t('Exportiert'), 'pp-status-exported'],
            'archived'  => [$this->t('Archiviert'), 'pp-status-archived'],
        ];

        $info = $map[$status] ?? [$status, ''];

        return sprintf(
            '<span class="pp-status %s">%s</span>',
            esc_attr($info[1]),
            esc_html($info[0])
        );
    }

    /**
     * Inline-CSS für Submission-Seite
     */
    private function renderStyles(): void
    {
        ?>
        <style>
        .pp-status { display:inline-block;padding:3px 8px;border-radius:3px;font-size:12px;font-weight:500; }
        .pp-status-pending { background:#fff3cd;color:#856404; }
        .pp-status-processed { background:#d4edda;color:#155724; }
        .pp-status-exported { background:#cce5ff;color:#004085; }
        .pp-status-archived { background:#e2e3e5;color:#383d41; }
        .pp-modal { display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;max-width:800px;width:90%;max-height:90vh;overflow:auto;box-shadow:0 4px 20px rgba(0,0,0,0.3);z-index:100001; }
        .pp-modal.pp-modal-open { display:block; }
        .pp-modal-overlay { display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:100000; }
        .pp-modal.pp-modal-open + .pp-modal-overlay { display:block; }
        .pp-modal-header { display:flex;justify-content:space-between;align-items:center;padding:15px 20px;border-bottom:1px solid #ddd;background:#f5f5f5; }
        .pp-modal-header h2 { margin:0;font-size:18px; }
        .pp-close-modal { background:none;border:none;font-size:24px;cursor:pointer;padding:0;line-height:1;color:#666; }
        .pp-close-modal:hover { color:#000; }
        .pp-modal-body { padding:20px; }
        .pp-modal-footer { padding:15px 20px;border-top:1px solid #ddd;display:flex;justify-content:space-between;align-items:center; }
        .pp-detail-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:10px; }
        .pp-detail-item { padding:8px 0; }
        .pp-detail-label { font-size:11px;color:#666;text-transform:uppercase;margin-bottom:2px; }
        .pp-detail-value { font-size:14px;color:#23282d; }
        body.pp-modal-open { overflow:hidden; }
        </style>
        <?php
    }

    /* =====================================================================
     * PRIVATE: DATEN-HELPER
     * ================================================================== */

    /**
     * Submission-Daten entschlüsseln (nullable bei Fehler)
     */
    private function decryptSubmissionData(array $submission, Encryption $encryption): ?array
    {
        if (empty($submission['encrypted_data'])) {
            return [];
        }

        try {
            return $encryption->decrypt($submission['encrypted_data'], true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Druckbares HTML für eine Einreichung generieren
     */
    private function generatePrintHtml(array $submission): string
    {
        $data       = $submission['form_data'] ?? $submission['data'] ?? [];
        $serviceKey = $submission['service_key'] ?? $data['service_type'] ?? 'unbekannt';

        $serviceLabels = [
            'rezept'             => '💊 Rezept-Anfrage',
            'ueberweisung'       => '📋 Überweisung',
            'brillenverordnung'  => '👓 Brillenverordnung',
            'dokument'           => '📄 Dokument',
            'termin'             => '📅 Terminanfrage',
            'terminabsage'       => '❌ Terminabsage',
            'anamnesebogen'      => '📝 Anamnesebogen',
        ];
        $serviceLabel = $serviceLabels[$serviceKey] ?? $serviceKey;

        $praxisName = get_option('pp_praxis_name', get_bloginfo('name'));
        $name       = trim(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''));

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html($serviceLabel . ' - ' . $name); ?></title>
    <style>
        body { font-family:Arial,sans-serif;font-size:12pt;line-height:1.5;max-width:800px;margin:20px auto;padding:20px; }
        h1 { font-size:18pt;border-bottom:2px solid #333;padding-bottom:10px; }
        h2 { font-size:14pt;color:#555;margin-top:20px;border-bottom:1px solid #ddd; }
        .meta { background:#f5f5f5;padding:10px;margin-bottom:20px; }
        .grid { display:grid;grid-template-columns:1fr 1fr;gap:10px; }
        .field { margin-bottom:10px; }
        .label { font-weight:bold;color:#555; }
        .value { margin-top:2px; }
        .full-width { grid-column:1/-1; }
        @media print { body { margin:0;padding:10px; } .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right;margin-bottom:20px;">
        <button onclick="window.print()" style="padding:10px 20px;cursor:pointer;">🖨️ Drucken</button>
        <button onclick="if(window.opener){window.close()}else{history.back()}" style="padding:10px 20px;cursor:pointer;">✕ Schließen</button>
    </div>

    <h1><?php echo esc_html($serviceLabel); ?></h1>

    <div class="meta">
        <strong><?php echo esc_html($this->t('Praxis')); ?>:</strong> <?php echo esc_html($praxisName); ?><br>
        <strong><?php echo esc_html($this->t('Eingang')); ?>:</strong> <?php echo esc_html($submission['created_at'] ?? '-'); ?><br>
        <strong><?php echo esc_html($this->t('Referenz')); ?>:</strong> #<?php echo esc_html($submission['id'] ?? '-'); ?>
    </div>

    <h2><?php echo esc_html($this->t('Persönliche Daten')); ?></h2>
    <div class="grid">
        <div class="field"><div class="label">Name</div><div class="value"><?php echo esc_html($name ?: '-'); ?></div></div>
        <div class="field"><div class="label">Geburtsdatum</div><div class="value"><?php echo esc_html($data['geburtsdatum'] ?? '-'); ?></div></div>
        <div class="field"><div class="label">Telefon</div><div class="value"><?php echo esc_html($data['telefon'] ?? '-'); ?></div></div>
        <div class="field"><div class="label">E-Mail</div><div class="value"><?php echo esc_html($data['email'] ?? '-'); ?></div></div>
        <div class="field"><div class="label">Versicherung</div><div class="value"><?php echo esc_html(ucfirst($data['kasse'] ?? $data['versicherung'] ?? '-')); ?></div></div>
    </div>

    <h2><?php echo esc_html($this->t('Anfrage-Details')); ?></h2>
    <div class="grid">
        <?php echo $this->renderServiceFields($serviceKey, $data); // phpcs:ignore ?>
    </div>

    <?php if (!empty($data['anmerkungen'])): ?>
    <h2><?php echo esc_html($this->t('Anmerkungen')); ?></h2>
    <p><?php echo esc_html($data['anmerkungen']); ?></p>
    <?php endif; ?>

    <div style="margin-top:40px;font-size:10pt;color:#888;border-top:1px solid #ddd;padding-top:10px;">
        Generiert am <?php echo date('d.m.Y H:i'); ?> | Praxis-Portal v<?php echo esc_html(PP_VERSION); ?>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Service-spezifische Felder als HTML rendern
     */
    private function renderServiceFields(string $serviceKey, array $data): string
    {
        $html = '';

        // Fragebogen-Services (fragebogen_*) wie Anamnesebogen behandeln
        if (strpos($serviceKey, 'fragebogen_') === 0) {
            $serviceKey = 'anamnesebogen';
        }

        switch ($serviceKey) {
            case 'rezept':
                if (!empty($data['medikamente']) && is_array($data['medikamente'])) {
                    $meds = [];
                    foreach ($data['medikamente'] as $i => $med) {
                        $art    = $data['medikament_arten'][$i] ?? '';
                        $meds[] = esc_html($med . ($art ? " ($art)" : ''));
                    }
                    $html .= '<div class="field full-width"><div class="label">Medikamente</div><div class="value">' . implode('<br>', $meds) . '</div></div>';
                }
                if (!empty($data['rezept_lieferung'])) {
                    $html .= '<div class="field"><div class="label">Lieferung</div><div class="value">'
                        . esc_html($data['rezept_lieferung'] === 'post' ? $this->t('Per Post') : $this->t('Abholung Praxis'))
                        . '</div></div>';
                }
                break;

            case 'ueberweisung':
                $html .= '<div class="field"><div class="label">Überweisungsziel</div><div class="value">' . esc_html($data['ueberweisungsziel'] ?? '-') . '</div></div>';
                $html .= '<div class="field full-width"><div class="label">Diagnose</div><div class="value">' . esc_html($data['diagnose'] ?? '-') . '</div></div>';
                break;

            case 'termin':
                if (!empty($data['termin_anliegen'])) {
                    $html .= '<div class="field"><div class="label">Anliegen</div><div class="value">' . esc_html($data['termin_anliegen']) . '</div></div>';
                }
                if (!empty($data['termin_grund'])) {
                    $grundLabels = ['vorsorge'=>$this->t('Vorsorgeuntersuchung'),'kontrolle'=>$this->t('Kontrolltermin'),'akut'=>$this->t('Akute Beschwerden'),'op_vorbereitung'=>$this->t('OP-Vorbereitung'),'nachsorge'=>$this->t('Nachsorge'),'sonstiges'=>$this->t('Sonstiges')];
                    $html .= '<div class="field"><div class="label">Grund</div><div class="value">' . esc_html($grundLabels[$data['termin_grund']] ?? $data['termin_grund']) . '</div></div>';
                }
                if (!empty($data['termin_zeit'])) {
                    $zeitLabels = ['vormittags'=>'🌅 Vormittags','nachmittags'=>'🌇 Nachmittags','egal'=>'🕐 Egal'];
                    $html .= '<div class="field"><div class="label">Bevorzugte Zeit</div><div class="value">' . esc_html($zeitLabels[$data['termin_zeit']] ?? $data['termin_zeit']) . '</div></div>';
                }
                if (!empty($data['termin_tageszeit'])) {
                    $html .= '<div class="field"><div class="label">Tageszeit</div><div class="value">' . esc_html(ucfirst($data['termin_tageszeit'])) . '</div></div>';
                }
                if (!empty($data['termin_tage']) && is_array($data['termin_tage'])) {
                    $tageLabels = ['mo'=>'Mo','di'=>'Di','mi'=>'Mi','do'=>'Do','fr'=>'Fr','sa'=>'Sa','egal'=>'Egal'];
                    $tageStr = implode(', ', array_map(fn($t) => $tageLabels[$t] ?? $t, $data['termin_tage']));
                    $html .= '<div class="field"><div class="label">Wochentage</div><div class="value">' . esc_html($tageStr) . '</div></div>';
                }
                if (!empty($data['termin_wunsch1'])) {
                    $html .= '<div class="field"><div class="label">Wunschtermin 1</div><div class="value">' . esc_html($data['termin_wunsch1']) . '</div></div>';
                }
                if (!empty($data['termin_wunsch2'])) {
                    $html .= '<div class="field"><div class="label">Wunschtermin 2</div><div class="value">' . esc_html($data['termin_wunsch2']) . '</div></div>';
                }
                if (!empty($data['termin_schnellstmoeglich']) && $data['termin_schnellstmoeglich'] === '1') {
                    $html .= '<div class="field"><div class="label">Schnellstmöglich</div><div class="value">✅ Ja</div></div>';
                }
                if (!empty($data['termin_beschwerden'])) {
                    $html .= '<div class="field full-width"><div class="label">Beschwerden</div><div class="value">' . esc_html($data['termin_beschwerden']) . '</div></div>';
                }
                if (!empty($data['termin_hinweis'])) {
                    $html .= '<div class="field full-width"><div class="label">Anmerkungen</div><div class="value">' . esc_html($data['termin_hinweis']) . '</div></div>';
                }
                break;

            case 'terminabsage':
                $html .= '<div class="field"><div class="label">Termin-Datum</div><div class="value">' . esc_html($data['absage_datum'] ?? '-') . '</div></div>';
                if (!empty($data['absage_uhrzeit'])) {
                    $html .= '<div class="field"><div class="label">Uhrzeit</div><div class="value">' . esc_html($data['absage_uhrzeit']) . ' Uhr</div></div>';
                }
                break;

            case 'dokument':
                $html .= '<div class="field"><div class="label">Dokumenttyp</div><div class="value">' . esc_html($data['dokument_typ'] ?? '-') . '</div></div>';
                if (!empty($data['bemerkung'])) {
                    $html .= '<div class="field full-width"><div class="label">Bemerkung</div><div class="value">' . esc_html($data['bemerkung']) . '</div></div>';
                }
                break;

            case 'brillenverordnung':
                // Brillenart anzeigen
                if (!empty($data['brillenart'])) {
                    $art = is_array($data['brillenart']) ? implode(', ', $data['brillenart']) : $data['brillenart'];
                    $html .= '<div class="field"><div class="label">Brillenart</div><div class="value">' . esc_html($art) . '</div></div>';
                }

                // Refraktion detailliert anzeigen
                $html .= $this->renderRefractionDetailedDisplay($data);

                // Prismen detailliert anzeigen
                $html .= $this->renderPrismenDetailedDisplay($data);

                // HSA anzeigen
                if (!empty($data['hsa'])) {
                    $html .= '<div class="field"><div class="label">HSA</div>';
                    $html .= '<div class="value">' . esc_html($data['hsa']) . ' mm</div></div>';
                }

                // PD anzeigen
                if (!empty($data['pd_rechts']) || !empty($data['pd_links']) || !empty($data['pd_gesamt'])) {
                    $pd_parts = [];
                    if (!empty($data['pd_rechts'])) $pd_parts[] = 'R: ' . esc_html($data['pd_rechts']) . ' mm';
                    if (!empty($data['pd_links'])) $pd_parts[] = 'L: ' . esc_html($data['pd_links']) . ' mm';
                    if (!empty($data['pd_gesamt'])) $pd_parts[] = 'Gesamt: ' . esc_html($data['pd_gesamt']) . ' mm';

                    $html .= '<div class="field"><div class="label">PD</div>';
                    $html .= '<div class="value">' . implode(' / ', $pd_parts) . '</div></div>';
                }

                // Bearbeiten-Button
                $html .= '<div class="field full-width" style="padding-top:10px;border-top:1px solid #ddd;">';
                $html .= '<button type="button" class="button pp-edit-brille-btn">';
                $html .= $this->t('Verordnungswerte bearbeiten') . '</button></div>';

                // Bearbeitungsformular (initial versteckt)
                $html .= $this->renderBrilleEditForm($data);
                break;

            case 'anamnesebogen':
                // Anamnese hat viele dynamische Felder — generisch rendern
                $skipKeys = ['vorname', 'nachname', 'geburtsdatum', 'email', 'telefon', 'kasse', 'versicherung', 'anmerkungen', 'service_type', '_form_id', '_form_source'];
                foreach ($data as $key => $value) {
                    if (in_array($key, $skipKeys, true) || $value === '' || $value === null) {
                        continue;
                    }
                    if (is_array($value)) {
                        $value = implode(', ', array_filter($value, 'is_string'));
                    }
                    if ($value === '') {
                        continue;
                    }

                    // Checkbox-Werte formatieren
                    if ($value === '1') {
                        $value = '✓';
                    } elseif ($value === 'ja') {
                        $value = '✓ Ja';
                    }

                    $label = ucfirst(str_replace('_', ' ', $key));
                    $html .= '<div class="field"><div class="label">' . esc_html($label) . '</div><div class="value">' . esc_html((string) $value) . '</div></div>';
                }
                break;
        }

        return $html;
    }

    /**
     * Refraktionswerte rendern (Brillenverordnung)
     */
    private function renderRefractionFields(array $data): string
    {
        $html = '';

        if (!empty($data['refraktion']) && is_array($data['refraktion'])) {
            $rv = $data['refraktion'];
            $parts = [];

            foreach (['rechts' => $this->t('Rechts'), 'links' => $this->t('Links')] as $side => $label) {
                if (empty($rv[$side])) {
                    continue;
                }
                $s = $rv[$side];
                $vals = array_filter([
                    !empty($s['sph']) ? 'Sph: ' . $s['sph'] : '',
                    !empty($s['zyl']) ? 'Zyl: ' . $s['zyl'] : '',
                    !empty($s['ach']) ? 'Ach: ' . $s['ach'] . '°' : '',
                    !empty($s['add']) ? 'Add: ' . $s['add'] : '',
                ]);
                if ($vals) {
                    $parts[] = '<strong>' . $label . ':</strong> ' . esc_html(implode(' ', $vals));
                }
            }

            if ($parts) {
                $html .= '<div class="field full-width"><div class="label">Refraktion</div><div class="value">'
                    . implode('<br>', $parts) . '</div></div>';
            }
        }

        return $html;
    }

    /**
     * Detaillierte Anzeige der Refraktionswerte
     */
    private function renderRefractionDetailedDisplay(array $data): string
    {
        if (empty($data['refraktion'])) return '';

        $html = '<div class="field full-width"><div class="label">Refraktion</div><div class="value">';
        foreach (['rechts' => 'Rechts', 'links' => 'Links'] as $side => $label) {
            if (empty($data['refraktion'][$side])) continue;
            $r = $data['refraktion'][$side];
            $parts = [];
            if (!empty($r['sph'])) $parts[] = 'SPH: ' . esc_html($r['sph']);
            if (!empty($r['zyl'])) $parts[] = 'ZYL: ' . esc_html($r['zyl']);
            if (!empty($r['ach'])) $parts[] = 'ACH: ' . esc_html($r['ach']) . '°';
            if (!empty($r['add'])) $parts[] = 'ADD: ' . esc_html($r['add']);
            if ($parts) {
                $html .= '<strong>' . esc_html($label) . ':</strong> ' . implode(' | ', $parts) . '<br>';
            }
        }
        $html .= '</div></div>';
        return $html;
    }

    /**
     * Detaillierte Anzeige der Prismenwerte
     */
    private function renderPrismenDetailedDisplay(array $data): string
    {
        if (empty($data['prismen'])) return '';

        $html = '<div class="field full-width"><div class="label">Prismen</div><div class="value">';
        foreach (['rechts' => 'Rechts', 'links' => 'Links'] as $side => $label) {
            if (empty($data['prismen'][$side])) continue;
            $parts = [];
            foreach (['horizontal' => 'H', 'vertikal' => 'V'] as $dir => $dirLabel) {
                if (empty($data['prismen'][$side][$dir])) continue;
                $p = $data['prismen'][$side][$dir];
                if (!empty($p['wert'])) {
                    $basis = !empty($p['basis']) ? ' ' . esc_html($p['basis']) : '';
                    $parts[] = $dirLabel . ': ' . esc_html($p['wert']) . $basis;
                }
            }
            if ($parts) {
                $html .= '<strong>' . esc_html($label) . ':</strong> ' . implode(' | ', $parts) . '<br>';
            }
        }
        $html .= '</div></div>';
        return $html;
    }

    /**
     * Bearbeitungsformular für Brillenverordnung rendern
     */
    private function renderBrilleEditForm(array $data): string
    {
        $html = '<div class="pp-brille-edit-form" style="display:none;padding-top:15px;border-top:1px solid #ccc;">';

        // Refraktion Grid
        $html .= '<h4>' . $this->t('Refraktionswerte') . '</h4>';
        $html .= '<table class="pp-refraction-grid" style="width:100%;max-width:600px;">';
        $html .= '<thead><tr>';
        $html .= '<th>Auge</th><th>SPH (±0.25)</th><th>ZYL (±0.25)</th>';
        $html .= '<th>ACH (°)</th><th>ADD (±0.25)</th></tr></thead><tbody>';

        foreach (['rechts' => 'Rechts', 'links' => 'Links'] as $side => $sideLabel) {
            $ref = $data['refraktion'][$side] ?? [];
            $html .= '<tr><td><strong>' . esc_html($sideLabel) . '</strong></td>';
            $html .= '<td><input type="number" step="0.25" name="refraktion_' . $side . '_sph" value="' . esc_attr($ref['sph'] ?? '') . '" style="width:80px;"></td>';
            $html .= '<td><input type="number" step="0.25" name="refraktion_' . $side . '_zyl" value="' . esc_attr($ref['zyl'] ?? '') . '" style="width:80px;"></td>';
            $html .= '<td><input type="number" step="1" name="refraktion_' . $side . '_ach" value="' . esc_attr($ref['ach'] ?? '') . '" style="width:60px;"></td>';
            $html .= '<td><input type="number" step="0.25" name="refraktion_' . $side . '_add" value="' . esc_attr($ref['add'] ?? '') . '" style="width:80px;"></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Prismen Grid
        $html .= '<h4 style="margin-top:20px;">' . $this->t('Prismen') . '</h4>';
        $html .= '<table class="pp-prismen-grid" style="width:100%;max-width:600px;">';
        $html .= '<thead><tr><th>Auge</th><th>Richtung</th><th>Wert (cm/m)</th><th>Basis</th></tr></thead><tbody>';

        foreach (['rechts' => 'Rechts', 'links' => 'Links'] as $side => $sideLabel) {
            foreach (['horizontal' => 'Horizontal', 'vertikal' => 'Vertikal'] as $dir => $dirLabel) {
                $prism = $data['prismen'][$side][$dir] ?? [];
                $basisOptions = ($dir === 'horizontal')
                    ? ['innen' => 'Innen', 'außen' => 'Außen']
                    : ['oben' => 'Oben', 'unten' => 'Unten'];

                $html .= '<tr><td><strong>' . esc_html($sideLabel) . '</strong></td>';
                $html .= '<td>' . esc_html($dirLabel) . '</td>';
                $html .= '<td><input type="number" step="0.01" name="prisma_' . $side . '_' . substr($dir,0,1) . '_wert" value="' . esc_attr($prism['wert'] ?? '') . '" style="width:80px;"></td>';
                $html .= '<td><select name="prisma_' . $side . '_' . substr($dir,0,1) . '_basis" style="width:100px;">';
                $html .= '<option value="">-</option>';
                foreach ($basisOptions as $val => $lbl) {
                    $selected = ($prism['basis'] ?? '') === $val ? ' selected' : '';
                    $html .= '<option value="' . esc_attr($val) . '"' . $selected . '>' . esc_html($lbl) . '</option>';
                }
                $html .= '</select></td></tr>';
            }
        }
        $html .= '</tbody></table>';

        // HSA + PD
        $html .= '<h4 style="margin-top:20px;">' . $this->t('Weitere Werte') . '</h4>';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;max-width:600px;">';

        $html .= '<div><label>' . $this->t('HSA (mm)') . '</label><br>';
        $html .= '<input type="number" step="0.1" name="hsa" value="' . esc_attr($data['hsa'] ?? '') . '" placeholder="12-16" style="width:100%;"></div>';

        $html .= '<div><label>' . $this->t('PD Rechts (mm)') . '</label><br>';
        $html .= '<input type="number" step="0.5" name="pd_rechts" value="' . esc_attr($data['pd_rechts'] ?? '') . '" style="width:100%;"></div>';

        $html .= '<div><label>' . $this->t('PD Links (mm)') . '</label><br>';
        $html .= '<input type="number" step="0.5" name="pd_links" value="' . esc_attr($data['pd_links'] ?? '') . '" style="width:100%;"></div>';

        $html .= '<div><label>' . $this->t('PD Gesamt (mm)') . '</label><br>';
        $html .= '<input type="number" step="0.5" name="pd_gesamt" value="' . esc_attr($data['pd_gesamt'] ?? '') . '" style="width:100%;"></div>';

        $html .= '</div>';

        // Buttons
        $html .= '<div style="margin-top:20px;">';
        $html .= '<button type="button" class="button button-primary pp-save-brille-btn">' . $this->t('Speichern') . '</button> ';
        $html .= '<button type="button" class="button pp-cancel-brille-btn">' . $this->t('Abbrechen') . '</button>';
        $html .= '</div>';

        $html .= '</div>'; // End pp-brille-edit-form

        return $html;
    }
}
