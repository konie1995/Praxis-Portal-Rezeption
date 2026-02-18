<?php
/**
 * Formular-Handler für die Verarbeitung von Einreichungen
 *
 * Verarbeitet Anamnese-Formulare und Widget-Service-Anfragen.
 * Portiert aus v3 PP_Form_Handler + PP_Widget_Handler mit v4-Architektur.
 *
 * @package PraxisPortal\Form
 * @since 4.0.0
 */

namespace PraxisPortal\Form;

if (!defined('ABSPATH')) {
    exit;
}

use PraxisPortal\Security\Encryption;
use PraxisPortal\Security\Sanitizer;
use PraxisPortal\Security\RateLimiter;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Location\LocationContext;

class FormHandler
{
    private Encryption $encryption;
    private Sanitizer $sanitizer;
    private FormValidator $validator;
    private SubmissionRepository $submissions;
    private FileRepository $files;
    private AuditRepository $audit;
    private RateLimiter $rateLimiter;

    /** DSGVO Art. 9: Sensible Felder (Dokumentation) */
    private const SENSITIVE_FIELDS = [
        'vorname', 'nachname', 'geburtsdatum', 'strasse', 'plz', 'ort',
        'email', 'telefon', 'signature_data',
        'hauptversicherter_vorname', 'hauptversicherter_nachname',
        'allergien_welche', 'medikamente_liste', 'medikamente_strukturiert',
        'cortison_details', 'chloroquin_details',
    ];

    /** Felder die beim Sanitizing ausgeschlossen werden */
    private const EXCLUDE_FIELDS = [
        'nonce', 'pp_nonce', 'action', 'uploaded_files', '_wp_http_referer',
        'form_token', 'dsgvo_consent',
    ];

    /** Textarea-Felder (Zeilenumbrüche behalten) */
    private const TEXTAREA_FIELDS = [
        'allergien_welche', 'medikamente_liste', 'anmerkungen',
        'autoimmun_andere', 'blutgerinnung_welche', 'infektionen_andere',
    ];

    public function __construct(
        Encryption $encryption,
        Sanitizer $sanitizer,
        SubmissionRepository $submissions,
        FileRepository $files,
        AuditRepository $audit,
        RateLimiter $rateLimiter
    ) {
        $this->encryption = $encryption;
        $this->sanitizer = $sanitizer;
        $this->validator = new FormValidator();
        $this->submissions = $submissions;
        $this->files = $files;
        $this->audit = $audit;
        $this->rateLimiter = $rateLimiter;
    }

    // ─── ANAMNESE-FORMULAR ───────────────────────────────────

    /**
     * Anamnese-Formular verarbeiten
     *
     * @param array $postData       $_POST Daten
     * @param LocationContext $ctx   Standort-Kontext
     * @param array $formDef        Formulardefinition (optional, aus JSON)
     * @return array{success: bool, message: string, errors?: array, submission_id?: string}
     */
    public function processAnamnese(array $postData, LocationContext $ctx, array $formDef = []): array
    {
        // 1. Spam-Schutz
        $spamCheck = $this->checkSpamProtection($postData);
        if ($spamCheck !== true) {
            return $spamCheck;
        }

        // 2. Validierung
        if (!$this->validator->validateAnamnese($postData, $formDef)) {
            return [
                'success' => false,
                'message' => 'Validierung fehlgeschlagen.',
                'errors'  => $this->validator->getErrors(),
            ];
        }

        // 3. Daten sanitieren
        $formData = $this->sanitizeFormData($postData);

        // 4. Geburtsdatum zusammenführen
        $formData = $this->mergeGeburtsdatum($formData);

        // 5. Metadaten
        $formData['_submitted_at'] = current_time('mysql');
        $formData['_form_version'] = PP_VERSION ?? '4.0.0';
        $formData['_location_id'] = $ctx->getLocationId();

        // 6. Signatur extrahieren
        $signatureData = null;
        $isPrivat = !empty($formData['kasse']) && strtolower($formData['kasse']) === 'privat';
        if (!empty($formData['signature_data']) && $isPrivat) {
            $signatureData = $formData['signature_data'];
            unset($formData['signature_data']);
        }

        // 7. Speichern
        try {
            $result = $this->submissions->create(
                $formData,
                [
                    'location_id'  => $ctx->getLocationId(),
                    'service_key'  => 'anamnese',
                    'request_type' => 'form_anamnese',
                ],
                $signatureData
            );
        } catch (\Exception $e) {
            error_log('PP FormHandler: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ein technischer Fehler ist aufgetreten. Bitte kontaktieren Sie die Praxis telefonisch.',
            ];
        }

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Speichern. Bitte versuchen Sie es erneut.',
            ];
        }

        // 8. Datei-Referenzen speichern
        $this->processUploadedFiles($postData, $formData, $result['submission_id']);

        // 9. Audit-Log
        $this->audit->logSubmission('submission_created', $result['submission_id'], [
            'service'  => 'anamnese',
            'location' => $ctx->getSlug(),
        ]);

        return [
            'success'       => true,
            'message'       => 'Vielen Dank! Ihr Anamnesebogen wurde erfolgreich übermittelt.',
            'submission_id' => $result['submission_hash'],
            'reference'     => $result['reference'],
        ];
    }

    // ─── WIDGET SERVICE-ANFRAGE ──────────────────────────────

    /**
     * Widget-Service-Anfrage verarbeiten
     *
     * @param array $postData   $_POST Daten
     * @param LocationContext $ctx Standort-Kontext
     * @return array{success: bool, message: string, reference?: string}
     */
    public function processWidgetRequest(array $postData, LocationContext $ctx): array
    {
        // 1. Spam-Schutz
        $spamCheck = $this->checkSpamProtection($postData);
        if ($spamCheck !== true) {
            return $spamCheck;
        }

        // 2. Validierung
        if (!$this->validator->validateWidgetRequest($postData)) {
            $errors = $this->validator->getErrors();
            $firstError = reset($errors);
            return [
                'success' => false,
                'message' => $firstError ?: 'Bitte füllen Sie alle Pflichtfelder aus.',
                'errors'  => $errors,
            ];
        }

        // 3. Service-Typ
        $serviceType = sanitize_key($postData['service_type'] ?? '');

        // 4. Daten aufbereiten
        $gebTag = str_pad((int) ($postData['geburtsdatum_tag'] ?? 0), 2, '0', STR_PAD_LEFT);
        $gebMonat = str_pad((int) ($postData['geburtsdatum_monat'] ?? 0), 2, '0', STR_PAD_LEFT);
        $gebJahr = (int) ($postData['geburtsdatum_jahr'] ?? 0);

        $formData = [
            'service_type'   => $serviceType,
            'patient_status' => $this->sanitizer->text($postData['patient_status'] ?? 'bestandspatient'),
            'vorname'        => $this->sanitizer->text($postData['vorname'] ?? ''),
            'nachname'       => $this->sanitizer->text($postData['nachname'] ?? ''),
            'geburtsdatum'   => "{$gebTag}.{$gebMonat}.{$gebJahr}",
            'telefon'        => $this->sanitizer->text($postData['telefon'] ?? ''),
            'email'          => sanitize_email($postData['email'] ?? ''),
            'versicherung'   => $this->sanitizer->text($postData['versicherung'] ?? ''),
            'anmerkungen'    => sanitize_textarea_field($postData['anmerkungen'] ?? ''),
            'submitted_at'   => current_time('mysql'),
        ];

        // Service-spezifische Felder
        $formData = $this->processServiceFields($formData, $postData, $serviceType);
        if (isset($formData['_error'])) {
            return ['success' => false, 'message' => $formData['_error']];
        }

        // Hochgeladene Dateien
        $this->extractUploadedFiles($postData, $formData);

        // 5. Speichern
        try {
            $result = $this->submissions->create(
                $formData,
                [
                    'location_id'  => $ctx->getLocationId(),
                    'service_key'  => $serviceType,
                    'request_type' => 'widget_' . $serviceType,
                ]
            );
        } catch (\Exception $e) {
            error_log('PP Widget FormHandler: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ein technischer Fehler ist aufgetreten.',
            ];
        }

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Speichern. Bitte versuchen Sie es erneut.',
            ];
        }

        // 6. Datei-Referenzen
        if (!empty($formData['uploaded_files'])) {
            foreach ($formData['uploaded_files'] as $field => $fileInfo) {
                if (!empty($fileInfo['file_id'])) {
                    $this->files->createReference($result['submission_id'], $fileInfo);
                }
            }
        }

        // 7. Audit
        $this->audit->logSubmission('service_request_submitted', $result['submission_id'], [
            'service'  => $serviceType,
            'location' => $ctx->getSlug(),
        ]);

        // 8. E-Mail-Benachrichtigung
        $this->sendNotificationEmail($result['submission_id'], $formData, $ctx);

        return [
            'success'   => true,
            'message'   => 'Ihre Anfrage wurde erfolgreich übermittelt.',
            'reference' => $result['reference'],
        ];
    }

    // ─── SPAM-SCHUTZ ─────────────────────────────────────────

    /**
     * Spam-/Bot-Schutz prüfen
     *
     * @return true|array True wenn OK, sonst Fehler-Array
     */
    private function checkSpamProtection(array $data): true|array
    {
        // Rate Limiting
        $result = $this->rateLimiter->attempt('form_submit');
        if (!$result['allowed']) {
            if ($this->audit) {
                $this->audit->logSecurity(
                    'form_submit_rate_limited',
                    [
                        'ip_hash' => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . wp_salt()),
                        'attempts' => $result['current'],
                    ]
                );
            }
            return [
                'success' => false,
                'message' => sprintf(
                    'Zu viele Anfragen. Bitte warten Sie %d Sekunden.',
                    $result['retry_after']
                ),
            ];
        }

        // Honeypot
        if (!empty($data['website_url']) || !empty($data['email_confirm'])) {
            $this->rateLimiter->increment('form_submit');
            // Fake-Success für Bots
            return [
                'success' => true,
                'message' => 'Anfrage gesendet.',
                'reference' => 'REF' . wp_rand(100000, 999999),
            ];
        }

        // Zeitstempel (min. 5 Sekunden)
        $formToken = $data['form_token'] ?? '';
        $minTime = (int) get_option('pp_min_form_time', 5);

        if (!empty($formToken)) {
            $decoded = base64_decode($formToken, true);
            if ($decoded) {
                $parts = explode('_', $decoded);
                if (count($parts) >= 2) {
                    $tokenTime = (int) $parts[0];
                    $timeDiff = time() - $tokenTime;

                    if ($timeDiff < $minTime) {
                        return [
                            'success' => false,
                            'message' => 'Bitte nehmen Sie sich etwas mehr Zeit zum Ausfüllen.',
                        ];
                    }
                }
            }
        }

        return true;
    }

    // ─── DATEN-SANITIERUNG ───────────────────────────────────

    /**
     * Formulardaten bereinigen
     */
    private function sanitizeFormData(array $data): array
    {
        $sanitized = [];

        // Custom textarea-Felder ermitteln
        $textareaFields = self::TEXTAREA_FIELDS;
        $customQuestions = get_option('pp_custom_questions', []);
        if (is_string($customQuestions)) {
            $customQuestions = json_decode($customQuestions, true) ?: [];
        }
        foreach ($customQuestions as $q) {
            if (($q['type'] ?? '') === 'textarea' && !empty($q['id'])) {
                $textareaFields[] = $q['id'];
            }
        }

        foreach ($data as $key => $value) {
            if (in_array($key, self::EXCLUDE_FIELDS, true)) {
                continue;
            }

            $key = sanitize_key($key);

            if (is_array($value)) {
                $sanitized[$key] = array_map('sanitize_text_field', $value);
            } elseif ($key === 'signature_data') {
                // Base64-Signatur: nur validieren
                if (preg_match('/^data:image\/(png|jpeg);base64,/', $value)) {
                    $sanitized[$key] = $value;
                }
            } elseif ($key === 'medikamente_strukturiert') {
                $sanitized[$key] = sanitize_text_field(stripslashes($value));
            } elseif ($key === 'email') {
                $sanitized[$key] = sanitize_email($value);
            } elseif (in_array($key, $textareaFields, true)) {
                $sanitized[$key] = sanitize_textarea_field($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }

    /**
     * Geburtsdatum aus separaten Feldern zusammenführen
     */
    private function mergeGeburtsdatum(array $data): array
    {
        if (!empty($data['geburtsdatum_tag']) && !empty($data['geburtsdatum_monat']) && !empty($data['geburtsdatum_jahr'])) {
            $tag = str_pad((int) $data['geburtsdatum_tag'], 2, '0', STR_PAD_LEFT);
            $monat = str_pad((int) $data['geburtsdatum_monat'], 2, '0', STR_PAD_LEFT);
            $jahr = (int) $data['geburtsdatum_jahr'];

            $data['geburtsdatum'] = "{$tag}.{$monat}.{$jahr}";
            $data['geburtsdatum_iso'] = "{$jahr}-{$monat}-{$tag}";

            unset($data['geburtsdatum_tag'], $data['geburtsdatum_monat'], $data['geburtsdatum_jahr']);
        }

        return $data;
    }

    // ─── SERVICE-SPEZIFISCH ──────────────────────────────────

    /**
     * Service-spezifische Felder verarbeiten
     */
    private function processServiceFields(array $formData, array $postData, string $serviceType): array
    {
        return match ($serviceType) {
            'rezept'            => $this->processRezeptFields($formData, $postData),
            'ueberweisung'      => $this->processUeberweisungFields($formData, $postData),
            'brillenverordnung' => $this->processBrilleFields($formData, $postData),
            'dokument'          => $this->processDokumentFields($formData, $postData),
            'termin'            => $this->processTerminFields($formData, $postData),
            'terminabsage'      => $this->processTerminabsageFields($formData, $postData),
            default             => $formData,
        };
    }

    private function processRezeptFields(array $formData, array $postData): array
    {
        $medikamente = [];
        $arten = [];
        $validArten = ['augentropfen', 'augensalbe', 'tabletten', 'sonstiges'];

        $rawMedikamente = array_map('sanitize_text_field', (array) ($postData['medikamente'] ?? []));
        $rawArten = array_map('sanitize_text_field', (array) ($postData['medikament_art'] ?? []));

        foreach ($rawMedikamente as $i => $name) {
            if (!empty(trim($name))) {
                $medikamente[] = $name;
                $arten[] = in_array($rawArten[$i] ?? '', $validArten, true) ? $rawArten[$i] : 'sonstiges';
            }
        }

        $medikamente = array_slice($medikamente, 0, 3);
        $arten = array_slice($arten, 0, 3);

        if (empty($medikamente)) {
            $formData['_error'] = 'Bitte geben Sie mindestens ein Medikament an.';
            return $formData;
        }

        $formData['medikamente'] = $medikamente;
        $formData['medikament_arten'] = $arten;

        if ($formData['versicherung'] === 'privat') {
            $formData['rezept_lieferung'] = in_array($postData['rezept_lieferung'] ?? '', ['praxis', 'post'], true)
                ? $postData['rezept_lieferung'] : 'praxis';

            if ($formData['rezept_lieferung'] === 'post') {
                $formData['versandadresse'] = [
                    'strasse' => sanitize_text_field($postData['versand_strasse'] ?? ''),
                    'plz'     => sanitize_text_field($postData['versand_plz'] ?? ''),
                    'ort'     => sanitize_text_field($postData['versand_ort'] ?? ''),
                ];
            }
        }

        return $formData;
    }

    private function processUeberweisungFields(array $formData, array $postData): array
    {
        $formData['fachrichtung'] = sanitize_text_field($postData['fachrichtung'] ?? '');
        $formData['diagnose'] = sanitize_text_field($postData['diagnose'] ?? '');
        $formData['arzt_name'] = sanitize_text_field($postData['arzt_name'] ?? '');
        return $formData;
    }

    private function processBrilleFields(array $formData, array $postData): array
    {
        $formData['brille_art'] = sanitize_text_field($postData['brille_art'] ?? '');
        $formData['brille_seit'] = sanitize_text_field($postData['brille_seit'] ?? '');
        $formData['brille_probleme'] = sanitize_textarea_field($postData['brille_probleme'] ?? '');

        // Prisma-Daten
        foreach (['re_sph', 're_cyl', 're_ach', 'li_sph', 'li_cyl', 'li_ach', 'hsa', 'pd'] as $field) {
            if (isset($postData['brille_' . $field])) {
                $formData['brille_' . $field] = sanitize_text_field($postData['brille_' . $field]);
            }
        }

        return $formData;
    }

    private function processDokumentFields(array $formData, array $postData): array
    {
        $formData['dokument_beschreibung'] = sanitize_textarea_field($postData['dokument_beschreibung'] ?? '');
        return $formData;
    }

    private function processTerminFields(array $formData, array $postData): array
    {
        $formData['termin_grund'] = sanitize_text_field($postData['termin_grund'] ?? '');
        $formData['termin_wunschtermin'] = sanitize_text_field($postData['termin_wunschtermin'] ?? '');
        $formData['termin_dringlichkeit'] = sanitize_text_field($postData['termin_dringlichkeit'] ?? 'normal');
        return $formData;
    }

    private function processTerminabsageFields(array $formData, array $postData): array
    {
        $formData['termin_datum'] = sanitize_text_field($postData['termin_datum'] ?? '');
        $formData['termin_absage_grund'] = sanitize_text_field($postData['termin_absage_grund'] ?? '');
        return $formData;
    }

    // ─── DATEI-VERARBEITUNG ──────────────────────────────────

    /**
     * Hochgeladene Dateien (Anamnese) verarbeiten
     */
    private function processUploadedFiles(array $postData, array $formData, int $submissionId): void
    {
        // uploaded_files JSON Array
        if (!empty($postData['uploaded_files'])) {
            $files = json_decode(stripslashes($postData['uploaded_files']), true);
            if (is_array($files)) {
                foreach ($files as $file) {
                    $this->files->createReference($submissionId, [
                        'file_id'       => sanitize_text_field($file['file_id'] ?? ''),
                        'original_name' => $file['original_name'] ?? 'unnamed',
                        'mime_type'     => $file['mime_type'] ?? 'application/octet-stream',
                        'file_size'     => (int) ($file['file_size'] ?? 0),
                    ]);
                }
            }
        }

        // Custom *_file_id Felder
        foreach ($formData as $key => $value) {
            if (preg_match('/^(.+)_file_id$/', $key, $m) && !empty($value)) {
                if (FileRepository::isValidFileId($value)) {
                    $fieldName = $m[1];
                    $this->files->createReference($submissionId, [
                        'file_id'       => $value,
                        'original_name' => $formData[$fieldName . '_original_name'] ?? 'upload_' . $fieldName,
                        'mime_type'     => $formData[$fieldName . '_mime_type'] ?? 'application/octet-stream',
                        'file_size'     => 0,
                    ]);
                }
            }
        }
    }

    /**
     * Widget uploaded_files extrahieren
     */
    private function extractUploadedFiles(array $postData, array &$formData): void
    {
        $raw = json_decode(stripslashes($postData['uploaded_files'] ?? '{}'), true);
        if (is_array($raw) && !empty($raw)) {
            $uploaded = [];
            foreach ($raw as $key => $fileData) {
                $cleanKey = sanitize_key($key);
                if (is_array($fileData)) {
                    $uploaded[$cleanKey] = [
                        'file_id'       => sanitize_text_field($fileData['file_id'] ?? ''),
                        'original_name' => sanitize_file_name($fileData['filename'] ?? ''),
                        'mime_type'     => sanitize_text_field($fileData['mime_type'] ?? ''),
                    ];
                }
            }
            $formData['uploaded_files'] = $uploaded;
        }
    }

    // ─── E-MAIL ──────────────────────────────────────────────

    /**
     * Benachrichtigungs-E-Mail senden
     */
    private function sendNotificationEmail(int $submissionId, array $formData, LocationContext $ctx): void
    {
        $notificationEmail = $ctx->getNotificationEmail();
        if (empty($notificationEmail)) {
            return;
        }

        $serviceType = $formData['service_type'] ?? 'unbekannt';
        $serviceLabels = [
            'rezept'            => 'Rezeptbestellung',
            'ueberweisung'      => 'Überweisungswunsch',
            'brillenverordnung' => 'Brillenverordnung',
            'dokument'          => 'Dokument-Upload',
            'termin'            => 'Terminanfrage',
            'terminabsage'      => 'Terminabsage',
        ];

        $serviceLabel = $serviceLabels[$serviceType] ?? ucfirst($serviceType);
        $practiceName = $ctx->getPracticeName();

        // DSGVO-konform: KEINE Patientendaten im E-Mail-Betreff oder Body
        // (E-Mail-Subjects werden von Mailservern im Klartext geloggt)
        $subject = "[{$practiceName}] Neue {$serviceLabel} (Ref: #{$submissionId})";
        $body = "Neue Service-Anfrage über das Praxis-Portal:\n\n";
        $body .= "Service: {$serviceLabel}\n";
        $body .= "Referenz: #{$submissionId}\n\n";
        $body .= "Bitte öffnen Sie das Praxis-Portal für Details:\n";
        $body .= admin_url('admin.php?page=praxis-portal&id=' . $submissionId) . "\n";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        $settings = $ctx->getSettings();
        if (!empty($settings['email_from_address'])) {
            $fromName  = str_replace(["\r", "\n"], '', $settings['email_from_name'] ?? $practiceName);
            $fromEmail = sanitize_email($settings['email_from_address']);
            if (!empty($fromEmail)) {
                $headers[] = "From: {$fromName} <{$fromEmail}>";
            }
        }

        wp_mail($notificationEmail, $subject, $body, $headers);
    }
}
