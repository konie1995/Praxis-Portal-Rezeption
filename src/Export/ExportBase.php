<?php
/**
 * Abstrakte Basisklasse für alle Export-Formate
 *
 * Stellt gemeinsame Funktionalität für GDT/BDT, HL7, FHIR und PDF bereit.
 * Mehrsprachig: de, en, fr, nl, it.
 *
 * v4-Änderungen gegenüber v3:
 * - DI statt statischer Klassen
 * - I18n-Klasse statt statischem $translations-Array
 * - Multi-Standort: location_uuid-aware
 * - Repository-Pattern für DB-Zugriff
 *
 * @package PraxisPortal\Export
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Export;

use PraxisPortal\Core\Container;
use PraxisPortal\I18n\I18n;
use PraxisPortal\Security\Encryption;
use PraxisPortal\Location\LocationManager;
use PraxisPortal\Location\LocationContext;

if (!defined('ABSPATH')) {
    exit;
}

abstract class ExportBase
{
    // =========================================================================
    // KONSTANTEN
    // =========================================================================

    /** Unterstützte Export-Sprachen (ISO 639-1) */
    public const SUPPORTED_LANGUAGES = ['de', 'en', 'fr', 'nl', 'it'];

    // =========================================================================
    // ABHÄNGIGKEITEN
    // =========================================================================

    protected Container $container;
    protected Encryption $encryption;
    protected LocationManager $locationManager;

    /** Aktuelle Location-UUID für Multi-Standort */
    protected string $locationUuid = '';

    /** Aktuelle Export-Sprache */
    protected string $language = 'de';

    // =========================================================================
    // ÜBERSETZUNGEN (medizinische Fachbegriffe)
    // =========================================================================

    /**
     * Mehrsprachige Übersetzungstabelle für medizinische Export-Texte.
     *
     * Enthält Kategorien, Warnungen, Seitenangaben, Diagnose-Sicherheiten,
     * Erkrankungsnamen und ICD-Texte.
     */
    protected static array $translations = [
        // ── Anamnese-Kategorien ──────────────────────────────────────────
        'eye_history' => [
            'de' => 'Augenanamnese',
            'en' => 'Eye History',
            'fr' => 'Antécédents ophtalmologiques',
            'nl' => 'Ooggeschiedenis',
            'it' => 'Anamnesi oculare',
        ],
        'family_history' => [
            'de' => 'Familienanamnese',
            'en' => 'Family History',
            'fr' => 'Antécédents familiaux',
            'nl' => 'Familiegeschiedenis',
            'it' => 'Anamnesi familiare',
        ],
        'general_history' => [
            'de' => 'Allgemeinanamnese',
            'en' => 'General Medical History',
            'fr' => 'Antécédents généraux',
            'nl' => 'Algemene anamnese',
            'it' => 'Anamnesi generale',
        ],
        'allergies' => [
            'de' => 'Allergien',
            'en' => 'Allergies',
            'fr' => 'Allergies',
            'nl' => 'Allergieën',
            'it' => 'Allergie',
        ],
        'medications' => [
            'de' => 'Medikamente',
            'en' => 'Medications',
            'fr' => 'Médicaments',
            'nl' => 'Medicijnen',
            'it' => 'Farmaci',
        ],
        'risk_factors' => [
            'de' => 'Risikofaktoren',
            'en' => 'Risk Factors',
            'fr' => 'Facteurs de risque',
            'nl' => 'Risicofactoren',
            'it' => 'Fattori di rischio',
        ],

        // ── Hinweise / Warnungen ─────────────────────────────────────────
        'note' => [
            'de' => 'HINWEIS',
            'en' => 'NOTE',
            'fr' => 'REMARQUE',
            'nl' => 'OPMERKING',
            'it' => 'NOTA',
        ],
        'warning' => [
            'de' => 'WARNUNG',
            'en' => 'WARNING',
            'fr' => 'AVERTISSEMENT',
            'nl' => 'WAARSCHUWING',
            'it' => 'AVVISO',
        ],

        // ── Medikamenten-Warnungen ───────────────────────────────────────
        'blood_thinner' => [
            'de' => 'Patient nimmt Blutverdünner ein',
            'en' => 'Patient takes blood thinners',
            'fr' => 'Patient sous anticoagulants',
            'nl' => 'Patiënt gebruikt bloedverdunners',
            'it' => 'Paziente assume anticoagulanti',
        ],
        'cortisone_regular' => [
            'de' => 'Regelmäßige Cortisoneinnahme',
            'en' => 'Regular cortisone use',
            'fr' => 'Prise régulière de cortisone',
            'nl' => 'Regelmatig cortisongebruik',
            'it' => 'Uso regolare di cortisone',
        ],
        'chloroquine' => [
            'de' => 'Chloroquineinnahme',
            'en' => 'Chloroquine use',
            'fr' => 'Prise de chloroquine',
            'nl' => 'Chloroquinegebruik',
            'it' => 'Uso di clorochina',
        ],
        'ifis_risk' => [
            'de' => 'IFIS-Risiko (Alpha-Blocker/Prostatamedikament)',
            'en' => 'IFIS risk (Alpha blocker/prostate medication)',
            'fr' => 'Risque IFIS (Alpha-bloquant/médicament prostate)',
            'nl' => 'IFIS-risico (Alfablocker/prostaatmedicatie)',
            'it' => 'Rischio IFIS (Alfa-bloccante/farmaco prostata)',
        ],
        'amiodarone' => [
            'de' => 'Amiodaron-Einnahme (Hornhautablagerungen möglich)',
            'en' => 'Amiodarone use (corneal deposits possible)',
            'fr' => "Prise d'amiodarone (dépôts cornéens possibles)",
            'nl' => 'Amiodarongebruik (hoornvliesafzettingen mogelijk)',
            'it' => 'Uso di amiodarone (depositi corneali possibili)',
        ],
        'pregnant_nursing' => [
            'de' => 'Patientin schwanger/stillend',
            'en' => 'Patient pregnant/nursing',
            'fr' => 'Patiente enceinte/allaitante',
            'nl' => 'Patiënt zwanger/borstvoeding',
            'it' => 'Paziente incinta/allattamento',
        ],

        // ── Ja / Nein ───────────────────────────────────────────────────
        'yes' => ['de' => 'Ja', 'en' => 'Yes', 'fr' => 'Oui', 'nl' => 'Ja', 'it' => 'Sì'],
        'no'  => ['de' => 'Nein', 'en' => 'No', 'fr' => 'Non', 'nl' => 'Nee', 'it' => 'No'],

        // ── Seitenangaben ────────────────────────────────────────────────
        'right' => ['de' => 'Rechts', 'en' => 'Right', 'fr' => 'Droit', 'nl' => 'Rechts', 'it' => 'Destro'],
        'left'  => ['de' => 'Links', 'en' => 'Left', 'fr' => 'Gauche', 'nl' => 'Links', 'it' => 'Sinistro'],
        'both'  => ['de' => 'Beide', 'en' => 'Both', 'fr' => 'Les deux', 'nl' => 'Beide', 'it' => 'Entrambi'],

        // ── Versicherung ─────────────────────────────────────────────────
        'public_insurance'  => [
            'de' => 'Gesetzlich versichert', 'en' => 'Public insurance',
            'fr' => 'Assurance publique', 'nl' => 'Zorgverzekering', 'it' => 'Assicurazione pubblica',
        ],
        'private_insurance' => [
            'de' => 'Privat versichert', 'en' => 'Private insurance',
            'fr' => 'Assurance privée', 'nl' => 'Privéverzekering', 'it' => 'Assicurazione privata',
        ],

        // ── Diagnose-Sicherheit ──────────────────────────────────────────
        'diagnosis_confirmed' => [
            'de' => 'Gesicherte Diagnose', 'en' => 'Confirmed diagnosis',
            'fr' => 'Diagnostic confirmé', 'nl' => 'Bevestigde diagnose', 'it' => 'Diagnosi confermata',
        ],
        'diagnosis_suspected' => [
            'de' => 'Verdachtsdiagnose', 'en' => 'Suspected diagnosis',
            'fr' => 'Diagnostic suspecté', 'nl' => 'Vermoedelijke diagnose', 'it' => 'Diagnosi sospetta',
        ],
        'diagnosis_history' => [
            'de' => 'Zustand nach', 'en' => 'History of',
            'fr' => 'Antécédent de', 'nl' => 'Toestand na', 'it' => 'Stato dopo',
        ],
        'diagnosis_excluded' => [
            'de' => 'Ausgeschlossen', 'en' => 'Excluded',
            'fr' => 'Exclu', 'nl' => 'Uitgesloten', 'it' => 'Escluso',
        ],

        // ── Erkrankungen (Klartext) ──────────────────────────────────────
        'glasses'               => ['de' => 'Brille', 'en' => 'Glasses', 'fr' => 'Lunettes', 'nl' => 'Bril', 'it' => 'Occhiali'],
        'contact_lenses'        => ['de' => 'Kontaktlinsen', 'en' => 'Contact lenses', 'fr' => 'Lentilles de contact', 'nl' => 'Contactlenzen', 'it' => 'Lenti a contatto'],
        'cataract'              => ['de' => 'Katarakt/Grauer Star', 'en' => 'Cataract', 'fr' => 'Cataracte', 'nl' => 'Cataract/Staar', 'it' => 'Cataratta'],
        'glaucoma'              => ['de' => 'Glaukom/Grüner Star', 'en' => 'Glaucoma', 'fr' => 'Glaucome', 'nl' => 'Glaucoom', 'it' => 'Glaucoma'],
        'macular_degeneration'  => ['de' => 'Makuladegeneration', 'en' => 'Macular degeneration', 'fr' => 'Dégénérescence maculaire', 'nl' => 'Maculadegeneratie', 'it' => 'Degenerazione maculare'],
        'diabetes'              => ['de' => 'Diabetes mellitus', 'en' => 'Diabetes mellitus', 'fr' => 'Diabète', 'nl' => 'Diabetes mellitus', 'it' => 'Diabete mellito'],
        'hypertension'          => ['de' => 'Bluthochdruck', 'en' => 'Hypertension', 'fr' => 'Hypertension', 'nl' => 'Hoge bloeddruk', 'it' => 'Ipertensione'],

        // ── ICD-Texte (häufigste Codes) ──────────────────────────────────
        'icd_Z96.1' => ['de' => 'Pseudophakie', 'en' => 'Pseudophakia', 'fr' => 'Pseudophakie', 'nl' => 'Pseudofakie', 'it' => 'Pseudofachia'],
        'icd_H40.1' => ['de' => 'Primäres Offenwinkelglaukom', 'en' => 'Primary open-angle glaucoma', 'fr' => 'Glaucome primitif à angle ouvert', 'nl' => 'Primair openkamerhoekglaucoom', 'it' => 'Glaucoma primario ad angolo aperto'],
        'icd_H33.0' => ['de' => 'Netzhautablösung mit Netzhautriss', 'en' => 'Retinal detachment with retinal break', 'fr' => 'Décollement de la rétine avec déchirure', 'nl' => 'Netvliesloslating met netvliesscheur', 'it' => 'Distacco di retina con rottura'],
        'icd_H35.3' => ['de' => 'Altersbedingte Makuladegeneration', 'en' => 'Age-related macular degeneration', 'fr' => "Dégénérescence maculaire liée à l'âge", 'nl' => 'Leeftijdsgebonden maculadegeneratie', 'it' => "Degenerazione maculare legata all'età"],
        'icd_E10'   => ['de' => 'Diabetes mellitus Typ 1', 'en' => 'Type 1 diabetes mellitus', 'fr' => 'Diabète de type 1', 'nl' => 'Diabetes mellitus type 1', 'it' => 'Diabete mellito tipo 1'],
        'icd_E11'   => ['de' => 'Diabetes mellitus Typ 2', 'en' => 'Type 2 diabetes mellitus', 'fr' => 'Diabète de type 2', 'nl' => 'Diabetes mellitus type 2', 'it' => 'Diabete mellito tipo 2'],
        'icd_I10'   => ['de' => 'Essentielle Hypertonie', 'en' => 'Essential hypertension', 'fr' => 'Hypertension essentielle', 'nl' => 'Essentiële hypertensie', 'it' => 'Ipertensione essenziale'],
    ];

    // =========================================================================
    // KONSTRUKTOR
    // =========================================================================

    /**
     * @param Container $container  DI-Container
     * @param string    $language   Export-Sprache (ISO 639-1), Default: de
     */
    public function __construct(Container $container, string $language = 'de')
    {
        $this->container       = $container;
        $this->encryption      = $container->get(Encryption::class);
        $this->locationManager = $container->get(LocationManager::class);

        // Location-UUID aus aktuellem Kontext
        if ($container->has(LocationContext::class)) {
            $ctx = $container->get(LocationContext::class);
            $this->locationUuid = $ctx->getLocationUuid() ?? '';
        }

        $this->setLanguage($language);
    }

    // =========================================================================
    // SPRACHE
    // =========================================================================

    /**
     * Setzt die Export-Sprache
     */
    public function setLanguage(string $language): void
    {
        $this->language = in_array($language, self::SUPPORTED_LANGUAGES, true)
            ? $language
            : 'de';
    }

    /**
     * Aktuelle Export-Sprache
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    // =========================================================================
    // ÜBERSETZUNGS-HELFER
    // =========================================================================

    /**
     * Übersetzt einen medizinischen Schlüsselbegriff
     *
     * @param string $key      Übersetzungsschlüssel
     * @param string $fallback Fallback wenn nicht gefunden
     */
    protected function t(string $key, string $fallback = ''): string
    {
        // 1. Aktuelle Sprache
        if (isset(self::$translations[$key][$this->language])) {
            return self::$translations[$key][$this->language];
        }

        // 2. Fallback auf Deutsch
        if (isset(self::$translations[$key]['de'])) {
            return self::$translations[$key]['de'];
        }

        return $fallback !== '' ? $fallback : $key;
    }

    /**
     * Formatiert einen Hinweis/Warnung
     *
     * @param string $messageKey Übersetzungsschlüssel der Nachricht
     * @param string $type       'note' oder 'warning'
     */
    protected function formatNote(string $messageKey, string $type = 'note'): string
    {
        return $this->t($type) . ': ' . $this->t($messageKey, $messageKey);
    }

    /**
     * Konvertiert Ja/Nein-Werte
     */
    protected function translateYesNo(string $value): string
    {
        $value = strtolower($value);
        if (in_array($value, ['ja', 'yes', 'oui', 'sì', '1', 'true'], true)) {
            return $this->t('yes');
        }
        return $this->t('no');
    }

    /**
     * Konvertiert Seitenangaben (R/L/B)
     */
    protected function translateSide(string $side): string
    {
        return match (strtoupper($side)) {
            'R' => $this->t('right'),
            'L' => $this->t('left'),
            'B' => $this->t('both'),
            default => $side,
        };
    }

    /**
     * Konvertiert Diagnose-Sicherheit (G/V/Z/A)
     */
    protected function translateDiagnosisCertainty(string $certainty): string
    {
        return match (strtoupper($certainty)) {
            'G' => $this->t('diagnosis_confirmed'),
            'V' => $this->t('diagnosis_suspected'),
            'Z' => $this->t('diagnosis_history'),
            'A' => $this->t('diagnosis_excluded'),
            default => $certainty,
        };
    }

    /**
     * Übersetzt ICD-Diagnosetext
     *
     * Versucht exakten Code, dann Basis-Code (z.B. E11.30 → E11).
     *
     * @param string $icdCode      ICD-10 Code
     * @param string $originalText Originaler deutscher Text
     */
    protected function translateIcdText(string $icdCode, string $originalText): string
    {
        // Normalisiere Code (entferne '-' am Ende etc.)
        $normalized = preg_replace('/[^A-Z0-9.]/', '', strtoupper($icdCode));

        // 1. Exakte Übersetzung
        $key = 'icd_' . $normalized;
        if (isset(self::$translations[$key][$this->language])) {
            return self::$translations[$key][$this->language];
        }

        // 2. Basis-Code (z.B. E11.30 → E11)
        $baseCode = explode('.', $normalized)[0];
        $baseKey  = 'icd_' . $baseCode;
        if (isset(self::$translations[$baseKey][$this->language])) {
            return self::$translations[$baseKey][$this->language];
        }

        // 3. Original zurückgeben
        return $originalText;
    }

    // =========================================================================
    // STANDORT-HELFER
    // =========================================================================

    /**
     * Location-ID (int) aus der UUID auflösen
     *
     * Wird für hasFeature() benötigt, das ?int erwartet.
     * Export-Klassen mit strict_types würden sonst einen TypeError werfen.
     *
     * @since 4.2.9
     */
    protected function getLocationId(): ?int
    {
        if (empty($this->locationUuid)) {
            return null;
        }

        $location = $this->locationManager->getByUuid($this->locationUuid);
        return $location ? (int) $location['id'] : null;
    }

    /**
     * Praxisinformationen für den aktuellen Standort laden
     *
     * @param string $locationUuid Standort-UUID (leer = globale Settings)
     * @return array{name: string, address: string, phone: string, email: string}
     */
    protected function getPraxisInfo(string $locationUuid = ''): array
    {
        // UUID aus Property als Fallback
        $uuid = $locationUuid !== '' ? $locationUuid : $this->locationUuid;

        if ($uuid !== '') {
            $location = $this->locationManager->getByUuid($uuid);
            if ($location) {
                return [
                    'name'    => $location['name'] ?? '',
                    'address' => trim(($location['street'] ?? '') . ', '
                        . ($location['postal_code'] ?? '') . ' ' . ($location['city'] ?? ''), ', '),
                    'phone'   => $location['phone'] ?? '',
                    'email'   => $location['email_notification'] ?? '',
                ];
            }
        }

        // Fallback auf globale Einstellungen
        return [
            'name'    => get_option('pp_practice_name', get_bloginfo('name')),
            'address' => get_option('pp_practice_address', ''),
            'phone'   => get_option('pp_practice_phone', ''),
            'email'   => get_option('pp_practice_email', ''),
        ];
    }

    // =========================================================================
    // ABSTRAKTE METHODEN
    // =========================================================================

    /**
     * Exportiert Formulardaten
     *
     * @param array       $formData   Entschlüsselte Formulardaten
     * @param object|null $submission Submission-Objekt (ID, created_at, location_id, …)
     * @return mixed Export-Daten (String oder Array je nach Format)
     */
    abstract public function export(array $formData, ?object $submission = null): mixed;

    /**
     * MIME-Type für den Export
     */
    abstract public function getMimeType(): string;

    /**
     * Dateiendung für den Export (ohne Punkt)
     */
    abstract public function getFileExtension(): string;
}
