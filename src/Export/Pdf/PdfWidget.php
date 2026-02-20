<?php
/**
 * PDF Export für Widget-Services
 *
 * Generiert druckoptimierte PDFs für Online-Service-Anfragen:
 *  - 💊 Rezept-Anfrage
 *  - 📋 Überweisung
 *  - 👓 Brillenverordnung (mit Refraktionswerten)
 *  - 📅 Terminanfrage
 *  - ❌ Terminabsage
 *  - 📄 Dokument-Upload
 *
 * @package PraxisPortal\Export\Pdf
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Export\Pdf;

use PraxisPortal\Core\Container;
use RuntimeException;

class PdfWidget extends PdfBase
{
    /* ──────────────────────────────────────────────────────────
     *  Service-Typ Konfiguration
     * ────────────────────────────────────────────────────────── */

    /** Labels mit Emoji (für Überschriften) */
    private const SERVICE_LABELS = [
        'rezept'             => '💊 Rezept-Anfrage',
        'ueberweisung'       => '📋 Überweisung',
        'brillenverordnung'  => '👓 Brillenverordnung',
        'dokument'           => '📄 Dokument',
        'termin'             => '📅 Terminanfrage',
        'terminabsage'       => '❌ Terminabsage',
    ];

    /** Einfache Labels (für PDF-Titel) */
    private const TYPE_LABELS = [
        'rezept'             => 'Rezept-Anfrage',
        'ueberweisung'       => 'Überweisungs-Anfrage',
        'brillenverordnung'  => 'Brillenverordnung',
        'dokument'           => 'Dokument-Upload',
        'termin'             => 'Termin-Anfrage',
        'terminabsage'       => 'Terminabsage',
    ];

    /** Bekannte Widget-Typen */
    private const WIDGET_TYPES = [
        'rezept', 'ueberweisung', 'brillenverordnung',
        'termin', 'terminabsage', 'dokument',
    ];

    /* ================================================================
     *  PUBLIC API
     * ================================================================ */

    /**
     * Prüft ob ein Request-Typ ein Widget-Typ ist.
     */
    public function isWidgetType(string $requestType): bool
    {
        $clean = str_replace('widget_', '', $requestType);
        return in_array($clean, self::WIDGET_TYPES, true)
            || str_starts_with($requestType, 'widget_');
    }

    /**
     * Generiert PDF-Bytes für Widget-Einreichungen (PVS-Archiv).
     *
     * @param  array  $formData    Formulardaten
     * @param  string $serviceType Service-Typ
     * @return string              PDF-Bytes oder HTML-Fallback
     */
    public function generateWidgetPdf(array $formData, string $serviceType): string
    {
        $cleanType = str_replace('widget_', '', $serviceType);
        $title = self::TYPE_LABELS[$cleanType] ?? 'Online-Anfrage';
        $createdAt = $formData['submitted_at'] ?? date('Y-m-d H:i:s');

        $html = $this->renderSimpleHtml($formData, $cleanType, $title, $createdAt);

        return $this->generatePdf($html, null, strtolower(str_replace(' ', '_', $title)) . '.pdf');
    }

    /**
     * Generiert druckbare HTML-Seite (direkte Ausgabe).
     *
     * @param int         $submissionId  Submission-ID
     * @param string      $requestType   Request-Typ
     * @param string|null $locationUuid  Standort-Filter
     */
    public function generatePrintPage(int $submissionId, string $requestType = '', ?string $locationUuid = null): void
    {
        $this->requireAuthorization($locationUuid);

        $submission = $this->loadSubmission($submissionId, $locationUuid);
        if (!$submission) {
            $this->abort('Eintrag nicht gefunden.');
        }

        $data = $this->decryptData($submission['encrypted_data'] ?? '');
        if (!$data) {
            $this->abort('Fehler beim Entschlüsseln der Daten.');
        }

        $cleanType = str_replace('widget_', '', $requestType);
        $serviceLabel = self::SERVICE_LABELS[$cleanType] ?? self::SERVICE_LABELS[$requestType] ?? $requestType;
        $name = trim(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''));

        $locUuid = $data['_location_uuid'] ?? $locationUuid ?? '';
        $praxis = $this->getPraxisInfo($locUuid ?: null);
        $createdAt = $submission['created_at'] ?? date('Y-m-d H:i:s');

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPrintHtml($data, $cleanType, $serviceLabel, $praxis, $name, $createdAt);
        exit;
    }

    /**
     * export() für ExportConfig-Kompatibilität.
     *
     * @param  array       $formData   Formulardaten
     * @param  object|null $submission Submission-Objekt
     * @return string                  HTML/PDF-Inhalt
     */
    public function export(array $formData, ?object $submission = null): string
    {
        $serviceType = $formData['service_type'] ?? 'termin';
        return $this->generateWidgetPdf($formData, $serviceType);
    }

    /* ── Abstract Method Implementations ── */

    /**
     * {@inheritdoc}
     */
    public function render(int $submissionId, string $mode = 'full'): void
    {
        $this->generatePrintPage($submissionId);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimeType(): string
    {
        return 'application/pdf';
    }

    /**
     * {@inheritdoc}
     */
    public function getFileExtension(): string
    {
        return 'pdf';
    }

    /* ================================================================
     *  SIMPLE HTML (für PVS-Archiv / TCPDF)
     * ================================================================ */

    /**
     * Rendert einfaches HTML für PVS-Archiv und TCPDF.
     */
    private function renderSimpleHtml(array $d, string $serviceType, string $title, string $createdAt): string
    {
        $e = fn(string $s) => $this->esc($s);

        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . '<title>' . $e($title) . '</title>'
            . '<style>'
            . 'body{font-family:Arial,sans-serif;font-size:12px;line-height:1.5;margin:20px;}'
            . 'h1{font-size:18px;border-bottom:2px solid #333;padding-bottom:10px;}'
            . 'h2{font-size:14px;margin-top:20px;color:#444;}'
            . '.section{margin-bottom:20px;}'
            . '.field{margin:8px 0;} .label{font-weight:bold;display:inline-block;width:150px;}'
            . '.value{display:inline-block;}'
            . '.footer{margin-top:40px;padding-top:10px;border-top:1px solid #ccc;font-size:10px;color:#666;}'
            . 'table{border-collapse:collapse;width:100%;margin:10px 0;}'
            . 'td,th{border:1px solid #ddd;padding:6px;text-align:left;} th{background:#f5f5f5;}'
            . '</style></head><body>';

        $html .= '<h1>' . $e($title) . '</h1>';
        $html .= '<p>Eingegangen am: ' . $e(date('d.m.Y \u\m H:i', strtotime($createdAt))) . ' Uhr</p>';

        // Patientendaten
        $html .= '<div class="section"><h2>Patientendaten</h2>';
        $html .= $this->simpleField('Name', ($d['vorname'] ?? '') . ' ' . ($d['nachname'] ?? ''));
        $html .= $this->simpleField('Geburtsdatum', $d['geburtsdatum'] ?? '-');
        $html .= $this->simpleField('Telefon', $d['telefon'] ?? '-');
        $html .= $this->simpleField('E-Mail', $d['email'] ?? '-');
        $html .= $this->simpleField('Versicherung', ucfirst($d['versicherung'] ?? $d['kasse'] ?? '-'));
        $html .= '</div>';

        // Service-spezifische Details
        $html .= $this->renderServiceDetails($d, $serviceType);

        // Anmerkungen
        if (!empty($d['anmerkungen'])) {
            $html .= '<div class="section"><h2>Anmerkungen</h2>'
                . '<p>' . nl2br($e($d['anmerkungen'])) . '</p></div>';
        }

        // Footer
        $html .= '<div class="footer">Praxis-Portal v' . self::VERSION . ' | Generiert am ' . date('d.m.Y H:i') . '</div>';
        $html .= '</body></html>';

        return $html;
    }

    /* ================================================================
     *  PRINT HTML (vollständige Browser-Druckansicht)
     * ================================================================ */

    /**
     * Rendert vollständiges Print-HTML mit Navigation und Layout.
     */
    private function renderPrintHtml(
        array  $data,
        string $cleanType,
        string $serviceLabel,
        array  $praxis,
        string $name,
        string $createdAt
    ): string {
        $e = fn(string $s) => $this->esc($s);
        $styles = $this->getCommonStyles();

        // Extra Styles für Widget
        $extraStyles = <<<'CSS'
h2 {
    font-size: 13pt; color: #1d2327; margin: 20px 0 12px 0;
    padding: 8px 12px; background: #f0f6fc; border-left: 3px solid #2271b1;
}
.meta {
    background: #f9f9f9; padding: 12px 16px; margin-bottom: 24px;
    border-radius: 6px; font-size: 11pt; color: #50575e;
}
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
CSS;

        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . '<title>' . $e($serviceLabel) . ' - ' . $e($name) . '</title>'
            . '<style>' . $styles . $extraStyles . '</style></head><body>';

        // Nav-Buttons
        $html .= '<div class="no-print" style="margin-bottom:20px;padding:10px;background:#fff3cd;border-radius:4px;">'
            . '<button onclick="window.print()" style="padding:8px 16px;cursor:pointer;">🖨️ Drucken</button>'
            . '<button onclick="window.close()" style="padding:8px 16px;cursor:pointer;margin-left:8px;">✕ Schließen</button>'
            . '</div>';

        // Überschrift
        $html .= '<h1 style="font-size:20pt;border-bottom:2px solid #2271b1;padding-bottom:12px;color:#1d2327;">'
            . $e($serviceLabel) . '</h1>';

        // Meta-Banner
        $html .= '<div class="meta"><strong>' . $e($praxis['name']) . '</strong> | '
            . 'Eingang: ' . $e(date('d.m.Y H:i', strtotime($createdAt))) . '</div>';

        // Persönliche Daten
        $html .= '<h2>👤 Persönliche Daten</h2><div class="grid">';
        $html .= $this->fieldHtml('Vorname', $e($data['vorname'] ?? '-'));
        $html .= $this->fieldHtml('Nachname', $e($data['nachname'] ?? '-'));
        $html .= $this->fieldHtml('Geburtsdatum', $e($data['geburtsdatum'] ?? '-'));
        $html .= $this->fieldHtml('Telefon', $e($data['telefon'] ?? '-'));
        $html .= $this->fieldHtml('E-Mail', $e($data['email'] ?? '-'));
        $html .= $this->fieldHtml('Versicherung', $e(ucfirst($data['versicherung'] ?? $data['kasse'] ?? '-')));
        $html .= '</div>';

        // Anfrage-Details
        $html .= '<h2>📋 Anfrage-Details</h2><div class="grid">';
        $html .= $this->renderPrintDetails($data, $cleanType);

        if (!empty($data['anmerkungen'])) {
            $html .= $this->fieldHtml('Anmerkungen', $e($data['anmerkungen']), true);
        }
        $html .= '</div>';

        // Footer
        $html .= '<div class="footer">Generiert am ' . date('d.m.Y H:i')
            . ' | Praxis-Portal v' . self::VERSION . '</div>';
        $html .= '</body></html>';

        return $html;
    }

    /* ================================================================
     *  SERVICE-SPEZIFISCHE DETAILS
     * ================================================================ */

    /**
     * Service-Details für einfaches HTML (PVS-Archiv).
     */
    private function renderServiceDetails(array $d, string $type): string
    {
        $html = '<div class="section"><h2>Details</h2>';

        switch ($type) {
            case 'rezept':
                $html .= $this->renderRezeptDetails($d, 'simple');
                break;
            case 'ueberweisung':
                $html .= $this->renderUeberweisungDetails($d, 'simple');
                break;
            case 'brillenverordnung':
                $html .= $this->renderBrillenDetails($d, 'simple');
                break;
            case 'termin':
                $html .= $this->renderTerminDetails($d, 'simple');
                break;
            case 'terminabsage':
                $html .= $this->renderTerminabsageDetails($d, 'simple');
                break;
            case 'dokument':
                $html .= $this->renderDokumentDetails($d, 'simple');
                break;
            default:
                $html .= '<p>Typ: ' . $this->esc($type) . '</p>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Service-Details für Print-HTML (Browser-Druckansicht).
     */
    private function renderPrintDetails(array $d, string $type): string
    {
        return match ($type) {
            'rezept'            => $this->renderRezeptDetails($d, 'print'),
            'ueberweisung'      => $this->renderUeberweisungDetails($d, 'print'),
            'brillenverordnung' => $this->renderBrillenDetails($d, 'print'),
            'termin'            => $this->renderTerminDetails($d, 'print'),
            'terminabsage'      => $this->renderTerminabsageDetails($d, 'print'),
            'dokument'          => $this->renderDokumentDetails($d, 'print'),
            default             => '',
        };
    }

    /* ── Rezept ── */

    private function renderRezeptDetails(array $d, string $mode): string
    {
        $html = '';
        $e = fn(string $s) => $this->esc($s);

        // Medikamente (Array von medikament + art)
        if (!empty($d['medikamente']) && is_array($d['medikamente'])) {
            $meds = [];
            foreach ($d['medikamente'] as $i => $med) {
                $art = isset($d['medikament_arten'][$i]) ? ' (' . $d['medikament_arten'][$i] . ')' : '';
                $meds[] = $e($med . $art);
            }
            $html .= $this->fieldHtml('Medikamente', implode('<br>', $meds), true);
        }

        // Lieferung
        if (!empty($d['rezept_lieferung'])) {
            $lieferung = $d['rezept_lieferung'] === 'post' ? 'Per Post' : 'Abholung Praxis';
            $html .= $this->fieldHtml('Lieferung', $lieferung);

            if ($d['rezept_lieferung'] === 'post' && !empty($d['versandadresse'])) {
                $va = $d['versandadresse'];
                $adresse = $e($va['strasse'] ?? '') . '<br>' . $e(($va['plz'] ?? '') . ' ' . ($va['ort'] ?? ''));
                $html .= $this->fieldHtml('Versandadresse', $adresse, true);
            }
        }

        // eEB (nur Kassenpatienten)
        if (isset($d['evn_erlaubt'])) {
            $evnJa = ($d['evn_erlaubt'] === '1' || $d['evn_erlaubt'] === 1);
            $html .= $this->fieldHtml('Elektr. Ersatzbescheinigung', $evnJa ? '✓ Ja' : '✗ Nein');
        }

        return $html;
    }

    /* ── Überweisung ── */

    private function renderUeberweisungDetails(array $d, string $mode): string
    {
        $e = fn(string $s) => $this->esc($s);
        $html  = $this->fieldHtml('Überweisungsziel', $e($d['ueberweisungsziel'] ?? '-'));
        $html .= $this->fieldHtml('Diagnose', $e($d['diagnose'] ?? '-'), true);

        // eEB
        if (isset($d['ueberweisung_evn_erlaubt'])) {
            $evnJa = ($d['ueberweisung_evn_erlaubt'] === '1' || $d['ueberweisung_evn_erlaubt'] === 1);
            $html .= $this->fieldHtml('Elektr. Ersatzbescheinigung', $evnJa ? '✓ Ja' : '✗ Nein');
        }

        return $html;
    }

    /* ── Brillenverordnung ── */

    private function renderBrillenDetails(array $d, string $mode): string
    {
        $e = fn(string $s) => $this->esc($s);
        $html = '';

        if (!empty($d['brillenart'])) {
            $ba = is_array($d['brillenart']) ? implode(', ', $d['brillenart']) : $d['brillenart'];
            $html .= $this->fieldHtml('Brillenart', $e($ba));
        }

        if (!empty($d['hsa'])) {
            $html .= $this->fieldHtml('HSA', $e($d['hsa']));
        }

        // Refraktionswerte
        if (!empty($d['refraktion'])) {
            $rv = $d['refraktion'];
            $rvHtml = '';

            foreach (['rechts' => 'Rechts', 'links' => 'Links'] as $key => $label) {
                if (!empty($rv[$key])) {
                    $s = $rv[$key];
                    $rvHtml .= '<strong>' . $label . ':</strong> ';
                    if (!empty($s['sph'])) $rvHtml .= 'Sph: ' . $e($s['sph']) . ' ';
                    if (!empty($s['zyl'])) $rvHtml .= 'Zyl: ' . $e($s['zyl']) . ' ';
                    if (!empty($s['ach'])) $rvHtml .= 'Ach: ' . $e($s['ach']) . '° ';
                    if (!empty($s['add'])) $rvHtml .= 'Add: ' . $e($s['add']);
                    $rvHtml .= '<br>';
                }
            }

            if ($rvHtml) {
                $html .= $this->fieldHtml('Refraktion', $rvHtml, true);
            }
        }

        // Prismenwerte
        if (!empty($d['prismen'])) {
            $pv = $d['prismen'];
            $pvHtml = '';
            foreach (['rechts' => 'Rechts', 'links' => 'Links'] as $key => $label) {
                if (!empty($pv[$key])) {
                    $p = $pv[$key];
                    $pvHtml .= '<strong>' . $label . ':</strong> ';
                    if (!empty($p['horizontal']['wert'])) {
                        $pvHtml .= 'H: ' . $e($p['horizontal']['wert']);
                        if (!empty($p['horizontal']['basis'])) $pvHtml .= ' ' . $e($p['horizontal']['basis']);
                        $pvHtml .= ' pdpt ';
                    }
                    if (!empty($p['vertikal']['wert'])) {
                        $pvHtml .= 'V: ' . $e($p['vertikal']['wert']);
                        if (!empty($p['vertikal']['basis'])) $pvHtml .= ' ' . $e($p['vertikal']['basis']);
                        $pvHtml .= ' pdpt';
                    }
                    $pvHtml .= '<br>';
                }
            }
            if ($pvHtml) {
                $html .= $this->fieldHtml('Prismenwerte', $pvHtml, true);
            }
        }

        // eEB / EVN
        if (isset($d['brillen_evn_erlaubt'])) {
            $evnJa = ($d['brillen_evn_erlaubt'] === '1' || $d['brillen_evn_erlaubt'] === 1);
            $html .= $this->fieldHtml('Elektr. Ersatzbescheinigung', $evnJa ? '✓ Ja' : '✗ Nein');
        } elseif (!empty($d['evn'])) {
            $html .= $this->fieldHtml('EVN', $e($d['evn']));
        }

        // Lieferung
        if (!empty($d['brillen_lieferung'])) {
            $html .= $this->fieldHtml('Lieferung', $d['brillen_lieferung'] === 'post' ? 'Per Post' : 'Abholung Praxis');

            if ($d['brillen_lieferung'] === 'post' && !empty($d['brillen_versandadresse'])) {
                $va = $d['brillen_versandadresse'];
                $adresse = $e($va['strasse'] ?? '') . '<br>' . $e(($va['plz'] ?? '') . ' ' . ($va['ort'] ?? ''));
                $html .= $this->fieldHtml('Versandadresse', $adresse, true);
            }
        }

        return $html;
    }

    /* ── Termin ── */

    private function renderTerminDetails(array $d, string $mode): string
    {
        $e = fn(string $s) => $this->esc($s);
        $html = '';

        if (!empty($d['termin_anliegen'])) {
            $html .= $this->fieldHtml('Anliegen', $e($d['termin_anliegen']));
        }

        if (!empty($d['termin_grund'])) {
            $html .= $this->fieldHtml('Grund', $e($d['termin_grund']));
        }

        if (!empty($d['termin_zeit'])) {
            $zeitLabels = ['vormittags' => 'Vormittags', 'nachmittags' => 'Nachmittags', 'egal' => 'Egal'];
            $html .= $this->fieldHtml('Bevorzugte Zeit', $zeitLabels[$d['termin_zeit']] ?? $e($d['termin_zeit']));
        }

        if (!empty($d['termin_tage']) && is_array($d['termin_tage'])) {
            $tageLabels = ['mo' => 'Mo', 'di' => 'Di', 'mi' => 'Mi', 'do' => 'Do', 'fr' => 'Fr', 'sa' => 'Sa', 'egal' => 'Egal'];
            $tage = array_map(fn($t) => $tageLabels[$t] ?? $t, $d['termin_tage']);
            $html .= $this->fieldHtml('Bevorzugte Tage', implode(', ', $tage));
        } elseif (!empty($d['termin_tage_display'])) {
            $html .= $this->fieldHtml('Bevorzugte Tage', $e($d['termin_tage_display']));
        }

        if (!empty($d['termin_schnellstmoeglich']) && $d['termin_schnellstmoeglich'] === '1') {
            $html .= $this->fieldHtml('Schnellstmöglich', '✓ Ja, so schnell wie möglich');
        }

        if (!empty($d['termin_beschwerden'])) {
            $html .= $this->fieldHtml('Beschwerden', $e($d['termin_beschwerden']), true);
        }

        if (!empty($d['termin_wunschzeit'])) {
            $html .= $this->fieldHtml('Wunschzeit', $e($d['termin_wunschzeit']));
        }

        return $html;
    }

    /* ── Terminabsage ── */

    private function renderTerminabsageDetails(array $d, string $mode): string
    {
        $e = fn(string $s) => $this->esc($s);
        $html = $this->fieldHtml('Termin-Datum', $e($d['absage_datum'] ?? '-'));

        if (!empty($d['absage_uhrzeit'])) {
            $html .= $this->fieldHtml('Uhrzeit', $e($d['absage_uhrzeit'] . ' Uhr'));
        }

        if (!empty($d['absage_grund'])) {
            $html .= $this->fieldHtml('Grund', $e($d['absage_grund']), true);
        }

        if (!empty($d['absage_neuer_termin'])) {
            $html .= $this->fieldHtml('Neuer Termin gewünscht', $d['absage_neuer_termin'] === 'ja' ? '✓ Ja' : '✗ Nein');
        }

        return $html;
    }

    /* ── Dokument ── */

    private function renderDokumentDetails(array $d, string $mode): string
    {
        $e = fn(string $s) => $this->esc($s);
        $html = '';

        if (!empty($d['dokument_typ'])) {
            $html .= $this->fieldHtml('Dokumenttyp', $e($d['dokument_typ']));
        }

        if (!empty($d['bemerkung'])) {
            $html .= $this->fieldHtml('Bemerkung', $e($d['bemerkung']), true);
        }

        return $html;
    }

    /* ================================================================
     *  HELPER
     * ================================================================ */

    /**
     * Einfaches Feld (für simple HTML).
     */
    private function simpleField(string $label, string $value): string
    {
        return '<div class="field"><span class="label">' . $this->esc($label) . ':</span> '
            . '<span class="value">' . $this->esc($value) . '</span></div>';
    }

    /**
     * Bricht mit Fehlermeldung ab.
     */
    private function abort(string $message): void
    {
        if (function_exists('wp_die')) {
            wp_die($message, 'Fehler', ['response' => 400]);
        }
        http_response_code(400);
        exit($message);
    }
}
