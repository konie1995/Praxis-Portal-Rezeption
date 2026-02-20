<?php
declare(strict_types=1);
/**
 * Formular-Validator
 *
 * Validiert Anamnese- und Widget-Formulardaten.
 * Portiert aus v3 PP_Form_Handler::validate() mit v4-Architektur.
 *
 * @package PraxisPortal\Form
 * @since 4.0.0
 */

namespace PraxisPortal\Form;

if (!defined('ABSPATH')) {
    exit;
}

class FormValidator
{
    /** @var array<string, string> Fehler: field_id => Fehlermeldung */
    private array $errors = [];

    /**
     * Alle Fehler zurückgeben
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Hat die Validierung Fehler?
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Fehler manuell hinzufügen
     */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    // ─── ANAMNESE-FORMULAR ───────────────────────────────────

    /**
     * Anamnesebogen validieren
     *
     * @param array $data   POST-Daten
     * @param array $formDefinition  JSON-Formulardefinition (aus FormConfig)
     * @return bool True wenn valide
     */
    public function validateAnamnese(array $data, array $formDefinition = []): bool
    {
        $this->errors = [];

        // 1. Pflichtfelder aus Formulardefinition
        if (!empty($formDefinition['fields'])) {
            $this->validateFieldDefinitions($data, $formDefinition['fields']);
        } else {
            // Fallback: Standard-Pflichtfelder
            $this->validateStandardRequired($data);
        }

        // 2. Geburtsdatum validieren (separate Felder oder Datums-Feld)
        $this->validateGeburtsdatum($data);

        // 3. E-Mail validieren
        if (!empty($data['email']) && !is_email($data['email'])) {
            $this->errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        }

        // 4. PLZ validieren (deutsche PLZ: 5 Ziffern)
        if (!empty($data['plz']) && !preg_match('/^[0-9]{5}$/', $data['plz'])) {
            $this->errors['plz'] = 'Bitte geben Sie eine gültige Postleitzahl ein.';
        }

        // 5. Telefon validieren
        $this->validateTelefon($data);

        // 6. Signatur-Pflicht für Privatpatienten
        $isPrivat = !empty($data['kasse']) && strtolower($data['kasse']) === 'privat';
        if ($isPrivat && empty($data['signature_data'])) {
            $this->errors['signature_data'] = 'Bitte unterschreiben Sie den Fragebogen.';
        }

        // 7. Datenschutz-Einwilligung
        if (empty($data['datenschutz_einwilligung'])) {
            $this->errors['datenschutz_einwilligung'] = 'Bitte stimmen Sie der Datenschutzerklärung zu.';
        }

        // 8. Custom Questions validieren
        $this->validateCustomQuestions($data);

        return empty($this->errors);
    }

    // ─── WIDGET-FORMULAR ─────────────────────────────────────

    /**
     * Widget Service-Anfrage validieren
     */
    public function validateWidgetRequest(array $data): bool
    {
        $this->errors = [];

        // DSGVO (alle üblichen Feldnamen akzeptieren)
        if (empty($data['dsgvo_consent']) && empty($data['datenschutz_einwilligung']) && empty($data['datenschutz'])) {
            $this->errors['dsgvo_consent'] = 'Bitte stimmen Sie der Datenschutzerklärung zu.';
        }

        // Service-Typ
        $serviceType = $data['service_type'] ?? '';
        $validTypes = ['rezept', 'ueberweisung', 'brillenverordnung', 'dokument', 'termin', 'terminabsage'];
        if (!in_array($serviceType, $validTypes, true)) {
            $this->errors['service_type'] = 'Ungültiger Service-Typ.';
        }

        // Pflichtfelder
        $required = [
            'vorname'  => 'Vorname',
            'nachname' => 'Nachname',
            'telefon'  => 'Telefonnummer',
            'email'    => 'E-Mail',
        ];

        foreach ($required as $field => $label) {
            if (empty($data[$field])) {
                $this->errors[$field] = "{$label} ist ein Pflichtfeld.";
            }
        }

        // Versicherung (beide Feldnamen: 'versicherung' oder 'kasse')
        if (empty($data['versicherung']) && empty($data['kasse'])) {
            $this->errors['versicherung'] = 'Versicherung ist ein Pflichtfeld.';
        }

        // Geburtsdatum (separate Felder)
        $this->validateGeburtsdatum($data);

        // E-Mail
        if (!empty($data['email']) && !is_email($data['email'])) {
            $this->errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        }

        // Telefon
        $this->validateTelefon($data);

        // Service-spezifische Validierung
        if (empty($this->errors) && !empty($serviceType)) {
            $this->validateServiceSpecific($data, $serviceType);
        }

        return empty($this->errors);
    }

    // ─── PRIVATE HELPERS ─────────────────────────────────────

    /**
     * Standard-Pflichtfelder validieren (wenn keine Formulardefinition)
     */
    private function validateStandardRequired(array $data): void
    {
        $required = [
            'vorname'  => 'Vorname',
            'nachname' => 'Nachname',
            'strasse'  => 'Straße + Hausnummer',
            'plz'      => 'Postleitzahl',
            'ort'      => 'Ort',
            'email'    => 'E-Mail',
            'telefon'  => 'Telefonnummer',
            'kasse'    => 'Versicherungsart',
        ];

        foreach ($required as $field => $label) {
            if (empty($data[$field])) {
                $this->errors[$field] = "{$label} ist ein Pflichtfeld.";
            }
        }
    }

    /**
     * Felder anhand JSON-Definition validieren
     */
    private function validateFieldDefinitions(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (empty($field['required']) || empty($field['enabled'])) {
                continue;
            }

            $fieldId = $field['id'];
            $label = $field['label'] ?? $fieldId;

            // Bedingte Felder prüfen
            if (!empty($field['condition'])) {
                if (!$this->isConditionMet($data, $field['condition'])) {
                    continue;
                }
            }

            // Spezielle Typen
            if (($field['type'] ?? '') === 'signature') {
                // Signatur wird separat geprüft
                continue;
            }

            if (($field['type'] ?? '') === 'date' && $fieldId === 'geburtsdatum') {
                // Geburtsdatum wird separat validiert
                continue;
            }

            if (empty($data[$fieldId])) {
                $this->errors[$fieldId] = "{$label} ist ein Pflichtfeld.";
            }
        }
    }

    /**
     * Bedingung prüfen (conditional fields)
     */
    private function isConditionMet(array $data, array $condition): bool
    {
        $condField = $condition['field'] ?? '';
        if (empty($condField) || !isset($data[$condField])) {
            return false;
        }

        $fieldValue = $data[$condField];

        // "contains" für checkbox_groups
        if (isset($condition['contains'])) {
            if (is_array($fieldValue)) {
                return in_array($condition['contains'], $fieldValue, true);
            }
            return $fieldValue === $condition['contains'];
        }

        // "value" für einfache Vergleiche
        if (isset($condition['value'])) {
            if (is_array($fieldValue)) {
                return in_array($condition['value'], $fieldValue, true);
            }
            return (string) $fieldValue === (string) $condition['value'];
        }

        return true;
    }

    /**
     * Geburtsdatum validieren
     */
    private function validateGeburtsdatum(array $data): void
    {
        // Variante 1: Separate Felder (tag, monat, jahr)
        if (isset($data['geburtsdatum_tag']) || isset($data['geburtsdatum_monat']) || isset($data['geburtsdatum_jahr'])) {
            $tag = trim($data['geburtsdatum_tag'] ?? '');
            $monat = trim($data['geburtsdatum_monat'] ?? '');
            $jahr = trim($data['geburtsdatum_jahr'] ?? '');

            if (empty($tag) || empty($monat) || empty($jahr)) {
                $this->errors['geburtsdatum'] = 'Bitte geben Sie Ihr Geburtsdatum vollständig ein.';
                return;
            }

            $this->validateDateParts((int) $tag, (int) $monat, (int) $jahr);
            return;
        }

        // Variante 2: Einzelnes Datumsfeld
        if (isset($data['geburtsdatum'])) {
            if (empty($data['geburtsdatum'])) {
                $this->errors['geburtsdatum'] = 'Geburtsdatum ist ein Pflichtfeld.';
                return;
            }

            // ISO-Format (YYYY-MM-DD) oder deutsch (TT.MM.JJJJ)
            $date = $data['geburtsdatum'];
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
                $this->validateDateParts((int) $m[3], (int) $m[2], (int) $m[1]);
            } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
                $this->validateDateParts((int) $m[1], (int) $m[2], (int) $m[3]);
            } else {
                $this->errors['geburtsdatum'] = 'Ungültiges Datumsformat.';
            }
        }
    }

    /**
     * Datumsteile validieren
     */
    private function validateDateParts(int $tag, int $monat, int $jahr): void
    {
        if ($tag < 1 || $tag > 31 || $monat < 1 || $monat > 12 || $jahr < 1900 || $jahr > (int) date('Y')) {
            $this->errors['geburtsdatum'] = 'Bitte geben Sie ein gültiges Geburtsdatum ein.';
            return;
        }

        if (!checkdate($monat, $tag, $jahr)) {
            $this->errors['geburtsdatum'] = 'Das eingegebene Datum existiert nicht.';
            return;
        }

        // Zukunft?
        $geb = new \DateTime("{$jahr}-{$monat}-{$tag}");
        if ($geb > new \DateTime()) {
            $this->errors['geburtsdatum'] = 'Das Geburtsdatum darf nicht in der Zukunft liegen.';
        }
    }

    /**
     * Telefonnummer validieren
     */
    private function validateTelefon(array $data): void
    {
        if (empty($data['telefon'])) {
            return;
        }

        $phone = preg_replace('/[^0-9+]/', '', $data['telefon']);
        if (strlen($phone) < 6 || strlen($phone) > 20) {
            $this->errors['telefon'] = 'Bitte geben Sie eine gültige Telefonnummer ein.';
        }
    }

    /**
     * Custom Questions validieren
     */
    private function validateCustomQuestions(array $data): void
    {
        $questions = get_option('pp_custom_questions', []);

        if (is_string($questions)) {
            $questions = json_decode($questions, true) ?: [];
        }

        if (!is_array($questions)) {
            return;
        }

        foreach ($questions as $question) {
            if (empty($question['active']) || empty($question['required']) || empty($question['id'])) {
                continue;
            }

            // Bedingung prüfen
            if (!empty($question['condition_field']) && !empty($question['condition_value'])) {
                $condValue = $data[$question['condition_field']] ?? null;
                if ($condValue === null) {
                    continue;
                }
                if (is_array($condValue)) {
                    if (!in_array($question['condition_value'], $condValue, true)) {
                        continue;
                    }
                } elseif ($condValue !== $question['condition_value']) {
                    continue;
                }
            }

            // Feld prüfen
            $checkField = $question['id'];
            if (($question['type'] ?? '') === 'file') {
                $checkField = $question['id'] . '_file_id';
            }

            if (empty($data[$checkField])) {
                $this->errors[$question['id']] = esc_html($question['label']) . ' ist ein Pflichtfeld.';
            }
        }
    }

    /**
     * Service-spezifische Widget-Validierung
     */
    private function validateServiceSpecific(array $data, string $serviceType): void
    {
        match ($serviceType) {
            'rezept' => $this->validateRezept($data),
            'ueberweisung' => $this->validateUeberweisung($data),
            'brillenverordnung' => $this->validateBrille($data),
            'termin' => $this->validateTermin($data),
            default => null,
        };
    }

    private function validateRezept(array $data): void
    {
        $medikamente = $data['medikamente'] ?? [];
        if (!is_array($medikamente)) {
            $medikamente = [];
        }
        $medikamente = array_filter($medikamente, fn($m) => !empty(trim($m)));

        if (empty($medikamente)) {
            $this->errors['medikamente'] = 'Bitte geben Sie mindestens ein Medikament an.';
        } elseif (count($medikamente) > 3) {
            $this->errors['medikamente'] = 'Maximal 3 Medikamente möglich.';
        }

        // Versandadresse bei Post-Lieferung
        if (($data['versicherung'] ?? '') === 'privat' && ($data['rezept_lieferung'] ?? '') === 'post') {
            if (empty($data['versand_strasse']) || empty($data['versand_plz']) || empty($data['versand_ort'])) {
                $this->errors['versand_strasse'] = 'Bitte Versandadresse angeben.';
            }
        }
    }

    private function validateUeberweisung(array $data): void
    {
        if (empty($data['fachrichtung'])) {
            $this->errors['fachrichtung'] = 'Bitte geben Sie die Fachrichtung an.';
        }
    }

    private function validateBrille(array $data): void
    {
        // Optional: spezielle Prüfung für Brillen-Daten
    }

    private function validateTermin(array $data): void
    {
        if (empty($data['termin_grund'])) {
            $this->errors['termin_grund'] = 'Bitte geben Sie einen Grund für den Termin an.';
        }
    }
}
