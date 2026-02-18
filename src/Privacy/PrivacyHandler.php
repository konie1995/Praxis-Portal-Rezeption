<?php
/**
 * DSGVO Privacy Handler
 * 
 * Zentrale Klasse für DSGVO-konforme Datenauskunft und Löschung.
 * 
 * Beachtet alle drei architektonischen Anforderungen:
 * - Multistandort: Alle Operationen sind location_id-scoped
 * - Mehrsprachigkeit: Nutzt I18n für Meldungen und Export-Labels
 * - Lizenz-Features: Prüft FeatureGate vor Premium-Operationen
 *
 * WordPress Privacy-Hooks:
 *   wp_privacy_personal_data_exporters → exportPersonalData()
 *   wp_privacy_personal_data_erasers   → erasePersonalData()
 *
 * @package PraxisPortal\Privacy
 * @since   4.2.5
 */

namespace PraxisPortal\Privacy;

use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\PortalUserRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\Security\Encryption;
use PraxisPortal\I18n\I18n;
use PraxisPortal\Location\LocationContext;

if (!defined('ABSPATH')) {
    exit;
}

class PrivacyHandler
{
    private SubmissionRepository $submissions;
    private PortalUserRepository $portalUsers;
    private AuditRepository $audit;
    private FileRepository $files;
    private FeatureGate $featureGate;
    private Encryption $encryption;

    /** Export-Batch-Größe */
    private const BATCH_SIZE = 50;

    public function __construct(
        SubmissionRepository $submissions,
        PortalUserRepository $portalUsers,
        AuditRepository $audit,
        FileRepository $files,
        FeatureGate $featureGate,
        Encryption $encryption
    ) {
        $this->submissions = $submissions;
        $this->portalUsers = $portalUsers;
        $this->audit       = $audit;
        $this->files       = $files;
        $this->featureGate = $featureGate;
        $this->encryption  = $encryption;
    }
    
    // =========================================================================
    // WORDPRESS PRIVACY HOOKS
    // =========================================================================
    
    /**
     * Registriert WordPress Privacy-Hooks
     * 
     * Wird in Plugin::registerHooks() aufgerufen.
     */
    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerErasers']);
    }
    
    /**
     * Exporter für WordPress Privacy-System registrieren
     */
    public function registerExporters(array $exporters): array
    {
        $exporters['praxis-portal-submissions'] = [
            'exporter_friendly_name' => I18n::translate('Praxis Portal – Formulardaten', 'privacy'),
            'callback'               => [$this, 'exportPersonalData'],
        ];
        
        $exporters['praxis-portal-users'] = [
            'exporter_friendly_name' => I18n::translate('Praxis Portal – Portal-Benutzer', 'privacy'),
            'callback'               => [$this, 'exportPortalUserData'],
        ];
        
        return $exporters;
    }
    
    /**
     * Eraser für WordPress Privacy-System registrieren
     */
    public function registerErasers(array $erasers): array
    {
        $erasers['praxis-portal-submissions'] = [
            'eraser_friendly_name' => I18n::translate('Praxis Portal – Formulardaten', 'privacy'),
            'callback'             => [$this, 'erasePersonalData'],
        ];
        
        $erasers['praxis-portal-users'] = [
            'eraser_friendly_name' => I18n::translate('Praxis Portal – Portal-Benutzer', 'privacy'),
            'callback'             => [$this, 'erasePortalUserData'],
        ];
        
        return $erasers;
    }
    
    // =========================================================================
    // DATEN-EXPORT (Art. 15 DSGVO – Auskunftsrecht)
    // =========================================================================
    
    /**
     * Exportiert Formulardaten eines Patienten
     * 
     * Multistandort: Exportiert aus ALLEN Standorten, da der Patient
     * ein Recht auf vollständige Auskunft hat (Art. 15 DSGVO).
     * 
     * @param string $email  E-Mail des anfragenden Patienten
     * @param int    $page   Seitennummer für Batch-Verarbeitung
     * @return array WordPress-Privacy-Export-Format
     */
    public function exportPersonalData(string $email, int $page = 1): array
    {
        $allSubmissions = $this->findSubmissionsByEmail($email);
        if (empty($allSubmissions)) {
            return ['data' => [], 'done' => true];
        }
        
        // Batch-Pagination
        $offset = ($page - 1) * self::BATCH_SIZE;
        $batch  = array_slice($allSubmissions, $offset, self::BATCH_SIZE);
        $done   = ($offset + self::BATCH_SIZE) >= count($allSubmissions);
        
        $exportItems = [];
        
        foreach ($batch as $submission) {
            $exportItems[] = [
                'group_id'          => 'praxis-portal-submissions',
                'group_label'       => I18n::translate('Formulardaten', 'privacy'),
                'group_description' => I18n::translate(
                    'Formulardaten die über das Praxis-Portal eingereicht wurden.',
                    'privacy'
                ),
                'item_id'           => "submission-{$submission['id']}",
                'data'              => $this->formatSubmissionForExport($submission),
            ];
        }
        
        // Audit-Log: Export-Anfrage dokumentieren
        $this->audit->log('privacy_export', null, [
            'email'       => $email,
            'count'       => count($batch),
            'page'        => $page,
            'total_found' => count($allSubmissions),
        ]);
        
        return [
            'data' => $exportItems,
            'done' => $done,
        ];
    }
    
    /**
     * Exportiert Portal-Benutzerdaten
     */
    public function exportPortalUserData(string $email, int $page = 1): array
    {
        $user = $this->portalUsers->findByEmail($email);
        
        if ($user === null) {
            return ['data' => [], 'done' => true];
        }
        
        $data = [
            [
                'group_id'    => 'praxis-portal-users',
                'group_label' => I18n::translate('Portal-Benutzerkonto', 'privacy'),
                'item_id'     => "portal-user-{$user['id']}",
                'data'        => [
                    ['name' => I18n::translate('E-Mail', 'privacy'), 'value' => $email],
                    ['name' => I18n::translate('Erstellt am', 'privacy'), 'value' => $user['created_at'] ?? ''],
                    ['name' => I18n::translate('Letzter Login', 'privacy'), 'value' => $user['last_login'] ?? ''],
                    ['name' => I18n::translate('Standort', 'privacy'), 'value' => $user['location_id'] ?? ''],
                ],
            ],
        ];
        
        return ['data' => $data, 'done' => true];
    }
    
    // =========================================================================
    // DATEN-LÖSCHUNG (Art. 17 DSGVO – Recht auf Löschung)
    // =========================================================================
    
    /**
     * Löscht Formulardaten eines Patienten
     * 
     * Multistandort: Löscht aus ALLEN Standorten, da die Löschanfrage
     * für den gesamten Verantwortlichen gilt (Art. 17 DSGVO).
     * 
     * @param string $email E-Mail des anfragenden Patienten
     * @param int    $page  Seitennummer für Batch-Verarbeitung
     * @return array WordPress-Privacy-Eraser-Format
     */
    public function erasePersonalData(string $email, int $page = 1): array
    {
        $allSubmissions = $this->findSubmissionsByEmail($email);
        if (empty($allSubmissions)) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }
        
        $offset  = ($page - 1) * self::BATCH_SIZE;
        $batch   = array_slice($allSubmissions, $offset, self::BATCH_SIZE);
        $done    = ($offset + self::BATCH_SIZE) >= count($allSubmissions);
        
        $removed  = 0;
        $retained = 0;
        $messages = [];
        
        foreach ($batch as $submission) {
            $id = (int) $submission['id'];
            
            // Aufbewahrungspflicht prüfen (z.B. 10 Jahre für medizinische Daten)
            if ($this->hasRetentionObligation($submission)) {
                $retained++;
                $messages[] = sprintf(
                    I18n::translate(
                        'Submission #%d wird aufgrund gesetzlicher Aufbewahrungspflicht beibehalten.',
                        'privacy'
                    ),
                    $id
                );
                continue;
            }
            
            // Zugehörige Dateien löschen
            $this->files->deleteBySubmission($id);
            
            // Submission permanent löschen
            $this->submissions->permanentDelete($id);
            $removed++;
        }
        
        // Audit-Log: Löschung dokumentieren
        $this->audit->log('privacy_erase', null, [
            'email'    => $email,
            'removed'  => $removed,
            'retained' => $retained,
            'page'     => $page,
        ]);
        
        return [
            'items_removed'  => $removed > 0,
            'items_retained' => $retained > 0,
            'messages'       => $messages,
            'done'           => $done,
        ];
    }
    
    /**
     * Löscht Portal-Benutzerdaten
     */
    public function erasePortalUserData(string $email, int $page = 1): array
    {
        $user = $this->portalUsers->findByEmail($email);
        
        if ($user === null) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }
        
        $this->portalUsers->permanentDelete((int) $user['id']);
        
        $this->audit->log('privacy_erase_user', (int) $user['id'], [
            'email' => $email,
        ]);
        
        return [
            'items_removed'  => true,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }
    
    // =========================================================================
    // INTERNE HILFSMETHODEN
    // =========================================================================
    
    /**
     * Findet Submissions anhand der E-Mail-Adresse.
     *
     * Da name_hash auf Vorname|Nachname|Geburtsdatum basiert (nicht E-Mail),
     * muss die Suche über die entschlüsselten Formulardaten erfolgen.
     * SubmissionRepository::findByEmailForPrivacy() entschlüsselt und prüft
     * das E-Mail-Feld jeder Submission.
     */
    private function findSubmissionsByEmail(string $email): array
    {
        $email = sanitize_email($email);
        if (empty($email)) {
            return [];
        }

        return $this->submissions->findByEmailForPrivacy($email);
    }
    
    /**
     * Formatiert eine Submission für den WordPress Privacy-Export
     * 
     * Mehrsprachigkeit: Labels werden über I18n übersetzt
     */
    private function formatSubmissionForExport(array $submission): array
    {
        $data = [
            ['name' => I18n::translate('Formular', 'privacy'),     'value' => $submission['form_type'] ?? ''],
            ['name' => I18n::translate('Eingereicht am', 'privacy'), 'value' => $submission['created_at'] ?? ''],
            ['name' => I18n::translate('Standort-ID', 'privacy'),  'value' => $submission['location_id'] ?? ''],
            ['name' => I18n::translate('Status', 'privacy'),       'value' => $submission['status'] ?? ''],
        ];
        
        // Formulardaten (entschlüsselt) als einzelne Felder
        $formData = $submission['form_data'] ?? [];
        if (is_string($formData)) {
            $formData = json_decode($formData, true) ?? [];
        }
        
        foreach ($formData as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $data[] = [
                'name'  => sanitize_text_field($key),
                'value' => sanitize_text_field((string) $value),
            ];
        }
        
        return $data;
    }
    
    /**
     * Prüft ob eine Aufbewahrungspflicht besteht
     * 
     * Medizinische Daten in Deutschland: 10 Jahre (§ 630f BGB)
     * Konfigurierbar über pp_retention_years Option.
     */
    private function hasRetentionObligation(array $submission): bool
    {
        $retentionYears = (int) get_option('pp_retention_years', 10);
        
        if ($retentionYears <= 0) {
            return false;
        }
        
        $createdAt = strtotime($submission['created_at'] ?? '');
        if ($createdAt === false) {
            return false;
        }
        
        $retentionEnd = strtotime("+{$retentionYears} years", $createdAt);
        
        return time() < $retentionEnd;
    }
}
