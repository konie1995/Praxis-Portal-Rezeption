<?php
declare(strict_types=1);
/**
 * Export-Konfiguration – zentrale Steuerung für alle Export-Formate
 *
 * v4-Änderungen gegenüber v3:
 * - DI-Container statt statischer Klassen
 * - FeatureGate statt PP_License
 * - Multi-Standort: Export-Einstellungen pro location_id
 * - PLACE_ID / LICENSE_KEY Felder für künftigen Lizenzserver
 * - Methoden nicht mehr static → Instanzmethoden mit DI
 *
 * Lizenz-Stufen:
 * - FREE:         Nur PDF-Export
 * - PREMIUM:      PDF + GDT + HL7
 * - PREMIUM+:     PDF + GDT + GDT+Archiv + HL7 + FHIR + API
 *
 * @package PraxisPortal\Export
 * @since   4.0.0
 */

namespace PraxisPortal\Export;

use PraxisPortal\Core\Container;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\Location\LocationManager;

if (!defined('ABSPATH')) {
    exit;
}

class ExportConfig
{
    // =========================================================================
    // KONSTANTEN
    // =========================================================================

    /**
     * Verfügbare Export-Formate mit Lizenz-Gating und Icons
     *
     * 'gate' verweist auf FeatureGate-Methode, 'free' = immer verfügbar
     */
    public const FORMATS = [
        'pdf'       => ['label' => 'PDF',          'icon' => '📄', 'gate' => 'canExportPdf',  'free' => true],
        'gdt'       => ['label' => 'GDT / BDT',    'icon' => '📋', 'gate' => 'canExportGdt',  'free' => true],  // DEBUG: Lizenz deaktiviert
        'gdt_image' => ['label' => 'GDT + Archiv',  'icon' => '🗄️', 'gate' => 'canExportGdt',  'free' => true],  // DEBUG: Lizenz deaktiviert
        'hl7'       => ['label' => 'HL7 v2.5',     'icon' => '🏥', 'gate' => 'canExportHl7',  'free' => true],  // DEBUG: Lizenz deaktiviert
        'fhir'      => ['label' => 'FHIR R4',      'icon' => '🔥', 'gate' => 'canExportFhir', 'free' => true],  // DEBUG: Lizenz deaktiviert
    ];

    /**
     * Verfügbare Export-Kontexte
     */
    public const CONTEXT_WIDGET   = 'widget';
    public const CONTEXT_ANAMNESE = 'anamnese';

    /**
     * Patiententypen für Anamnese-Konfiguration
     */
    public const PATIENT_KASSE  = 'kasse';
    public const PATIENT_PRIVAT = 'privat';

    /**
     * PDF-Ausgabetypen
     */
    public const PDF_FULL       = 'full';
    public const PDF_STAMMDATEN = 'stammdaten';

    /**
     * Options-Prefix für WordPress-Optionen
     */
    private const OPT_PREFIX = 'pp_export_';

    // =========================================================================
    // ABHÄNGIGKEITEN
    // =========================================================================

    private FeatureGate     $featureGate;
    private LocationManager $locationManager;

    // =========================================================================
    // KONSTRUKTOR
    // =========================================================================

    public function __construct(Container $container)
    {
        $this->featureGate     = $container->get(FeatureGate::class);
        $this->locationManager = $container->get(LocationManager::class);
    }

    /**
     * Option für einen Standort auslesen (Fallback auf globale Option)
     *
     * Sucht zuerst in pp_export_{key}_{locationId}, dann in pp_export_{key},
     * dann Fallback auf $default.
     *
     * @param string $key        Option-Suffix (z.B. 'widget_format')
     * @param mixed  $default    Fallback-Wert
     * @param int    $locationId 0 = nur globale Option
     * @return mixed
     */
    private function getLocationOption(string $key, $default = '', int $locationId = 0)
    {
        // Per-Location Option prüfen
        if ($locationId > 0) {
            $locValue = get_option("pp_export_{$key}_{$locationId}", null);
            if ($locValue !== null) {
                return $locValue;
            }
        }

        // Globale Option
        return get_option("pp_export_{$key}", $default);
    }

    // =========================================================================
    // FORMAT-VERFÜGBARKEIT
    // =========================================================================

    /**
     * Prüft ob ein Format für die aktuelle Lizenz freigeschaltet ist
     *
     * @param string $format Format-Schlüssel aus FORMATS
     */
    public function isFormatAvailable(string $format): bool
    {
        if (!isset(self::FORMATS[$format])) {
            return false;
        }

        $def = self::FORMATS[$format];

        // Free-Formate sind immer verfügbar
        if ($def['free']) {
            return true;
        }

        // FeatureGate-Methode aufrufen
        $method = $def['gate'];
        if (method_exists($this->featureGate, $method)) {
            return $this->featureGate->{$method}();
        }

        return false;
    }

    /**
     * Prüft ob Premium-Export-Formate verfügbar sind
     */
    public function hasPremiumExport(): bool
    {
        return $this->featureGate->isPremium()
            || $this->featureGate->canUseApi();
    }

    /**
     * Gibt alle für die aktuelle Lizenz verfügbaren Formate zurück
     *
     * @return array<string, array> Format-Key => Definition
     */
    public static function getAvailableFormats(): array
    {
        $available = [];
        foreach (self::FORMATS as $key => $def) {
            $available[$key] = $def;
        }
        return $available;
    }

    /**
     * Gibt lizenzgefilterte Formate zurück (benötigt Instanz)
     *
     * @return array<string, array>
     */
    public function getLicensedFormats(): array
    {
        $available = [];
        foreach (self::FORMATS as $key => $def) {
            if ($this->isFormatAvailable($key)) {
                $available[$key] = $def;
            }
        }
        return $available;
    }

    // =========================================================================
    // WIDGET-KONFIGURATION
    // =========================================================================

    /**
     * Holt die Widget-Export-Konfiguration für einen Standort
     *
     * @param int $locationId 0 = globale Einstellung
     * @return array{format: string, delete_after: bool}
     */
    public function getWidgetConfig(int $locationId = 0): array
    {
        return [
            'format'       => $this->getLocationOption('widget_format', 'pdf', $locationId),
            'delete_after' => (bool) $this->getLocationOption('widget_delete_after', false, $locationId),
        ];
    }

    // =========================================================================
    // ANAMNESE-KONFIGURATION
    // =========================================================================

    /**
     * Holt die Anamnese-Export-Konfiguration für Patiententyp + Standort
     *
     * @param string $patientType 'kasse' oder 'privat'
     * @param int    $locationId  0 = globale Einstellung
     * @return array{pdf_type: string, format: string, delete_after: bool}
     */
    public function getAnamneseConfig(string $patientType = self::PATIENT_PRIVAT, int $locationId = 0): array
    {
        $type = in_array($patientType, [self::PATIENT_KASSE, self::PATIENT_PRIVAT], true)
            ? $patientType
            : self::PATIENT_PRIVAT;

        // Defaults je nach Patiententyp
        $defaults = [
            self::PATIENT_KASSE  => ['pdf_type' => self::PDF_STAMMDATEN, 'format' => 'pdf', 'delete' => true],
            self::PATIENT_PRIVAT => ['pdf_type' => self::PDF_FULL,       'format' => 'pdf', 'delete' => true],
        ];

        $d = $defaults[$type];

        return [
            'pdf_type'     => $this->getLocationOption("anamnese_{$type}_pdf_type",     $d['pdf_type'], $locationId),
            'format'       => $this->getLocationOption("anamnese_{$type}_format",        $d['format'],   $locationId),
            'delete_after' => (bool) $this->getLocationOption("anamnese_{$type}_delete_after", $d['delete'], $locationId),
        ];
    }

    /**
     * Gibt den PDF-Typ für Anamnese zurück
     *
     * @param string $patientType 'kasse' oder 'privat'
     * @param int    $locationId  Standort-ID
     * @return string 'full' oder 'stammdaten'
     */
    public function getAnamnesePdfType(string $patientType = self::PATIENT_PRIVAT, int $locationId = 0): string
    {
        $config = $this->getAnamneseConfig($patientType, $locationId);
        return $config['pdf_type'];
    }

    // =========================================================================
    // PVS-ARCHIV-KONFIGURATION (GDT + Bild)
    // =========================================================================

    /**
     * PVS-Archiv-Einstellungen für einen Standort
     *
     * @param int $locationId Standort-ID
     * @return array{gdt_path: string, image_path: string, sender_id: string, receiver_id: string}
     */
    public function getPvsArchiveConfig(int $locationId = 0): array
    {
        return [
            'gdt_path'    => $this->getLocationOption('pvs_gdt_path',     '', $locationId),
            'image_path'  => $this->getLocationOption('pvs_image_path',   '', $locationId),
            'sender_id'   => $this->getLocationOption('pvs_sender_id',    'PRAXPORTAL', $locationId),
            'receiver_id' => $this->getLocationOption('pvs_receiver_id',  'PRAX_EDV',   $locationId),
        ];
    }

    /**
     * Prüft ob GDT + Archiv-Export verfügbar ist (Pfade konfiguriert)
     *
     * @param int $locationId Standort-ID
     */
    public function isGdtImageAvailable(int $locationId = 0): bool
    {
        $config = $this->getPvsArchiveConfig($locationId);
        return !empty($config['gdt_path']) && !empty($config['image_path']);
    }

    // =========================================================================
    // PLACE-ID & LIZENZ-IDENTIFIKATOREN
    // =========================================================================

    /**
     * Gibt die PLACE_ID für einen Standort zurück
     *
     * PLACE_IDs identifizieren physische Praxis-Standorte eindeutig
     * und werden vom künftigen Lizenzserver verwendet.
     *
     * @param int $locationId Standort-ID
     * @return string PLACE_ID oder leer wenn nicht konfiguriert
     */
    public function getPlaceId(int $locationId = 0): string
    {
        // PLACE_ID ist in der locations-Tabelle gespeichert
        if ($locationId > 0) {
            $location = $this->locationManager->getById($locationId);
            return $location['uuid'] ?? '';
        }

        return get_option(self::OPT_PREFIX . 'place_id', '');
    }

    /**
     * Gibt den LICENSE_KEY zurück
     *
     * @return string Verschlüsselter Lizenzschlüssel
     */
    public function getLicenseKey(): string
    {
        return get_option('pp_license_key', '');
    }

    // =========================================================================
    // BUTTONS FÜR UI
    // =========================================================================

    /**
     * Gibt die verfügbaren Export-Buttons für einen Kontext zurück
     *
     * Berücksichtigt:
     * - Lizenz-Stufe (FREE = nur PDF)
     * - Admin-Konfiguration (welches Format eingestellt)
     * - Standort-spezifische Einstellungen
     * - PVS-Archiv-Verfügbarkeit (für GDT + Bild)
     *
     * @param string $context     'widget' oder 'anamnese'
     * @param string $patientType Für Anamnese: 'kasse' oder 'privat'
     * @param int    $locationId  Standort-ID
     * @return array<string, array> Button-Definitionen
     */
    public function getAvailableButtons(
        string $context     = self::CONTEXT_WIDGET,
        string $patientType = self::PATIENT_PRIVAT,
        int    $locationId  = 0
    ): array {
        $buttons = [];

        // PDF ist immer verfügbar
        $buttons['pdf'] = [
            'format' => 'pdf',
            'label'  => 'PDF herunterladen',
            'icon'   => '📄',
            'class'  => 'button-primary',
        ];

        // Ohne Premium keine weiteren Buttons
        if (!$this->hasPremiumExport()) {
            return $buttons;
        }

        // Konfiguriertes Format für diesen Kontext + Standort holen
        $format = $this->getEffectiveFormat($context, $patientType, $locationId);

        // Wenn nur PDF konfiguriert → keine weiteren Buttons
        if ($format === 'pdf') {
            return $buttons;
        }

        // GDT-Button
        if (in_array($format, ['gdt', 'gdt_image'], true) && $this->isFormatAvailable('gdt')) {
            $buttons['gdt'] = [
                'format' => 'gdt',
                'label'  => 'GDT herunterladen',
                'icon'   => '📋',
                'class'  => 'button-secondary',
            ];
        }

        // GDT + Archiv (nur wenn Pfade für diesen Standort konfiguriert)
        if ($format === 'gdt_image' && $this->isGdtImageAvailable($locationId) && $this->isFormatAvailable('gdt')) {
            $buttons['gdt_image'] = [
                'format' => 'gdt_image',
                'label'  => 'GDT + Archiv',
                'icon'   => '🗄️',
                'class'  => 'button-secondary',
            ];
        }

        // HL7-Button
        if ($format === 'hl7' && $this->isFormatAvailable('hl7')) {
            $buttons['hl7'] = [
                'format' => 'hl7',
                'label'  => 'HL7 herunterladen',
                'icon'   => '🏥',
                'class'  => 'button-secondary',
            ];
        }

        // FHIR-Button (nur Anamnese-Kontext)
        if ($format === 'fhir' && $context === self::CONTEXT_ANAMNESE && $this->isFormatAvailable('fhir')) {
            $buttons['fhir'] = [
                'format' => 'fhir',
                'label'  => 'FHIR herunterladen',
                'icon'   => '🔥',
                'class'  => 'button-secondary',
            ];
        }

        return $buttons;
    }

    // =========================================================================
    // API-FORMAT-BESTIMMUNG
    // =========================================================================

    /**
     * Gibt das effektive Export-Format zurück (mit Lizenz- und Verfügbarkeits-Checks)
     *
     * Fallback-Kette:
     * 1. Konfiguriertes Format
     * 2. Wenn nicht lizenziert → PDF
     * 3. Wenn GDT+Bild ohne Pfade → GDT
     *
     * @param string $context     'widget' oder 'anamnese'
     * @param string $patientType 'kasse' oder 'privat'
     * @param int    $locationId  Standort-ID
     * @return string Format-Code
     */
    public function getEffectiveFormat(
        string $context     = self::CONTEXT_WIDGET,
        string $patientType = self::PATIENT_PRIVAT,
        int    $locationId  = 0
    ): string {
        // 1. Ohne Premium → immer PDF
        if (!$this->hasPremiumExport()) {
            return 'pdf';
        }

        // 2. Konfiguriertes Format für den Kontext holen
        if ($context === self::CONTEXT_WIDGET) {
            $config = $this->getWidgetConfig($locationId);
        } else {
            $config = $this->getAnamneseConfig($patientType, $locationId);
        }

        $format = $config['format'] ?? 'pdf';

        // 3. Format-Verfügbarkeit prüfen
        if (!$this->isFormatAvailable($format)) {
            return 'pdf';
        }

        // 4. GDT+Bild: Prüfen ob Pfade konfiguriert
        if ($format === 'gdt_image' && !$this->isGdtImageAvailable($locationId)) {
            return 'gdt';
        }

        return $format;
    }
}