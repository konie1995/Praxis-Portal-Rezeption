<?php
/**
 * HL7 v2.5.1 Export
 *
 * Konvertiert Formulardaten in HL7 v2.5.1 Pipe-Delimited-Format
 * für deutsche PVS-Systeme (Medistar, Turbomed, Albis, etc.).
 *
 * Nachrichtentypen:
 *  - ADT^A04  (Patientenaufnahme + Anamnese)
 *  - ORU^R01  (Beobachtungen / Befunde)
 *
 * Segmente: MSH, PID, PV1, OBR, OBX, DG1, AL1
 *
 * @package    PraxisPortal\Export
 * @since      4.0.0
 * @see        http://www.hl7.org/implement/standards/product_brief.cfm?product_id=185
 */

declare(strict_types=1);

namespace PraxisPortal\Export;

if (!defined('ABSPATH')) {
    exit;
}

use PraxisPortal\Core\Container;

class Hl7Export extends ExportBase
{
    /* ─── Constants ─────────────────────────────────────────── */

    private const HL7_VERSION = '2.5.1';

    /** Trennzeichen */
    private const FIELD_SEP        = '|';
    private const COMPONENT_SEP    = '^';
    private const REPETITION_SEP   = '~';
    private const ESCAPE_CHAR      = '\\';
    private const SUBCOMPONENT_SEP = '&';

    /** Encoding-Feld (MSH-2) */
    private const ENCODING_CHARS = '^~\\&';

    /** Interne Felder die nicht als OBX exportiert werden */
    private const SKIP_FIELDS = [
        'nachname', 'vorname', 'geburtsdatum', 'geschlecht',
        'strasse', 'plz', 'ort', 'telefon', 'email',
        'anrede', 'titel', 'kasse', 'privat_art',
        'unterschrift', 'unterschrift_datum',
        'datenschutz_einwilligung', 'einwilligung_richtigkeit',
        '_form_id', '_location_uuid', '_submission_id',
    ];

    /* ─── Properties ────────────────────────────────────────── */

    private string $sendingApp      = 'PRAXIS-PORTAL';
    private string $sendingFacility = 'PRAXIS';

    /* ─── Constructor ───────────────────────────────────────── */

    public function __construct(Container $container, string $language = 'de')
    {
        parent::__construct($container, $language);

        // Lizenz-Check
        $featureGate = $container->get('feature_gate');
        if (!$featureGate->hasFeature('hl7_export', $this->getLocationId())) {
            throw new \RuntimeException(
                $this->t('hl7_premium_required', 'HL7 v2 Export ist eine Premium-Funktion.')
            );
        }

        // Praxisname aus Standort-Settings
        $locationMgr = $container->get('location_manager');
        $settings    = $locationMgr->getLocationSettings($this->locationUuid);
        $this->sendingFacility = $settings['practice_name'] ?? get_option('pp_practice_name', 'PRAXIS');
    }

    /* ─── Public API ────────────────────────────────────────── */

    /**
     * Exportiert Formulardaten als HL7 v2.5.1 Nachricht.
     *
     * @param string $messageType 'ADT' oder 'ORU'
     */
    public function export(array $formData, ?object $submission = null, string $messageType = 'ADT'): string
    {
        $segments = [];

        // MSH – Message Header
        $segments[] = $this->buildMsh($messageType);

        // PID – Patient Identification
        $segments[] = $this->buildPid($formData);

        // PV1 – Patient Visit
        $segments[] = $this->buildPv1();

        if ($messageType === 'ORU') {
            // OBR – Observation Request
            $segments[] = $this->buildObr();

            // OBX – Observation Values (alle Formularfelder)
            array_push($segments, ...$this->buildObxSegments($formData));
        } else {
            // DG1 – Diagnosen
            array_push($segments, ...$this->buildDg1Segments($formData));

            // AL1 – Allergien
            array_push($segments, ...$this->buildAl1Segments($formData));
        }

        // HL7 v2: Segmente mit CR trennen
        return implode("\r", $segments) . "\r";
    }

    public function getMimeType(): string
    {
        return 'application/hl7-v2';
    }

    public function getFileExtension(): string
    {
        return 'hl7';
    }

    /**
     * Download-Methode (ISO-8859-1 Ausgabe).
     */
    public function download(array $formData, ?object $submission = null, ?string $filename = null): void
    {
        $hl7 = $this->export($formData, $submission);

        if (!$filename) {
            $name     = sanitize_file_name($formData['nachname'] ?? 'patient');
            $filename = 'anamnese_' . $name . '_' . date('Ymd_His') . '.hl7';
        }

        $encoded = mb_convert_encoding($hl7, 'ISO-8859-1', 'UTF-8');

        header('Content-Type: ' . $this->getMimeType() . '; charset=iso-8859-1');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($encoded));
        echo $encoded;
        exit;
    }

    /* ─── Segment Builders ──────────────────────────────────── */

    /**
     * MSH – Message Header Segment.
     */
    private function buildMsh(string $type = 'ADT'): string
    {
        $trigger = ($type === 'ORU') ? 'R01' : 'A04';
        $ts      = date('YmdHis');
        $msgId   = uniqid('PP');

        return $this->seg([
            'MSH',
            self::ENCODING_CHARS,                         // MSH-2
            $this->sendingApp,                            // MSH-3
            $this->esc($this->sendingFacility),           // MSH-4
            '',                                           // MSH-5 Receiving App
            '',                                           // MSH-6 Receiving Facility
            $ts,                                          // MSH-7
            '',                                           // MSH-8 Security
            $type . self::COMPONENT_SEP . $trigger .      // MSH-9
                self::COMPONENT_SEP . $type . '_' . $trigger,
            $msgId,                                       // MSH-10 Control ID
            'P',                                          // MSH-11 Processing
            self::HL7_VERSION,                            // MSH-12
            '',                                           // MSH-13
            '',                                           // MSH-14
            '',                                           // MSH-15
            '',                                           // MSH-16
            'DE',                                         // MSH-17 Country
            'ISO IR100',                                  // MSH-18 Charset
        ]);
    }

    /**
     * PID – Patient Identification Segment.
     */
    private function buildPid(array $data): string
    {
        $birthdate = $this->formatDateHl7($data['geburtsdatum'] ?? '');
        $sex       = $this->mapGenderHl7($data);

        $nachname = $this->esc($data['nachname'] ?? '');
        $vorname  = $this->esc($data['vorname'] ?? '');
        $strasse  = $this->esc($data['strasse'] ?? '');
        $plz      = $this->esc($data['plz'] ?? '');
        $ort      = $this->esc($data['ort'] ?? '');
        $telefon  = $this->esc($data['telefon'] ?? '');

        // PID-11: Street^^City^^ZIP^Country
        $address = $strasse . self::COMPONENT_SEP . '' .
            self::COMPONENT_SEP . $ort .
            self::COMPONENT_SEP . '' .
            self::COMPONENT_SEP . $plz .
            self::COMPONENT_SEP . 'DE';

        return $this->seg([
            'PID',
            '1',                                                     // PID-1
            '',                                                      // PID-2
            '',                                                      // PID-3
            '',                                                      // PID-4
            $nachname . self::COMPONENT_SEP . $vorname,             // PID-5
            '',                                                      // PID-6
            $birthdate,                                              // PID-7
            $sex,                                                    // PID-8
            '',                                                      // PID-9
            '',                                                      // PID-10
            $address,                                                // PID-11
            '',                                                      // PID-12
            $telefon,                                                // PID-13
        ]);
    }

    /**
     * PV1 – Patient Visit Segment.
     */
    private function buildPv1(): string
    {
        return $this->seg([
            'PV1',
            '1',  // Set ID
            'O',  // O = Outpatient (Ambulant)
        ]);
    }

    /**
     * OBR – Observation Request Segment.
     */
    private function buildObr(): string
    {
        $ts = date('YmdHis');

        return $this->seg([
            'OBR',
            '1',
            '',
            uniqid('PP'),
            'ANAMNESE' . self::COMPONENT_SEP . 'Anamnese' . self::COMPONENT_SEP . 'L',
            '',
            $ts,
            $ts,
        ]);
    }

    /**
     * OBX Segmente – Alle Formularfelder als Observations.
     */
    private function buildObxSegments(array $data): array
    {
        $segments = [];
        $setId    = 1;

        foreach ($data as $key => $value) {
            if (empty($value) || in_array($key, self::SKIP_FIELDS, true)) {
                continue;
            }
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $displayName = ucfirst(str_replace('_', ' ', $key));

            $segments[] = $this->seg([
                'OBX',
                (string)$setId++,
                'ST',
                $key . self::COMPONENT_SEP . $this->esc($displayName) . self::COMPONENT_SEP . 'L',
                '',
                $this->esc((string)$value),
                '', '', '', '', '',
                'F',
            ]);
        }

        return $segments;
    }

    /**
     * DG1 Segmente – Diagnosen.
     */
    private function buildDg1Segments(array $data): array
    {
        $segments = [];
        $setId    = 1;
        $ts       = date('YmdHis');

        // ICD-Codes aus Repository
        $icdRepo = $this->container->has('icd_repository')
            ? $this->container->get('icd_repository')
            : null;

        if ($icdRepo) {
            $formId      = $data['_form_id'] ?? 'augenarzt';
            $zuordnungen = $icdRepo->getAll(true, $formId, $this->locationUuid);

            foreach ($zuordnungen as $z) {
                if (!$this->isJa($data, $z['frage_key'])) {
                    continue;
                }

                $icdCode = $z['icd_code'] ?? '';
                $desc    = $z['bezeichnung'] ?? $z['frage_key'];

                // Spezialfall Diabetes: Typ 1/2 mit bilateraler Retinopathie
                if ($z['frage_key'] === 'diabetes' && !empty($data['diabetes_typ'])) {
                    if ($data['diabetes_typ'] === '1') {
                        // Diabetes Typ 1: 3 ICD-Codes
                        $segments[] = $this->seg(['DG1', (string)$setId++, 'I10', 'E10.30' . self::COMPONENT_SEP . 'Diabetes Typ 1 mit Augenkomplikationen' . self::COMPONENT_SEP . 'I10GM', 'Diabetes Typ 1 mit Augenkomplikationen', $ts, 'W']);
                        $segments[] = $this->seg(['DG1', (string)$setId++, 'I10', 'H36.0' . self::COMPONENT_SEP . 'Diab. Retinopathie rechts' . self::COMPONENT_SEP . 'I10GM', 'Diab. Retinopathie rechts', $ts, 'W']);
                        $segments[] = $this->seg(['DG1', (string)$setId++, 'I10', 'H36.0' . self::COMPONENT_SEP . 'Diab. Retinopathie links' . self::COMPONENT_SEP . 'I10GM', 'Diab. Retinopathie links', $ts, 'W']);
                        continue;
                    } elseif ($data['diabetes_typ'] === '2') {
                        // Diabetes Typ 2: 3 ICD-Codes
                        $segments[] = $this->seg(['DG1', (string)$setId++, 'I10', 'E11.30' . self::COMPONENT_SEP . 'Diabetes Typ 2 mit Augenkomplikationen' . self::COMPONENT_SEP . 'I10GM', 'Diabetes Typ 2 mit Augenkomplikationen', $ts, 'W']);
                        $segments[] = $this->seg(['DG1', (string)$setId++, 'I10', 'H36.0' . self::COMPONENT_SEP . 'Diab. Retinopathie rechts' . self::COMPONENT_SEP . 'I10GM', 'Diab. Retinopathie rechts', $ts, 'W']);
                        $segments[] = $this->seg(['DG1', (string)$setId++, 'I10', 'H36.0' . self::COMPONENT_SEP . 'Diab. Retinopathie links' . self::COMPONENT_SEP . 'I10GM', 'Diab. Retinopathie links', $ts, 'W']);
                        continue;
                    }
                    // Fallback: generischer Code wird unten hinzugefügt
                }

                $segments[] = $this->seg([
                    'DG1',
                    (string)$setId++,
                    'I10',
                    $icdCode . self::COMPONENT_SEP . $this->esc($desc) . self::COMPONENT_SEP . 'I10GM',
                    $this->esc($desc),
                    $ts,
                    'W',
                ]);
            }
        }

        // Fallback: Freitext-Diagnosen
        if (empty($segments)) {
            $rawDiagnosen = [];
            foreach (['diagnosen', 'vorerkrankungen', 'icd_codes'] as $field) {
                if (!empty($data[$field])) {
                    $vals = is_array($data[$field]) ? $data[$field] : [$data[$field]];
                    array_push($rawDiagnosen, ...$vals);
                }
            }

            foreach ($rawDiagnosen as $diag) {
                $icdCode = '';
                $desc    = (string)$diag;

                if (preg_match('/([A-Z]\d{2}\.?\d*)/i', $diag, $m)) {
                    $icdCode = strtoupper($m[1]);
                    $desc    = trim(preg_replace('/^[A-Z]\d{2}\.?\d*\s*[-:]\s*/i', '', $diag));
                }

                $segments[] = $this->seg([
                    'DG1',
                    (string)$setId++,
                    'I10',
                    $icdCode . self::COMPONENT_SEP . $this->esc($desc) . self::COMPONENT_SEP . 'I10GM',
                    $this->esc($desc),
                    $ts,
                    'W',
                ]);
            }
        }

        return $segments;
    }

    /**
     * AL1 Segmente – Allergien.
     */
    private function buildAl1Segments(array $data): array
    {
        $segments  = [];
        $setId     = 1;
        $allergien = [];

        // allergien_welche (Freitext)
        if ($this->isJa($data, 'allergien') && !empty($data['allergien_welche'])) {
            $items = array_map('trim', preg_split('/[,;]/', $data['allergien_welche']));
            array_push($allergien, ...$items);
        }

        // Kontrastmittel
        if ($this->isJa($data, 'kontrastmittel_allergie')) {
            $allergien[] = $this->t('contrast_media', 'Kontrastmittel');
        }

        foreach ($allergien as $allergie) {
            $allergie = trim($allergie);
            if ($allergie === '') continue;

            $segments[] = $this->seg([
                'AL1',
                (string)$setId++,
                'DA',
                $this->esc($allergie),
                '', '', '',
            ]);
        }

        return $segments;
    }

    /* ─── Helpers ────────────────────────────────────────────── */

    /**
     * Baut Segment aus Feld-Array.
     */
    private function seg(array $fields): string
    {
        return implode(self::FIELD_SEP, $fields);
    }

    /**
     * HL7 Escape: Sonderzeichen.
     */
    private function esc(string $value): string
    {
        if ($value === '') return '';

        // Backslash muss ZUERST escaped werden (sonst doppel-Escape)
        $value = str_replace('\\', '\\E\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\.br\\', $value);
        $value = str_replace('|', '\\F\\', $value);
        $value = str_replace('^', '\\S\\', $value);
        $value = str_replace('&', '\\T\\', $value);
        $value = str_replace('~', '\\R\\', $value);

        return $value;
    }

    /**
     * Datum → YYYYMMDD.
     */
    private function formatDateHl7(string $date): string
    {
        if ($date === '') return '';

        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $date, $m)) {
            return $m[3] . $m[2] . $m[1];
        }
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return $m[1] . $m[2] . $m[3];
        }
        return $date;
    }

    /**
     * Geschlecht → HL7-Code (M/F/O).
     */
    private function mapGenderHl7(array $data): string
    {
        $val = strtolower($data['geschlecht'] ?? $data['anrede'] ?? '');

        if (in_array($val, ['maennlich', 'männlich', 'm', 'male', 'herr'], true)) return 'M';
        if (in_array($val, ['weiblich', 'w', 'f', 'female', 'frau'], true))       return 'F';
        if (in_array($val, ['divers', 'd'], true))                                return 'O';

        return '';
    }

    /**
     * Prüft ob Formularfeld "Ja" ist.
     */
    private function isJa(array $data, string $field): bool
    {
        if (empty($data[$field])) return false;
        return in_array(strtolower((string)$data[$field]), ['ja', 'yes', '1', 'true', 'oui'], true);
    }
}
