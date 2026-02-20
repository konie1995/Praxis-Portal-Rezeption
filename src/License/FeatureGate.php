<?php
declare(strict_types=1);
/**
 * Feature-Gate
 * 
 * Prüft ob ein Feature für die aktuelle Lizenz verfügbar ist.
 * Zentrale Stelle für Feature-Checks – ersetzt verstreute Prüfungen.
 *
 * Nutzung:
 *   $gate = Plugin::make(FeatureGate::class);
 *   if ($gate->can('gdt_export')) { ... }
 *   if ($gate->canAddLocation()) { ... }
 *
 * @package PraxisPortal\License
 * @since   4.0.0
 */

namespace PraxisPortal\License;

if (!defined('ABSPATH')) {
    exit;
}

class FeatureGate
{
    private LicenseManager $license;
    
    public function __construct(LicenseManager $license)
    {
        $this->license = $license;
    }
    
    /**
     * Prüft ob ein Feature verfügbar ist
     */
    public function can(string $feature): bool
    {
        return $this->license->isValid() && $this->license->hasFeature($feature);
    }
    
    /**
     * Ob GDT/BDT-Export verfügbar ist
     */
    public function canExportGdt(): bool
    {
        return $this->can('gdt_export');
    }
    
    /**
     * Ob FHIR-Export verfügbar ist
     */
    public function canExportFhir(): bool
    {
        return $this->can('fhir_export');
    }
    
    /**
     * Ob HL7-Export verfügbar ist
     */
    public function canExportHl7(): bool
    {
        return $this->can('hl7_export');
    }
    
    /**
     * Ob PDF-Export verfügbar ist (immer, auch Free)
     */
    public function canExportPdf(): bool
    {
        return $this->can('pdf_export');
    }
    
    /**
     * Ob PVS-API verfügbar ist
     */
    public function canUseApi(): bool
    {
        return $this->can('api_access');
    }
    
    /**
     * Ob E-Mail-Benachrichtigungen verfügbar sind
     */
    public function canSendEmails(): bool
    {
        return $this->can('email_notifications');
    }
    
    /**
     * Ob Multi-Standort verfügbar ist
     */
    public function canUseMultiLocation(): bool
    {
        return $this->can('multi_location');
    }
    
    /**
     * Ob White-Label verfügbar ist
     */
    public function canUseWhiteLabel(): bool
    {
        return $this->can('white_label');
    }
    
    /**
     * Ob ein neuer Standort hinzugefügt werden kann
     * (Feature UND Limit prüfen)
     */
    public function canAddLocation(): bool
    {
        return $this->license->canAddLocation();
    }
    
    /**
     * Aktueller Plan-Name
     */
    public function getPlan(): string
    {
        return $this->license->getPlan();
    }
    
    /**
     * Ob es ein bezahlter Plan ist
     */
    public function isPremium(): bool
    {
        return in_array($this->getPlan(), ['premium', 'premium_plus'], true);
    }
    
    /**
     * Ob es der höchste Plan ist
     */
    public function isPremiumPlus(): bool
    {
        return $this->getPlan() === 'premium_plus';
    }
    
    /**
     * Alias für getPlan() – Kompatibilität mit Admin-Seiten
     */
    public function getCurrentPlan(): string
    {
        return $this->getPlan();
    }
    
    /**
     * Alle verfügbaren Features des aktuellen Plans
     */
    public function getAvailableFeatures(): array
    {
        $status = $this->license->getStatus();
        return $status['features'] ?? [];
    }
    
    /**
     * Prüft ob ein Feature verfügbar ist (optional standortbezogen)
     */
    public function hasFeature(string $feature, ?int $locationId = null): bool
    {
        return $this->can($feature);
    }

    /**
     * Medikamenten-Verwaltung: Für alle Pläne verfügbar (inkl. Free)
     */
    public function canManageMedications(): bool
    {
        return true;
    }

    /**
     * Brillenverordnung: Für alle Pläne verfügbar (inkl. Free).
     * Darf NICHT hinter eine Premium-Prüfung gestellt werden.
     */
    public function canUseBrille(): bool
    {
        return true;
    }

    /**
     * Prüft ob ein Widget-Service kostenlos (= ohne Premium) nutzbar ist.
     *
     * Alle Basis-Widget-Services (Rezept, Überweisung, Brille, Dokument,
     * Termin, Terminabsage) sind free. Nur das monatliche Anfrage-Limit
     * (50/Monat) gilt im Free-Plan.
     *
     * Premium-Gating betrifft NUR: Export-Formate (GDT, HL7, FHIR),
     * Multi-Standort, White-Label, API-Zugang, unbegrenzte Submissions.
     */
    public function isServiceFree(string $serviceKey): bool
    {
        $freeServices = [
            'rezept',
            'ueberweisung',
            'brillenverordnung',
            'dokument',
            'termin',
            'terminabsage',
        ];

        return in_array($serviceKey, $freeServices, true);
    }

    /**
     * Unbegrenzte Widget-Submissions (Premium)
     * Free-Plan: 50 Anfragen/Monat
     */
    public function hasUnlimitedSubmissions(): bool
    {
        return $this->can('unlimited_submissions');
    }
}
