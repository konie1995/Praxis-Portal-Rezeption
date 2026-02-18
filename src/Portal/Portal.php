<?php
/**
 * Portal – Hauptklasse für das Praxis-Personal
 *
 * Stellt das Portal-Frontend bereit, über das Praxismitarbeiter
 * Einreichungen (Anamnese, Rezepte, Überweisungen etc.) einsehen,
 * bearbeiten, exportieren und beantworten können.
 *
 * Verwendet PortalAuth für Session-Management.
 * Alle sensiblen Daten werden verschlüsselt gespeichert.
 *
 * Multi-Standort:
 *  - Standort-gebundene User sehen nur ihren Standort
 *  - Location-Filter im Frontend
 *
 * @package PraxisPortal\Portal
 * @since   4.0.0
 */

namespace PraxisPortal\Portal;

use PraxisPortal\Security\Encryption;
use PraxisPortal\Location\LocationContext;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\Core\Container;

if (!defined('ABSPATH')) {
    exit;
}

class Portal
{
    private Encryption      $encryption;
    private LocationContext  $context;
    private PortalAuth      $auth;

    public function __construct(Encryption $encryption, LocationContext $context)
    {
        $this->encryption = $encryption;
        $this->context    = $context;

        // Auth wird aus dem Container geholt oder on-demand erstellt
        $container  = Container::getInstance();
        $this->auth = $container->has(PortalAuth::class)
            ? $container->get(PortalAuth::class)
            : $this->createAuth($container);
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    /**
     * Portal rendern (Shortcode-Callback)
     *
     * @param array $atts Shortcode-Attribute
     * @return string HTML
     */
    public function render(array $atts = []): string
    {
        $this->enqueueAssets();

        ob_start();
        $this->renderPortalHtml();
        return ob_get_clean();
    }

    // =========================================================================
    // AJAX-HANDLER (delegiert von Hooks.php)
    // =========================================================================

    /**
     * Login (delegiert an PortalAuth)
     */
    public function handleLogin(): void
    {
        $this->auth->handleLogin();
    }

    /**
     * AJAX-Router für Portal-Aktionen
     *
     * Erwartet $_POST['portal_action'] als Dispatcher-Key.
     */
    public function handleAction(): void
    {
        $action = sanitize_text_field($_POST['portal_action'] ?? '');

        $actionMap = [
            'logout'            => 'handleLogout',
            'get_submissions'   => 'handleGetSubmissions',
            'get_submission'    => 'handleGetSubmission',
            'check_new'         => 'handleCheckNew',
            'mark_read'         => 'handleMarkRead',
            'change_status'     => 'handleChangeStatus',
            'send_response'     => 'handleSendResponse',
            'delete_submission' => 'handleDeleteSubmission',
            'download_file'     => 'handleDownloadFile',
            'preview_file'      => 'handlePreviewFile',
            'get_file_token'    => 'handleGetFileToken',
            'export_gdt'        => 'handleExportGdt',
            'export_pdf'        => 'handleExportPdf',
            'export_fhir'       => 'handleExportFhir',
            'export_hl7'        => 'handleExportHl7',
        ];

        if (!isset($actionMap[$action])) {
            wp_send_json_error(['message' => 'Unbekannte Aktion.']);
        }

        // Zentrale Auth-Prüfung: Alle Actions außer logout benötigen volle Session-Verifizierung.
        // Logout macht eigene Nonce-Prüfung (braucht keine aktive Session).
        if ($action !== 'logout') {
            $this->auth->verifyAjaxRequest();
        }

        $method = $actionMap[$action];
        $this->$method();
    }

    // =========================================================================
    // SUBMISSIONS
    // =========================================================================

    /**
     * Submissions-Liste laden (paginiert, gefiltert, entschlüsselt)
     */
    private function handleGetSubmissions(): void
    {

        $this->requireEncryption();

        $session        = $this->auth->getSessionData();
        $userLocationId = $session['location_id'] ?? null;

        if (!$this->auth->hasPermission('can_view', $session)) {
            wp_send_json_error([
                'message' => 'Sie haben keine Berechtigung, Einreichungen anzusehen.',
                'code'    => 'no_permission',
            ]);
        }

        // Filter-Parameter
        $type       = sanitize_text_field($_POST['type'] ?? 'all');
        $status     = sanitize_text_field($_POST['status'] ?? 'all');
        $page       = max(1, (int) ($_POST['page'] ?? 1));
        $search     = sanitize_text_field($_POST['search'] ?? '');
        $locationId = isset($_POST['location_id']) ? (int) $_POST['location_id'] : null;

        // Standort-gebundene User → Override
        if ($userLocationId !== null && $userLocationId > 0) {
            $locationId = $userLocationId;
        }

        $perPage = 50;
        $repo    = $this->getSubmissionRepo();

        // Submissions laden (verschlüsselt)
        $statusFilter  = ($status !== 'all') ? $status : '';
        $serviceFilter = ($type !== 'all') ? $type : '';
        $result = $repo->listForLocation($locationId ?? 0, $page, $perPage, $statusFilter, $serviceFilter);
        $rows   = $result['items'] ?? [];
        $total  = $result['total'] ?? 0;

        // Entschlüsseln & filtern
        $submissions = [];
        foreach ($rows as $row) {
            $data = $this->decryptSubmission($row);
            if (!$data) {
                continue;
            }

            $serviceType = $this->normalizeType($data['service_type'] ?? 'anamnese');

            // Suche (muss in PHP bleiben, da Name verschlüsselt)
            if (!empty($search) && !$this->matchesSearch($data, $search)) {
                continue;
            }

            $patientName = $this->buildPatientName($data);

            $submissions[] = [
                'id'           => (int) $row['id'],
                'type'         => $serviceType,
                'service_type' => $serviceType,
                'type_label'   => $this->getTypeLabel($serviceType),
                'patient_name' => $patientName,
                'vorname'      => $data['vorname'] ?? '',
                'nachname'     => $data['nachname'] ?? '',
                'geburtsdatum' => $data['geburtsdatum'] ?? '',
                'email'        => $data['email'] ?? '',
                'status'       => $row['status'],
                'created_at'   => $row['created_at'],
                'is_read'      => !in_array($row['status'], ['pending', 'new'], true),
                'file_count'   => (int) ($row['file_count'] ?? 0),
                'location_id'  => (int) ($row['location_id'] ?? 0),
            ];
        }

        // Counts per Typ
        $typeCounts = $this->getTypeCounts($userLocationId);

        // Permissions für Frontend
        $permissions = [
            'can_view'    => $session['can_view'] ?? true,
            'can_edit'    => $session['can_edit'] ?? true,
            'can_delete'  => $session['can_delete'] ?? true,
            'can_export'  => $session['can_export'] ?? true,
            'location_id' => $userLocationId,
        ];

        wp_send_json_success([
            'submissions' => $submissions,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'counts'      => $typeCounts,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Lightweight: Nur neue Submissions zählen (für Polling).
     *
     * Kein Entschlüsseln, keine Pagination — nur ein COUNT-Query.
     * Deutlich schneller als handleGetSubmissions() für den 30s-Polling.
     */
    private function handleCheckNew(): void
    {
        $session        = $this->auth->getSessionData();
        $userLocationId = $session['location_id'] ?? null;
        $locationId     = isset($_POST['location_id']) ? (int) $_POST['location_id'] : null;

        // Standort-gebundene User → Override
        if ($userLocationId !== null && $userLocationId > 0) {
            $locationId = $userLocationId;
        }

        $repo     = $this->getSubmissionRepo();
        $newCount = $repo->countForLocation($locationId ?? 0, 'pending');

        wp_send_json_success([
            'new_count' => $newCount,
        ]);
    }

    /**
     * Einzelne Submission laden (entschlüsselt, mit Dateien + Signatur)
     */
    private function handleGetSubmission(): void
    {

        $this->requireEncryption();

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'Ungültige ID']);
        }

        $repo       = $this->getSubmissionRepo();
        $submission = $repo->findById($id);

        if (!$submission) {
            wp_send_json_error(['message' => 'Eintrag nicht gefunden']);
        }

        // Standort-Check
        $this->requireLocationAccess((int) ($submission['location_id'] ?? 0));

        // Entschlüsseln
        $data = $this->decryptSubmission($submission);
        if (!$data) {
            wp_send_json_error(['message' => 'Entschlüsselung fehlgeschlagen']);
        }

        // Daten normalisieren
        $data = $this->normalizeSubmissionData($data);

        // Dateien laden
        $fileRepo = $this->getFileRepo();
        $files    = $fileRepo->findBySubmissionId($id);
        $fileList = $this->buildFileList($files);

        // Signatur entschlüsseln
        $signature = null;
        if (!empty($submission['signature_data'])) {
            try {
                $signature = $this->encryption->decrypt($submission['signature_data']);
            } catch (\Exception $e) {
                error_log("PP Portal signature decrypt error ID {$id}: " . $e->getMessage());
            }
        }

        $serviceType = $this->normalizeType($data['service_type'] ?? 'anamnese');
        $patientName = $this->buildPatientName($data);

        // Referenz-ID
        $referenceId = !empty($submission['submission_hash'])
            ? strtoupper(substr($submission['submission_hash'], 0, 8))
            : '#' . $id;

        // Audit
        $this->getAuditRepo()->log('portal_view_submission', $id);

        wp_send_json_success([
            'id'           => $id,
            'reference_id' => $referenceId,
            'data'         => $data,
            'files'        => $fileList,
            'signature'    => $signature,
            'status'       => $submission['status'],
            'created_at'   => $submission['created_at'],
            'type'         => $serviceType,
            'service_type' => $serviceType,
            'patient_name' => $patientName,
            'type_label'   => $this->getTypeLabel($serviceType),
        ]);
    }

    // =========================================================================
    // STATUS-AKTIONEN
    // =========================================================================

    private function handleMarkRead(): void
    {


        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'Ungültige ID']);
        }

        $this->requireLocationAccessForSubmission($id);

        $this->getSubmissionRepo()->updateStatus($id, 'read');
        $this->getAuditRepo()->log('portal_mark_read', $id);

        wp_send_json_success();
    }

    private function handleChangeStatus(): void
    {


        $id        = (int) ($_POST['id'] ?? 0);
        $newStatus = sanitize_text_field($_POST['status'] ?? '');

        if (!$id) {
            wp_send_json_error(['message' => 'Ungültige ID']);
        }

        $allowed = ['pending', 'read', 'responded', 'pdf_downloaded', 'exported_to_pvs'];
        if (!in_array($newStatus, $allowed, true)) {
            wp_send_json_error(['message' => 'Ungültiger Status']);
        }

        $this->requireLocationAccessForSubmission($id);

        $this->getSubmissionRepo()->updateStatus($id, $newStatus);
        $this->getAuditRepo()->log('portal_status_changed', $id, ['new_status' => $newStatus]);

        wp_send_json_success(['status' => $newStatus]);
    }

    // =========================================================================
    // ANTWORT SENDEN (E-Mail)
    // =========================================================================

    private function handleSendResponse(): void
    {

        $this->requireEncryption();

        $id           = (int) ($_POST['id'] ?? 0);
        $responseType = sanitize_text_field($_POST['response_type'] ?? '');
        $responseText = sanitize_textarea_field($_POST['custom_text'] ?? $_POST['response_text'] ?? '');

        if (!$id || empty($responseType)) {
            wp_send_json_error(['message' => 'Ungültige Anfrage']);
        }

        $repo       = $this->getSubmissionRepo();
        $submission = $repo->findById($id);
        if (!$submission) {
            wp_send_json_error(['message' => 'Eintrag nicht gefunden']);
        }

        $this->requireLocationAccess((int) ($submission['location_id'] ?? 0));

        $data = $this->decryptSubmission($submission);
        if (!$data) {
            wp_send_json_error(['message' => 'Entschlüsselung fehlgeschlagen']);
        }

        $email       = $data['email'] ?? '';
        $vorname     = $data['vorname'] ?? '';
        $serviceType = $data['service_type'] ?? 'anamnese';
        $emailSent   = false;

        // E-Mail senden
        if (!empty($email)) {
            $subject = $this->buildResponseSubject($responseType, $serviceType);
            $message = $this->buildResponseMessage($responseType, $serviceType, $vorname, $responseText);

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_option('pp_praxis_name', get_bloginfo('name'))
                    . ' <' . get_option('admin_email') . '>',
            ];

            $emailSent = wp_mail($email, $subject, $message, $headers);
            if (!$emailSent) {
                wp_send_json_error(['message' => 'E-Mail konnte nicht gesendet werden']);
            }
        }

        // Status aktualisieren
        $newStatus    = $this->getStatusForResponse($responseType);
        $session      = $this->auth->getSessionData();
        $portalUserId = $session['user_id'] ?? null;

        $repo->updateStatus($id, $newStatus, $responseText);

        $this->getAuditRepo()->log('portal_send_response', $id, [
            'response_type' => $responseType,
            'email_sent'    => $emailSent,
        ]);

        wp_send_json_success([
            'message' => 'Antwort wurde ' . ($emailSent ? 'gesendet' : 'gespeichert'),
        ]);
    }

    // =========================================================================
    // LÖSCHEN
    // =========================================================================

    private function handleDeleteSubmission(): void
    {


        if (!$this->auth->hasPermission('can_delete')) {
            wp_send_json_error(['message' => 'Sie haben keine Berechtigung zum Löschen.']);
        }

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'Ungültige ID']);
        }

        $this->requireLocationAccessForSubmission($id);

        $this->getSubmissionRepo()->softDelete($id);
        $this->getAuditRepo()->log('portal_delete_submission', $id);

        wp_send_json_success(['message' => 'Eintrag gelöscht']);
    }

    // =========================================================================
    // DATEI-DOWNLOADS
    // =========================================================================

    private function handleDownloadFile(): void
    {
        if (!$this->auth->isFileAccessAuthorized()) {
            wp_die('Nicht autorisiert – bitte erneut anmelden');
        }

        $this->auth->addDownloadSecurityHeaders();

        $fileId = sanitize_text_field($_GET['file_id'] ?? $_POST['file_id'] ?? '');
        if (empty($fileId)) {
            wp_die('Ungültige Anfrage – keine Datei-ID');
        }

        $fileRepo = $this->getFileRepo();

        // File-ID Validierung
        if (!$fileRepo->isValidFileId($fileId)) {
            wp_die('Ungültige Datei-ID');
        }

        $file = $fileRepo->findByFileId($fileId);
        if (!$file) {
            wp_die('Datei nicht gefunden');
        }

        $filePath = $this->getUploadDir() . $fileId;
        if (!file_exists($filePath)) {
            wp_die('Datei nicht auf Disk');
        }

        // Entschlüsseln
        try {
            $encrypted = file_get_contents($filePath);
            $content   = $this->encryption->decrypt($encrypted);
            if (!$content) {
                wp_die('Entschlüsselung fehlgeschlagen');
            }

            $originalName = $this->encryption->decrypt($file['original_name_encrypted']) ?: 'download';
        } catch (\Exception $e) {
            error_log('PP Portal file decrypt: ' . $e->getMessage());
            wp_die('Entschlüsselung fehlgeschlagen');
        }

        // Medikamentenplan-Benennung für PVS
        $downloadName = $this->resolveDownloadName(
            $originalName,
            $file['mime_type'],
            (int) $file['submission_id']
        );

        $this->getAuditRepo()->log('portal_download_file', (int) $file['submission_id'], [
            'file_id' => $fileId,
        ]);

        $safeName = sanitize_file_name($downloadName);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . strlen($content));

        echo $content;
        exit;
    }

    private function handlePreviewFile(): void
    {
        if (!$this->auth->isFileAccessAuthorized()) {
            wp_die('Nicht autorisiert – bitte erneut anmelden');
        }

        $this->auth->addDownloadSecurityHeaders();

        $fileId = sanitize_text_field($_GET['file_id'] ?? $_POST['file_id'] ?? '');
        if (empty($fileId)) {
            wp_die('Ungültige Anfrage');
        }

        $fileRepo = $this->getFileRepo();
        if (!$fileRepo->isValidFileId($fileId)) {
            wp_die('Ungültige Datei-ID');
        }

        $file = $fileRepo->findByFileId($fileId);
        if (!$file) {
            wp_die('Datei nicht gefunden');
        }

        $filePath = $this->getUploadDir() . $fileId;
        if (!file_exists($filePath)) {
            wp_die('Datei nicht auf Disk');
        }

        try {
            $encrypted = file_get_contents($filePath);
            $content   = $this->encryption->decrypt($encrypted);
            if (!$content) {
                wp_die('Entschlüsselung fehlgeschlagen');
            }
        } catch (\Exception $e) {
            wp_die('Entschlüsselung fehlgeschlagen');
        }

        try {
            $displayName = $this->encryption->decrypt($file['original_name_encrypted']) ?: 'document.pdf';
        } catch (\Exception $e) {
            $displayName = 'document.pdf';
        }

        $displayName = sanitize_file_name($displayName);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: inline; filename="' . $displayName . '"');
        header('Content-Length: ' . strlen($content));

        echo $content;
        exit;
    }

    private function handleGetFileToken(): void
    {
        $this->auth->handleGetFileToken();
    }

    // =========================================================================
    // EXPORT-HANDLER
    // =========================================================================

    private function handleExportGdt(): void
    {
        $this->requireFileAccessAndExportPermission();
        $this->auth->addDownloadSecurityHeaders();

        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) {
            wp_die('Ungültige ID');
        }

        $submission = $this->loadAndAuthorizeSubmission($id);
        $locationId = (int) ($submission['location_id'] ?? 0);

        // Feature-Check
        $gate = $this->getFeatureGate();
        if (!$gate->hasFeature('gdt_export', $locationId)) {
            wp_die('GDT-Export ist nur mit PREMIUM oder PREMIUM+ Lizenz verfügbar.');
        }

        $data = $this->decryptSubmission($submission);
        if (!$data || ($data['service_type'] ?? 'anamnese') !== 'anamnese') {
            wp_die('GDT-Export nur für Anamnesebögen verfügbar');
        }

        // GDT generieren
        $container = Container::getInstance();
        $gdtExport = $container->get(\PraxisPortal\Export\GdtExport::class);
        $content   = $gdtExport->export($data);

        if (empty($content)) {
            wp_die('GDT-Export fehlgeschlagen.');
        }

        $this->getAuditRepo()->log('portal_export_gdt', $id);

        header('Content-Type: text/plain; charset=ISO-8859-15');
        header('Content-Disposition: attachment; filename="Anamnese.bdt"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    private function handleExportPdf(): void
    {
        $this->requireFileAccessAndExportPermission();
        $this->auth->addDownloadSecurityHeaders();

        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) {
            wp_die('Ungültige ID');
        }

        $submission = $this->loadAndAuthorizeSubmission($id);
        $locationId = (int) ($submission['location_id'] ?? 0);

        $gate = $this->getFeatureGate();
        if (!$gate->hasFeature('pdf_export', $locationId)) {
            wp_die('PDF-Export ist für diesen Plan nicht verfügbar.');
        }

        $this->getAuditRepo()->log('portal_export_pdf', $id);

        // PDF-Klasse generiert und sendet
        $container = Container::getInstance();
        $pdfExport = $container->get(\PraxisPortal\Export\Pdf\PdfAnamnese::class);
        $pdfExport->generateAndSend($id);
        exit;
    }

    private function handleExportFhir(): void
    {
        $this->requireFileAccessAndExportPermission();
        $this->auth->addDownloadSecurityHeaders();

        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) {
            wp_die('Ungültige ID');
        }

        $submission = $this->loadAndAuthorizeSubmission($id);
        $locationId = (int) ($submission['location_id'] ?? 0);

        $gate = $this->getFeatureGate();
        if (!$gate->hasFeature('fhir_export', $locationId)) {
            wp_die('FHIR-Export ist für diesen Plan nicht verfügbar.');
        }

        $data = $this->decryptSubmission($submission);
        if (!$data) {
            wp_die('Entschlüsselung fehlgeschlagen');
        }

        $container  = Container::getInstance();
        $fhirExport = $container->get(\PraxisPortal\Export\FhirExport::class);

        try {
            $result = $fhirExport->export($data, (object) $submission);
        } catch (\Exception $e) {
            wp_die(esc_html($e->getMessage()));
        }

        $this->getAuditRepo()->log('portal_export_fhir', $id);

        $filename = 'anamnese_fhir_' . $id . '_' . date('Ymd_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * HL7 v2.5 Export (PREMIUM+ Lizenz erforderlich)
     *
     * Multistandort: location_id aus Submission, FeatureGate prüft pro Standort.
     */
    private function handleExportHl7(): void
    {
        $this->requireFileAccessAndExportPermission();
        $this->auth->addDownloadSecurityHeaders();

        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) {
            wp_die('Ungültige ID');
        }

        $submission = $this->loadAndAuthorizeSubmission($id);
        $locationId = (int) ($submission['location_id'] ?? 0);

        // Lizenzprüfung: HL7 nur mit PREMIUM+
        $gate = $this->getFeatureGate();
        if (!$gate->hasFeature('hl7_export', $locationId)) {
            wp_die('HL7-Export ist nur mit PREMIUM+ Lizenz verfügbar.');
        }

        $data = $this->decryptSubmission($submission);
        if (!$data || ($data['service_type'] ?? 'anamnese') !== 'anamnese') {
            wp_die('HL7-Export nur für Anamnesebögen verfügbar');
        }

        $container  = Container::getInstance();
        $hl7Export  = $container->get(\PraxisPortal\Export\Hl7Export::class);

        try {
            $content = $hl7Export->export($data, (object) $submission);
        } catch (\Exception $e) {
            wp_die('HL7-Export fehlgeschlagen: ' . esc_html($e->getMessage()));
        }

        $this->getAuditRepo()->log('portal_export_hl7', $id);

        $filename = 'anamnese_hl7_' . $id . '_' . date('Ymd_His') . '.hl7';
        header('Content-Type: application/hl7-v2; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    // =========================================================================
    // LOGOUT (Delegation)
    // =========================================================================

    private function handleLogout(): void
    {
        $this->auth->handleLogout();
    }

    // =========================================================================
    // RENDERING (intern)
    // =========================================================================

    /**
     * Assets enqueuen
     */
    private function enqueueAssets(): void
    {
        $cssPath = PP_PLUGIN_DIR . 'assets/css/portal.css';
        $jsPath  = PP_PLUGIN_DIR . 'assets/js/portal.js';

        $version = PP_VERSION;
        if (file_exists($jsPath)) {
            $version .= '.' . filemtime($jsPath);
        }

        wp_enqueue_style('pp-portal', PP_PLUGIN_URL . 'assets/css/portal.css', [], $version);
        wp_enqueue_script('pp-portal', PP_PLUGIN_URL . 'assets/js/portal.js', [], $version, true);

        // Standorte für JS
        $locationRepo = $this->getLocationRepo();
        $locations    = $locationRepo->findAll();
        $locationsJs  = [];
        foreach ($locations as $loc) {
            $locationsJs[] = [
                'id'            => (int) $loc['id'],
                'name'          => $loc['name'],
                'export_format' => $loc['export_format'] ?? 'gdt',
            ];
        }

        $session        = $this->auth->getSessionData();
        $userLocationId = $session['location_id'] ?? null;
        $userCanExport  = $session['can_export'] ?? true;

        // Feature-Flags
        $gate            = $this->getFeatureGate();
        $defaultLocId    = !empty($locations) ? (int) $locations[0]['id'] : 0;
        $checkLoc        = $userLocationId ?: $defaultLocId;

        $canGdt  = $gate->hasFeature('gdt_export', $checkLoc);
        $canFhir = $gate->hasFeature('fhir_export', $checkLoc);
        $canPdf  = $gate->hasFeature('pdf_export', $checkLoc);

        $exportFormat = 'gdt';
        if ($checkLoc) {
            $locData = $locationRepo->findById($checkLoc);
            $exportFormat = $locData['export_format'] ?? 'gdt';
        }

        wp_localize_script('pp-portal', 'pp_portal', [
            'ajax_url'         => admin_url('admin-ajax.php'),
            'nonce'            => $this->auth->createNonce(),
            'is_authenticated' => $this->auth->isAuthenticated(),
            'praxis_name'      => get_option('pp_praxis_name', get_bloginfo('name')),
            'locations'        => $locationsJs,
            'multi_location'   => count($locationsJs) > 1,
            'user_location_id' => $userLocationId,
            'user_can_export'  => $userCanExport,
            'can_gdt_export'   => $canGdt && $exportFormat === 'gdt',
            'can_fhir_export'  => $canFhir && $exportFormat === 'fhir',
            'can_pdf_export'   => $canPdf,
            'export_format'    => $exportFormat,
        ]);
    }

    /**
     * Portal-Template einbinden
     */
    private function renderPortalHtml(): void
    {
        $templatePath = PP_PLUGIN_DIR . 'templates/portal.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<!-- Praxis-Portal: Template nicht gefunden -->';
        }
    }

    // =========================================================================
    // HILFSMETHODEN
    // =========================================================================

    private function decryptSubmission(array $row): ?array
    {
        if (empty($row['encrypted_data'])) {
            return null;
        }

        try {
            $decrypted = $this->encryption->decrypt($row['encrypted_data']);
            if (!$decrypted) {
                return null;
            }
            $data = json_decode($decrypted, true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            error_log('PP Portal decrypt error ID ' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            return null;
        }
    }

    private function normalizeType(string $type): string
    {
        return preg_replace('/^widget_/', '', $type);
    }

    private function buildPatientName(array $data): string
    {
        // Alte Feldnamen normalisieren
        if (empty($data['nachname']) && !empty($data['name'])) {
            $data['nachname'] = $data['name'];
        }

        $name = trim(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''));
        return $name ?: 'Unbekannt';
    }

    private function matchesSearch(array $data, string $search): bool
    {
        $searchLower = mb_strtolower($search);
        $name = mb_strtolower(
            ($data['vorname'] ?? '') . ' '
            . ($data['nachname'] ?? '') . ' '
            . ($data['name'] ?? '')
        );
        return str_contains($name, $searchLower);
    }

    private function getTypeLabel(string $type): string
    {
        $labels = [
            'anamnese'            => 'Anamnese',
            'rezept'              => 'Rezept',
            'ueberweisung'        => 'Überweisung',
            'brillenverordnung'   => 'Brillenverordnung',
            'dokument'            => 'Dokument',
            'termin'              => 'Terminanfrage',
            'terminabsage'        => 'Terminabsage',
            'ersatzbescheinigung' => 'Ersatzbescheinigung',
        ];
        return $labels[$this->normalizeType($type)] ?? ucfirst($type);
    }

    /**
     * Submission-Daten für JS-Frontend normalisieren
     */
    private function normalizeSubmissionData(array $data): array
    {
        // Alte Feldnamen → Neue
        if (empty($data['nachname']) && !empty($data['name'])) {
            $data['nachname'] = $data['name'];
        }
        if (!empty($data['ueberweisungsziel'])) {
            $data['facharzt'] = $data['ueberweisungsziel'];
        }
        if (!empty($data['diagnose'])) {
            $data['grund'] = $data['diagnose'];
        }
        if (!empty($data['anmerkungen']) && empty($data['anmerkung'])) {
            $data['anmerkung'] = $data['anmerkungen'];
        }
        if (!empty($data['rezept_lieferung'])) {
            $data['lieferung'] = $data['rezept_lieferung'];
        }

        // Medikamente: Array → String
        if (!empty($data['medikamente']) && is_array($data['medikamente'])) {
            $data['medikament'] = implode(', ', $data['medikamente']);

            $artLabels = [
                'augentropfen' => 'Augentropfen',
                'augensalbe'   => 'Augensalbe',
                'tabletten'    => 'Tabletten',
                'sonstiges'    => 'Sonstiges',
            ];
            $data['medikamente_mit_art'] = [];
            foreach ($data['medikamente'] as $i => $name) {
                $artKey = $data['medikament_arten'][$i] ?? 'sonstiges';
                $data['medikamente_mit_art'][] = [
                    'name' => $name,
                    'art'  => $artLabels[$artKey] ?? 'Sonstiges',
                ];
            }
        }

        // Brille-Art
        if (!empty($data['brille_art']) && is_array($data['brille_art'])) {
            $data['brille_art_display'] = implode(', ', $data['brille_art']);
        }
        if (!empty($data['brillenart']) && is_array($data['brillenart'])) {
            $data['brillenart_display'] = implode(', ', $data['brillenart']);
            if (empty($data['brille_art'])) {
                $data['brille_art']         = $data['brillenart'];
                $data['brille_art_display'] = $data['brillenart_display'];
            }
        }

        // Termin-Felder
        if (!empty($data['termin_zeit'])) {
            $zeitLabels = ['vormittags' => 'Vormittags', 'nachmittags' => 'Nachmittags', 'egal' => 'Egal / Flexibel'];
            $data['termin_zeit_display'] = $zeitLabels[$data['termin_zeit']] ?? $data['termin_zeit'];
        }
        if (!empty($data['termin_tage']) && is_array($data['termin_tage'])) {
            $tageLabels = ['mo' => 'Montag', 'di' => 'Dienstag', 'mi' => 'Mittwoch', 'do' => 'Donnerstag', 'fr' => 'Freitag', 'sa' => 'Samstag', 'egal' => 'Egal'];
            $tageDisplay = [];
            foreach ($data['termin_tage'] as $tag) {
                $tageDisplay[] = $tageLabels[$tag] ?? $tag;
            }
            $data['termin_tage_display'] = implode(', ', $tageDisplay);
        }
        if (isset($data['termin_schnellstmoeglich'])) {
            $data['termin_schnellstmoeglich_display'] = ($data['termin_schnellstmoeglich'] === '1' || $data['termin_schnellstmoeglich'] === true) ? 'Ja' : 'Nein';
        }

        // Terminabsage
        if (!empty($data['absage_datum'])) {
            $data['termin_datum'] = $data['absage_datum'];
        }
        if (!empty($data['absage_uhrzeit'])) {
            $data['termin_uhrzeit'] = $data['absage_uhrzeit'];
        }

        // Strukturierte Medikamente
        if (!empty($data['medikamente_strukturiert'])) {
            $medsArray = json_decode($data['medikamente_strukturiert'], true);
            if (is_array($medsArray)) {
                $data['medikamente_strukturiert_parsed'] = array_map(fn($med) => [
                    'name'      => sanitize_text_field($med['name'] ?? ''),
                    'staerke'   => sanitize_text_field($med['staerke'] ?? ''),
                    'wirkstoff' => sanitize_text_field($med['wirkstoff'] ?? ''),
                    'dosierung' => sanitize_text_field($med['dosierung'] ?? ''),
                    'hinweis'   => sanitize_text_field($med['hinweis'] ?? ''),
                    'source'    => ($med['source'] ?? 'manual') === 'database' ? 'database' : 'manual',
                ], $medsArray);
            }
        }

        return $data;
    }

    private function buildFileList(array $files): array
    {
        $list = [];
        foreach ($files as $file) {
            try {
                $originalName = $this->encryption->decrypt($file['original_name_encrypted']);
            } catch (\Exception $e) {
                $originalName = 'Unbekannt';
            }
            $list[] = [
                'id'            => $file['file_id'],
                'original_name' => $originalName ?: 'Unbekannt',
                'file_type'     => $file['mime_type'],
                'file_size'     => (int) $file['file_size'],
            ];
        }
        return $list;
    }

    private function resolveDownloadName(string $originalName, string $mimeType, int $submissionId): string
    {
        // Anamnese-Dateien als "medplan.*" für PVS
        $repo = $this->getSubmissionRepo();
        $sub  = $repo->findById($submissionId);

        if ($sub && ($sub['request_type'] ?? '') === 'anamnese') {
            $extMap = [
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'image/gif'       => 'gif',
                'image/webp'      => 'webp',
                'application/pdf' => 'pdf',
            ];
            $ext = $extMap[$mimeType] ?? (pathinfo($originalName, PATHINFO_EXTENSION) ?: 'dat');
            return 'medplan.' . $ext;
        }

        return $originalName;
    }

    /**
     * Typ-Counts für alle Submissions berechnen
     */
    private function getTypeCounts(?int $locationId = null): array
    {
        // Typ-basierte Zählung ist aktuell nicht in SubmissionRepository implementiert.
        // Gibt leere Counts zurück, da service_type verschlüsselt ist und nicht per SQL gezählt werden kann.
        return [];
    }

    // =========================================================================
    // E-MAIL TEMPLATES
    // =========================================================================

    private function buildResponseSubject(string $responseType, string $serviceType): string
    {
        $praxisName = get_option('pp_praxis_name', get_bloginfo('name'));

        $subjects = [
            'ready'          => 'Ihre Anfrage ist bereit zur Abholung',
            'sent'           => 'Ihre Anfrage wurde bearbeitet',
            'need_info'      => 'Rückfrage zu Ihrer Anfrage',
            'rejected'       => 'Zu Ihrer Anfrage',
            'appointment'    => 'Terminvereinbarung erforderlich',
            'insurance_card' => 'Bitte Versichertenkarte einreichen',
        ];

        return $praxisName . ': ' . ($subjects[$responseType] ?? 'Zu Ihrer Anfrage');
    }

    private function buildResponseMessage(string $responseType, string $serviceType, string $vorname, string $customText): string
    {
        $praxisName = get_option('pp_praxis_name', get_bloginfo('name'));
        $praxisTel  = get_option('pp_praxis_telefon', '');
        $typeLabel  = $this->getTypeLabel($serviceType);

        $messages = [
            'ready' => "<p>Sehr geehrte/r {$vorname},</p><p>Ihre Anfrage ({$typeLabel}) wurde bearbeitet und liegt zur Abholung bereit.</p><p>Bitte bringen Sie Ihre Versichertenkarte mit.</p>",
            'sent' => "<p>Sehr geehrte/r {$vorname},</p><p>Ihre Anfrage ({$typeLabel}) wurde bearbeitet und per Post an Sie versandt.</p>",
            'need_info' => "<p>Sehr geehrte/r {$vorname},</p><p>Zu Ihrer Anfrage ({$typeLabel}) benötigen wir noch weitere Informationen.</p><p><strong>Unsere Rückfrage:</strong></p><p>{$customText}</p><p>Bitte kontaktieren Sie uns unter {$praxisTel}.</p>",
            'rejected' => "<p>Sehr geehrte/r {$vorname},</p><p>Leider können wir Ihrer Anfrage ({$typeLabel}) nicht entsprechen.</p><p><strong>Begründung:</strong></p><p>{$customText}</p><p>Bei Fragen: {$praxisTel}.</p>",
            'appointment' => "<p>Sehr geehrte/r {$vorname},</p><p>Für Ihre Anfrage ({$typeLabel}) ist ein persönlicher Termin erforderlich.</p><p>{$customText}</p><p>Bitte Termin vereinbaren: {$praxisTel}.</p>",
            'insurance_card' => "<p>Sehr geehrte/r {$vorname},</p><p>Zur Bearbeitung Ihrer Anfrage ({$typeLabel}) benötigen wir Ihre Versichertenkarte.</p><p>Bitte reichen Sie diese in unserer Praxis ein.</p><p>Bei Fragen: {$praxisTel}.</p>",
        ];

        $message = $messages[$responseType] ?? "<p>Sehr geehrte/r {$vorname},</p><p>{$customText}</p>";

        return "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>body{font-family:Arial,sans-serif;line-height:1.6;color:#333}.container{max-width:600px;margin:0 auto;padding:20px}.header{background:#1a365d;color:#fff;padding:20px;text-align:center}.content{padding:20px;background:#f9f9f9}.footer{padding:15px;font-size:12px;color:#666;text-align:center}</style></head><body><div class='container'><div class='header'><h2>{$praxisName}</h2></div><div class='content'>{$message}</div><div class='footer'><p>Mit freundlichen Grüßen<br>{$praxisName}</p></div></div></body></html>";
    }

    private function getStatusForResponse(string $responseType): string
    {
        $map = [
            'ready'          => 'ready_pickup',
            'sent'           => 'sent',
            'need_info'      => 'waiting_info',
            'rejected'       => 'rejected',
            'appointment'    => 'appointment_needed',
            'insurance_card' => 'waiting_insurance',
        ];
        return $map[$responseType] ?? 'processed';
    }

    // =========================================================================
    // SICHERHEITS-CHECKS
    // =========================================================================

    private function requireEncryption(): void
    {
        // Prüfe ob Encryption funktioniert
        try {
            $test = $this->encryption->encrypt('test');
            if (empty($test)) {
                throw new \RuntimeException('Encryption returned empty');
            }
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Verschlüsselung nicht verfügbar. Bitte System-Status prüfen.',
                'code'    => 'encryption_error',
            ]);
        }
    }

    private function requireLocationAccess(int $locationId): void
    {
        if (!$this->auth->canAccessLocation($locationId)) {
            wp_send_json_error(['message' => 'Keine Berechtigung für diesen Standort.']);
        }
    }

    private function requireLocationAccessForSubmission(int $submissionId): void
    {
        $session = $this->auth->getSessionData();
        $userLoc = $session['location_id'] ?? null;
        if ($userLoc !== null && $userLoc > 0) {
            $sub = $this->getSubmissionRepo()->findById($submissionId);
            if ($sub && (int) $sub['location_id'] !== $userLoc) {
                wp_send_json_error(['message' => 'Keine Berechtigung für diesen Standort.']);
            }
        }
    }

    private function requireFileAccessAndExportPermission(): void
    {
        if (!$this->auth->isFileAccessAuthorized()) {
            wp_die('Nicht autorisiert – bitte erneut anmelden');
        }

        $session = $this->auth->getSessionFromFileToken();
        if ($session && empty($session['can_export'])) {
            wp_die('Sie haben keine Export-Berechtigung.');
        }
    }

    private function loadAndAuthorizeSubmission(int $id): array
    {
        $repo       = $this->getSubmissionRepo();
        $submission = $repo->findById($id);

        if (!$submission) {
            wp_die('Eintrag nicht gefunden');
        }

        // Location-Check
        $session = $this->auth->getSessionFromFileToken();
        if ($session && !empty($session['location_id'])) {
            if ((int) $session['location_id'] !== (int) ($submission['location_id'] ?? 0)) {
                wp_die('Keine Berechtigung für diesen Standort.');
            }
        }

        return $submission;
    }

    // =========================================================================
    // REPOSITORY-ZUGRIFF
    // =========================================================================

    private function getSubmissionRepo(): SubmissionRepository
    {
        return Container::getInstance()->get(SubmissionRepository::class);
    }

    private function getFileRepo(): FileRepository
    {
        return Container::getInstance()->get(FileRepository::class);
    }

    private function getAuditRepo(): AuditRepository
    {
        return Container::getInstance()->get(AuditRepository::class);
    }

    private function getLocationRepo(): LocationRepository
    {
        return Container::getInstance()->get(LocationRepository::class);
    }

    private function getFeatureGate(): FeatureGate
    {
        return Container::getInstance()->get(FeatureGate::class);
    }

    private function getUploadDir(): string
    {
        return defined('PP_UPLOAD_DIR') ? PP_UPLOAD_DIR : (WP_CONTENT_DIR . '/uploads/praxis-portal/');
    }

    /**
     * PortalAuth on-demand erstellen (falls nicht im Container)
     */
    private function createAuth(Container $container): PortalAuth
    {
        return new PortalAuth(
            $this->encryption,
            $container->get(\PraxisPortal\Security\RateLimiter::class),
            $container->get(\PraxisPortal\Database\Repository\PortalUserRepository::class),
            $container->get(\PraxisPortal\Database\Repository\AuditRepository::class)
        );
    }
}
