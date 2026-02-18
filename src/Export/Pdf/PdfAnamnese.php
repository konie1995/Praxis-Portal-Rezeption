<?php
/**
 * PDF Export für Anamnesebögen (Privatpatienten)
 *
 * Generiert druckoptimierte HTML / echtes PDF mit Stammdaten + Unterschrift.
 * Nur für Privatpatienten (kasse === 'privat').
 *
 * Modi:
 *  - compact  – 11 pt, biometrisch eingebettet (API / PVS-Übergabe)
 *  - full     – 12 pt, Druckbutton (Browser-Ansicht)
 *
 * Multistandort: getPraxisInfo() löst automatisch den richtigen Standort auf.
 * Mehrsprachigkeit: über ExportBase::setLanguage() / t()-System.
 * Lizenz: PDF-Export über FeatureGate geprüft (Aufrufer verantwortlich).
 *
 * @package    PraxisPortal\Export\Pdf
 * @since      4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Export\Pdf;

use PraxisPortal\Core\Container;

class PdfAnamnese extends PdfBase
{
    /* ─── Public API ────────────────────────────────────────── */

    /**
     * Gibt HTML als String zurück (für API-Abruf / TCPDF-Pipeline).
     *
     * @return string|\WP_Error  HTML oder Fehler
     */
    public function getHtmlContent(int $submissionId): string|\WP_Error
    {
        $submission = $this->submissionRepo->findById($submissionId);
        if (!$submission) {
            return new \WP_Error('not_found', 'Eintrag nicht gefunden');
        }

        $data = $this->decryptSubmissionData($submission);
        if (!$data) {
            return new \WP_Error('decrypt_error', 'Entschlüsselungsfehler');
        }

        if (strtolower($data['kasse'] ?? '') !== 'privat') {
            return new \WP_Error('not_private', 'PDF nur für Privatpatienten');
        }

        $praxis        = $this->getPraxisInfoByLocationId((int) ($submission['location_id'] ?? 0));
        $signatureData = $this->loadSignature($submissionId, $submission);
        $biometric     = $this->extractBiometricData($data);
        $referenceId   = ($submission['submission_hash'] ?? '') ?: '#' . ($submission['id'] ?? '0');
        $createdAt     = $submission['created_at'] ?? '';

        ob_start();
        $this->renderCompactHtml($data, $praxis, $referenceId, $signatureData, $createdAt, $biometric);
        return ob_get_clean();
    }

    /**
     * Rendert das PDF / die HTML-Seite direkt in den Browser.
     */
    public function render(int $submissionId, string $mode = 'full'): void
    {
        if (!$this->isAuthorized()) {
            wp_die('Nicht autorisiert.', 'Zugriff verweigert', ['response' => 403]);
        }

        $submission = $this->submissionRepo->findById($submissionId);
        if (!$submission) {
            wp_die('Eintrag nicht gefunden.');
        }

        $type = $submission['request_type'] ?? 'anamnese';
        if (!str_contains($type, 'anamnese') && $type !== '') {
            wp_die('PDF-Export nicht verfügbar für diesen Typ.');
        }

        $data = $this->decryptSubmissionData($submission);
        if (!$data) {
            wp_die('Fehler beim Entschlüsseln.');
        }

        if (strtolower($data['kasse'] ?? '') !== 'privat') {
            wp_die('PDF-Export nur für Privatpatienten.');
        }

        $praxis      = $this->getPraxisInfoByLocationId((int) ($submission['location_id'] ?? 0));
        $sigData     = $this->loadSignature($submissionId, $submission);
        $referenceId = ($submission['submission_hash'] ?? '') ?: '#' . ($submission['id'] ?? '0');
        $createdAt   = $submission['created_at'] ?? '';

        if ($mode === 'compact') {
            $biometric = $this->extractBiometricData($data);
            $this->renderCompactHtml($data, $praxis, $referenceId, $sigData, $createdAt, $biometric);
        } else {
            $this->renderFullHtml($data, $praxis, $referenceId, $sigData, $createdAt);
        }
        exit;
    }

    /**
     * Generiert PDF und sendet es direkt an den Browser (Portal-Download).
     *
     * Nutzt TCPDF wenn verfügbar, sonst HTML-Fallback.
     */
    public function generateAndSend(int $submissionId): void
    {
        $this->setInternalAccess(true);

        $html = $this->getHtmlContent($submissionId);
        if (is_wp_error($html)) {
            wp_die(esc_html($html->get_error_message()));
        }

        $submission = $this->submissionRepo->findById($submissionId);
        $data       = $submission ? $this->decryptSubmissionData($submission) : [];
        $biometric  = $data ? $this->extractBiometricData($data) : null;
        $filename   = 'Anamnese_' . $submissionId . '.pdf';

        // TCPDF-Pipeline
        $pdfContent = $this->generatePdfWithBiometrics($html, $biometric, $filename);

        if ($pdfContent !== false && $pdfContent !== $html) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfContent));
            echo $pdfContent;
        } else {
            // Fallback: HTML direkt senden
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
        }
        exit;
    }

    /**
     * Generiert PDF als String (API-Rückgabe, base64-Encoding).
     *
     * @return string PDF-Binärdaten oder HTML-Fallback
     * @throws \RuntimeException bei Fehler
     */
    public function generateToString(int $submissionId): string
    {
        $this->setInternalAccess(true);

        $html = $this->getHtmlContent($submissionId);
        if (is_wp_error($html)) {
            throw new \RuntimeException($html->get_error_message());
        }

        $submission = $this->submissionRepo->findById($submissionId);
        $data       = $submission ? $this->decryptSubmissionData($submission) : [];
        $biometric  = $data ? $this->extractBiometricData($data) : null;
        $filename   = 'Anamnese_' . $submissionId . '.pdf';

        $pdfContent = $this->generatePdfWithBiometrics($html, $biometric, $filename);

        return ($pdfContent !== false) ? $pdfContent : $html;
    }

    /** @inheritDoc */
    public function export(array $formData, ?object $submission = null): string { return ''; }
    public function getMimeType(): string  { return 'text/html'; }
    public function getFileExtension(): string { return 'html'; }

    /* ─── Private Helpers ──────────────────────────────────── */

    /**
     * Entschlüsselt Formulardaten aus einer Submission-Row (Array).
     */
    private function decryptSubmissionData(array $submission): ?array
    {
        if (empty($submission['encrypted_data'])) {
            return null;
        }

        return $this->decryptData($submission['encrypted_data']);
    }

    /* ─── Compact HTML (API / PVS) ──────────────────────────── */

    private function renderCompactHtml(
        array $data, array $praxis, string $refId,
        ?string $sigData, string $createdAt, ?array $bio
    ): void {
        $nm = esc_html(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''));
        $dt = esc_html(date('d.m.Y H:i', strtotime($createdAt)));
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
        echo '<title>Patientenstammdaten - ' . $nm . '</title>';
        echo '<style>';
        echo '@page{size:A4;margin:10mm 15mm}*{margin:0;padding:0;box-sizing:border-box}';
        echo 'body{font-family:"Segoe UI",Arial,sans-serif;font-size:11pt;line-height:1.4;padding:15px 25px;max-width:800px;margin:0 auto}';
        echo '@media print{body{padding:0}.no-print{display:none!important}}';
        echo '.hdr{display:flex;justify-content:space-between;border-bottom:2px solid #1e40af;padding-bottom:12px;margin-bottom:15px}';
        echo '.pi h1{font-size:16pt;color:#1e40af;margin-bottom:3px}.pi p{font-size:9pt;color:#666;white-space:pre-line}';
        echo '.di{text-align:right;font-size:10pt}.dt{font-size:13pt;font-weight:bold;color:#1e40af}';
        echo '.sec{margin-bottom:12px}.st{font-size:11pt;font-weight:bold;color:#1e40af;border-bottom:1px solid #ddd;padding-bottom:3px;margin-bottom:6px}';
        echo '.fr{display:flex;margin-bottom:4px}.fl{font-weight:600;color:#555;width:140px;font-size:10pt}.fv{flex:1;font-size:10pt}';
        echo '.ss{margin-top:20px;border-top:1px solid #ddd;padding-top:12px}.si{max-height:70px}';
        echo '.bb{display:inline-block;font-size:8pt;background:#059669;color:#fff;padding:2px 6px;border-radius:3px;margin-left:8px}';
        echo '.ft{margin-top:20px;padding-top:10px;border-top:1px solid #eee;font-size:9pt;color:#999}';
        echo '</style></head><body>';

        // Header (Multistandort: praxis kommt vom richtigen Standort)
        echo '<div class="hdr"><div class="pi"><h1>' . esc_html($praxis['name']) . '</h1>';
        if (!empty($praxis['address'])) echo '<p>' . esc_html($praxis['address']) . '</p>';
        echo '</div><div class="di"><div class="dt">Patientenstammdaten</div><div>Privatpatient</div><div>' . $dt . '</div></div></div>';

        // Persönliche Daten
        echo '<div class="sec"><div class="st">Persönliche Daten</div>';
        echo $this->row('Name:', '<strong>' . $nm . '</strong>');
        echo $this->row('Geburtsdatum:', esc_html($data['geburtsdatum'] ?? '-'));
        echo $this->row('Geschlecht:', esc_html($data['geschlecht'] ?? '-'));
        echo '</div>';

        // Adresse
        echo '<div class="sec"><div class="st">Adresse</div>';
        echo $this->row('Straße:', esc_html($data['strasse'] ?? '-'));
        echo $this->row('PLZ / Ort:', esc_html(($data['plz'] ?? '') . ' ' . ($data['ort'] ?? '')));
        echo '</div>';

        // Kontakt
        echo '<div class="sec"><div class="st">Kontakt</div>';
        echo $this->row('Telefon:', esc_html($data['telefon'] ?? '-'));
        echo $this->row('E-Mail:', esc_html($data['email'] ?? '-'));
        echo '</div>';

        // Versicherung
        echo '<div class="sec"><div class="st">Versicherung</div>';
        echo $this->row('Art:', 'Privat');
        if (!empty($data['versicherung_name'])) echo $this->row('Versicherung:', esc_html($data['versicherung_name']));
        echo '</div>';

        // Unterschrift
        if ($sigData) {
            echo '<div class="ss"><div class="st">Unterschrift';
            if ($bio) echo '<span class="bb" title="Biometrische Daten">✓ Biometrisch</span>';
            echo '</div>';
            echo '<img src="' . esc_attr($sigData) . '" alt="Unterschrift" class="si">';
            echo '<p style="font-size:9pt;color:#666;margin-top:5px">Elektronisch unterschrieben am ' . $dt;
            if ($bio && isset($bio['strokes'])) {
                $tp = 0;
                foreach ($bio['strokes'] as $s) $tp += count($s);
                echo ' (' . count($bio['strokes']) . ' Striche, ' . $tp . ' Datenpunkte)';
            }
            echo '</p></div>';
        }

        // Footer
        echo '<div class="ft">Referenz: ' . esc_html($refId);
        if ($bio) echo ' | Biometrische Signaturdaten eingebettet (ISO 19794-7)';
        echo '</div>';

        // Biometric JSON
        if ($bio) {
            echo '<script type="application/json" id="signature-biometric-data" style="display:none">';
            echo json_encode($bio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            echo '</script>';
        }

        echo '</body></html>';
    }

    /* ─── Full HTML (Browser / Print) ───────────────────────── */

    private function renderFullHtml(
        array $data, array $praxis, string $refId,
        ?string $sigData, string $createdAt
    ): void {
        $nm     = esc_html(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''));
        $dShort = esc_html(date('d.m.Y', strtotime($createdAt)));
        $dFull  = esc_html(date('d.m.Y \u\m H:i', strtotime($createdAt)));

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Stammdaten - ' . $nm . '</title>';
        echo '<style>';
        echo '*{margin:0;padding:0;box-sizing:border-box}';
        echo 'body{font-family:Arial,Helvetica,sans-serif;font-size:12pt;line-height:1.3;color:#333;background:#fff;padding:10mm;max-width:210mm;margin:0 auto}';
        echo '@page{size:A4;margin:10mm}@media print{body{padding:0}.no-print{display:none!important}.pb{page-break-inside:avoid}}';
        echo '.hdr{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e40af;padding-bottom:8px;margin-bottom:8px}';
        echo '.pi h1{font-size:18pt;color:#1e40af;margin-bottom:2px}.pi p{font-size:10pt;color:#666;white-space:pre-line}';
        echo '.di{text-align:right;font-size:11pt}.dt{font-size:15pt;font-weight:bold;color:#1e40af}';
        echo '.rb{background:#ecfdf5;border:1px solid #059669;border-radius:4px;padding:4px 10px;margin-bottom:10px;font-size:9pt}';
        echo '.rl{color:#065f46;font-weight:600}.ri{font-family:monospace;font-weight:bold;color:#059669}';
        echo '.sec{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:8px}';
        echo '.sec h2{font-size:13pt;color:#1e40af;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #cbd5e1}';
        echo '.dg{display:grid;grid-template-columns:1fr 1fr;gap:6px 25px}';
        echo '.gi{display:flex;flex-direction:column}.gi.fw{grid-column:span 2}';
        echo '.gl{font-size:8pt;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-bottom:1px}';
        echo '.gv{font-size:12pt;color:#1e293b;font-weight:500}';
        echo '.is{background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:8px 12px;margin-bottom:8px}';
        echo '.is h3{font-size:12pt;color:#92400e;margin-bottom:6px}';
        echo '.id{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:11pt}.id strong{color:#92400e}';
        echo '.ss{margin-top:10px;padding-top:10px;border-top:1px solid #e2e8f0}';
        echo '.ss h3{font-size:12pt;color:#1e40af;margin-bottom:6px}';
        echo '.ct{font-size:10pt;color:#64748b;margin-bottom:8px}';
        echo '.sb{display:flex;justify-content:space-between;align-items:flex-end}';
        echo '.sd{text-align:center}.sv{font-size:12pt;font-weight:600;padding:3px 15px;border-bottom:1px solid #333;min-width:130px;display:inline-block}';
        echo '.sl{font-size:9pt;color:#666;margin-top:2px}';
        echo '.sim{text-align:center}.sim img{max-width:200px;max-height:70px;border-bottom:1px solid #333;padding-bottom:3px}';
        echo '.ft{margin-top:10px;padding-top:6px;border-top:1px solid #e2e8f0;font-size:8pt;color:#94a3b8;text-align:center}';
        echo '.pb-btn{position:fixed;top:15px;right:15px;background:#1e40af;color:#fff;border:none;padding:12px 24px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(30,64,175,.3);z-index:1000}';
        echo '.pb-btn:hover{background:#1d4ed8}';
        echo '.ph{position:fixed;top:60px;right:15px;background:#fef3c7;border:1px solid #f59e0b;padding:8px 12px;border-radius:6px;font-size:11px;color:#92400e;max-width:200px;z-index:1000}';
        echo '</style></head><body>';

        echo '<button class="pb-btn no-print" onclick="window.print()">🖨️ Drucken / PDF</button>';
        echo '<div class="ph no-print">💡 Für PDF: „Als PDF speichern" wählen</div>';

        // Header (Multistandort)
        echo '<div class="hdr"><div class="pi"><h1>' . esc_html($praxis['name']) . '</h1>';
        if (!empty($praxis['address'])) echo '<p>' . esc_html($praxis['address']) . '</p>';
        echo '</div><div class="di"><div class="dt">Patientenstammdaten</div><div>' . $dShort . '</div></div></div>';

        echo '<div class="rb"><span class="rl">Referenz:</span> <span class="ri">' . esc_html($refId) . '</span></div>';

        // Persönliche Daten
        echo '<div class="sec pb"><h2>Persönliche Daten</h2><div class="dg">';
        echo $this->gridItem('Anrede / Titel', trim(($data['anrede'] ?? '') . ' ' . ($data['titel'] ?? '')));
        echo $this->gridItem('Geburtsdatum', $data['geburtsdatum'] ?? '-');
        echo $this->gridItem('Vorname', $data['vorname'] ?? '-');
        echo $this->gridItem('Nachname', $data['nachname'] ?? '-');
        echo $this->gridItem('Anschrift', ($data['strasse'] ?? '') . ', ' . ($data['plz'] ?? '') . ' ' . ($data['ort'] ?? ''), true);
        echo $this->gridItem('Telefon', $data['telefon'] ?? '-');
        echo $this->gridItem('E-Mail', $data['email'] ?? '-');
        echo '</div></div>';

        // Versicherung
        echo '<div class="is pb"><h3>Privatversicherung</h3><div class="id">';
        $privatArt = $data['privat_art'] ?? [];
        if (is_string($privatArt)) $privatArt = json_decode($privatArt, true) ?: [];
        foreach ($privatArt as $opt) {
            echo '<div>✓ <strong>' . esc_html($opt) . '</strong></div>';
        }
        $hvN = $data['hv_nachname'] ?? '';
        if ($hvN) {
            $hvV = $data['hv_vorname'] ?? '';
            $hvS = $data['hv_strasse'] ?? '';
            $hvP = $data['hv_plz'] ?? '';
            $hvO = $data['hv_ort'] ?? '';
            echo '<div>Hauptvers.: <strong>' . esc_html($hvV . ' ' . $hvN) . '</strong>';
            if ($hvS) echo ' (' . esc_html($hvS . ', ' . $hvP . ' ' . $hvO) . ')';
            echo '</div>';
        }
        echo '</div></div>';

        // Unterschrift
        if ($sigData) {
            echo '<div class="ss"><h3>Bestätigung</h3>';
            echo '<p class="ct">Hiermit bestätige ich die Richtigkeit der oben gemachten Angaben.</p>';
            echo '<div class="sb">';
            echo '<div class="sd"><span class="sv">' . $dShort . '</span><div class="sl">Datum</div></div>';
            echo '<div class="sim"><img src="' . esc_attr($sigData) . '" alt="Unterschrift"><div class="sl">Unterschrift Patient/in</div></div>';
            echo '</div></div>';
        }

        echo '<div class="ft">Elektronisch erstellt am ' . $dFull . ' Uhr • Ref: ' . esc_html($refId) . '</div>';
        echo '</body></html>';
    }

    /* ─── Micro-Helpers ─────────────────────────────────────── */

    private function row(string $label, string $value): string
    {
        return '<div class="fr"><span class="fl">' . $label . '</span><span class="fv">' . $value . '</span></div>';
    }

    private function gridItem(string $label, string $value, bool $full = false): string
    {
        $cls = $full ? 'gi fw' : 'gi';
        return '<div class="' . $cls . '"><span class="gl">' . esc_html($label) . '</span><span class="gv">' . esc_html($value) . '</span></div>';
    }
}
