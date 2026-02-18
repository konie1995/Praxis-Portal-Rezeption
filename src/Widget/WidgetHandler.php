<?php
/**
 * Widget AJAX Handler (v4)
 * 
 * Verarbeitet Service-Anfragen, Datei-Uploads und Medikamentensuche.
 * 
 * v4-Änderungen:
 * - DI statt direkte Klasseninstanzierung
 * - Repositories statt PP_Database
 * - v4 Encryption (AES-256-GCM)
 * - v4 RateLimiter + Sanitizer
 * - location_uuid statt location_id
 * - Consent-Versionierung integriert
 * - PVS-Export-Hook statt direktem Aufruf
 * 
 * @package    PraxisPortal\Widget
 * @since      4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Widget;

use PraxisPortal\Core\Container;
use PraxisPortal\Security\Encryption;
use PraxisPortal\Security\Sanitizer;
use PraxisPortal\Security\RateLimiter;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Database\Repository\MedicationRepository;
use PraxisPortal\Location\LocationContext;
use PraxisPortal\Location\LocationManager;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class WidgetHandler
{
    /** @var Container */
    private Container $container;

    /** @var Encryption */
    private Encryption $encryption;

    /** @var Sanitizer */
    private Sanitizer $sanitizer;

    /** @var RateLimiter */
    private RateLimiter $rateLimiter;

    /** @var SubmissionRepository */
    private SubmissionRepository $submissionRepo;

    /** @var FileRepository */
    private FileRepository $fileRepo;

    /** @var AuditRepository */
    private AuditRepository $auditRepo;

    /** @var MedicationRepository */
    private MedicationRepository $medicationRepo;

    /** @var LocationContext */
    private LocationContext $locationContext;

    /** @var LocationManager */
    private LocationManager $locationManager;

    /** @var FeatureGate */
    private FeatureGate $featureGate;

    /** @var I18n */
    private I18n $i18n;

    /** @var array Erlaubte Service-Typen */
    private const VALID_SERVICE_TYPES = [
        'rezept',
        'ueberweisung',
        'brillenverordnung',
        'dokument',
        'termin',
        'terminabsage',
    ];

    /**
     * Services die IMMER im Free-Plan verfügbar sind (kein Feature-Gate).
     * Diese dürfen NICHT hinter eine Lizenzprüfung gestellt werden.
     *
     * Aktuell: Alle Basis-Services sind free. Nur das monatliche
     * Anfrage-Limit (50/Monat) gilt für den Free-Plan.
     */
    private const FREE_SERVICES = [
        'rezept',
        'ueberweisung',
        'brillenverordnung',
        'dokument',
        'termin',
        'terminabsage',
    ];

    /** @var array Erlaubte MIME-Types für Uploads */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    /** @var int Max. Upload-Größe (10 MB) */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** @var int Monatliches Anfrage-Limit im Free-Plan */
    private const FREE_MONTHLY_LIMIT = 50;

    /** @var int Max. Medikamente pro Rezept */
    private const MAX_MEDICATIONS = 3;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(Container $container)
    {
        $this->container       = $container;
        $this->encryption      = $container->get(Encryption::class);
        $this->sanitizer       = $container->get(Sanitizer::class);
        $this->rateLimiter     = $container->get(RateLimiter::class);
        $this->submissionRepo  = $container->get(SubmissionRepository::class);
        $this->fileRepo        = $container->get(FileRepository::class);
        $this->auditRepo       = $container->get(AuditRepository::class);
        $this->medicationRepo  = $container->get(MedicationRepository::class);
        $this->locationContext = $container->get(LocationContext::class);
        $this->locationManager = $container->get(LocationManager::class);
        $this->featureGate     = $container->get(FeatureGate::class);
        $this->i18n            = $container->get(I18n::class);
    }

    // =========================================================================
    // SERVICE-ANFRAGE
    // =========================================================================

    /**
     * Service-Anfrage verarbeiten
     * 
     * AJAX: pp_submit_service_request
     * 
     * Ablauf:
     * 1. Nonce-Prüfung
     * 2. Spam-Schutz (Rate-Limit, Honeypot, Zeitstempel)
     * 3. DSGVO-Einwilligung
     * 4. Validierung (Pflichtfelder + Service-spezifisch)
     * 5. Daten sammeln + verschlüsseln
     * 6. Speichern (Submission + Dateien + Audit)
     * 7. E-Mail-Benachrichtigung
     * 8. PVS-Export-Hook
     * 9. Referenznummer zurückgeben
     */
    public function handleServiceRequest(): void
    {
        // ── 1. Nonce ──
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'pp_widget_nonce')) {
            wp_send_json_error([
                'message' => $this->i18n->t('Sicherheitsüberprüfung fehlgeschlagen. Bitte laden Sie die Seite neu.'),
            ]);
        }

        // ── 2. Spam-Schutz ──
        $spamResult = $this->checkSpamProtection();
        if ($spamResult !== true) {
            if ($spamResult === 'honeypot') {
                // Bot: Fake-Erfolg zurückgeben
                wp_send_json_success([
                    'message'   => $this->i18n->t('Anfrage gesendet.'),
                    'reference' => 'REF' . wp_rand(100000, 999999),
                ]);
            }
            wp_send_json_error(['message' => $spamResult]);
        }

        // ── 3. DSGVO ──
        if (empty($_POST['dsgvo_consent'])) {
            wp_send_json_error([
                'message' => $this->i18n->t('Bitte stimmen Sie der Datenschutzerklärung zu.'),
            ]);
        }

        // ── 3b. Monatliches Anfrage-Limit (Free-Plan: 50/Monat) ──
        if (!$this->featureGate->can('unlimited_submissions')) {
            $monthlyCount = $this->getMonthlySubmissionCount();
            if ($monthlyCount >= self::FREE_MONTHLY_LIMIT) {
                wp_send_json_error([
                    'message' => $this->i18n->t('Das monatliche Anfrage-Limit wurde erreicht. Bitte kontaktieren Sie die Praxis telefonisch oder upgraden Sie auf einen Premium-Plan.'),
                ]);
            }
        }

        // ── 4. Service-Typ validieren ──
        // Fallback: Templates + widget.js senden 'service', nicht 'service_type'
        $serviceType = sanitize_text_field($_POST['service_type'] ?? $_POST['service'] ?? '');
        if (!in_array($serviceType, self::VALID_SERVICE_TYPES, true)) {
            wp_send_json_error([
                'message' => $this->i18n->t('Ungültiger Service-Typ.'),
            ]);
        }

        // ── 5. Pflichtfelder validieren ──
        $validationError = $this->validateRequiredFields();
        if ($validationError !== null) {
            wp_send_json_error(['message' => $validationError]);
        }

        // ── 6. Location auflösen ──
        $locationUuid = sanitize_text_field($_POST['location_uuid'] ?? '');
        if (empty($locationUuid)) {
            $locationUuid = $this->locationContext->getLocationUuid();
        }

        // Location validieren
        $location = $this->locationManager->getByUuid($locationUuid);
        if (!$location) {
            wp_send_json_error([
                'message' => $this->i18n->t('Ungültiger Standort.'),
            ]);
        }

        // ── 7. Geburtsdatum validieren ──
        $geburtsdatum = $this->validateAndFormatBirthdate();
        if ($geburtsdatum === null) {
            wp_send_json_error([
                'message' => $this->i18n->t('Bitte geben Sie ein gültiges Geburtsdatum ein.'),
            ]);
        }

        // ── 8. E-Mail validieren ──
        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error([
                'message' => $this->i18n->t('Bitte geben Sie eine gültige E-Mail-Adresse an.'),
            ]);
        }

        // ── 9. Patientenstatus ──
        $patientStatus = 'bestandspatient';
        if (!empty($_POST['patient_status'])) {
            $patientStatus = in_array($_POST['patient_status'], ['bestandspatient', 'neupatient'], true)
                ? sanitize_text_field($_POST['patient_status'])
                : 'bestandspatient';
        }

        // ── 10. Formulardaten sammeln ──
        $formData = [
            'service_type'      => $serviceType,
            'location_uuid'     => $locationUuid,
            'patient_status'    => $patientStatus,
            'vorname'           => $this->sanitizer->sanitizeText($_POST['vorname'] ?? ''),
            'nachname'          => $this->sanitizer->sanitizeText($_POST['nachname'] ?? ''),
            'geburtsdatum'      => $geburtsdatum,
            'telefon'           => $this->sanitizer->sanitizePhone($_POST['telefon'] ?? ''),
            'email'             => $email,
            'versicherung'      => sanitize_text_field($_POST['versicherung'] ?? ''),
            'anmerkungen'       => sanitize_textarea_field($_POST['anmerkungen'] ?? ''),
            'dsgvo_consent'     => true,
            'consent_timestamp' => current_time('mysql'),
            'submitted_at'      => current_time('mysql'),
        ];

        // ── 11. Service-spezifische Felder ──
        $formData = $this->processServiceSpecificFields($formData, $serviceType);
        if (is_wp_error($formData)) {
            wp_send_json_error(['message' => $formData->get_error_message()]);
        }

        // ── 12. Hochgeladene Dateien ──
        $uploadedFiles = $this->collectUploadedFiles();
        if (!empty($uploadedFiles)) {
            $formData['uploaded_files'] = $uploadedFiles;
        }

        // ── 13. Verschlüsseln + Speichern ──
        try {
            $result = $this->submissionRepo->create(
                $formData,
                [
                    'location_id'  => (int) ($location['id'] ?? 0),
                    'service_key'  => $serviceType,
                    'request_type' => 'widget_' . $serviceType,
                ]
            );

            $submissionId = $result['success'] ? ($result['id'] ?? null) : null;
        } catch (\Exception $e) {
            error_log('PP4 Widget Save Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $this->i18n->t('Ein technischer Fehler ist aufgetreten. Bitte kontaktieren Sie die Praxis telefonisch.'),
            ]);
        }

        if (!$submissionId) {
            wp_send_json_error([
                'message' => $this->i18n->t('Fehler beim Speichern. Bitte versuchen Sie es erneut.'),
            ]);
        }

        // ── 14. Dateien verknüpfen ──
        if (!empty($formData['uploaded_files'])) {
            foreach ($formData['uploaded_files'] as $field => $fileInfo) {
                if (!empty($fileInfo['file_id'])) {
                    try {
                        $this->fileRepo->linkToSubmission(
                            (int) $submissionId,
                            sanitize_text_field($fileInfo['file_id']),
                            sanitize_text_field($field),
                            sanitize_text_field($fileInfo['mime_type'] ?? 'application/octet-stream'),
                            intval($fileInfo['size'] ?? 0)
                        );
                    } catch (\Exception $e) {
                        error_log('PP4 Widget file link error: ' . $e->getMessage());
                    }
                }
            }
        }

        // ── 15. Audit-Log ──
        $this->auditRepo->logSubmission('widget_service_submitted', (int) $submissionId, [
            'service'       => $serviceType,
            'location_uuid' => $locationUuid,
        ]);

        // ── 16. E-Mail ──
        $this->sendNotificationEmail((int) $submissionId, $formData, $location);

        // ── 17. PVS-Export-Hook ──
        do_action('pp_submission_created', $submissionId, $formData, $serviceType, $locationUuid);

        // ── 17b. Monatszähler-Cache invalidieren ──
        delete_transient('pp_monthly_subs_' . gmdate('Y_m'));

        // ── 18. Referenznummer ──
        $submission = $this->submissionRepo->findById((int) $submissionId);
        $reference  = strtoupper(substr($submission['submission_hash'] ?? bin2hex(random_bytes(4)), 0, 8));

        wp_send_json_success([
            'message'   => $this->i18n->t('Ihre Anfrage wurde erfolgreich übermittelt.'),
            'reference' => $reference,
        ]);
    }

    // =========================================================================
    // SPAM-SCHUTZ
    // =========================================================================

    /**
     * 3-stufiger Spam-Schutz
     *
     * @return true|string true = OK, 'honeypot' = Bot erkannt, string = Fehlermeldung
     */
    private function checkSpamProtection()
    {
        // 1. Rate Limiting (IP wird intern vom RateLimiter ermittelt)
        $result = $this->rateLimiter->attempt('widget_submit', 10, 300);
        if (!$result['allowed']) {
            $this->auditRepo->logSecurity(
                'widget_submit_rate_limited',
                [
                    'ip_hash' => hash('sha256', $this->getClientIp() . wp_salt()),
                    'attempts' => $result['current'],
                    'limit' => $result['limit'],
                ]
            );
            return sprintf(
                $this->i18n->t('Zu viele Anfragen. Bitte warten Sie %d Sekunden.'),
                $result['retry_after']
            );
        }

        // 2. Honeypot (unsichtbare Felder)
        if (!empty($_POST['website_url']) || !empty($_POST['email_confirm'])) {
            $this->rateLimiter->increment('widget_submit', 300);
            return 'honeypot';
        }

        // 3. Zeitstempel-Prüfung (zu schnell ausgefüllt = Bot)
        $formToken = sanitize_text_field($_POST['form_token'] ?? '');
        $minTime   = intval(get_option('pp_min_form_time', 5));

        if (!empty($formToken)) {
            $decoded = base64_decode($formToken, true);
            if ($decoded !== false) {
                $parts = explode('_', $decoded);
                if (count($parts) === 2) {
                    $tokenTime = intval($parts[0]);
                    $timeDiff  = time() - $tokenTime;

                    if ($timeDiff < $minTime) {
                        return $this->i18n->t('Bitte nehmen Sie sich etwas mehr Zeit zum Ausfüllen des Formulars.');
                    }
                }
            }
        }

        return true;
    }

    // =========================================================================
    // VALIDIERUNG
    // =========================================================================

    /**
     * Pflichtfelder validieren
     * 
     * @return string|null Fehlermeldung oder null wenn OK
     */
    private function validateRequiredFields(): ?string
    {
        $required = [
            'vorname', 'nachname',
            'geburtsdatum_tag', 'geburtsdatum_monat', 'geburtsdatum_jahr',
            'telefon', 'email', 'versicherung',
        ];

        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                return $this->i18n->t('Bitte füllen Sie alle Pflichtfelder aus.');
            }
        }

        // Versicherung validieren
        $versicherung = sanitize_text_field($_POST['versicherung'] ?? '');
        if (!in_array($versicherung, ['gesetzlich', 'privat'], true)) {
            return $this->i18n->t('Bitte wählen Sie eine Versicherungsart.');
        }

        return null;
    }

    /**
     * Geburtsdatum validieren und formatieren
     * 
     * @return string|null TT.MM.JJJJ oder null bei Fehler
     */
    private function validateAndFormatBirthdate(): ?string
    {
        $tag   = intval($_POST['geburtsdatum_tag'] ?? 0);
        $monat = intval($_POST['geburtsdatum_monat'] ?? 0);
        $jahr  = intval($_POST['geburtsdatum_jahr'] ?? 0);

        if ($tag < 1 || $tag > 31 || $monat < 1 || $monat > 12 || $jahr < 1900 || $jahr > (int) date('Y')) {
            return null;
        }

        if (!checkdate($monat, $tag, $jahr)) {
            return null;
        }

        // Nicht in der Zukunft
        $birthDate = sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
        if ($birthDate > date('Y-m-d')) {
            return null;
        }

        return sprintf('%02d.%02d.%04d', $tag, $monat, $jahr);
    }

    // =========================================================================
    // SERVICE-SPEZIFISCHE FELDER
    // =========================================================================

    /**
     * Service-spezifische Felder verarbeiten
     * 
     * @param array  $formData    Grunddaten
     * @param string $serviceType Service-Typ
     * @return array|\WP_Error Ergänzte Daten oder Fehler
     */
    private function processServiceSpecificFields(array $formData, string $serviceType)
    {
        switch ($serviceType) {
            case 'rezept':
                return $this->processRezeptFields($formData);
            case 'ueberweisung':
                return $this->processUeberweisungFields($formData);
            case 'brillenverordnung':
                return $this->processBrilleFields($formData);
            case 'dokument':
                return $this->processDokumentFields($formData);
            case 'termin':
                return $this->processTerminFields($formData);
            case 'terminabsage':
                return $this->processTerminabsageFields($formData);
            default:
                return $formData;
        }
    }

    /**
     * Rezept-Felder: Medikamente (max 3), Art, Lieferung, EVN
     */
    private function processRezeptFields(array $formData): array|\WP_Error
    {
        $medikamente    = [];
        $medikamentArten = [];
        $validArten     = ['augentropfen', 'augensalbe', 'tabletten', 'sonstiges'];

        if (!empty($_POST['medikamente']) && is_array($_POST['medikamente'])) {
            $rawMeds  = array_map('sanitize_text_field', $_POST['medikamente']);
            $rawArten = isset($_POST['medikament_art']) && is_array($_POST['medikament_art'])
                ? array_map('sanitize_text_field', $_POST['medikament_art'])
                : [];

            foreach ($rawMeds as $index => $name) {
                $name = trim($name);
                if (!empty($name)) {
                    $art = isset($rawArten[$index]) && in_array($rawArten[$index], $validArten, true)
                        ? $rawArten[$index]
                        : 'sonstiges';
                    $medikamente[]     = $name;
                    $medikamentArten[] = $art;
                }
            }

            $medikamente     = array_slice($medikamente, 0, self::MAX_MEDICATIONS);
            $medikamentArten = array_slice($medikamentArten, 0, self::MAX_MEDICATIONS);
        }

        if (empty($medikamente)) {
            return new \WP_Error(
                'missing_medication',
                $this->i18n->t('Bitte geben Sie mindestens ein Medikament an.')
            );
        }

        $formData['medikamente']      = $medikamente;
        $formData['medikament_arten'] = $medikamentArten;

        // Lieferung: Privatversicherte → praxis|post, Gesetzlich → EVN-Checkbox
        if ($formData['versicherung'] === 'privat') {
            $formData['rezept_lieferung'] = in_array($_POST['rezept_lieferung'] ?? '', ['praxis', 'post'], true)
                ? sanitize_text_field($_POST['rezept_lieferung'])
                : 'praxis';

            if ($formData['rezept_lieferung'] === 'post') {
                $addressError = $this->validateShippingAddress('versand');
                if ($addressError !== null) {
                    return $addressError;
                }
                $formData['versandadresse'] = $this->collectShippingAddress('versand');
            }
        } else {
            // Gesetzlich versichert → kein physisches Rezept, EVN-Checkbox (v3-Stil)
            $formData['rezept_lieferung'] = 'praxis';
            $formData['evn_erlaubt']      = !empty($_POST['evn_erlaubt']) ? '1' : '0';
        }

        return $formData;
    }

    /**
     * Überweisungs-Felder: Diagnose, Ziel, EVN
     */
    private function processUeberweisungFields(array $formData): array|\WP_Error
    {
        if (empty($_POST['diagnose']) || empty($_POST['ueberweisungsziel'])) {
            return new \WP_Error(
                'missing_fields',
                $this->i18n->t('Bitte füllen Sie Diagnose und Überweisungsziel aus.')
            );
        }

        $formData['diagnose']           = sanitize_textarea_field($_POST['diagnose'] ?? '');
        $formData['ueberweisungsziel']  = sanitize_text_field($_POST['ueberweisungsziel'] ?? '');
        $formData['abholung']           = 'praxis';

        if ($formData['versicherung'] === 'gesetzlich') {
            $formData['ueberweisung_evn_erlaubt'] = !empty($_POST['ueberweisung_evn_erlaubt']) ? '1' : '0';
        }

        return $formData;
    }

    /**
     * Brillenverordnungs-Felder: Art, Refraktion, Prismen, HSA, Lieferung
     */
    private function processBrilleFields(array $formData): array|\WP_Error
    {
        // Brillenart (Pflicht)
        $brillenart = [];
        if (!empty($_POST['brillenart']) && is_array($_POST['brillenart'])) {
            $brillenart = array_map('sanitize_text_field', $_POST['brillenart']);
        }
        if (empty($brillenart)) {
            return new \WP_Error(
                'missing_brillenart',
                $this->i18n->t('Bitte wählen Sie mindestens eine Brillenart aus.')
            );
        }
        $formData['brillenart'] = $brillenart;

        // Refraktionswerte
        $formData['refraktion'] = [
            'rechts' => [
                'sph' => sanitize_text_field($_POST['refraktion_r_sph'] ?? ''),
                'zyl' => sanitize_text_field($_POST['refraktion_r_zyl'] ?? ''),
                'ach' => sanitize_text_field($_POST['refraktion_r_ach'] ?? ''),
                'add' => sanitize_text_field($_POST['refraktion_r_add'] ?? ''),
            ],
            'links' => [
                'sph' => sanitize_text_field($_POST['refraktion_l_sph'] ?? ''),
                'zyl' => sanitize_text_field($_POST['refraktion_l_zyl'] ?? ''),
                'ach' => sanitize_text_field($_POST['refraktion_l_ach'] ?? ''),
                'add' => sanitize_text_field($_POST['refraktion_l_add'] ?? ''),
            ],
        ];

        // Prismen-Werte (horizontal + vertikal)
        $formData['prismen'] = [
            'rechts' => [
                'horizontal' => [
                    'wert'  => sanitize_text_field($_POST['prisma_r_h_wert'] ?? ''),
                    'basis' => sanitize_text_field($_POST['prisma_r_h_basis'] ?? ''),
                ],
                'vertikal' => [
                    'wert'  => sanitize_text_field($_POST['prisma_r_v_wert'] ?? ''),
                    'basis' => sanitize_text_field($_POST['prisma_r_v_basis'] ?? ''),
                ],
            ],
            'links' => [
                'horizontal' => [
                    'wert'  => sanitize_text_field($_POST['prisma_l_h_wert'] ?? ''),
                    'basis' => sanitize_text_field($_POST['prisma_l_h_basis'] ?? ''),
                ],
                'vertikal' => [
                    'wert'  => sanitize_text_field($_POST['prisma_l_v_wert'] ?? ''),
                    'basis' => sanitize_text_field($_POST['prisma_l_v_basis'] ?? ''),
                ],
            ],
        ];

        // Hornhautscheitelabstand
        $formData['hsa'] = sanitize_text_field($_POST['hsa'] ?? '');

        // Lieferung (nur Privat)
        if ($formData['versicherung'] === 'privat') {
            $formData['brillen_lieferung'] = in_array($_POST['brillen_lieferung'] ?? '', ['praxis', 'post'], true)
                ? sanitize_text_field($_POST['brillen_lieferung'])
                : 'praxis';

            if ($formData['brillen_lieferung'] === 'post') {
                $addressError = $this->validateShippingAddress('brillen_versand');
                if ($addressError !== null) {
                    return $addressError;
                }
                $formData['brillen_versandadresse'] = $this->collectShippingAddress('brillen_versand');
            }
        } else {
            $formData['brillen_lieferung']    = 'praxis';
            $formData['brillen_evn_erlaubt']  = !empty($_POST['brillen_evn_erlaubt']) ? '1' : '0';
        }

        return $formData;
    }

    /**
     * Dokument-Felder: Typ, Bemerkung, Datei (Pflicht)
     */
    private function processDokumentFields(array $formData): array|\WP_Error
    {
        if (empty($_POST['dokument_typ'])) {
            return new \WP_Error(
                'missing_type',
                $this->i18n->t('Bitte wählen Sie einen Dokumententyp aus.')
            );
        }

        $formData['dokument_typ'] = sanitize_text_field($_POST['dokument_typ'] ?? '');
        $formData['bemerkung']    = sanitize_textarea_field($_POST['bemerkung'] ?? '');

        // Datei-Upload Pflicht prüfen
        $uploadedFiles = $_POST['uploaded_files'] ?? [];
        if (!is_array($uploadedFiles)) {
            $uploadedFiles = [];
        }
        $hasFile = false;
        foreach ($uploadedFiles as $f) {
            if (is_array($f) && !empty($f['file_id'])) {
                $hasFile = true;
                break;
            }
        }
        if (!$hasFile) {
            return new \WP_Error(
                'missing_file',
                $this->i18n->t('Bitte laden Sie ein Dokument hoch.')
            );
        }

        return $formData;
    }

    /**
     * Termin-Felder: Anliegen, Beschwerden, Zeitwunsch, Tage
     */
    private function processTerminFields(array $formData): array|\WP_Error
    {
        if (!empty($_POST['termin_patient_type'])) {
            $formData['termin_patient_type'] = in_array(
                $_POST['termin_patient_type'] ?? '',
                ['bestandspatient', 'neupatient'],
                true
            ) ? sanitize_text_field($_POST['termin_patient_type']) : 'bestandspatient';
        }

        if (!empty($_POST['termin_anliegen'])) {
            $formData['termin_anliegen'] = sanitize_text_field($_POST['termin_anliegen'] ?? '');
        }

        if (!empty($_POST['termin_beschwerden'])) {
            $formData['termin_beschwerden'] = sanitize_textarea_field($_POST['termin_beschwerden'] ?? '');
        }

        if (!empty($_POST['termin_zeit'])) {
            $formData['termin_zeit'] = in_array(
                $_POST['termin_zeit'] ?? '',
                ['vormittags', 'nachmittags', 'egal'],
                true
            ) ? sanitize_text_field($_POST['termin_zeit']) : 'egal';
        }

        if (!empty($_POST['termin_tage']) && is_array($_POST['termin_tage'])) {
            $validDays = ['mo', 'di', 'mi', 'do', 'fr', 'sa', 'egal'];
            $formData['termin_tage'] = array_values(array_intersect(
                array_map('sanitize_text_field', $_POST['termin_tage']),
                $validDays
            ));
        }

        $formData['termin_schnellstmoeglich'] = !empty($_POST['termin_schnellstmoeglich']) ? '1' : '0';

        // v4 Template-Felder
        if (!empty($_POST['termin_grund'])) {
            $validGruende = ['vorsorge', 'kontrolle', 'akut', 'op_vorbereitung', 'nachsorge', 'sonstiges'];
            $formData['termin_grund'] = in_array($_POST['termin_grund'], $validGruende, true)
                ? sanitize_text_field($_POST['termin_grund']) : '';
        }
        if (!empty($_POST['termin_wunsch1'])) {
            $formData['termin_wunsch1'] = sanitize_text_field($_POST['termin_wunsch1'] ?? '');
        }
        if (!empty($_POST['termin_wunsch2'])) {
            $formData['termin_wunsch2'] = sanitize_text_field($_POST['termin_wunsch2'] ?? '');
        }
        if (!empty($_POST['termin_tageszeit'])) {
            $formData['termin_tageszeit'] = in_array(
                $_POST['termin_tageszeit'] ?? '',
                ['morgens', 'mittags', 'nachmittags', 'egal'],
                true
            ) ? sanitize_text_field($_POST['termin_tageszeit']) : 'egal';
        }
        if (!empty($_POST['termin_hinweis'])) {
            $formData['termin_hinweis'] = sanitize_textarea_field($_POST['termin_hinweis'] ?? '');
        }

        return $formData;
    }

    /**
     * Terminabsage-Felder: Datum (Pflicht), Uhrzeit (Optional)
     */
    private function processTerminabsageFields(array $formData): array|\WP_Error
    {
        $tag   = intval($_POST['absage_tag'] ?? 0);
        $monat = intval($_POST['absage_monat'] ?? 0);
        $jahr  = intval($_POST['absage_jahr'] ?? 0);

        if ($tag < 1 || $tag > 31 || $monat < 1 || $monat > 12 || $jahr < 2020 || $jahr > 2100) {
            return new \WP_Error(
                'invalid_date',
                $this->i18n->t('Bitte geben Sie das Datum des abzusagenden Termins an.')
            );
        }

        if (!checkdate($monat, $tag, $jahr)) {
            return new \WP_Error(
                'invalid_date',
                $this->i18n->t('Bitte geben Sie ein gültiges Datum an.')
            );
        }

        $formData['absage_datum'] = sprintf('%02d.%02d.%04d', $tag, $monat, $jahr);

        // Optional: Uhrzeit
        $stunde = isset($_POST['absage_stunde']) ? intval($_POST['absage_stunde']) : -1;
        $minute = isset($_POST['absage_minute']) ? intval($_POST['absage_minute']) : 0;

        if ($stunde >= 0 && $stunde <= 23) {
            $minute = ($minute >= 0 && $minute <= 59) ? $minute : 0;
            $formData['absage_uhrzeit'] = sprintf('%02d:%02d', $stunde, $minute);
        }

        return $formData;
    }

    // =========================================================================
    // DATEI-UPLOAD
    // =========================================================================

    /**
     * Datei-Upload verarbeiten
     * 
     * AJAX: pp_widget_upload
     * 
     * 1. Rate-Limit (strenger: 10 Uploads / 5 Min)
     * 2. Nonce prüfen
     * 3. Datei validieren (Größe, MIME, Magic Bytes)
     * 4. Datei verschlüsseln (XSalsa20-Poly1305)
     * 5. Verschlüsselte Datei speichern
     */
    public function handleFileUpload(): void
    {
        // Rate Limit für Uploads
        $ip = $this->getClientIp();
        $result = $this->rateLimiter->attempt('widget_upload', 10, 300);
        if (!$result['allowed']) {
            $this->auditRepo->logSecurity(
                'widget_upload_rate_limited',
                [
                    'ip_hash' => hash('sha256', $ip . wp_salt()),
                    'attempts' => $result['current'],
                    'limit' => $result['limit'],
                    'retry_after' => $result['retry_after'],
                ]
            );
            wp_send_json_error([
                'message' => sprintf(
                    $this->i18n->t('Zu viele Uploads. Bitte warten Sie %d Sekunden.'),
                    $result['retry_after']
                ),
            ], 429);
        }

        // Nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pp_widget_upload_nonce')) {
            wp_send_json_error([
                'message' => $this->i18n->t('Sicherheitsüberprüfung fehlgeschlagen.'),
            ]);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error([
                'message' => $this->i18n->t('Keine Datei ausgewählt.'),
            ]);
        }

        $file  = $_FILES['file'];
        $field = sanitize_text_field($_POST['field_id'] ?? $_POST['field'] ?? 'file');

        // Upload-Fehler
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE  => $this->i18n->t('Datei zu groß (Server-Limit).'),
                UPLOAD_ERR_FORM_SIZE => $this->i18n->t('Datei zu groß.'),
                UPLOAD_ERR_PARTIAL   => $this->i18n->t('Upload unvollständig.'),
                UPLOAD_ERR_NO_FILE   => $this->i18n->t('Keine Datei ausgewählt.'),
            ];
            $msg = $errorMessages[$file['error']] ?? $this->i18n->t('Upload-Fehler.');
            wp_send_json_error(['message' => $msg]);
        }

        // Dateigröße
        if ($file['size'] > self::MAX_FILE_SIZE) {
            wp_send_json_error([
                'message' => $this->i18n->t('Datei zu groß (max. 10MB).'),
            ]);
        }

        // Echter Upload
        if (!is_uploaded_file($file['tmp_name'])) {
            wp_send_json_error([
                'message' => $this->i18n->t('Ungültiger Upload.'),
            ]);
        }

        // MIME-Type (Magic Bytes)
        $mimeType = $this->detectMimeType($file);
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            wp_send_json_error([
                'message' => $this->i18n->t('Ungültiger Dateityp. Erlaubt: JPG, PNG, GIF, WEBP, PDF'),
            ]);
        }

        // Location für Datei-Zuordnung
        $locationUuid = sanitize_text_field($_POST['location_uuid'] ?? '');
        if (empty($locationUuid)) {
            $locationUuid = $this->locationContext->getLocationUuid();
        }

        // Datei via FileRepository speichern (verschlüsselt)
        try {
            $fileContent = file_get_contents($file['tmp_name']);
            if ($fileContent === false) {
                throw new \RuntimeException('Datei konnte nicht gelesen werden.');
            }

            $result = $this->fileRepo->storeFile(
                $fileContent,
                sanitize_file_name($file['name']),
                $mimeType,
                $file['size'],
                $locationUuid
            );

            // Temp-Datei löschen
            if (file_exists($file['tmp_name'])) {
                unlink($file['tmp_name']);
            }

            wp_send_json_success([
                'file_id'       => $result['file_id'],
                'original_name' => sanitize_file_name($file['name']),
                'filename'      => sanitize_file_name($file['name']),
                'mime_type'     => $mimeType,
                'size'          => $file['size'],
                'field'         => $field,
            ]);
        } catch (\Exception $e) {
            // Temp-Datei auch bei Fehler aufräumen
            if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
                @unlink($file['tmp_name']);
            }
            error_log('PP4 File Upload Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $this->i18n->t('Ein technischer Fehler ist aufgetreten. Bitte kontaktieren Sie die Praxis.'),
            ]);
        }
    }

    // =========================================================================
    // MEDIKAMENTEN-SUCHE
    // =========================================================================

    /**
     * Medikamenten-Suche (Autocomplete)
     * 
     * AJAX: pp_medication_search
     * 
     * Sucht in der Medikamenten-Datenbank (pp_medications) nach Treffern.
     * Einzige Datenquelle für Widget UND Admin.
     * CSV dient nur als Erst-Import bei Aktivierung.
     *
     * @since 4.2.6 – Umgestellt von CSV auf DB
     * @since 4.2.9 – Rate-Limiting + erweitertes Response-Format (v3-Parität)
     */
    public function handleMedicationSearch(): void
    {
        // ── Rate-Limiting: Max 20 Anfragen pro Minute pro IP ──
        $result = $this->rateLimiter->attempt('med_search', 20, 60);
        if (!$result['allowed']) {
            wp_send_json_error([
                'message' => sprintf(
                    'Rate limit exceeded. Retry after %d seconds.',
                    $result['retry_after']
                ),
            ], 429);
        }

        // Nonce – nur POST (widget.js sendet POST, GET öffnet CSRF-Risiko)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pp_medication_search_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        $query = sanitize_text_field($_POST['query'] ?? $_POST['term'] ?? $_POST['search'] ?? '');
        if (strlen($query) < 2) {
            wp_send_json_success(['results' => [], 'medications' => []]);
        }

        // ── Location auflösen (Multistandort-Säule) ──
        $locationId   = null;
        $locationUuid = sanitize_text_field($_POST['location_uuid'] ?? '');

        if (empty($locationUuid)) {
            $locationUuid = $this->locationContext->getLocationUuid();
        }

        if (!empty($locationUuid)) {
            $location = $this->locationManager->getByUuid($locationUuid);
            if ($location) {
                $locationId = (int) ($location['id'] ?? 0) ?: null;
            }
        }

        // ── Repository-Suche (mit Standortfilter: eigene + globale) ──
        $rawResults = $this->medicationRepo->search($query, 15, $locationId);

        // ── Response im v3-kompatiblen Format aufbauen ──
        $medications = [];
        foreach ($rawResults as $med) {
            $displayName = esc_html($med['name']);
            if (!empty($med['staerke'])) {
                $displayName .= ' ' . esc_html($med['staerke']);
            }

            $medications[] = [
                'id'                 => (int) $med['id'],
                'name'               => esc_html($med['name']),
                'wirkstoff'          => esc_html($med['wirkstoff'] ?? ''),
                'staerke'            => esc_html($med['staerke'] ?? ''),
                'einheit'            => esc_html($med['einheit'] ?? ''),
                'pzn'                => esc_html($med['pzn'] ?? ''),
                'standard_dosierung' => esc_html($med['standard_dosierung'] ?? ''),
                'einnahme_hinweis'   => esc_html($med['einnahme_hinweis'] ?? ''),
                'kategorie'          => esc_html($med['kategorie'] ?? ''),
                'display'            => $displayName,
            ];
        }

        // Beide Keys für Kompatibilität (v3 nutzt 'medications', v4-JS nutzt 'results')
        wp_send_json_success([
            'results'     => $medications,
            'medications' => $medications,
        ]);
    }

    // =========================================================================
    // E-MAIL-BENACHRICHTIGUNG
    // =========================================================================

    /**
     * E-Mail an Praxis senden (DSGVO-konform: keine PII)
     * 
     * @param int   $submissionId Einreichungs-ID
     * @param array $formData     Formulardaten (für Service-Label)
     * @param array $location     Standort-Daten
     */
    private function sendNotificationEmail(int $submissionId, array $formData, array $location): void
    {
        // E-Mails aktiviert?
        $emailEnabled = get_option('pp_email_enabled', '1');
        if ($emailEnabled !== '1') {
            return;
        }

        // Feature-Gate (erwartet int locationId, nicht UUID-String)
        $emailLocationId = (int) ($location['id'] ?? 0);
        if (!$this->featureGate->hasFeature('email_notifications', $emailLocationId ?: null)) {
            return;
        }

        // Empfänger: Location > Global > Admin
        $to = $location['email_notification'] ?? '';
        if (empty($to)) {
            $to = get_option('pp_notification_email', '');
        }
        if (empty($to)) {
            $to = get_option('admin_email');
        }
        if (empty($to)) {
            error_log('PP4 Widget: Keine E-Mail-Adresse für Benachrichtigungen konfiguriert');
            return;
        }

        // Praxisname
        $praxisName = $location['practice_name'] ?? '';
        if (empty($praxisName)) {
            $praxisName = get_option('pp_praxis_name', get_bloginfo('name'));
        }

        // Service-Label
        $serviceLabels = [
            'rezept'            => $this->i18n->t('Rezept-Anfrage'),
            'ueberweisung'      => $this->i18n->t('Überweisung'),
            'brillenverordnung' => $this->i18n->t('Brillenverordnung'),
            'dokument'          => $this->i18n->t('Dokument-Upload'),
            'termin'            => $this->i18n->t('Terminanfrage'),
            'terminabsage'      => $this->i18n->t('Terminabsage'),
        ];
        $serviceLabel = $serviceLabels[$formData['service_type']] ?? $formData['service_type'];

        // Betreff
        $subject = sprintf('[%s] Neue Service-Anfrage: %s', $praxisName, $serviceLabel);

        // Headers (Newlines entfernen = Header-Injection-Schutz)
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $fromName  = str_replace(["\r", "\n"], '', $location['email_from_name'] ?? '');
        $fromEmail = sanitize_email($location['email_from_address'] ?? '');

        if (!empty($fromName) && !empty($fromEmail)) {
            $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        } elseif (!empty($fromEmail)) {
            $headers[] = 'From: ' . $fromEmail;
        }

        // E-Mail-Signatur
        $signature = '';
        if (!empty($location['email_signature'])) {
            $signature = "\n\n--\n" . $location['email_signature'];
        }

        // Nachricht (DSGVO-konform: KEINE Patientendaten)
        $message  = $this->i18n->t('Neue Service-Anfrage eingegangen') . "\n";
        $message .= "================================\n\n";
        $message .= $this->i18n->t('Art der Anfrage') . ': ' . $serviceLabel . "\n";
        $message .= $this->i18n->t('Eingegangen am') . ': ' . current_time('d.m.Y H:i') . " Uhr\n";

        if (!empty($location['name'])) {
            $message .= $this->i18n->t('Standort') . ': ' . $location['name'] . "\n";
        }

        $message .= "\n" . $this->i18n->t('Bitte öffnen Sie das Praxis-Portal, um die vollständigen Details einzusehen.');
        $message .= $signature;

        wp_mail($to, $subject, $message, $headers);
    }

    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================

    /**
     * Hochgeladene Dateien aus POST sammeln
     */
    private function collectUploadedFiles(): array
    {
        // JS sendet uploaded_files als FormData-Array (uploaded_files[0][file_id], etc.)
        // PHP empfängt das automatisch als verschachteltes Array
        $raw = $_POST['uploaded_files'] ?? [];
        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        $files = [];
        foreach ($raw as $key => $fileData) {
            $cleanKey = sanitize_key($key);
            if (is_array($fileData) && !empty($fileData['file_id'])) {
                $files[$cleanKey] = [
                    'file_id'   => sanitize_text_field($fileData['file_id']),
                    'field_id'  => sanitize_key($fileData['field_id'] ?? ''),
                    'filename'  => sanitize_file_name($fileData['filename'] ?? $fileData['original_name'] ?? 'unnamed'),
                    'mime_type' => sanitize_text_field($fileData['mime_type'] ?? 'application/octet-stream'),
                    'size'      => intval($fileData['size'] ?? 0),
                ];
            }
        }

        return $files;
    }

    /**
     * Versandadresse validieren
     * 
     * @param string $prefix Feld-Prefix (z.B. 'versand', 'brillen_versand')
     * @return \WP_Error|null Fehler oder null
     */
    private function validateShippingAddress(string $prefix): ?\WP_Error
    {
        $strasse = sanitize_text_field($_POST[$prefix . '_strasse'] ?? '');
        $plz     = sanitize_text_field($_POST[$prefix . '_plz'] ?? '');
        $ort     = sanitize_text_field($_POST[$prefix . '_ort'] ?? '');

        if (empty($strasse) || empty($plz) || empty($ort)) {
            return new \WP_Error(
                'missing_address',
                $this->i18n->t('Bitte geben Sie eine vollständige Versandadresse an.')
            );
        }

        return null;
    }

    /**
     * Versandadresse sammeln
     */
    private function collectShippingAddress(string $prefix): array
    {
        return [
            'strasse' => sanitize_text_field($_POST[$prefix . '_strasse'] ?? ''),
            'plz'     => sanitize_text_field($_POST[$prefix . '_plz'] ?? ''),
            'ort'     => sanitize_text_field($_POST[$prefix . '_ort'] ?? ''),
        ];
    }

    /**
     * MIME-Type via Magic Bytes erkennen
     */
    private function detectMimeType(array $file): string
    {
        $mimeType = '';

        // finfo (bevorzugt)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $file['tmp_name']);
                $mimeType = is_string($detected) ? $detected : '';
                finfo_close($finfo);
            }
        }

        // Fallback: mime_content_type
        if (empty($mimeType) && function_exists('mime_content_type')) {
            $mimeType = mime_content_type($file['tmp_name']) ?: '';
        }

        // Letzter Fallback: Extension
        if (empty($mimeType)) {
            $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $extMap = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'pdf'  => 'application/pdf',
            ];
            $mimeType = $extMap[$ext] ?? '';
        }

        return $mimeType;
    }

    /**
     * Services für einen Standort laden (AJAX-Handler)
     *
     * @since 4.2.9 – Rate-Limiting hinzugefügt
     */
    public function handleLoadServices(): void
    {
        // Nonce-Prüfung (Defense-in-Depth, Daten sind öffentlich)
        $nonce = sanitize_text_field($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'pp_widget_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 403);
        }

        // Rate-Limiting: Max 30/min pro IP
        $result = $this->rateLimiter->attempt('load_services', 30, 60);
        if (!$result['allowed']) {
            wp_send_json_error([
                'message' => sprintf('Rate limit exceeded. Retry after %d seconds.', $result['retry_after']),
            ], 429);
        }

        $locationId = (int) ($_POST['location_id'] ?? $_GET['location_id'] ?? 0);

        if ($locationId <= 0) {
            $resolver   = $this->container->get(\PraxisPortal\Location\LocationResolver::class);
            $context    = $resolver->resolve();
            $locationId = $context->getLocationId();
        }

        if ($locationId <= 0) {
            wp_send_json_error(['message' => 'Kein Standort angegeben.']);
            return;
        }

        $serviceMgr = $this->container->get(\PraxisPortal\Location\ServiceManager::class);
        $services   = $serviceMgr->getActiveServices($locationId);

        $result = [];
        foreach ($services as $svc) {
            $result[] = [
                'key'         => $svc['service_key'],
                'label'       => $svc['label'] ?? '',
                'description' => $svc['description'] ?? '',
                'icon'        => $svc['icon'] ?? '',
                'type'        => $svc['service_type'] ?? 'builtin',
                'restriction' => $svc['patient_restriction'] ?? 'all',
                'external_url' => $svc['external_url'] ?? '',
            ];
        }

        wp_send_json_success(['services' => $result]);
    }

    /**
     * Formular-Definition laden (AJAX-Handler)
     *
     * @since 4.2.9 – Rate-Limiting hinzugefügt
     */
    public function handleLoadForm(): void
    {
        // Nonce-Prüfung (Defense-in-Depth, Daten sind öffentlich)
        $nonce = sanitize_text_field($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'pp_widget_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 403);
        }

        // Rate-Limiting: Max 30/min pro IP
        $result = $this->rateLimiter->attempt('load_form', 30, 60);
        if (!$result['allowed']) {
            wp_send_json_error([
                'message' => sprintf('Rate limit exceeded. Retry after %d seconds.', $result['retry_after']),
            ], 429);
        }

        $formId = sanitize_text_field($_POST['form_id'] ?? $_GET['form_id'] ?? '');
        $lang   = sanitize_text_field($_POST['lang'] ?? $_GET['lang'] ?? 'de');

        if (empty($formId)) {
            wp_send_json_error(['message' => 'Keine Formular-ID angegeben.']);
            return;
        }

        $formLoader = $this->container->get(\PraxisPortal\Form\FormLoader::class);
        $form       = $formLoader->getLocalizedForm($formId, $lang);

        if (!$form) {
            wp_send_json_error(['message' => 'Formular nicht gefunden.']);
            return;
        }

        wp_send_json_success(['form' => $form]);
    }

    /**
     * Client-IP ermitteln
     */
    private function getClientIp(): string
    {
        $rawIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip    = sanitize_text_field(wp_unslash($rawIp));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * Monatliche Widget-Submissions zählen (für Free-Plan-Limit)
     *
     * Zählt alle Widget-Submissions des aktuellen Monats.
     * Nutzt Transient-Cache (1h TTL) um DB-Last zu minimieren.
     *
     * @since 4.2.9
     */
    private function getMonthlySubmissionCount(): int
    {
        $cacheKey = 'pp_monthly_subs_' . gmdate('Y_m');
        $cached   = get_transient($cacheKey);

        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pp_submissions';
        $monthStart = gmdate('Y-m-01 00:00:00');

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE request_type LIKE 'widget_%%'
                   AND status != 'deleted'
                   AND created_at >= %s",
                $monthStart
            )
        );

        // Cache für 1 Stunde (wird bei neuem Submit schnell genug aktualisiert)
        set_transient($cacheKey, $count, HOUR_IN_SECONDS);

        return $count;
    }
}
