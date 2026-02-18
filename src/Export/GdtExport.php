<?php
/**
 * BDT/GDT 3.0 Export
 *
 * Konvertiert Formulardaten in BDT 3.0 Release 0.0 Format
 * für deutsche PVS-Systeme (Medistar, Albis, Turbomed, etc.).
 *
 * Zeichensatz: ISO-8859-15 (Code '3')
 * Zeilenende:  CR+LF
 * Feldformat:  [3-stellige Länge][4-stelliger FK][Inhalt][CRLF]
 * Max. Feldlänge: 999 Byte
 *
 * Segmente:
 *   0001 – Kommunikations-Header
 *   0020 – Datei-Header
 *   6100 – Patienten-Stammdaten
 *   6200 – Behandlungsdaten (Anamnese, Diagnosen, Medikamente)
 *   0021 – Datei-Abschluss
 *   0002 – Kommunikations-Abschluss
 *
 * @package PraxisPortal\Export
 * @since   4.0.0
 */

namespace PraxisPortal\Export;

use PraxisPortal\Core\Container;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\Location\LocationManager;
use PraxisPortal\Database\Repository\SubmissionRepository;

if (!defined('ABSPATH')) {
    exit;
}

class GdtExport extends ExportBase
{
    // =========================================================================
    // KONSTANTEN – BDT 3.0 Spezifikation
    // =========================================================================

    /** BDT-Version */
    private const BDT_VERSION = 'BDT 3.0 Release 0.0';

    /** Zeichensatz-Code (3 = ISO-8859-15) */
    private const CHARSET_CODE = '3';

    /** Zeilenende */
    private const CRLF = "\r\n";

    /** Max. Feldlänge in Bytes */
    private const MAX_FIELD_LENGTH = 999;

    // ── Satzarten ──────────────────────────────────────────────────────
    private const SA_KOMM_HEADER   = '0001';
    private const SA_DATEI_HEADER  = '0020';
    private const SA_STAMMDATEN    = '6100';
    private const SA_BEHANDLUNG    = '6200';
    private const SA_DATEI_ENDE    = '0021';
    private const SA_KOMM_ENDE     = '0002';

    // ── Feldkennungen (FK) ─────────────────────────────────────────────
    // Header
    private const FK_SATZART       = '8000';
    private const FK_SATZLAENGE    = '8100';
    private const FK_SATZVERSION   = '9218';
    private const FK_ZEICHENSATZ   = '9206';
    private const FK_SOFTWARENAME  = '0102';
    private const FK_SOFTWAREVERS  = '0103';
    private const FK_TIMESTAMP     = '9103';
    private const FK_GUID          = '0105';
    private const FK_DATEIFORMAT   = '0001';

    // Stammdaten
    private const FK_NACHNAME      = '3101';
    private const FK_VORNAME       = '3102';
    private const FK_GEBURTSDATUM  = '3103';
    private const FK_GESCHLECHT    = '3110';
    private const FK_STRASSE       = '3107';
    private const FK_PLZ           = '3112';
    private const FK_ORT           = '3113';
    private const FK_TELEFON       = '3120';
    private const FK_EMAIL         = '3122';
    private const FK_TITEL         = '3104';
    private const FK_VERSICHERUNG  = '4104';
    private const FK_VNR           = '4111';

    // Anamnese
    private const FK_AUGENANAMNESE = '3662';
    private const FK_FAM_ANAMNESE  = '3663';
    private const FK_ALLG_ANAMNESE = '3664';

    // Diagnosen
    private const FK_DIAGNOSE      = '6001';
    private const FK_DIAG_SICHER   = '6003';
    private const FK_DIAG_SEITE    = '6205';

    // Medikamente
    private const FK_HANDELSNAME   = '6208';
    private const FK_WIRKSTOFF     = '6212';
    private const FK_DOSIERUNG     = '3686';
    private const FK_HINWEIS       = '3688';

    // Befund / Freitext
    private const FK_BEFUNDTEXT    = '6220';
    private const FK_KOMMENTAR     = '6213';

    // =========================================================================
    // ABHÄNGIGKEITEN
    // =========================================================================

    private FeatureGate $featureGate;
    private SubmissionRepository $submissionRepo;

    /** Gesammelte BDT-Zeilen */
    private array $lines = [];

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(Container $container, string $language = 'de')
    {
        parent::__construct($container, $language);

        $this->featureGate    = $container->get(FeatureGate::class);
        $this->submissionRepo = $container->get(SubmissionRepository::class);
    }

    // =========================================================================
    // HAUPTEXPORT
    // =========================================================================

    /**
     * Exportiert Formulardaten als BDT 3.0 Datei.
     *
     * @param array       $formData   Entschlüsselte Formulardaten
     * @param object|null $submission Einreichungs-Objekt (für Metadaten)
     * @return string BDT-Dateiinhalt (ISO-8859-15)
     */
    public function export(array $formData, ?object $submission = null): string
    {
        $this->lines = [];

        // Standort-Kontext
        $locationUuid = $formData['_location_uuid'] ?? '';
        $praxis = $this->getPraxisInfo($locationUuid);

        // 1. Kommunikations-Header (SA 0001)
        $this->buildKommHeader($praxis);

        // 2. Datei-Header (SA 0020)
        $this->buildDateiHeader();

        // 3. Stammdaten (SA 6100)
        $this->buildStammdaten($formData);

        // 4. Behandlungsdaten (SA 6200)
        $this->buildBehandlung($formData, $submission);

        // 5. Datei-Abschluss (SA 0021)
        $this->buildSegment(self::SA_DATEI_ENDE);

        // 6. Kommunikations-Abschluss (SA 0002)
        $this->buildSegment(self::SA_KOMM_ENDE);

        // Zu ISO-8859-15 konvertieren
        $output = implode('', $this->lines);
        return mb_convert_encoding($output, 'ISO-8859-15', 'UTF-8');
    }

    /** @inheritDoc */
    public function getMimeType(): string
    {
        return 'application/x-bdt';
    }

    /** @inheritDoc */
    public function getFileExtension(): string
    {
        return 'bdt';
    }

    // =========================================================================
    // BDT-ZEILENBAU
    // =========================================================================

    /**
     * Fügt eine BDT-Zeile hinzu: [Länge 3-stellig][FK 4-stellig][Inhalt]CRLF
     *
     * @param string $fk    4-stellige Feldkennung
     * @param string $value Feldinhalt (UTF-8, wird beim Output konvertiert)
     */
    private function addField(string $fk, string $value): void
    {
        if ($value === '' || $value === null) {
            return;
        }

        // Mehrzeilige Werte: Jede Zeile als eigenes Feld
        $lines = explode("\n", str_replace("\r\n", "\n", $value));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Länge berechnen: 3 (Länge) + 4 (FK) + strlen(Inhalt) + 2 (CRLF)
            $contentBytes = strlen(mb_convert_encoding($line, 'ISO-8859-15', 'UTF-8'));
            $totalLength  = 3 + 4 + $contentBytes + 2;

            if ($totalLength > self::MAX_FIELD_LENGTH) {
                // Abschneiden wenn zu lang
                $maxContent = self::MAX_FIELD_LENGTH - 9; // 3+4+2
                $line       = mb_substr($line, 0, $maxContent);
                $totalLength = self::MAX_FIELD_LENGTH;
            }

            $this->lines[] = sprintf('%03d', $totalLength) . $fk . $line . self::CRLF;
        }
    }

    /**
     * Baut ein minimales Segment (nur Satzart-Feld).
     */
    private function buildSegment(string $satzart): void
    {
        $this->addField(self::FK_SATZART, $satzart);
    }

    /**
     * Formatiert ein Datum von verschiedenen Formaten zu TTMMJJJJ (BDT).
     */
    private function formatDateBdt(string $date): string
    {
        // YYYY-MM-DD → DDMMYYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $m[3] . $m[2] . $m[1];
        }
        // DD.MM.YYYY → DDMMYYYY
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
            return $m[1] . $m[2] . $m[3];
        }
        // Bereits DDMMYYYY
        if (preg_match('/^\d{8}$/', $date)) {
            return $date;
        }
        return '';
    }

    /**
     * Geschlecht → BDT-Code (1=männlich, 2=weiblich, 3=divers)
     */
    private function mapGender(string $value): string
    {
        $v = strtolower(trim($value));
        if (in_array($v, ['männlich', 'maennlich', 'm', 'male', 'herr'], true)) {
            return '1';
        }
        if (in_array($v, ['weiblich', 'w', 'f', 'female', 'frau'], true)) {
            return '2';
        }
        return '3'; // divers / unbekannt
    }

    // =========================================================================
    // SEGMENT-BUILDER: HEADER
    // =========================================================================

    /**
     * SA 0001 – Kommunikations-Header
     */
    private function buildKommHeader(array $praxis): void
    {
        $this->addField(self::FK_SATZART, self::SA_KOMM_HEADER);
        $this->addField(self::FK_SATZVERSION, self::BDT_VERSION);
        $this->addField(self::FK_ZEICHENSATZ, self::CHARSET_CODE);
        $this->addField(self::FK_SOFTWARENAME, 'Praxis-Portal');
        $this->addField(self::FK_SOFTWAREVERS, PP_VERSION ?? '4.0.0');
        $this->addField(self::FK_TIMESTAMP, date('dmYHis'));
        $this->addField(self::FK_GUID, wp_generate_uuid4());
    }

    /**
     * SA 0020 – Datei-Header
     */
    private function buildDateiHeader(): void
    {
        $this->addField(self::FK_SATZART, self::SA_DATEI_HEADER);
        $this->addField(self::FK_DATEIFORMAT, 'BDT');
        $this->addField(self::FK_TIMESTAMP, date('dmYHis'));
    }

    // =========================================================================
    // SEGMENT-BUILDER: STAMMDATEN (SA 6100)
    // =========================================================================

    /**
     * SA 6100 – Patienten-Stammdaten
     */
    private function buildStammdaten(array $data): void
    {
        $this->addField(self::FK_SATZART, self::SA_STAMMDATEN);

        // Pflichtfelder
        $this->addField(self::FK_NACHNAME, $data['nachname'] ?? '');
        $this->addField(self::FK_VORNAME, $data['vorname'] ?? '');

        if (!empty($data['titel'])) {
            $this->addField(self::FK_TITEL, $data['titel']);
        }

        if (!empty($data['geburtsdatum'])) {
            $this->addField(self::FK_GEBURTSDATUM, $this->formatDateBdt($data['geburtsdatum']));
        }

        if (!empty($data['geschlecht'])) {
            $this->addField(self::FK_GESCHLECHT, $this->mapGender($data['geschlecht']));
        } elseif (!empty($data['anrede'])) {
            $this->addField(self::FK_GESCHLECHT, $this->mapGender($data['anrede']));
        }

        // Adresse
        $this->addField(self::FK_STRASSE, $data['strasse'] ?? '');
        $this->addField(self::FK_PLZ, $data['plz'] ?? '');
        $this->addField(self::FK_ORT, $data['ort'] ?? '');

        // Kontakt
        $this->addField(self::FK_TELEFON, $data['telefon'] ?? '');
        $this->addField(self::FK_EMAIL, $data['email'] ?? '');

        // Versicherung
        if (!empty($data['kasse'])) {
            $kasseTxt = ($data['kasse'] === 'privat') ? 'Privat versichert' : 'Gesetzlich versichert';
            $this->addField(self::FK_VERSICHERUNG, $kasseTxt);
        }
    }

    // =========================================================================
    // SEGMENT-BUILDER: BEHANDLUNG (SA 6200)
    // =========================================================================

    /**
     * SA 6200 – Behandlungsdaten: Anamnese + Diagnosen + Medikamente
     */
    private function buildBehandlung(array $data, ?object $submission): void
    {
        $this->addField(self::FK_SATZART, self::SA_BEHANDLUNG);

        // Anamnese: 3 Freitext-Felder
        $this->exportAugenanamnese($data);
        $this->exportFamilienanamnese($data);
        $this->exportAllgemeinanamnese($data);

        // Diagnosen
        $this->exportDiagnosen($data);

        // Medikamente
        $this->exportMedikamente($data);

        // Allergien als Befundtext
        $this->exportAllergien($data);

        // Widget-Service-Daten
        $this->exportWidgetData($data);

        // Custom Fields
        $this->exportCustomFields($data);
    }

    // =========================================================================
    // ANAMNESE EXPORT (FK 3662, 3663, 3664)
    // =========================================================================

    /**
     * FK 3662 – Augenanamnese
     */
    private function exportAugenanamnese(array $data): void
    {
        $parts = [];

        // Besuchsgrund
        if (!empty($data['beschwerde'])) {
            $parts[] = $this->t('visit_reason', 'Besuchsgrund') . ': ' . $data['beschwerde'];
        }
        if (!empty($data['beschwerde_dauer'])) {
            $parts[] = $this->t('duration', 'Dauer') . ': ' . $data['beschwerde_dauer'];
        }
        if (!empty($data['beschwerde_seite'])) {
            $parts[] = $this->t('affected_eye', 'Betroffenes Auge') . ': '
                . $this->translateSideLabel($data['beschwerde_seite']);
        }

        // Brille
        if ($this->isJa($data, 'brille')) {
            $brilleText = $this->t('glasses', 'Brille') . ': ' . $this->t('yes', 'Ja');
            if (!empty($data['brille_art'])) {
                $arten = $this->parseCheckboxGroup($data['brille_art']);
                if (!empty($arten)) {
                    $brilleText .= ' (' . implode(', ', $arten) . ')';
                }
            }
            $parts[] = $brilleText;
        }

        // Letzter Augenarztbesuch
        if (!empty($data['letzter_augenarzt'])) {
            $parts[] = $this->t('last_visit', 'Letzter Augenarztbesuch') . ': ' . $data['letzter_augenarzt'];
        }

        // Augen-Vorerkrankungen
        $vorerkrankungen = [
            'glaukom'            => 'Glaukom',
            'katarakt'           => 'Katarakt',
            'schielen'           => 'Schielen',
            'laser'              => 'Laser-OP',
            'netzhautabloesung'  => 'Netzhautablösung',
            'amd'                => 'Makuladegeneration',
            'keratokonus'        => 'Keratokonus',
            'keratoplastik'      => 'Keratoplastik',
            'sicca'              => 'Sicca-Syndrom',
        ];

        foreach ($vorerkrankungen as $field => $label) {
            if ($this->isJa($data, $field)) {
                $txt = $label . ': ' . $this->t('yes', 'Ja');
                // Seite anhängen wenn vorhanden
                $seiteField = $field . '_seite';
                if (!empty($data[$seiteField])) {
                    $txt .= ', ' . $this->translateSideLabel($data[$seiteField]);
                }
                $parts[] = $txt;
            }
        }

        // Glaukom-Details
        if ($this->isJa($data, 'glaukom')) {
            if ($this->isJa($data, 'glaukom_tropfen') && !empty($data['glaukom_tropfen_welche'])) {
                $parts[] = '  Tropfen: ' . $data['glaukom_tropfen_welche'];
            }
            if ($this->isJa($data, 'glaukom_op')) {
                $parts[] = '  Glaukom-OP: Ja';
            }
        }

        // Katarakt-Details
        if ($this->isJa($data, 'katarakt') && $this->isJa($data, 'katarakt_op')) {
            $txt = '  Katarakt-OP: Ja';
            if (!empty($data['katarakt_op_seite'])) {
                $txt .= ', ' . $this->translateSideLabel($data['katarakt_op_seite']);
            }
            $parts[] = $txt;
        }

        // AMD-Details
        if ($this->isJa($data, 'amd') && !empty($data['amd_art'])) {
            $parts[] = '  AMD-Art: ' . ucfirst($data['amd_art']);
        }

        // Kontaktlinsen
        if ($this->isJa($data, 'kontaktlinsen')) {
            $klText = $this->t('contact_lenses', 'Kontaktlinsen') . ': ' . $this->t('yes', 'Ja');
            if (!empty($data['kontaktlinsen_art'])) {
                $klText .= ' (' . $data['kontaktlinsen_art'] . ')';
            }
            if (!empty($data['kontaktlinsen_jahre'])) {
                $klText .= ', seit ' . $data['kontaktlinsen_jahre'] . ' Jahren';
            }
            $parts[] = $klText;
        }

        if (!empty($parts)) {
            $this->addField(self::FK_AUGENANAMNESE, implode("\n", $parts));
        }
    }

    /**
     * FK 3663 – Familienanamnese
     */
    private function exportFamilienanamnese(array $data): void
    {
        $parts = [];

        if ($this->isJa($data, 'familie_augen')) {
            $arten = $this->parseCheckboxGroup($data['familie_augen_art'] ?? '');
            $artLabels = [
                'glaukom'            => 'Glaukom',
                'makuladegeneration' => 'Makuladegeneration',
                'netzhautabloesung'  => 'Netzhautablösung',
                'erblindung'         => 'Erblindung',
            ];
            $artTexte = [];
            foreach ($arten as $a) {
                $artTexte[] = $artLabels[$a] ?? $a;
            }
            $parts[] = $this->t('eye_diseases_family', 'Augenerkrankungen in Familie')
                . ': ' . (empty($artTexte) ? 'Ja' : implode(', ', $artTexte));

            if (!empty($data['familie_andere'])) {
                $parts[] = '  Andere: ' . $data['familie_andere'];
            }
        }

        // Weitere Familienanamnese
        $familieFields = [
            'familie_ms'     => 'Multiple Sklerose',
            'familie_darm'   => 'Chronisch entzündliche Darmerkrankung',
            'familie_rheuma' => 'Rheuma',
            'familie_krebs'  => 'Krebs',
        ];

        foreach ($familieFields as $field => $label) {
            if ($this->isJa($data, $field)) {
                $txt = $label . ': ' . $this->t('yes', 'Ja');
                if ($field === 'familie_krebs' && !empty($data['familie_krebs_art'])) {
                    $txt .= ' (' . $data['familie_krebs_art'] . ')';
                }
                $parts[] = $txt;
            }
        }

        if (!empty($parts)) {
            $this->addField(self::FK_FAM_ANAMNESE, implode("\n", $parts));
        }
    }

    /**
     * FK 3664 – Allgemeinanamnese
     */
    private function exportAllgemeinanamnese(array $data): void
    {
        $parts = [];

        // ── Herz-Kreislauf ──
        $herzFields = [
            'bluthochdruck'   => 'Bluthochdruck',
            'herzinsuffizienz' => 'Herzinsuffizienz',
            'khk'             => 'Koronare Herzkrankheit',
            'herzrhythmus'    => 'Herzrhythmusstörungen',
            'schrittmacher'   => 'Herzschrittmacher',
        ];
        foreach ($herzFields as $field => $label) {
            if ($this->isJa($data, $field)) {
                $parts[] = $label . ': Ja';
            }
        }

        // Herzinfarkt mit Zeitangabe
        if ($this->isJa($data, 'herzinfarkt')) {
            $txt = 'Herzinfarkt: Ja';
            if (!empty($data['herzinfarkt_wann'])) {
                $txt .= ' (' . $data['herzinfarkt_wann'] . ')';
            }
            $parts[] = $txt;
        }

        // Thrombose / Lungenembolie
        foreach (['thrombose' => 'Tiefe Beinvenenthrombose', 'lungenembolie' => 'Lungenembolie'] as $f => $l) {
            if ($this->isJa($data, $f)) {
                $txt = $l . ': Ja';
                if (!empty($data[$f . '_wann'])) {
                    $txt .= ' (' . $data[$f . '_wann'] . ')';
                }
                $parts[] = $txt;
            }
        }

        // ── Lunge ──
        if ($this->isJa($data, 'rauchen')) {
            $parts[] = 'Raucher: Ja';
        }
        foreach (['bronchitis' => 'Chron. Bronchitis', 'copd' => 'COPD', 'asthma' => 'Asthma'] as $f => $l) {
            if ($this->isJa($data, $f)) {
                $parts[] = $l . ': Ja';
            }
        }

        // ── Diabetes ──
        if ($this->isJa($data, 'diabetes')) {
            $txt = 'Diabetes';
            if (!empty($data['diabetes_typ'])) {
                $txt .= ' Typ ' . $data['diabetes_typ'];
            }
            $txt .= ': Ja';
            if (!empty($data['diabetes_hba1c'])) {
                $txt .= ', HbA1c: ' . $data['diabetes_hba1c'];
            }
            if ($this->isJa($data, 'diabetes_dmp')) {
                $txt .= ', DMP: Ja';
            }
            $parts[] = $txt;
        }

        // ── Schilddrüse ──
        if ($this->isJa($data, 'schilddruese')) {
            $arten = $this->parseCheckboxGroup($data['schilddruese_art'] ?? '');
            $txt = 'Schilddrüse: Ja';
            if (!empty($arten)) {
                $txt .= ' (' . implode(', ', $arten) . ')';
            }
            $parts[] = $txt;
        }

        // ── Niere ──
        if ($this->isJa($data, 'nieren')) {
            $arten = $this->parseCheckboxGroup($data['nieren_art'] ?? '');
            $txt = 'Nierenerkrankung: Ja';
            if (!empty($arten)) {
                $txt .= ' (' . implode(', ', $arten) . ')';
            }
            $parts[] = $txt;
        }

        // ── Krebs ──
        if ($this->isJa($data, 'krebs')) {
            $txt = 'Krebserkrankung: Ja';
            if (!empty($data['krebs_welcher'])) {
                $txt .= ' (' . $data['krebs_welcher'] . ')';
            }
            if (!empty($data['krebs_seit'])) {
                $txt .= ', seit ' . $data['krebs_seit'];
            }
            $parts[] = $txt;
            if ($this->isJa($data, 'krebs_chemo')) {
                $parts[] = '  Chemotherapie: Ja';
            }
            if ($this->isJa($data, 'krebs_tamoxifen')) {
                $parts[] = '  Tamoxifen: Ja'
                    . (!empty($data['tamoxifen_details']) ? ' (' . $data['tamoxifen_details'] . ')' : '');
            }
        }

        // ── Autoimmun ──
        $autoFields = [
            'autoimmun_rheuma'       => 'Rheuma',
            'autoimmun_neurodermitis' => 'Neurodermitis',
            'autoimmun_psoriasis'    => 'Psoriasis',
            'autoimmun_rosacea'      => 'Rosacea',
            'autoimmun_lupus'        => 'Lupus erythematodes',
            'autoimmun_crohn'        => 'Morbus Crohn',
            'autoimmun_colitis'      => 'Colitis ulcerosa',
            'autoimmun_sjogren'      => 'Sjögren-Syndrom',
        ];
        foreach ($autoFields as $f => $l) {
            if ($this->isJa($data, $f)) {
                $parts[] = $l . ': Ja';
            }
        }
        if (!empty($data['autoimmun_andere'])) {
            $parts[] = 'Andere Autoimmun: ' . $data['autoimmun_andere'];
        }

        // ── Blutgerinnung, Schlaganfall, Neurologie ──
        if ($this->isJa($data, 'blutgerinnung')) {
            $txt = 'Blutgerinnungsstörung: Ja';
            if (!empty($data['blutgerinnung_welche'])) {
                $txt .= ' (' . $data['blutgerinnung_welche'] . ')';
            }
            $parts[] = $txt;
        }
        if ($this->isJa($data, 'schlaganfall')) {
            $txt = 'Schlaganfall: Ja';
            if (!empty($data['schlaganfall_wann'])) {
                $txt .= ' (' . $data['schlaganfall_wann'] . ')';
            }
            $parts[] = $txt;
        }
        if ($this->isJa($data, 'nerven_leiden') && !empty($data['nerven_leiden_welche'])) {
            $parts[] = 'Psych. Erkrankung: ' . $data['nerven_leiden_welche'];
        }
        foreach (['ms' => 'Multiple Sklerose', 'migraene' => 'Migräne', 'myasthenia' => 'Myasthenia gravis'] as $f => $l) {
            if ($this->isJa($data, $f)) {
                $parts[] = $l . ': Ja';
            }
        }

        // ── Infektionen ──
        foreach (['hepatitis_b' => 'Hepatitis B', 'hepatitis_c' => 'Hepatitis C', 'hiv' => 'HIV/AIDS', 'tuberkulose' => 'Tuberkulose'] as $f => $l) {
            if ($this->isJa($data, $f)) {
                $parts[] = $l . ': Ja';
            }
        }
        if (!empty($data['infektionen_andere'])) {
            $parts[] = 'Andere Infektionen: ' . $data['infektionen_andere'];
        }

        if (!empty($parts)) {
            $this->addField(self::FK_ALLG_ANAMNESE, implode("\n", $parts));
        }
    }

    // =========================================================================
    // DIAGNOSEN EXPORT (FK 6001)
    // =========================================================================

    /**
     * Exportiert ICD-10 Diagnosen.
     *
     * Format: ICD-Code,Seite,Sicherheit
     * Seite: R=rechts, L=links, B=beidseits
     * Sicherheit: G=gesichert, V=Verdacht, Z=Zustand nach, A=ausgeschlossen
     */
    private function exportDiagnosen(array $data): void
    {
        $diagnosen = [];

        // ── ICD-Zuordnungen aus Repository laden (Multistandort-Säule) ──
        $icdRepo = $this->container->has('icd_repository')
            ? $this->container->get('icd_repository')
            : null;

        $formId      = $data['_form_id'] ?? 'augenarzt';
        $zuordnungen = $icdRepo
            ? $icdRepo->getAll(true, $formId, $this->locationUuid)
            : [];

        if (!empty($zuordnungen)) {
            // ── Repository-basierte Zuordnungen ──
            foreach ($zuordnungen as $z) {
                $frageKey = $z['frage_key'];
                if (!$this->isJa($data, $frageKey)) {
                    continue;
                }

                $icdCode    = $z['icd_code'] ?? '';
                $sicherheit = $z['sicherheit'] ?? 'G';
                $seite      = '';

                if (!empty($z['seite_field']) && !empty($data[$z['seite_field']])) {
                    $seite = $this->mapSideCode($data[$z['seite_field']]);
                }

                // Spezialfall AMD: trocken/feucht differenzieren
                if ($frageKey === 'amd' && !empty($data['amd_art'])) {
                    $icdCode = ($data['amd_art'] === 'feucht') ? 'H35.31' : 'H35.30';
                }

                // Spezialfall Diabetes: Typ 1/2 mit bilateraler Retinopathie
                if ($frageKey === 'diabetes' && !empty($data['diabetes_typ'])) {
                    if ($data['diabetes_typ'] === '1') {
                        // Diabetes Typ 1: Hauptcode + bilaterale Retinopathie
                        $diagnosen[] = 'E10.30,,' . $sicherheit;  // Mit Augenkomplikationen
                        $diagnosen[] = 'H36.0,R,' . $sicherheit;  // Diab. Retinopathie rechts
                        $diagnosen[] = 'H36.0,L,' . $sicherheit;  // Diab. Retinopathie links
                        continue; // Überspringen, da bereits hinzugefügt
                    } elseif ($data['diabetes_typ'] === '2') {
                        // Diabetes Typ 2: Hauptcode + bilaterale Retinopathie
                        $diagnosen[] = 'E11.30,,' . $sicherheit;  // Mit Augenkomplikationen
                        $diagnosen[] = 'H36.0,R,' . $sicherheit;  // Diab. Retinopathie rechts
                        $diagnosen[] = 'H36.0,L,' . $sicherheit;  // Diab. Retinopathie links
                        continue; // Überspringen, da bereits hinzugefügt
                    } else {
                        // Fallback: Typ unbekannt, generischer Code
                        $icdCode = 'E11.3-';
                    }
                }

                $diagnosen[] = $icdCode . ',' . $seite . ',' . $sicherheit;
            }
        } else {
            // ── Fallback: Hardcoded Zuordnungen (Abwärtskompatibilität) ──
            $icdMap = [
                'glaukom'            => ['H40.9', 'glaukom_seite'],
                'katarakt'           => ['H25.9', 'katarakt_seite'],
                'keratokonus'        => ['H18.6', 'keratokonus_seite'],
                'netzhautabloesung'  => ['H33.0', 'netzhaut_seite'],
                'sicca'              => ['H04.1', null],
                'bluthochdruck'      => ['I10', null],
                'herzinsuffizienz'   => ['I50.9', null],
                'khk'                => ['I25.9', null],
                'copd'               => ['J44.9', null],
                'asthma'             => ['J45.9', null],
                'ms'                 => ['G35', null],
                'autoimmun_rheuma'   => ['M06.9', null],
                'autoimmun_crohn'    => ['K50.9', null],
                'autoimmun_colitis'  => ['K51.9', null],
                'autoimmun_sjogren'  => ['M35.0', null],
                'autoimmun_lupus'    => ['M32.9', null],
            ];

            foreach ($icdMap as $field => $mapping) {
                if (!$this->isJa($data, $field)) {
                    continue;
                }
                [$icd, $seiteField] = $mapping;
                $seite = '';
                if ($seiteField && !empty($data[$seiteField])) {
                    $seite = $this->mapSideCode($data[$seiteField]);
                }
                $diagnosen[] = $icd . ',' . $seite . ',G';
            }

            // Spezialfall: AMD (trocken/feucht)
            if ($this->isJa($data, 'amd')) {
                $icd = ($data['amd_art'] ?? 'trocken') === 'feucht' ? 'H35.31' : 'H35.30';
                $seite = !empty($data['amd_seite']) ? $this->mapSideCode($data['amd_seite']) : '';
                $diagnosen[] = $icd . ',' . $seite . ',G';
            }

            // Spezialfall: Diabetes (Typ 1/2)
            if ($this->isJa($data, 'diabetes')) {
                $icd = ($data['diabetes_typ'] ?? '2') === '1' ? 'E10.3-' : 'E11.3-';
                $diagnosen[] = $icd . ',,G';
            }
        }

        // Duplikate entfernen
        $diagnosen = array_unique($diagnosen);

        foreach ($diagnosen as $diag) {
            $this->addField(self::FK_DIAGNOSE, $diag);
        }
    }

    // =========================================================================
    // MEDIKAMENTE EXPORT (FK 6208, 6212, 3686, 3688)
    // =========================================================================

    /**
     * Exportiert Medikamente aus strukturierter Liste.
     */
    private function exportMedikamente(array $data): void
    {
        if (!$this->isJa($data, 'medikamente')) {
            return;
        }

        // Strukturierte Medikamente (JSON)
        $medsRaw = $data['medikamente_strukturiert'] ?? $data['medikamente_strukturiert_parsed'] ?? null;
        if (!empty($medsRaw)) {
            $meds = is_string($medsRaw) ? json_decode($medsRaw, true) : $medsRaw;
            if (is_array($meds)) {
                foreach ($meds as $med) {
                    if (is_array($med)) {
                        $name = ($med['name'] ?? '') . (!empty($med['staerke']) ? ' ' . $med['staerke'] : '');
                        $this->addField(self::FK_HANDELSNAME, trim($name));
                        $this->addField(self::FK_WIRKSTOFF, $med['wirkstoff'] ?? '');
                        $this->addField(self::FK_DOSIERUNG, $med['dosierung'] ?? '');
                        $this->addField(self::FK_HINWEIS, $med['hinweis'] ?? '');
                    } else {
                        $this->addField(self::FK_HANDELSNAME, (string) $med);
                    }
                }
            }
        }

        // Wichtige Einzelmedikamente als Warnungen
        if ($this->isJa($data, 'blutverduenner')) {
            $this->addField(self::FK_HINWEIS, '⚠ ' . $this->t('blood_thinner', 'Blutverdünner'));
        }
        if ($this->isJa($data, 'cortison')) {
            $txt = '⚠ ' . $this->t('cortisone_regular', 'Regelmäßig Cortison');
            if (!empty($data['cortison_details'])) {
                $txt .= ': ' . $data['cortison_details'];
            }
            $this->addField(self::FK_HINWEIS, $txt);
        }
        if ($this->isJa($data, 'chloroquin')) {
            $txt = '⚠ Chloroquin/Hydroxychloroquin';
            if (!empty($data['chloroquin_details'])) {
                $txt .= ': ' . $data['chloroquin_details'];
            }
            $this->addField(self::FK_HINWEIS, $txt);
        }
        if ($this->isJa($data, 'alpha_blocker')) {
            $txt = '⚠ ' . $this->t('ifis_risk', 'IFIS-Risiko (Prostata-Medikament)');
            if (!empty($data['alpha_blocker_name'])) {
                $txt .= ': ' . $data['alpha_blocker_name'];
            }
            $this->addField(self::FK_HINWEIS, $txt);
        }
        if ($this->isJa($data, 'amiodaron')) {
            $this->addField(self::FK_HINWEIS, '⚠ Amiodaron (Hornhautablagerungen möglich)');
        }
    }

    // =========================================================================
    // ALLERGIEN EXPORT
    // =========================================================================

    private function exportAllergien(array $data): void
    {
        if (!$this->isJa($data, 'allergien')) {
            return;
        }

        $allergieTxt = $this->t('allergies', 'Allergien') . ': '
            . ($data['allergien_welche'] ?? 'Ja, Details unbekannt');
        $this->addField(self::FK_BEFUNDTEXT, $allergieTxt);

        if (!empty($data['schwanger']) && $data['schwanger'] === 'ja') {
            $this->addField(self::FK_BEFUNDTEXT, '⚠ ' . $this->t('pregnant_nursing', 'Schwanger/Stillend'));
        }
    }

    // =========================================================================
    // WIDGET-DATEN EXPORT
    // =========================================================================

    private function exportWidgetData(array $data): void
    {
        $serviceType = $data['service_type'] ?? '';
        if (empty($serviceType)) {
            return;
        }

        $serviceLabels = [
            'rezept'              => '💊 Rezept-Anfrage',
            'ueberweisung'        => '📋 Überweisung',
            'brillenverordnung'   => '👓 Brillenverordnung',
            'termin'              => '📅 Terminwunsch',
            'terminabsage'        => '❌ Terminabsage',
            'dokument'            => '📄 Dokument',
        ];

        $label = $serviceLabels[$serviceType] ?? 'Service: ' . $serviceType;
        $this->addField(self::FK_BEFUNDTEXT, $label);

        // Service-spezifische Felder als Befundtext (60-Zeichen-Umbruch)
        $serviceFields = $this->collectWidgetFields($data, $serviceType);
        foreach ($serviceFields as $line) {
            $this->addField(self::FK_BEFUNDTEXT, $this->wrapLine($line, 60));
        }
    }

    /**
     * Sammelt widget-spezifische Felder als Text-Zeilen.
     */
    private function collectWidgetFields(array $data, string $type): array
    {
        $lines = [];

        switch ($type) {
            case 'rezept':
                if (!empty($data['rezept_medikamente'])) {
                    $lines[] = 'Medikamente: ' . $data['rezept_medikamente'];
                }
                if (!empty($data['rezept_lieferung'])) {
                    $lines[] = 'Lieferung: ' . $data['rezept_lieferung'];
                }
                break;

            case 'ueberweisung':
                if (!empty($data['ueberweisung_fachrichtung'])) {
                    $lines[] = 'Fachrichtung: ' . $data['ueberweisung_fachrichtung'];
                }
                if (!empty($data['ueberweisung_grund'])) {
                    $lines[] = 'Grund: ' . $data['ueberweisung_grund'];
                }
                break;

            case 'brillenverordnung':
                if (!empty($data['brillenverordnung_art'])) {
                    $lines[] = 'Brillenart: ' . $data['brillenverordnung_art'];
                }
                // Refraktionswerte
                foreach (['r' => 'Rechts', 'l' => 'Links'] as $side => $label) {
                    $sph = $data["refraktion_{$side}_sph"] ?? '';
                    $cyl = $data["refraktion_{$side}_cyl"] ?? '';
                    $ach = $data["refraktion_{$side}_ach"] ?? '';
                    $add = $data["refraktion_{$side}_add"] ?? '';
                    if ($sph || $cyl || $ach || $add) {
                        $lines[] = "{$label}: Sph {$sph} Cyl {$cyl} Ach {$ach} Add {$add}";
                    }
                }
                break;

            case 'termin':
                if (!empty($data['termin_grund'])) {
                    $lines[] = 'Anliegen: ' . $data['termin_grund'];
                }
                if (!empty($data['termin_wunsch'])) {
                    $lines[] = 'Wunschtermin: ' . $data['termin_wunsch'];
                }
                break;

            case 'terminabsage':
                if (!empty($data['terminabsage_datum'])) {
                    $lines[] = 'Datum: ' . $data['terminabsage_datum'];
                }
                if (!empty($data['terminabsage_grund'])) {
                    $lines[] = 'Grund: ' . $data['terminabsage_grund'];
                }
                break;
        }

        // Allgemeine Anmerkungen
        if (!empty($data['anmerkungen'])) {
            $lines[] = 'Anmerkungen: ' . $data['anmerkungen'];
        }

        return $lines;
    }

    // =========================================================================
    // CUSTOM FIELDS EXPORT
    // =========================================================================

    private function exportCustomFields(array $data): void
    {
        $formId = $data['_form_id'] ?? 'augenarzt';
        $customFields = get_option('pp_custom_fields_' . $formId, []);

        foreach ($customFields as $cf) {
            $fieldId = $cf['id'] ?? '';
            if (empty($fieldId) || !isset($data[$fieldId]) || $data[$fieldId] === '') {
                continue;
            }

            $value = $data[$fieldId];
            $label = $cf['label'] ?? $fieldId;

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            // BDT-Code vorhanden? → Eigenes Feld
            $bdtCode = $cf['bdt_code'] ?? '';
            if (!empty($bdtCode) && preg_match('/^\d{4}$/', $bdtCode)) {
                $this->addField($bdtCode, $label . ': ' . $value);
            } else {
                // Fallback: Kommentarfeld
                $this->addField(self::FK_KOMMENTAR, $label . ': ' . $value);
            }
        }
    }

    // =========================================================================
    // HILFSMETHODEN
    // =========================================================================

    /**
     * Prüft ob ein Feld mit "Ja" beantwortet wurde.
     */
    private function isJa(array $data, string $field): bool
    {
        if (empty($data[$field])) {
            return false;
        }
        return in_array(strtolower($data[$field]), ['ja', 'yes', 'oui', '1', 'true', 'sì'], true);
    }

    /**
     * Parst eine Checkbox-Group (Array oder komma-/semikolonseparierter String).
     *
     * @return string[]
     */
    private function parseCheckboxGroup($value): array
    {
        if (is_array($value)) {
            return array_filter($value);
        }
        if (is_string($value) && $value !== '') {
            return array_filter(array_map('trim', preg_split('/[,;]/', $value)));
        }
        return [];
    }

    /**
     * Mappt Seiten-Wert auf BDT-Code (R/L/B).
     */
    private function mapSideCode(string $value): string
    {
        $v = strtolower(trim($value));
        if (in_array($v, ['rechts', 'r', 'right'], true)) {
            return 'R';
        }
        if (in_array($v, ['links', 'l', 'left'], true)) {
            return 'L';
        }
        if (in_array($v, ['beidseits', 'b', 'both', 'bilateral'], true)) {
            return 'B';
        }
        return '';
    }

    /**
     * Übersetzt Seiten-Wert in lesbaren Text.
     */
    private function translateSideLabel(string $value): string
    {
        $code = $this->mapSideCode($value);
        $labels = [
            'R' => $this->translateSide('R'),
            'L' => $this->translateSide('L'),
            'B' => $this->translateSide('B'),
        ];
        return $labels[$code] ?? $value;
    }

    /**
     * Bricht eine Zeile bei maxLen Zeichen um (für BDT-Befundtext).
     */
    private function wrapLine(string $text, int $maxLen = 60): string
    {
        return wordwrap($text, $maxLen, "\n", true);
    }

    /**
     * Download-Methode für direkte Ausgabe.
     */
    public function download(array $formData, ?object $submission = null, ?string $filename = null): void
    {
        $bdt = $this->export($formData, $submission);

        if (!$filename) {
            $name = sanitize_file_name($formData['nachname'] ?? 'patient');
            $filename = 'anamnese_' . $name . '_' . date('Ymd_His') . '.bdt';
        }

        header('Content-Type: ' . $this->getMimeType() . '; charset=iso-8859-15');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bdt));
        echo $bdt;
        exit;
    }
}
