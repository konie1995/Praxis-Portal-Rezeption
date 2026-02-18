<?php
/**
 * Repository für Patienteneinreichungen (Submissions)
 *
 * Alle Patientendaten werden verschlüsselt gespeichert.
 * Name-Hash für Duplikat-Erkennung, IP-Hash für Audit.
 *
 * @package PraxisPortal\Database\Repository
 * @since 4.0.0
 */

namespace PraxisPortal\Database\Repository;

use PraxisPortal\Security\Encryption;

class SubmissionRepository extends AbstractRepository
{
    protected string $tableKey = 'submissions';

    private Encryption $encryption;

    public function __construct(Encryption $encryption)
    {
        parent::__construct();
        $this->encryption = $encryption;
    }

    // ─── CREATE ──────────────────────────────────────────────

    /**
     * Neue Submission speichern
     *
     * @param array $formData    Klartextdaten (werden verschlüsselt)
     * @param array $meta        Metadaten (location_id, service_key, request_type, etc.)
     * @param string|null $signatureData  Base64-Signatur (wird separat verschlüsselt)
     * @return array{success: bool, submission_id?: int, submission_hash?: string, reference?: string, error?: string}
     */
    public function create(array $formData, array $meta, ?string $signatureData = null): array
    {
        try {
            // Daten verschlüsseln
            $encryptedData = $this->encryption->encrypt($formData);
            $encryptedSignature = $signatureData ? $this->encryption->encrypt($signatureData) : null;

            // Submission-Hash (eindeutige ID)
            $submissionHash = hash('sha256', wp_generate_uuid4() . microtime(true));

            // Name-Hash für Duplikatprüfung
            $nameHash = $this->buildNameHash($formData);

            // IP + User-Agent hashen (nicht im Klartext speichern)
            $ipHash = $this->encryption->hash($this->getClientIp() . date('Y-m'));
            $uaHash = $this->encryption->hash(
                ($_SERVER['HTTP_USER_AGENT'] ?? '') . date('Y-m')
            );

            $insertData = [
                'location_id'     => (int) ($meta['location_id'] ?? 0),
                'service_key'     => sanitize_key($meta['service_key'] ?? 'anamnese'),
                'submission_hash' => $submissionHash,
                'name_hash'       => $nameHash,
                'encrypted_data'  => $encryptedData,
                'signature_data'  => $encryptedSignature,
                'ip_hash'         => $ipHash,
                'user_agent_hash' => $uaHash,
                'consent_given'   => 1,
                'request_type'    => sanitize_key($meta['request_type'] ?? 'form'),
                'status'          => 'pending',
                'created_at'      => current_time('mysql'),
            ];

            $id = $this->insert($insertData);

            if (!$id) {
                return ['success' => false, 'error' => $this->lastError() ?: 'Insert fehlgeschlagen'];
            }

            return [
                'success'         => true,
                'id'              => $id,
                'submission_id'   => $id,
                'submission_hash' => $submissionHash,
                'hash'            => $submissionHash,
                'reference'       => strtoupper(substr($submissionHash, 0, 8)),
            ];
        } catch (\Exception $e) {
            error_log('PP SubmissionRepository::create Error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Verschlüsselungsfehler'];
        }
    }

    // ─── READ ────────────────────────────────────────────────

    /**
     * Submission per Hash finden
     */
    public function findByHash(string $hash): ?array
    {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->table()} WHERE submission_hash = %s AND deleted_at IS NULL LIMIT 1",
                $hash
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Submission laden + entschlüsseln
     *
     * @return array|null Klartextdaten oder null
     */
    public function findDecrypted(int $id): ?array
    {
        $row = $this->findById($id);
        if (!$row || !empty($row['deleted_at'])) {
            return null;
        }

        return $this->decryptRow($row);
    }

    /**
     * Paginierte Submissions für einen Standort
     */
    public function listForLocation(
        int $locationId,
        int $page = 1,
        int $perPage = 25,
        string $status = '',
        string $serviceKey = ''
    ): array {
        $where = "location_id = %d AND deleted_at IS NULL";
        $params = [$locationId];

        if (!empty($status)) {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        if (!empty($serviceKey)) {
            $where .= " AND service_key = %s";
            $params[] = $serviceKey;
        }

        return $this->paginate($page, $perPage, 'created_at DESC', $where, $params);
    }

    /**
     * Gefilterte Submissions für Portal-Ansicht
     *
     * @param array $args {
     *     @type int    $location_id Location ID (required)
     *     @type string $status      Status-Filter (optional)
     *     @type string $search      Suchbegriff (optional, sucht in entschlüsselten Daten)
     *     @type string $date        Datumsfilter Y-m-d (optional)
     *     @type int    $page        Seitennummer (default: 1)
     *     @type int    $per_page    Items pro Seite (default: 25)
     * }
     * @return array{items: array, total: int}
     */
    public function findFiltered(array $args): array
    {
        $locationId = (int) ($args['location_id'] ?? 0);
        $status = sanitize_text_field($args['status'] ?? '');
        $search = sanitize_text_field($args['search'] ?? '');
        $date = sanitize_text_field($args['date'] ?? '');
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));

        // Base WHERE
        $where = "location_id = %d AND deleted_at IS NULL";
        $params = [$locationId];

        // Status-Filter
        if (!empty($status) && $status !== 'all') {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        // Datumsfilter
        if (!empty($date)) {
            $where .= " AND DATE(created_at) = %s";
            $params[] = $date;
        }

        // Suche: Wenn Suchbegriff vorhanden, müssen wir alle Rows entschlüsseln und filtern
        // (ineffizient, aber notwendig für verschlüsselte Daten)
        if (!empty($search)) {
            return $this->findFilteredWithSearch($locationId, $status, $search, $date, $page, $perPage);
        }

        // Ohne Suche: Direkte DB-Abfrage
        return $this->paginate($page, $perPage, 'created_at DESC', $where, $params);
    }

    /**
     * Gefilterte Suche mit Entschlüsselung (langsam)
     */
    private function findFilteredWithSearch(
        int $locationId,
        string $status,
        string $search,
        string $date,
        int $page,
        int $perPage
    ): array {
        // Alle relevanten Submissions holen
        $where = "location_id = %d AND deleted_at IS NULL";
        $params = [$locationId];

        if (!empty($status) && $status !== 'all') {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        if (!empty($date)) {
            $where .= " AND DATE(created_at) = %s";
            $params[] = $date;
        }

        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY created_at DESC LIMIT 1000";
        $allRows = $this->db->get_results(
            $this->db->prepare($sql, ...$params),
            ARRAY_A
        ) ?: [];

        // Entschlüsseln und filtern
        $filtered = [];
        $searchLower = mb_strtolower($search);

        foreach ($allRows as $row) {
            $decrypted = $this->decryptRow($row);
            if (!$decrypted) {
                continue;
            }

            // Suche in Name, Email, Telefon, Anmerkungen
            $haystack = mb_strtolower(implode(' ', [
                $decrypted['vorname'] ?? '',
                $decrypted['nachname'] ?? '',
                $decrypted['email'] ?? '',
                $decrypted['telefon'] ?? '',
                $decrypted['anmerkungen'] ?? '',
            ]));

            if (str_contains($haystack, $searchLower)) {
                $filtered[] = $row;
            }
        }

        // Paginierung manuell
        $total = count($filtered);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($filtered, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Submissions per Name-Hash suchen (Duplikatprüfung)
     */
    public function findByNameHash(string $nameHash, int $locationId = 0): array
    {
        $where = "name_hash = %s AND deleted_at IS NULL";
        $params = [$nameHash];

        if ($locationId > 0) {
            $where .= " AND location_id = %d";
            $params[] = $locationId;
        }

        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY created_at DESC LIMIT 10";
        return $this->db->get_results(
            $this->db->prepare($sql, ...$params),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Zählt Submissions pro Standort
     */
    public function countForLocation(int $locationId, string $status = ''): int
    {
        $where = "location_id = %d AND deleted_at IS NULL";
        $params = [$locationId];

        if (!empty($status)) {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        return $this->count($where, $params);
    }

    /**
     * Zählt Submissions nach Status (für Portal-Badges)
     *
     * @param string $status     Status (z.B. 'new', 'pending', 'completed')
     * @param int    $locationId Location ID
     * @return int
     */
    public function countByStatus(string $status, int $locationId): int
    {
        return $this->countForLocation($locationId, $status);
    }

    /**
     * Zählt Submissions in einem Zeitraum (für Rate Limiting / Statistik)
     */
    public function countSince(string $ipHash, string $since): int
    {
        return $this->count(
            "ip_hash = %s AND created_at >= %s",
            [$ipHash, $since]
        );
    }

    // ─── UPDATE ──────────────────────────────────────────────

    /**
     * Status ändern
     */
    public function updateStatus(int $id, string $status, ?string $responseText = null): bool
    {
        $data = ['status' => $status];

        if ($responseText !== null) {
            $data['response_text'] = $this->encryption->encrypt($responseText);
        }

        return $this->update($id, $data);
    }

    /**
     * Soft-Delete (DSGVO-konform: Daten bleiben für Aufbewahrungspflicht)
     */
    public function softDelete(int $id): bool
    {
        return $this->update($id, ['deleted_at' => current_time('mysql')]);
    }

    /**
     * Endgültig löschen (nach Aufbewahrungsfrist)
     */
    public function permanentDelete(int $id): bool
    {
        return $this->delete($id);
    }

    // ─── CLEANUP ─────────────────────────────────────────────

    /**
     * Alte gelöschte Submissions endgültig entfernen
     *
     * @param int $daysOld Tage seit Soft-Delete
     * @return int Anzahl gelöschter Einträge
     */
    public function purgeDeleted(int $daysOld = 90): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        return (int) $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->table()} WHERE deleted_at IS NOT NULL AND deleted_at < %s",
                $cutoff
            )
        );
    }

    // ─── HELPERS ─────────────────────────────────────────────

    /**
     * Zeile entschlüsseln
     */
    private function decryptRow(array $row): array
    {
        // Verschlüsselte Daten entschlüsseln
        if (!empty($row['encrypted_data'])) {
            $decrypted = $this->encryption->decrypt($row['encrypted_data'], true);
            $row['form_data'] = is_array($decrypted) ? $decrypted : [];
        } else {
            $row['form_data'] = [];
        }

        // Signatur entschlüsseln
        if (!empty($row['signature_data'])) {
            $row['signature_decrypted'] = $this->encryption->decrypt($row['signature_data']);
        }

        // Antworttext entschlüsseln
        if (!empty($row['response_text'])) {
            $row['response_decrypted'] = $this->encryption->decrypt($row['response_text']);
        }

        return $row;
    }

    /**
     * Name-Hash für Duplikaterkennung erstellen
     * Format: vorname|nachname|geburtsdatum (lowercase, trimmed)
     */
    private function buildNameHash(array $formData): ?string
    {
        $vorname = $formData['vorname'] ?? '';
        $nachname = $formData['nachname'] ?? '';
        $geburtsdatum = $formData['geburtsdatum'] ?? '';

        if (empty($vorname) || empty($nachname) || empty($geburtsdatum)) {
            return null;
        }

        $raw = strtolower(trim($vorname)) . '|' .
               strtolower(trim($nachname)) . '|' .
               $geburtsdatum;

        return $this->encryption->hash($raw);
    }

    /**
     * Client-IP ermitteln
     */
    private function getClientIp(): string
    {
        $trustProxy = (defined('PP_TRUST_PROXY') && PP_TRUST_PROXY)
                    || get_option('pp_trust_proxy', '0') === '1';
        if ($trustProxy) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
                $ip = $_SERVER[$header] ?? '';
                if (!empty($ip)) {
                    $ip = explode(',', $ip)[0];
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Einreichungen älter als X Tage löschen
     */
    public function cleanupOlderThan(int $days): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return (int) $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->table()} WHERE created_at < %s AND status IN ('deleted','archived')",
                $cutoff
            )
        );
    }
    
    /**
     * Submissions für DSGVO-Privacy-Batch laden
     * 
     * Findet alle Submissions eines Patienten (über name_hash)
     * für Export oder Löschung. Respektiert location_id für
     * Multistandort-Sicherheit.
     * 
     * @param string $nameHash  Gehashter Name des Patienten
     * @param int    $locationId Standort-Filter (0 = alle)
     * @return array Entschlüsselte Submissions
     */
    public function findBatchForPrivacy(string $nameHash, int $locationId = 0): array
    {
        $sql = "SELECT * FROM {$this->table()} WHERE name_hash = %s AND status != 'deleted'";
        $params = [$nameHash];
        
        if ($locationId > 0) {
            $sql .= " AND location_id = %d";
            $params[] = $locationId;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 500";
        
        $rows = $this->db->get_results(
            $this->db->prepare($sql, ...$params),
            ARRAY_A
        );
        
        if (!$rows) {
            return [];
        }
        
        $results = [];
        foreach ($rows as $row) {
            try {
                $results[] = $this->decryptRow($row);
            } catch (\Throwable $e) {
                // Nicht entschlüsselbare Zeile überspringen, ID loggen
                error_log("PP Privacy: Konnte Submission #{$row['id']} nicht entschlüsseln");
                continue;
            }
        }
        
        return $results;
    }
    
    /**
     * Findet Submissions anhand der E-Mail (DSGVO Art. 15/17).
     *
     * Da name_hash auf Vorname|Nachname|Geburtsdatum basiert,
     * muss für die E-Mail-Suche entschlüsselt und geprüft werden.
     *
     * @param string $email E-Mail-Adresse des Patienten
     * @return array Entschlüsselte Submissions mit übereinstimmender E-Mail
     */
    public function findByEmailForPrivacy(string $email, ?int $locationId = null): array
    {
        $email = mb_strtolower(trim($email));
        if (empty($email)) {
            return [];
        }

        $results  = [];
        $batchSize = 100;
        $offset    = 0;
        $maxRows   = 2000; // Sicherheitslimit

        // Multistandort: Location-Filter wenn angegeben
        $whereLocation = '';
        if ($locationId !== null && $locationId > 0) {
            $whereLocation = $this->db->prepare(' AND location_id = %d', $locationId);
        }

        while ($offset < $maxRows) {
            $rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->table()} WHERE status != 'deleted' {$whereLocation} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $batchSize,
                    $offset
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                try {
                    $decrypted = $this->decryptRow($row);
                    $formData = $decrypted['form_data'] ?? [];
                    if (is_string($formData)) {
                        $formData = json_decode($formData, true) ?? [];
                    }
                    $rowEmail = mb_strtolower(trim($formData['email'] ?? ''));
                    if ($rowEmail === $email) {
                        $results[] = $decrypted;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $offset += $batchSize;

            // Abbruch wenn Batch kleiner als Limit (= keine weiteren Zeilen)
            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $results;
    }

    /**
     * Entschlüsselt suchen (für DSGVO-Auskunft)
     */
    public function searchDecrypted(string $searchTerm, int $limit = 200, ?int $locationId = null): array
    {
        $results     = [];
        $searchLower = mb_strtolower($searchTerm);
        $batchSize   = 100;
        $offset      = 0;
        $maxRows     = max($limit * 5, 2000); // Ausreichend Zeilen scannen

        // Multistandort: Location-Filter wenn angegeben
        $whereLocation = '';
        if ($locationId !== null && $locationId > 0) {
            $whereLocation = $this->db->prepare(' AND location_id = %d', $locationId);
        }

        while ($offset < $maxRows && count($results) < $limit) {
            $rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->table()} WHERE status != 'deleted' {$whereLocation} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $batchSize,
                    $offset
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (count($results) >= $limit) {
                    break 2;
                }

                try {
                    $decrypted = $this->decryptRow($row);
                    $jsonData  = json_encode($decrypted, JSON_UNESCAPED_UNICODE);

                    if (mb_stripos($jsonData, $searchLower) !== false) {
                        $results[] = $decrypted;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $offset += $batchSize;

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $results;
    }
}
