<?php
/**
 * PDF Export Basis-Klasse
 *
 * Gemeinsame Funktionalität für alle PDF-Exporte:
 *  - Zugriffskontrollen (Admin, Portal-Session, File-Token)
 *  - TCPDF-Integration mit biometrischer Einbettung
 *  - Verschlüsselung via DI
 *  - Multi-Standort Praxis-Informationen
 *
 * @package    PraxisPortal\Export\Pdf
 * @since      4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Export\Pdf;

use PraxisPortal\Core\Container;
use PraxisPortal\Export\ExportBase;

abstract class PdfBase extends ExportBase
{
    /* ─── Constants ─────────────────────────────────────────── */

    /** Plugin-Version (für PDF-Footer) */
    protected const VERSION = '4.2.908';

    /* ─── Properties ────────────────────────────────────────── */

    /** @var bool Zugriffsprüfung überspringen (für interne Aufrufe) */
    protected bool $skipAccessCheck = false;

    /** Repositories (aus Container) */
    protected object $submissionRepo;
    protected object $fileRepo;

    /* ─── Constructor ───────────────────────────────────────── */

    public function __construct(Container $container, string $language = 'de')
    {
        parent::__construct($container, $language);

        $this->submissionRepo = $container->get('submission_repository');
        $this->fileRepo       = $container->get('file_repository');
    }

    /* ─── Access Control ────────────────────────────────────── */

    /**
     * Prüft ob der aktuelle Benutzer PDF-Export-Berechtigung hat.
     *
     * Berechtigt:
     *  1. WordPress-Administratoren
     *  2. Portal-Benutzer mit gültiger Session
     *  3. Requests mit gültigem File-Token
     */
    protected function isAuthorized(): bool
    {
        if ($this->skipAccessCheck) {
            return true;
        }

        // 1) WP-Admin
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }

        // 2) Portal-Session (Cookie)
        $sessionKey = 'pp_portal_session';
        if (!empty($_COOKIE[$sessionKey])) {
            $token = sanitize_text_field($_COOKIE[$sessionKey]);
            $data  = get_transient('pp_portal_session_' . $token);
            if ($data) {
                return true;
            }
        }

        // 3) File-Token (GET/POST)
        $fileToken = sanitize_text_field($_GET['token'] ?? $_POST['token'] ?? '');
        if ($fileToken !== '') {
            $tokenData = get_transient('pp_file_token_' . $fileToken);
            if ($tokenData && !empty($tokenData['valid'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Setzt internen Zugriff (überspringt Autorisierung).
     */
    public function setInternalAccess(bool $internal = true): void
    {
        $this->skipAccessCheck = $internal;
    }

    /* ─── Data Loading ──────────────────────────────────────── */

    /**
     * Lädt + entschlüsselt Submission-Daten.
     */
    protected function loadSubmissionData(int $submissionId): ?array
    {
        $submission = $this->submissionRepo->findById($submissionId);
        if (!$submission) {
            return null;
        }

        if (empty($submission['encrypted_data'])) {
            return null;
        }

        return $this->decryptData($submission['encrypted_data']);
    }

    /**
     * Lädt die Unterschrift für eine Submission.
     *
     * Versucht zuerst die Datei aus dem FileRepository,
     * dann Fallback auf submission.signature_data.
     */
    protected function loadSignature(int $submissionId, ?array $submission = null): ?string
    {
        // 1) Aus FileRepository (verschlüsselte Datei)
        $files = $this->fileRepo->findBySubmission($submissionId);

        foreach ($files as $file) {
            // original_name_encrypted entschlüsseln und prüfen
            $originalName = '';
            if (!empty($file['original_name_encrypted'])) {
                try {
                    $originalName = $this->encryption->decrypt($file['original_name_encrypted']) ?: '';
                } catch (\Exception $e) {
                    continue;
                }
            }

            if ($originalName && str_contains($originalName, '.signature')) {
                // Datei über FileRepository entschlüsselt laden
                $fileId = $file['file_id'] ?? '';
                if (!empty($fileId)) {
                    $decrypted = $this->fileRepo->getDecryptedFile($fileId);
                    if ($decrypted && !empty($decrypted['content'])) {
                        return 'data:image/png;base64,' . base64_encode($decrypted['content']);
                    }
                }
            }
        }

        // 2) Fallback: signature_data auf Submission
        if ($submission && !empty($submission['signature_data'])) {
            try {
                $decrypted = $this->encryption->decrypt($submission['signature_data']);
                if ($decrypted && str_starts_with($decrypted, 'data:image')) {
                    return $decrypted;
                }
            } catch (\Exception $e) {
                // Ignorieren
            }
        }

        return null;
    }

    /**
     * Extrahiert biometrische Daten aus Formulardaten.
     *
     * Format: ISO 19794-7 kompatibles JSON mit Timestamps,
     * Druckwerten und Stift-Bewegungsdaten.
     */
    public function extractBiometricData(array $formData): ?array
    {
        if (empty($formData['unterschrift_biometric'])) {
            return null;
        }

        $data = $formData['unterschrift_biometric'];
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return is_array($data) ? $data : null;
    }

    /* ─── Praxis Info ───────────────────────────────────────── */

    /**
     * Lädt Praxis-Informationen (standortabhängig).
     */
    protected function getPraxisInfo(?string $locationUuid = ''): array
    {
        // UUID aus Property als Fallback
        $locationUuid = $locationUuid ?? '';
        $uuid = $locationUuid !== '' ? $locationUuid : $this->locationUuid;

        // Standortspezifische Settings via LocationManager
        $locationMgr = $this->container->get('location_manager');
        $settings    = $locationMgr->getLocationSettings($uuid);

        $address = $settings['practice_address'] ?? get_option('pp_praxis_anschrift', '');

        return [
            'name'      => $settings['practice_name']    ?? get_option('pp_praxis_name', get_bloginfo('name')),
            'anschrift' => $address,
            'address'   => $address, // Alias für PdfAnamnese
            'telefon'   => $settings['practice_phone']    ?? get_option('pp_praxis_telefon', ''),
            'email'     => $settings['practice_email']    ?? get_option('pp_praxis_email', ''),
            'logo_url'  => $settings['practice_logo_url'] ?? get_option('pp_praxis_logo', ''),
        ];
    }

    /**
     * Praxis-Informationen per Location-ID laden (Multistandort).
     *
     * Löst die UUID auf und delegiert an getPraxisInfo().
     */
    protected function getPraxisInfoByLocationId(int $locationId): array
    {
        if ($locationId < 1) {
            return $this->getPraxisInfo('');
        }

        $locationMgr = $this->container->get('location_manager');
        $location    = $locationMgr->getById($locationId);

        if ($location && !empty($location['uuid'])) {
            return $this->getPraxisInfo($location['uuid']);
        }

        return $this->getPraxisInfo('');
    }

    /* ─── TCPDF Generation ──────────────────────────────────── */

    /**
     * Generiert ein PDF mit TCPDF + optionaler biometrischer Einbettung.
     *
     * @param string     $html           HTML-Inhalt für das PDF
     * @param array|null $biometricData  Biometrische Signaturdaten (optional)
     * @param string     $filename       Dateiname (für Metadaten)
     * @return string|false              PDF als Binary-String oder false
     */
    public function generatePdfWithBiometrics(
        string $html,
        ?array $biometricData = null,
        string $filename = 'dokument.pdf'
    ): string|false {
        if (!class_exists('TCPDF')) {
            // Fallback: HTML zurückgeben wenn TCPDF nicht verfügbar
            return $html;
        }

        try {
            $praxis = $this->getPraxisInfo();

            $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');

            $pdf->SetCreator('Praxis-Portal v4');
            $pdf->SetAuthor($praxis['name']);
            $pdf->SetTitle($filename);
            $pdf->SetSubject('Medizinisches Dokument');

            $pdf->SetAutoPageBreak(true, 15);
            $pdf->SetMargins(15, 15, 15);
            $pdf->AddPage();

            $pdf->writeHTML($html, true, false, true, false, '');

            // Biometrische Daten als unsichtbaren JSON-Anhang einbetten
            if (!empty($biometricData)) {
                $jsonContent = json_encode($biometricData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $this->embedJsonAttachment($pdf, $jsonContent, 'signature_biometric.json');
            }

            return $pdf->Output('', 'S');

        } catch (\Exception $e) {
            if ($this->container->has('logger')) {
                $this->container->get('logger')->error('PDF-Generierung fehlgeschlagen', [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ]);
            }
            return false;
        }
    }

    /**
     * Bettet JSON-Datei unsichtbar in TCPDF ein.
     *
     * Nutzt Reflection um TCPDF-Embedded-Files zu setzen,
     * Fallback auf Annotation.
     */
    protected function embedJsonAttachment(\TCPDF $pdf, string $jsonContent, string $filename): void
    {
        $reflection = new \ReflectionClass($pdf);

        if ($reflection->hasProperty('embeddedfiles')) {
            $prop = $reflection->getProperty('embeddedfiles');
            $prop->setAccessible(true);
            $embeddedFiles = $prop->getValue($pdf);

            $tempFile = wp_tempnam($filename);
            file_put_contents($tempFile, $jsonContent);

            $embeddedFiles[$filename] = [
                'f'    => 0,
                'n'    => 0,
                'file' => $tempFile,
            ];

            $prop->setValue($pdf, $embeddedFiles);

            // Cleanup bei Script-Ende
            register_shutdown_function(function () use ($tempFile) {
                if (file_exists($tempFile)) {
                    if (file_exists($tempFile)) { unlink($tempFile); }
                }
            });
        } else {
            // Fallback: Annotation
            $tempPath = $this->createTempJsonFile($jsonContent, $filename);
            $pdf->Annotation(-100, -100, 0, 0, 'Biometrische Signaturdaten', [
                'Subtype' => 'FileAttachment',
                'Name'    => 'Paperclip',
                'FS'      => $tempPath,
            ]);
        }
    }

    /**
     * Erstellt temporäre JSON-Datei (mit Auto-Cleanup nach 1h).
     */
    protected function createTempJsonFile(string $content, string $filename): string
    {
        $uploadDir = wp_upload_dir();
        $tempDir   = $uploadDir['basedir'] . '/pp-temp/';

        if (!is_dir($tempDir)) {
            wp_mkdir_p($tempDir);
        }

        $filepath = $tempDir . sanitize_file_name($filename);
        file_put_contents($filepath, $content);

        // Cleanup nach 1 Stunde
        wp_schedule_single_event(time() + 3600, 'pp_cleanup_temp_file', [$filepath]);

        return $filepath;
    }

    /* ─── HTML Helpers ──────────────────────────────────────── */

    /**
     * Generiert HTML für ein Label+Value Feld.
     */
    protected function fieldHtml(string $label, string $value, bool $fullWidth = false): string
    {
        $class = $fullWidth ? 'field full-width' : 'field';
        return '<div class="' . $class . '">'
            . '<div class="label">' . esc_html($label) . '</div>'
            . '<div class="value">' . $value . '</div>'
            . '</div>';
    }

    /**
     * Generiert HTML für eine Überschrift (Section-Header).
     */
    protected function sectionHtml(string $title): string
    {
        return '<div class="section-header">'
            . '<h3>' . esc_html($title) . '</h3>'
            . '</div>';
    }

    /* ─── Utility Helpers ──────────────────────────────────── */

    /**
     * HTML-Escape Helper.
     */
    protected function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Gemeinsame CSS-Styles für Print-HTML.
     */
    protected function getCommonStyles(): string
    {
        return <<<'CSS'
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    font-size: 11pt; line-height: 1.6; color: #1d2327; margin: 0; padding: 24px;
}
h1 { font-size: 18pt; margin: 0 0 16px 0; }
.field { margin: 6px 0; }
.field .label { font-weight: 600; color: #50575e; font-size: 10pt; margin-bottom: 2px; }
.field .value { font-size: 11pt; }
.field.full-width { grid-column: 1 / -1; }
.section-header h3 { font-size: 13pt; margin: 20px 0 8px 0; color: #1d2327; }
.footer { margin-top: 40px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 9pt; color: #888; }
@media print { .no-print { display: none !important; } body { padding: 0; } }
CSS;
    }

    /**
     * Generiert PDF aus HTML (Wrapper um generatePdfWithBiometrics).
     *
     * @param  string     $html      HTML-Inhalt
     * @param  array|null $biometric Biometrische Daten (optional)
     * @param  string     $filename  Dateiname
     * @return string                PDF-Bytes oder HTML-Fallback
     */
    protected function generatePdf(string $html, ?array $biometric = null, string $filename = 'dokument.pdf'): string
    {
        $result = $this->generatePdfWithBiometrics($html, $biometric, $filename);
        return $result !== false ? $result : $html;
    }

    /**
     * Lädt eine Submission aus dem Repository.
     *
     * @param  int         $submissionId  Submission-ID
     * @param  string|null $locationUuid  Standort-Filter
     * @return object|null
     */
    protected function loadSubmission(int $submissionId, ?string $locationUuid = null): ?array
    {
        return $this->submissionRepo->findById($submissionId);
    }

    /**
     * Entschlüsselt encrypted_data aus einer Submission.
     *
     * @param  string $encryptedData  Verschlüsselte JSON-Daten
     * @return array|null
     */
    protected function decryptData(string $encryptedData): ?array
    {
        try {
            $decrypted = $this->encryption->decrypt($encryptedData);
            if (!$decrypted) {
                return null;
            }
            $data = json_decode($decrypted, true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Prüft Autorisierung und bricht bei Fehler ab.
     *
     * @param  string|null $locationUuid Standort-UUID (für Context)
     * @throws \RuntimeException
     */
    protected function requireAuthorization(?string $locationUuid = null): void
    {
        if ($locationUuid) {
            $this->locationUuid = $locationUuid;
        }

        if (!$this->isAuthorized()) {
            if (function_exists('wp_die')) {
                wp_die('Zugriff verweigert.', 'Fehler', ['response' => 403]);
            }
            http_response_code(403);
            exit('Zugriff verweigert.');
        }
    }

    /* ─── Abstract Methods ──────────────────────────────────── */

    /**
     * Generiert das PDF und gibt es aus.
     */
    abstract public function render(int $submissionId, string $mode = 'full'): void;
}
