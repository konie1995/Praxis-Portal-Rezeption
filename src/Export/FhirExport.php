<?php
/**
 * HL7 FHIR R4 Export
 *
 * Konvertiert Anamnese-/Widget-Daten in ein FHIR R4 Bundle (JSON).
 *
 * Unterstützte Ressourcen:
 *  - Patient
 *  - Condition          (ICD-10-GM Diagnosen)
 *  - AllergyIntolerance (Allergien)
 *  - MedicationStatement(Medikamente + Warnungen)
 *  - Observation         (Anamnese, Custom-Fields)
 *
 * @package    PraxisPortal\Export
 * @since      4.0.0
 * @see        https://hl7.org/fhir/R4/
 */

declare(strict_types=1);

namespace PraxisPortal\Export;

if (!defined('ABSPATH')) {
    exit;
}

use PraxisPortal\Core\Container;

class FhirExport extends ExportBase
{
    /* ─── Constants ─────────────────────────────────────────── */

    private const FHIR_VERSION = 'R4';

    /** Coding-Systeme */
    private const CS_ICD10GM       = 'http://fhir.de/CodeSystem/bfarm/icd-10-gm';
    private const CS_SNOMED        = 'http://snomed.info/sct';
    private const CS_LOINC         = 'http://loinc.org';
    private const CS_COND_CLINICAL = 'http://terminology.hl7.org/CodeSystem/condition-clinical';
    private const CS_COND_VERIFY   = 'http://terminology.hl7.org/CodeSystem/condition-ver-status';
    private const CS_ALLERGY_CLIN  = 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical';
    private const CS_ALLERGY_VER   = 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification';
    private const CS_OBS_CAT       = 'http://terminology.hl7.org/CodeSystem/observation-category';

    /** SNOMED Seitencodes */
    private const SNOMED_RIGHT     = '24028007';
    private const SNOMED_LEFT      = '7771000';
    private const SNOMED_BILATERAL = '51440002';

    /* ─── Properties ────────────────────────────────────────── */

    private string $baseUrl = '';

    /* ─── Constructor ───────────────────────────────────────── */

    public function __construct(Container $container, string $language = 'de')
    {
        parent::__construct($container, $language);

        // Lizenz-Check: Premium-Feature
        $featureGate = $container->get('feature_gate');
        if (!$featureGate->hasFeature('fhir_export', $this->getLocationId())) {
            throw new \RuntimeException(
                $this->t('fhir_premium_required', 'HL7 FHIR Export ist eine Premium-Funktion.')
            );
        }

        $this->baseUrl = rtrim(home_url(), '/');
    }

    /* ─── Public API ────────────────────────────────────────── */

    /**
     * Exportiert Formulardaten als FHIR R4 Bundle.
     */
    public function export(array $formData, ?object $submission = null): string
    {
        $patientId = $this->generateUUID();

        $bundle = [
            'resourceType' => 'Bundle',
            'id'           => $this->generateUUID(),
            'meta'         => [
                'lastUpdated' => date('c'),
                'profile'     => ['http://hl7.org/fhir/StructureDefinition/Bundle'],
            ],
            'type'  => 'collection',
            'entry' => [],
        ];

        // 1) Patient
        $bundle['entry'][] = [
            'fullUrl'  => 'urn:uuid:' . $patientId,
            'resource' => $this->createPatient($formData, $patientId),
        ];

        // 2) Conditions (Diagnosen)
        foreach ($this->createConditions($formData, $patientId) as $c) {
            $bundle['entry'][] = [
                'fullUrl'  => 'urn:uuid:' . $c['id'],
                'resource' => $c,
            ];
        }

        // 3) Allergien
        foreach ($this->createAllergies($formData, $patientId) as $a) {
            $bundle['entry'][] = [
                'fullUrl'  => 'urn:uuid:' . $a['id'],
                'resource' => $a,
            ];
        }

        // 4) Medikamente
        foreach ($this->createMedications($formData, $patientId) as $m) {
            $bundle['entry'][] = [
                'fullUrl'  => 'urn:uuid:' . $m['id'],
                'resource' => $m,
            ];
        }

        // 5) Observations (Anamnese + Custom-Fields)
        foreach ($this->createObservations($formData, $patientId) as $o) {
            $bundle['entry'][] = [
                'fullUrl'  => 'urn:uuid:' . $o['id'],
                'resource' => $o,
            ];
        }

        return json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function getMimeType(): string
    {
        return 'application/fhir+json';
    }

    public function getFileExtension(): string
    {
        return 'json';
    }

    /**
     * Download-Methode für direkte Ausgabe.
     */
    public function download(array $formData, ?object $submission = null, ?string $filename = null): void
    {
        $json = $this->export($formData, $submission);

        if (!$filename) {
            $name     = sanitize_file_name($formData['nachname'] ?? 'patient');
            $filename = 'fhir_' . $name . '_' . date('Ymd_His') . '.json';
        }

        header('Content-Type: ' . $this->getMimeType() . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    /* ─── Patient ───────────────────────────────────────────── */

    private function createPatient(array $data, string $id): array
    {
        $patient = [
            'resourceType' => 'Patient',
            'id'           => $id,
            'meta'         => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Patient']],
            'identifier'   => [[
                'system' => $this->baseUrl . '/patient',
                'value'  => 'PP_' . date('YmdHis') . '_' . substr($id, 0, 8),
            ]],
            'active' => true,
            'name'   => [[
                'use'    => 'official',
                'family' => $data['nachname'] ?? '',
                'given'  => [$data['vorname'] ?? ''],
            ]],
        ];

        // Titel
        if (!empty($data['titel'])) {
            $patient['name'][0]['prefix'] = [$data['titel']];
        }

        // Geburtsdatum
        if (!empty($data['geburtsdatum'])) {
            $patient['birthDate'] = $this->formatDateFhir($data['geburtsdatum']);
        }

        // Geschlecht
        $patient['gender'] = $this->mapGenderFhir($data);

        // Adresse
        if (!empty($data['strasse']) || !empty($data['plz']) || !empty($data['ort'])) {
            $patient['address'] = [[
                'use'        => 'home',
                'type'       => 'physical',
                'line'       => !empty($data['strasse']) ? [$data['strasse']] : [],
                'city'       => $data['ort'] ?? '',
                'postalCode' => $data['plz'] ?? '',
                'country'    => 'DE',
            ]];
        }

        // Telecom
        $telecoms = [];
        if (!empty($data['telefon'])) {
            $telecoms[] = ['system' => 'phone', 'value' => $data['telefon'], 'use' => 'mobile'];
        }
        if (!empty($data['email'])) {
            $telecoms[] = ['system' => 'email', 'value' => $data['email'], 'use' => 'home'];
        }
        if ($telecoms) {
            $patient['telecom'] = $telecoms;
        }

        return $patient;
    }

    /* ─── Conditions (Diagnosen) ────────────────────────────── */

    private function createConditions(array $data, string $patientId): array
    {
        $conditions = [];

        // Dynamische ICD-Zuordnungen aus DB (PP_Fragen_ICD)
        $icdRepo = $this->container->has('icd_repository')
            ? $this->container->get('icd_repository')
            : null;

        if ($icdRepo) {
            $formId       = $data['_form_id'] ?? 'augenarzt';
            $zuordnungen  = $icdRepo->getAll(true, $formId, $this->locationUuid);

            foreach ($zuordnungen as $z) {
                $fieldKey = $z['frage_key'];

                if (!$this->isJa($data, $fieldKey)) {
                    continue;
                }

                // Special case: Diabetes mit Typ → 3 ICD-Codes
                if ($fieldKey === 'diabetes' && !empty($data['diabetes_typ'])) {
                    $verificationStatus = $this->codeable(
                        self::CS_COND_VERIFY,
                        $this->mapCertaintyFhir($z['sicherheit'] ?? 'G'),
                        $this->translateCertainty($z['sicherheit'] ?? 'G')
                    );

                    if ($data['diabetes_typ'] === '1') {
                        // E10.30 – Diabetes Typ 1 mit Augenkomplikationen
                        $conditions[] = [
                            'resourceType'       => 'Condition',
                            'id'                 => $this->generateUUID(),
                            'meta'               => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']],
                            'clinicalStatus'     => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                            'verificationStatus' => $verificationStatus,
                            'code'               => ['coding' => [['system' => self::CS_ICD10GM, 'code' => 'E10.30', 'display' => 'Diabetes Typ 1 mit Augenkomplikationen']]],
                            'subject'            => ['reference' => 'urn:uuid:' . $patientId],
                            'recordedDate'       => date('c'),
                        ];
                        // H36.0 – Diab. Retinopathie rechts
                        $conditions[] = [
                            'resourceType'       => 'Condition',
                            'id'                 => $this->generateUUID(),
                            'meta'               => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']],
                            'clinicalStatus'     => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                            'verificationStatus' => $verificationStatus,
                            'code'               => ['coding' => [['system' => self::CS_ICD10GM, 'code' => 'H36.0', 'display' => 'Diab. Retinopathie rechts']]],
                            'subject'            => ['reference' => 'urn:uuid:' . $patientId],
                            'recordedDate'       => date('c'),
                            'bodySite'           => [['coding' => [['system' => self::CS_SNOMED, 'code' => self::SNOMED_RIGHT, 'display' => 'Right']]]],
                        ];
                        // H36.0 – Diab. Retinopathie links
                        $conditions[] = [
                            'resourceType'       => 'Condition',
                            'id'                 => $this->generateUUID(),
                            'meta'               => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']],
                            'clinicalStatus'     => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                            'verificationStatus' => $verificationStatus,
                            'code'               => ['coding' => [['system' => self::CS_ICD10GM, 'code' => 'H36.0', 'display' => 'Diab. Retinopathie links']]],
                            'subject'            => ['reference' => 'urn:uuid:' . $patientId],
                            'recordedDate'       => date('c'),
                            'bodySite'           => [['coding' => [['system' => self::CS_SNOMED, 'code' => self::SNOMED_LEFT, 'display' => 'Left']]]],
                        ];
                        continue;
                    } elseif ($data['diabetes_typ'] === '2') {
                        // E11.30 – Diabetes Typ 2 mit Augenkomplikationen
                        $conditions[] = [
                            'resourceType'       => 'Condition',
                            'id'                 => $this->generateUUID(),
                            'meta'               => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']],
                            'clinicalStatus'     => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                            'verificationStatus' => $verificationStatus,
                            'code'               => ['coding' => [['system' => self::CS_ICD10GM, 'code' => 'E11.30', 'display' => 'Diabetes Typ 2 mit Augenkomplikationen']]],
                            'subject'            => ['reference' => 'urn:uuid:' . $patientId],
                            'recordedDate'       => date('c'),
                        ];
                        // H36.0 – Diab. Retinopathie rechts
                        $conditions[] = [
                            'resourceType'       => 'Condition',
                            'id'                 => $this->generateUUID(),
                            'meta'               => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']],
                            'clinicalStatus'     => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                            'verificationStatus' => $verificationStatus,
                            'code'               => ['coding' => [['system' => self::CS_ICD10GM, 'code' => 'H36.0', 'display' => 'Diab. Retinopathie rechts']]],
                            'subject'            => ['reference' => 'urn:uuid:' . $patientId],
                            'recordedDate'       => date('c'),
                            'bodySite'           => [['coding' => [['system' => self::CS_SNOMED, 'code' => self::SNOMED_RIGHT, 'display' => 'Right']]]],
                        ];
                        // H36.0 – Diab. Retinopathie links
                        $conditions[] = [
                            'resourceType'       => 'Condition',
                            'id'                 => $this->generateUUID(),
                            'meta'               => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']],
                            'clinicalStatus'     => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                            'verificationStatus' => $verificationStatus,
                            'code'               => ['coding' => [['system' => self::CS_ICD10GM, 'code' => 'H36.0', 'display' => 'Diab. Retinopathie links']]],
                            'subject'            => ['reference' => 'urn:uuid:' . $patientId],
                            'recordedDate'       => date('c'),
                            'bodySite'           => [['coding' => [['system' => self::CS_SNOMED, 'code' => self::SNOMED_LEFT, 'display' => 'Left']]]],
                        ];
                        continue;
                    }
                }

                $condition = [
                    'resourceType'       => 'Condition',
                    'id'                 => $this->generateUUID(),
                    'meta'               => ['profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']],
                    'clinicalStatus'     => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                    'verificationStatus' => $this->codeable(
                        self::CS_COND_VERIFY,
                        $this->mapCertaintyFhir($z['sicherheit'] ?? 'G'),
                        $this->translateCertainty($z['sicherheit'] ?? 'G')
                    ),
                    'code'    => ['coding' => [['system' => self::CS_ICD10GM, 'code' => $z['icd_code']]]],
                    'subject' => ['reference' => 'urn:uuid:' . $patientId],
                    'recordedDate' => date('c'),
                ];

                // Seitenlokalisation
                $seite = $this->determineSide($z, $data, $fieldKey);
                if ($seite) {
                    $condition['bodySite'] = [['coding' => [[
                        'system'  => self::CS_SNOMED,
                        'code'    => $this->snomedSideCode($seite),
                        'display' => $this->translateSideLabel($seite),
                    ]]]];
                }

                $conditions[] = $condition;
            }
        }

        // Fallback: bekannte Felder → ICD-Mapping
        if (empty($conditions)) {
            $conditions = $this->fallbackConditions($data, $patientId);
        }

        return $conditions;
    }

    /**
     * Fallback Diagnosen für Felder ohne PP_Fragen_ICD.
     */
    private function fallbackConditions(array $data, string $patientId): array
    {
        $map = [
            'glaukom'            => ['H40.9',  'Glaukom'],
            'katarakt'           => ['H25.9',  'Katarakt'],
            'amd'                => ['H35.3-', 'Makuladegeneration'],
            'netzhautabloesung'  => ['H33.0',  'Netzhautablösung'],
            'keratokonus'        => ['H18.6',  'Keratokonus'],
            'sicca'              => ['H04.1',  'Sicca-Syndrom'],
            'bluthochdruck'      => ['I10',    'Hypertonie'],
            'diabetes'           => ['E11.9',  'Diabetes mellitus'],
            'herzinsuffizienz'   => ['I50.9',  'Herzinsuffizienz'],
            'khk'                => ['I25.9',  'KHK'],
            'herzrhythmus'       => ['I49.9',  'Herzrhythmusstörung'],
            'copd'               => ['J44.9',  'COPD'],
            'asthma'             => ['J45.9',  'Asthma'],
            'autoimmun_rheuma'   => ['M06.9',  'Rheumatoide Arthritis'],
            'autoimmun_crohn'    => ['K50.9',  'Morbus Crohn'],
            'autoimmun_colitis'  => ['K51.9',  'Colitis ulcerosa'],
            'ms'                 => ['G35',    'Multiple Sklerose'],
            'migraene'           => ['G43.9',  'Migräne'],
            'schlaganfall'       => ['I63.9',  'Schlaganfall'],
        ];

        $conditions = [];
        foreach ($map as $field => [$icd, $display]) {
            if (!$this->isJa($data, $field)) {
                continue;
            }

            $condition = [
                'resourceType'   => 'Condition',
                'id'             => $this->generateUUID(),
                'clinicalStatus' => $this->codeable(self::CS_COND_CLINICAL, 'active', 'Active'),
                'code'           => ['coding' => [['system' => self::CS_ICD10GM, 'code' => $icd, 'display' => $display]]],
                'subject'        => ['reference' => 'urn:uuid:' . $patientId],
                'recordedDate'   => date('c'),
            ];

            // Seite
            $seiteField = $field . '_seite';
            if (!empty($data[$seiteField])) {
                $s = strtolower($data[$seiteField]) === 'beidseits'
                    ? 'B'
                    : strtoupper(substr($data[$seiteField], 0, 1));

                $condition['bodySite'] = [['coding' => [[
                    'system'  => self::CS_SNOMED,
                    'code'    => $this->snomedSideCode($s),
                    'display' => $this->translateSideLabel($s),
                ]]]];
            }

            $conditions[] = $condition;
        }

        return $conditions;
    }

    /* ─── Allergies ─────────────────────────────────────────── */

    private function createAllergies(array $data, string $patientId): array
    {
        $allergies = [];

        // Allergien-Freitext (allergien_welche)
        if ($this->isJa($data, 'allergien') && !empty($data['allergien_welche'])) {
            $items = array_map('trim', preg_split('/[,;]/', $data['allergien_welche']));

            foreach ($items as $item) {
                if ($item === '') continue;

                $allergies[] = [
                    'resourceType'       => 'AllergyIntolerance',
                    'id'                 => $this->generateUUID(),
                    'clinicalStatus'     => $this->codeable(self::CS_ALLERGY_CLIN, 'active'),
                    'verificationStatus' => $this->codeable(self::CS_ALLERGY_VER, 'confirmed'),
                    'code'               => ['text' => $item],
                    'patient'            => ['reference' => 'urn:uuid:' . $patientId],
                    'recordedDate'       => date('c'),
                ];
            }
        }

        // Kontrastmittel-Allergie
        if ($this->isJa($data, 'kontrastmittel_allergie')) {
            $allergies[] = [
                'resourceType'       => 'AllergyIntolerance',
                'id'                 => $this->generateUUID(),
                'clinicalStatus'     => $this->codeable(self::CS_ALLERGY_CLIN, 'active'),
                'type'               => 'allergy',
                'category'           => ['medication'],
                'code'               => [
                    'coding' => [['system' => self::CS_SNOMED, 'code' => '39290007', 'display' => 'Contrast media']],
                    'text'   => $this->t('contrast_media', 'Kontrastmittel'),
                ],
                'patient'      => ['reference' => 'urn:uuid:' . $patientId],
                'recordedDate' => date('c'),
            ];
        }

        return $allergies;
    }

    /* ─── Medications ───────────────────────────────────────── */

    private function createMedications(array $data, string $patientId): array
    {
        $medications = [];

        // Strukturierte Medikamente
        $medsRaw = $data['medikamente_strukturiert'] ?? $data['medikamente_strukturiert_parsed'] ?? null;
        if (!empty($medsRaw)) {
            $meds = is_string($medsRaw) ? json_decode($medsRaw, true) : $medsRaw;

            if (is_array($meds)) {
                foreach ($meds as $med) {
                    $medication = [
                        'resourceType'              => 'MedicationStatement',
                        'id'                        => $this->generateUUID(),
                        'status'                    => 'active',
                        'medicationCodeableConcept' => [
                            'text' => is_array($med) ? ($med['name'] ?? 'Unbekannt') : (string)$med,
                        ],
                        'subject'           => ['reference' => 'urn:uuid:' . $patientId],
                        'effectiveDateTime' => date('c'),
                    ];

                    if (is_array($med)) {
                        // Wirkstoff
                        if (!empty($med['wirkstoff'])) {
                            $medication['medicationCodeableConcept']['coding'] = [
                                ['display' => $med['wirkstoff']],
                            ];
                        }
                        // Dosierung
                        $dosageParts = array_filter([
                            $med['staerke']   ?? '',
                            $med['dosierung'] ?? '',
                            $med['hinweis']   ?? '',
                        ]);
                        if ($dosageParts) {
                            $medication['dosage'] = [['text' => implode(' – ', $dosageParts)]];
                        }
                    }

                    $medications[] = $medication;
                }
            }
        }

        // Wichtige Einzelmedikamente (Warnungen)
        $importantMeds = [
            'blutverduenner' => 'Blutverdünner',
            'cortison'       => 'Cortison',
            'chloroquin'     => 'Chloroquin / Hydroxychloroquin',
            'alpha_blocker'  => 'Alpha-Blocker (Prostata)',
            'amiodaron'      => 'Amiodaron',
        ];

        foreach ($importantMeds as $field => $label) {
            if (!$this->isJa($data, $field)) {
                continue;
            }

            $med = [
                'resourceType'              => 'MedicationStatement',
                'id'                        => $this->generateUUID(),
                'status'                    => 'active',
                'medicationCodeableConcept' => ['text' => $label],
                'subject'           => ['reference' => 'urn:uuid:' . $patientId],
                'effectiveDateTime' => date('c'),
                'note'              => [['text' => '⚠ ' . $label]],
            ];

            // Spezifischer Name wenn vorhanden
            $nameField = $field . '_name';
            if (!empty($data[$nameField])) {
                $med['medicationCodeableConcept']['text'] = $data[$nameField];
            }

            // Details (Dosierung/Dauer)
            $detailField = $field . '_details';
            if (!empty($data[$detailField])) {
                $med['dosage'] = [['text' => $data[$detailField]]];
            }

            $medications[] = $med;
        }

        return $medications;
    }

    /* ─── Observations ──────────────────────────────────────── */

    private function createObservations(array $data, string $patientId): array
    {
        $observations = [];

        // Augenanamnese
        $eyeHistory = [];
        if ($this->isJa($data, 'brille')) {
            $eyeHistory[] = $this->t('glasses', 'Brille') . ': ' . $this->t('yes', 'Ja');
        }
        if ($this->isJa($data, 'kontaktlinsen')) {
            $eyeHistory[] = $this->t('contact_lenses', 'Kontaktlinsen') . ': ' . $this->t('yes', 'Ja');
        }

        if ($eyeHistory) {
            $observations[] = [
                'resourceType' => 'Observation',
                'id'           => $this->generateUUID(),
                'status'       => 'final',
                'category'     => [['coding' => [['system' => self::CS_OBS_CAT, 'code' => 'exam', 'display' => 'Exam']]]],
                'code'         => [
                    'coding' => [['system' => self::CS_LOINC, 'code' => '11450-4', 'display' => 'Problem list']],
                    'text'   => $this->t('eye_history', 'Augenanamnese'),
                ],
                'subject'           => ['reference' => 'urn:uuid:' . $patientId],
                'effectiveDateTime' => date('c'),
                'valueString'       => implode('; ', $eyeHistory),
            ];
        }

        // Schwangerschaft
        if ($this->isJa($data, 'schwanger')) {
            $observations[] = [
                'resourceType' => 'Observation',
                'id'           => $this->generateUUID(),
                'status'       => 'final',
                'code'         => [
                    'coding' => [['system' => self::CS_LOINC, 'code' => '82810-3', 'display' => 'Pregnancy status']],
                    'text'   => $this->t('pregnant_nursing', 'Schwanger / Stillend'),
                ],
                'subject'              => ['reference' => 'urn:uuid:' . $patientId],
                'effectiveDateTime'    => date('c'),
                'valueCodeableConcept' => [
                    'coding' => [['system' => self::CS_SNOMED, 'code' => '77386006', 'display' => 'Pregnant']],
                ],
            ];
        }

        // Custom-Fields als Observations
        $this->addCustomFieldObservations($data, $patientId, $observations);

        return $observations;
    }

    /**
     * Fügt Custom-Fields als Observations hinzu.
     */
    private function addCustomFieldObservations(array $data, string $patientId, array &$observations): void
    {
        $formConfigRepo = $this->container->has('form_config_repository')
            ? $this->container->get('form_config_repository')
            : null;

        if (!$formConfigRepo) {
            return;
        }

        $formId       = $data['_form_id'] ?? 'augenarzt';
        $customFields = $formConfigRepo->getCustomFields($formId, $this->locationUuid);

        foreach ($customFields as $cf) {
            $fieldId = $cf['id'];
            if (!isset($data[$fieldId]) || $data[$fieldId] === '') {
                continue;
            }

            $value     = $data[$fieldId];
            $label     = $cf['label'] ?? $fieldId;
            $loincCode = $cf['loinc_code'] ?? '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $obs = [
                'resourceType'      => 'Observation',
                'id'                => $this->generateUUID(),
                'status'            => 'final',
                'category'          => [['coding' => [['system' => self::CS_OBS_CAT, 'code' => 'survey', 'display' => 'Survey']]]],
                'code'              => ['text' => $label],
                'subject'           => ['reference' => 'urn:uuid:' . $patientId],
                'effectiveDateTime' => date('c'),
            ];

            // LOINC-Code
            if (!empty($loincCode)) {
                $obs['code']['coding'] = [['system' => self::CS_LOINC, 'code' => $loincCode, 'display' => $label]];
            } else {
                $obs['code']['coding'] = [[
                    'system'  => $this->baseUrl . '/fhir/custom-fields',
                    'code'    => $fieldId,
                    'display' => $label,
                ]];
            }

            // Wert-Typ erkennen
            $lowerVal = strtolower((string)$value);
            if (in_array($lowerVal, ['ja', 'yes', 'oui', 'sì'], true)) {
                $obs['valueBoolean'] = true;
            } elseif (in_array($lowerVal, ['nein', 'no', 'non', 'nee'], true)) {
                $obs['valueBoolean'] = false;
            } elseif (is_numeric($value)) {
                $obs['valueQuantity'] = ['value' => (float)$value];
            } else {
                $obs['valueString'] = $value;
            }

            $observations[] = $obs;
        }
    }

    /* ─── Helpers ────────────────────────────────────────────── */

    /**
     * Erstellt ein CodeableConcept.
     */
    private function codeable(string $system, string $code, string $display = ''): array
    {
        $coding = ['system' => $system, 'code' => $code];
        if ($display !== '') {
            $coding['display'] = $display;
        }
        return ['coding' => [$coding]];
    }

    /**
     * Mappt Diagnosesicherheit → FHIR VerificationStatus.
     */
    private function mapCertaintyFhir(string $certainty): string
    {
        return match (strtoupper($certainty)) {
            'G'     => 'confirmed',
            'V'     => 'provisional',
            'Z'     => 'confirmed',   // Zustand nach = bestätigt (historisch)
            'A'     => 'refuted',
            default => 'unconfirmed',
        };
    }

    /**
     * Übersetzt Diagnosesicherheit als Display-String.
     */
    private function translateCertainty(string $certainty): string
    {
        return match (strtoupper($certainty)) {
            'G'     => 'Confirmed',
            'V'     => 'Provisional',
            'Z'     => 'Confirmed (historic)',
            'A'     => 'Refuted',
            default => 'Unconfirmed',
        };
    }

    /**
     * Bestimmt Seitenlokalisation aus ICD-Zuordnung + Formulardaten.
     */
    private function determineSide(array $zuordnung, array $data, string $fieldKey): ?string
    {
        if (!empty($zuordnung['seite_override'])) {
            return strtoupper($zuordnung['seite_override']);
        }

        if (!empty($zuordnung['seite_aus_formular'])) {
            $seiteField = $fieldKey . '_seite';
            if (!empty($data[$seiteField])) {
                $val = strtolower($data[$seiteField]);
                if ($val === 'beidseits') return 'B';
                return strtoupper(substr($val, 0, 1));
            }
        }

        return null;
    }

    /**
     * SNOMED-Code für Seitenlokalisation.
     */
    private function snomedSideCode(string $side): string
    {
        return match (strtoupper($side)) {
            'R'     => self::SNOMED_RIGHT,
            'L'     => self::SNOMED_LEFT,
            'B'     => self::SNOMED_BILATERAL,
            default => self::SNOMED_BILATERAL,
        };
    }

    /**
     * Seitenlokalisation → lesbarer Label.
     */
    private function translateSideLabel(string $side): string
    {
        return match (strtoupper($side)) {
            'R'     => 'Right',
            'L'     => 'Left',
            'B'     => 'Bilateral',
            default => 'Bilateral',
        };
    }

    /**
     * Mappt Geschlecht für FHIR.
     */
    private function mapGenderFhir(array $data): string
    {
        $map = [
            'maennlich' => 'male',
            'männlich'  => 'male',
            'weiblich'  => 'female',
            'divers'    => 'other',
            'herr'      => 'male',
            'frau'      => 'female',
        ];

        if (!empty($data['geschlecht'])) {
            return $map[strtolower($data['geschlecht'])] ?? 'unknown';
        }
        if (!empty($data['anrede'])) {
            return $map[strtolower($data['anrede'])] ?? 'unknown';
        }
        return 'unknown';
    }

    /**
     * Datum → FHIR-Format (YYYY-MM-DD).
     */
    private function formatDateFhir(string $date): string
    {
        // DD.MM.YYYY
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // YYYY-MM-DD (bereits korrekt)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        return date('Y-m-d', strtotime($date));
    }

    /**
     * Generiert UUID v4.
     */
    private function generateUUID(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Prüft ob Formularfeld "Ja" ist.
     */
    private function isJa(array $data, string $field): bool
    {
        if (empty($data[$field])) return false;
        return in_array(strtolower((string)$data[$field]), ['ja', 'yes', '1', 'true', 'oui', 'sì'], true);
    }
}
