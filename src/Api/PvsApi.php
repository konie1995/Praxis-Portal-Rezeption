<?php
declare(strict_types=1);
/**
 * PVS-API – REST-Endpunkte für Praxisverwaltungssysteme
 *
 * Stellt REST-Endpunkte bereit, über die ein PVS (z.B. ALBIS, Medistar,
 * Turbomed) Einreichungen abrufen, Exporte generieren und Status-Updates
 * durchführen kann.
 *
 * Authentifizierung: API-Key (Header X-API-Key oder Query-Parameter)
 * Autorisierung:     Lizenz- und Feature-Prüfung pro Standort
 *
 * Endpunkte (registriert in Hooks.php):
 *  GET  /submissions          → Alle pendenden Submissions
 *  GET  /submissions/{id}     → Einzelne Submission
 *  POST /submissions/{id}/status → Status ändern
 *  GET  /submissions/{id}/gdt → GDT/BDT-Export
 *  GET  /submissions/{id}/fhir → FHIR-Bundle
 *  GET  /submissions/{id}/pdf → PDF-Export
 *  GET  /submissions/{id}/files → Datei-Liste + Download
 *  GET  /widget/services      → Services für Widget
 *  GET  /status               → Health-Check
 *
 * Multi-Standort:
 *  - API-Key ist an Standort gebunden
 *  - Nur Submissions des eigenen Standorts sichtbar
 *
 * @package PraxisPortal\Api
 * @since   4.0.0
 */

namespace PraxisPortal\Api;

use PraxisPortal\Security\Encryption;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Database\Repository\ServiceRepository;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\Core\Container;

if (!defined('ABSPATH')) {
    exit;
}

class PvsApi
{
    private Encryption $encryption;

    public function __construct(Encryption $encryption)
    {
        $this->encryption = $encryption;
    }

    // =========================================================================
    // SUBMISSIONS
    // =========================================================================

    /**
     * GET /submissions — Alle Submissions abrufen (paginiert)
     *
     * Query-Parameter:
     *  - status: pending|read|all (default: pending)
     *  - type:   anamnese|rezept|... (default: all)
     *  - page:   int (default: 1)
     *  - per_page: int (default: 50, max: 100)
     *  - since:  ISO-DateTime — nur Submissions nach diesem Zeitpunkt
     */
    public function getSubmissions(\WP_REST_Request $request): \WP_REST_Response
    {
        $apiKeyData = $request->get_param('_pp_api_key_data');
        $locationId = $apiKeyData['location_id'] ?? null;

        $status  = sanitize_text_field($request->get_param('status') ?? 'pending');
        $type    = sanitize_text_field($request->get_param('type') ?? 'all');
        $page    = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?? 50)));
        $since   = $request->get_param('since');

        $repo = $this->getSubmissionRepo();

        // Submissions laden
        $rows  = $repo->findForApi($type, $status, $locationId, $page, $perPage, $since);
        $total = $repo->countForApi($type, $status, $locationId, $since);

        $submissions = [];
        foreach ($rows as $row) {
            $data = $this->decryptSubmission($row);
            if (!$data) {
                continue;
            }

            $serviceType = $this->normalizeType($data['service_type'] ?? 'anamnese');

            $submissions[] = [
                'id'           => (int) $row['id'],
                'reference_id' => $this->buildReferenceId($row),
                'type'         => $serviceType,
                'patient'      => [
                    'vorname'      => $data['vorname'] ?? '',
                    'nachname'     => $data['nachname'] ?? $data['name'] ?? '',
                    'geburtsdatum' => $data['geburtsdatum'] ?? '',
                    'email'        => $data['email'] ?? '',
                    'telefon'      => $data['telefon'] ?? '',
                ],
                'status'       => $row['status'],
                'location_id'  => (int) ($row['location_id'] ?? 0),
                'created_at'   => $row['created_at'],
                'updated_at'   => $row['updated_at'] ?? $row['created_at'],
                'has_files'    => ((int) ($row['file_count'] ?? 0)) > 0,
            ];
        }

        $this->getAuditRepo()->log('api_get_submissions', null, [
            'count' => count($submissions),
            'page'  => $page,
        ]);

        return new \WP_REST_Response([
            'submissions' => $submissions,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'pages'       => (int) ceil($total / $perPage),
        ], 200);
    }

    /**
     * GET /submissions/{id} — Einzelne Submission mit allen Daten
     */
    public function getSubmission(\WP_REST_Request $request): \WP_REST_Response
    {
        $id   = (int) $request->get_param('id');
        $repo = $this->getSubmissionRepo();

        $submission = $repo->findById($id);
        if (!$submission) {
            return new \WP_REST_Response(['error' => 'Submission nicht gefunden'], 404);
        }

        // Standort-Check
        if (!$this->checkLocationAccess($request, $submission)) {
            return new \WP_REST_Response(['error' => 'Kein Zugriff auf diesen Standort'], 403);
        }

        $data = $this->decryptSubmission($submission);
        if (!$data) {
            return new \WP_REST_Response(['error' => 'Entschlüsselung fehlgeschlagen'], 500);
        }

        // Dateien laden
        $fileRepo = $this->getFileRepo();
        $files    = $fileRepo->findBySubmissionId($id);
        $fileList = [];
        foreach ($files as $file) {
            try {
                $originalName = $this->encryption->decrypt($file['original_name_encrypted']);
            } catch (\Exception $e) {
                $originalName = 'unknown';
            }
            $fileList[] = [
                'file_id'       => $file['file_id'],
                'original_name' => $originalName ?: 'unknown',
                'mime_type'     => $file['mime_type'],
                'file_size'     => (int) $file['file_size'],
            ];
        }

        // Signatur
        $signature = null;
        if (!empty($submission['signature_data'])) {
            try {
                $signature = $this->encryption->decrypt($submission['signature_data']);
            } catch (\Exception $e) {
                // Signatur nicht kritisch
            }
        }

        $this->getAuditRepo()->log('api_get_submission', $id);

        return new \WP_REST_Response([
            'id'           => $id,
            'reference_id' => $this->buildReferenceId($submission),
            'type'         => $this->normalizeType($data['service_type'] ?? 'anamnese'),
            'data'         => $data,
            'files'        => $fileList,
            'signature'    => $signature,
            'status'       => $submission['status'],
            'location_id'  => (int) ($submission['location_id'] ?? 0),
            'created_at'   => $submission['created_at'],
        ], 200);
    }

    /**
     * POST /submissions/{id}/status — Status aktualisieren
     *
     * Body: { "status": "exported_to_pvs" }
     */
    public function updateStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        $id        = (int) $request->get_param('id');
        $newStatus = sanitize_text_field($request->get_param('status') ?? '');

        $allowed = [
            'pending', 'read', 'exported_to_pvs', 'pdf_downloaded',
            'responded', 'ready_pickup', 'sent', 'processed',
        ];
        if (!in_array($newStatus, $allowed, true)) {
            return new \WP_REST_Response(['error' => 'Ungültiger Status', 'allowed' => $allowed], 400);
        }

        $repo       = $this->getSubmissionRepo();
        $submission = $repo->findById($id);
        if (!$submission) {
            return new \WP_REST_Response(['error' => 'Submission nicht gefunden'], 404);
        }

        if (!$this->checkLocationAccess($request, $submission)) {
            return new \WP_REST_Response(['error' => 'Kein Zugriff'], 403);
        }

        $repo->updateStatus($id, $newStatus);
        $this->getAuditRepo()->log('api_update_status', $id, ['status' => $newStatus]);

        return new \WP_REST_Response([
            'id'     => $id,
            'status' => $newStatus,
        ], 200);
    }

    // =========================================================================
    // EXPORT-ENDPUNKTE
    // =========================================================================

    /**
     * GET /submissions/{id}/gdt — GDT/BDT-Export
     */
    public function getGdt(\WP_REST_Request $request): \WP_REST_Response
    {
        $id         = (int) $request->get_param('id');
        $submission = $this->loadAndAuthorize($request, $id);

        if ($submission instanceof \WP_REST_Response) {
            return $submission;
        }

        $locationId = (int) ($submission['location_id'] ?? 0);
        $gate       = $this->getFeatureGate();

        if (!$gate->hasFeature('gdt_export', $locationId)) {
            return new \WP_REST_Response(['error' => 'GDT-Export nicht lizenziert'], 403);
        }

        $data = $this->decryptSubmission($submission);
        if (!$data) {
            return new \WP_REST_Response(['error' => 'Entschlüsselung fehlgeschlagen'], 500);
        }

        $container = Container::getInstance();
        $gdtExport = $container->get(\PraxisPortal\Export\GdtExport::class);
        $content   = $gdtExport->export($data, (object) $submission);

        if (empty($content)) {
            return new \WP_REST_Response(['error' => 'GDT-Export fehlgeschlagen'], 500);
        }

        $this->getAuditRepo()->log('api_export_gdt', $id);

        // Status automatisch auf exported_to_pvs setzen
        $this->getSubmissionRepo()->updateStatus($id, 'exported_to_pvs');

        return new \WP_REST_Response([
            'id'       => $id,
            'format'   => 'gdt',
            'encoding' => 'ISO-8859-15',
            'content'  => base64_encode($content),
            'filename' => 'Anamnese.bdt',
        ], 200);
    }

    /**
     * GET /submissions/{id}/fhir — FHIR R4 Bundle
     */
    public function getFhir(\WP_REST_Request $request): \WP_REST_Response
    {
        $id         = (int) $request->get_param('id');
        $submission = $this->loadAndAuthorize($request, $id);

        if ($submission instanceof \WP_REST_Response) {
            return $submission;
        }

        $locationId = (int) ($submission['location_id'] ?? 0);
        $gate       = $this->getFeatureGate();

        if (!$gate->hasFeature('fhir_export', $locationId)) {
            return new \WP_REST_Response(['error' => 'FHIR-Export nicht lizenziert'], 403);
        }

        $data = $this->decryptSubmission($submission);
        if (!$data) {
            return new \WP_REST_Response(['error' => 'Entschlüsselung fehlgeschlagen'], 500);
        }

        $container  = Container::getInstance();
        $fhirExport = $container->get(\PraxisPortal\Export\FhirExport::class);

        try {
            $bundle = $fhirExport->export($data, (object) $submission);
        } catch (\Exception $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 500);
        }

        $this->getAuditRepo()->log('api_export_fhir', $id);

        // JSON zurückgeben (nicht Base64, da FHIR nativ JSON ist)
        $result = is_string($bundle) ? json_decode($bundle, true) : $bundle;

        return new \WP_REST_Response($result, 200);
    }

    /**
     * GET /submissions/{id}/pdf — PDF-Export (Base64)
     */
    public function getPdf(\WP_REST_Request $request): \WP_REST_Response
    {
        $id         = (int) $request->get_param('id');
        $submission = $this->loadAndAuthorize($request, $id);

        if ($submission instanceof \WP_REST_Response) {
            return $submission;
        }

        $locationId = (int) ($submission['location_id'] ?? 0);
        $gate       = $this->getFeatureGate();

        if (!$gate->hasFeature('pdf_export', $locationId)) {
            return new \WP_REST_Response(['error' => 'PDF-Export nicht lizenziert'], 403);
        }

        // PDF generieren (als String, nicht direkt senden)
        $container = Container::getInstance();
        $pdfExport = $container->get(\PraxisPortal\Export\Pdf\PdfAnamnese::class);

        try {
            $pdfContent = $pdfExport->generateToString($id);
        } catch (\Exception $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 500);
        }

        $this->getAuditRepo()->log('api_export_pdf', $id);

        // Status aktualisieren
        $this->getSubmissionRepo()->updateStatus($id, 'pdf_downloaded');

        return new \WP_REST_Response([
            'id'       => $id,
            'format'   => 'pdf',
            'content'  => base64_encode($pdfContent),
            'filename' => 'Anamnese_' . $id . '.pdf',
        ], 200);
    }

    /**
     * GET /submissions/{id}/files — Datei-Downloads
     *
     * Query: ?file_id=xxx — Einzelne Datei (Base64)
     *        ohne file_id — Liste aller Dateien
     */
    public function getFiles(\WP_REST_Request $request): \WP_REST_Response
    {
        $id         = (int) $request->get_param('id');
        $submission = $this->loadAndAuthorize($request, $id);

        if ($submission instanceof \WP_REST_Response) {
            return $submission;
        }

        $fileRepo = $this->getFileRepo();
        $files    = $fileRepo->findBySubmissionId($id);

        // Einzelne Datei?
        $fileId = sanitize_text_field($request->get_param('file_id') ?? '');
        if (!empty($fileId)) {
            return $this->downloadSingleFile($fileId, $id);
        }

        // Datei-Liste
        $fileList = [];
        foreach ($files as $file) {
            try {
                $originalName = $this->encryption->decrypt($file['original_name_encrypted']);
            } catch (\Exception $e) {
                $originalName = 'unknown';
            }
            $fileList[] = [
                'file_id'       => $file['file_id'],
                'original_name' => $originalName ?: 'unknown',
                'mime_type'     => $file['mime_type'],
                'file_size'     => (int) $file['file_size'],
                'download_url'  => rest_url("praxis-portal/v1/submissions/{$id}/files?file_id=" . $file['file_id']),
            ];
        }

        $this->getAuditRepo()->log('api_get_files', $id, ['count' => count($fileList)]);

        return new \WP_REST_Response([
            'submission_id' => $id,
            'files'         => $fileList,
        ], 200);
    }

    // =========================================================================
    // SERVICES / STATUS
    // =========================================================================

    /**
     * GET /widget/services — Aktive Services für Widget
     */
    public function getServices(\WP_REST_Request $request): \WP_REST_Response
    {
        $locationId = (int) ($request->get_param('location_id') ?? 0);

        $serviceRepo = $this->getServiceRepo();
        $services    = $serviceRepo->findActiveForLocation($locationId);

        return new \WP_REST_Response([
            'services' => $services,
        ], 200);
    }

    /**
     * GET /status — Health-Check
     */
    public function getStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        $apiKeyData = $request->get_param('_pp_api_key_data');

        // Basis-Info
        $status = [
            'status'     => 'ok',
            'version'    => defined('PP_VERSION') ? PP_VERSION : 'unknown',
            'php'        => phpversion(),
            'wordpress'  => get_bloginfo('version'),
            'encryption' => 'active',
            'timestamp'  => current_time('c'),
        ];

        // Encryption-Check
        try {
            $testResult = $this->encryption->encrypt('health_check');
            if (empty($testResult)) {
                $status['encryption'] = 'error';
                $status['status']     = 'degraded';
            }
        } catch (\Exception $e) {
            $status['encryption'] = 'error';
            $status['status']     = 'degraded';
        }

        // Submission-Count
        $repo     = $this->getSubmissionRepo();
        $locationId = $apiKeyData['location_id'] ?? null;
        $status['pending_count'] = $repo->countForApi('all', 'pending', $locationId);

        return new \WP_REST_Response($status, 200);
    }

    // =========================================================================
    // STATISCHE HILFSMETHODEN
    // =========================================================================

    /**
     * API-Key generieren (für Admin-Panel)
     */
    public static function generateApiKey(): string
    {
        return 'pp_' . wp_generate_password(40, false);
    }

    // =========================================================================
    // INTERNE METHODEN
    // =========================================================================

    /**
     * Submission laden und Standort-Zugriff prüfen
     *
     * @return array|\WP_REST_Response Submission oder Error-Response
     */
    private function loadAndAuthorize(\WP_REST_Request $request, int $id)
    {
        $repo       = $this->getSubmissionRepo();
        $submission = $repo->findById($id);

        if (!$submission) {
            return new \WP_REST_Response(['error' => 'Submission nicht gefunden'], 404);
        }

        if (!$this->checkLocationAccess($request, $submission)) {
            return new \WP_REST_Response(['error' => 'Kein Zugriff auf diesen Standort'], 403);
        }

        return $submission;
    }

    /**
     * Standort-Zugriff prüfen (API-Key-Location vs. Submission-Location)
     */
    private function checkLocationAccess(\WP_REST_Request $request, array $submission): bool
    {
        $apiKeyData   = $request->get_param('_pp_api_key_data');
        $keyLocationId = $apiKeyData['location_id'] ?? null;

        // Key ohne Location-Binding → Zugriff auf alles
        if ($keyLocationId === null || $keyLocationId === 0) {
            return true;
        }

        $subLocationId = (int) ($submission['location_id'] ?? 0);
        return (int) $keyLocationId === $subLocationId;
    }

    /**
     * Einzelne Datei als Base64 zurückgeben
     */
    private function downloadSingleFile(string $fileId, int $submissionId): \WP_REST_Response
    {
        $fileRepo = $this->getFileRepo();

        if (!$fileRepo->isValidFileId($fileId)) {
            return new \WP_REST_Response(['error' => 'Ungültige Datei-ID'], 400);
        }

        $file = $fileRepo->findByFileId($fileId);
        if (!$file || (int) $file['submission_id'] !== $submissionId) {
            return new \WP_REST_Response(['error' => 'Datei nicht gefunden'], 404);
        }

        $uploadDir = defined('PP_UPLOAD_DIR') ? PP_UPLOAD_DIR : (WP_CONTENT_DIR . '/uploads/praxis-portal/');
        $filePath  = $uploadDir . $fileId;

        if (!file_exists($filePath)) {
            return new \WP_REST_Response(['error' => 'Datei nicht auf Disk'], 404);
        }

        try {
            $encrypted = file_get_contents($filePath);
            $content   = $this->encryption->decrypt($encrypted);
            if (!$content) {
                return new \WP_REST_Response(['error' => 'Entschlüsselung fehlgeschlagen'], 500);
            }

            $originalName = $this->encryption->decrypt($file['original_name_encrypted']) ?: 'download';
        } catch (\Exception $e) {
            return new \WP_REST_Response(['error' => 'Entschlüsselung fehlgeschlagen'], 500);
        }

        $this->getAuditRepo()->log('api_download_file', $submissionId, [
            'file_id' => $fileId,
        ]);

        return new \WP_REST_Response([
            'file_id'       => $fileId,
            'original_name' => $originalName,
            'mime_type'     => $file['mime_type'],
            'file_size'     => (int) $file['file_size'],
            'content'       => base64_encode($content),
        ], 200);
    }

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
            error_log('PP API decrypt error ID ' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            return null;
        }
    }

    private function normalizeType(string $type): string
    {
        return preg_replace('/^widget_/', '', $type);
    }

    private function buildReferenceId(array $row): string
    {
        return !empty($row['submission_hash'])
            ? strtoupper(substr($row['submission_hash'], 0, 8))
            : '#' . $row['id'];
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

    private function getServiceRepo(): ServiceRepository
    {
        return Container::getInstance()->get(ServiceRepository::class);
    }

    private function getFeatureGate(): FeatureGate
    {
        return Container::getInstance()->get(FeatureGate::class);
    }
}
