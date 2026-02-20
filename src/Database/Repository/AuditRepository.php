<?php
declare(strict_types=1);
/**
 * Repository für Audit-Logging (DSGVO-konform)
 *
 * Alle sicherheitsrelevanten Aktionen werden protokolliert.
 * Details werden verschlüsselt, Benutzernamen gehasht.
 *
 * @package PraxisPortal\Database\Repository
 * @since 4.0.0
 */

namespace PraxisPortal\Database\Repository;

use PraxisPortal\Security\Encryption;

class AuditRepository extends AbstractRepository
{
    protected string $tableKey = 'audit_log';

    private ?Encryption $encryption;

    public function __construct(?Encryption $encryption = null)
    {
        parent::__construct();
        $this->encryption = $encryption;
    }

    // ─── AKTIONEN LOGGEN ─────────────────────────────────────

    /**
     * Aktion loggen
     *
     * @param string $action         z.B. 'submission_created', 'login_success', 'data_export'
     * @param int|null $entityId     Betroffene Entity-ID (submission_id, location_id, etc.)
     * @param array $details         Zusätzliche Details (werden verschlüsselt)
     * @param string $entityType     Typ der Entity (submission, location, user, etc.)
     * @return int|false
     */
    public function log(
        string $action,
        ?int $entityId = null,
        array $details = [],
        string $entityType = 'submission'
    ): int|false {
        // IP hashen
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipHash = $this->encryption->hash($ip);

        // WordPress-User ermitteln
        $wpUserId = get_current_user_id();
        $portalUsername = null;

        // Portal-Session prüfen
        if (!empty($_SESSION['pp_portal_session']['username'])) {
            $portalUsername = $this->encryption->encrypt(
                $_SESSION['pp_portal_session']['username']
            );
        }

        // Details verschlüsseln (wenn vorhanden)
        $encryptedDetails = null;
        if (!empty($details)) {
            $encryptedDetails = $this->encryption->encrypt($details);
        }

        return $this->insert([
            'action'             => sanitize_key($action),
            'entity_type'        => sanitize_key($entityType),
            'entity_id'          => $entityId,
            'wp_user_id'         => $wpUserId ?: null,
            'portal_username'    => $portalUsername,
            'ip_hash'            => $ipHash,
            'user_agent_hash'    => $this->encryption->hash($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'details_encrypted'  => $encryptedDetails,
            'created_at'         => current_time('mysql'),
        ]);
    }

    // ─── SHORTHAND-METHODEN ──────────────────────────────────

    /**
     * Submission-bezogene Aktionen
     */
    public function logSubmission(string $action, int $submissionId, array $details = []): int|false
    {
        return $this->log($action, $submissionId, $details, 'submission');
    }

    /**
     * Login/Auth-Aktionen
     */
    public function logAuth(string $action, array $details = []): int|false
    {
        return $this->log($action, null, $details, 'auth');
    }

    /**
     * Datenexport loggen
     */
    public function logExport(string $format, int $submissionId, array $details = []): int|false
    {
        $details['format'] = $format;
        return $this->log('data_export', $submissionId, $details, 'export');
    }

    /**
     * Standort-Änderungen loggen
     */
    public function logLocation(string $action, int $locationId, array $details = []): int|false
    {
        return $this->log($action, $locationId, $details, 'location');
    }

    /**
     * Sicherheitsrelevante Aktionen
     */
    public function logSecurity(string $action, array $details = []): int|false
    {
        return $this->log($action, null, $details, 'security');
    }

    // ─── ABFRAGEN ────────────────────────────────────────────

    /**
     * Audit-Log paginiert abrufen
     */
    public function getLog(
        int $page = 1,
        int $perPage = 50,
        ?string $action = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): array {
        $where = '1=1';
        $params = [];

        if ($action) {
            $where .= ' AND action = %s';
            $params[] = $action;
        }

        if ($entityType) {
            $where .= ' AND entity_type = %s';
            $params[] = $entityType;
        }

        if ($entityId) {
            $where .= ' AND entity_id = %d';
            $params[] = $entityId;
        }

        return $this->paginate($page, $perPage, 'created_at DESC', $where, $params);
    }

    /**
     * Entschlüsselter Audit-Eintrag
     */
    public function getDecrypted(int $id): ?array
    {
        $row = $this->findById($id);
        if (!$row) {
            return null;
        }

        if (!empty($row['details_encrypted'])) {
            $row['details'] = $this->encryption->decrypt($row['details_encrypted'], true);
        }

        if (!empty($row['portal_username'])) {
            $row['portal_username_decrypted'] = $this->encryption->decrypt($row['portal_username']);
        }

        return $row;
    }

    /**
     * Fehlgeschlagene Logins zählen (für Lockout)
     */
    public function countFailedLogins(string $ipHash, int $minutesBack = 30): int
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$minutesBack} minutes"));

        return $this->count(
            "action = 'login_failed' AND ip_hash = %s AND created_at >= %s",
            [$ipHash, $since]
        );
    }

    // ─── CLEANUP ─────────────────────────────────────────────

    /**
     * Alte Audit-Einträge löschen (DSGVO: max. 1 Jahr)
     */
    public function purgeOld(int $daysOld = 365): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        return (int) $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->table()} WHERE created_at < %s",
                $cutoff
            )
        );
    }
    
    /**
     * Settings-Änderung protokollieren
     */
    public function logSettings(string $action, array $details = []): int|false
    {
        return $this->log($action, null, $details, 'settings');
    }
    
    /**
     * DSGVO-Aktion protokollieren
     */
    public function logDsgvo(string $action, int $submissionId = 0, array $details = []): int|false
    {
        return $this->log($action, $submissionId ?: null, $details, 'dsgvo');
    }
    
    /**
     * Audit-Einträge mit Filtern und Pagination abrufen
     */
    public function list(array $filters = [], int $perPage = 50, int $offset = 0): array
    {
        $where = '1=1';
        $params = [];

        if (!empty($filters['action'])) {
            $where .= ' AND action = %s';
            $params[] = $filters['action'];
        }

        // Action-Prefix-Filter (z.B. 'portal_' für alle Portal-Aktionen)
        if (!empty($filters['action_prefix'])) {
            $where .= ' AND action LIKE %s';
            $params[] = $filters['action_prefix'] . '%';
        }

        if (!empty($filters['entity_type'])) {
            $where .= ' AND entity_type = %s';
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= %s';
            $params[] = $filters['date_to'];
        }

        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $perPage;
        $params[] = $offset;

        return $this->db->get_results(
            $this->db->prepare($sql, ...$params),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Audit-Einträge zählen (mit denselben Filtern wie list())
     */
    public function countEntries(array $filters = []): int
    {
        $where = '1=1';
        $params = [];

        if (!empty($filters['action'])) {
            $where .= ' AND action = %s';
            $params[] = $filters['action'];
        }

        if (!empty($filters['action_prefix'])) {
            $where .= ' AND action LIKE %s';
            $params[] = $filters['action_prefix'] . '%';
        }

        if (!empty($filters['entity_type'])) {
            $where .= ' AND entity_type = %s';
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= %s';
            $params[] = $filters['date_to'];
        }

        return $this->count($where, $params);
    }
    
    /**
     * Einträge älter als X Tage löschen (Alias für purgeOld)
     */
    public function deleteOlderThan(int $days): int
    {
        return $this->purgeOld($days);
    }
}
