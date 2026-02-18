<?php
/**
 * Repository für verschlüsselte Datei-Referenzen
 *
 * Dateien werden physisch als {file_id}.enc gespeichert.
 * Originalnamen sind separat verschlüsselt.
 *
 * @package PraxisPortal\Database\Repository
 * @since 4.0.0
 */

namespace PraxisPortal\Database\Repository;

use PraxisPortal\Core\Config;
use PraxisPortal\Security\Encryption;

class FileRepository extends AbstractRepository
{
    protected string $tableKey = 'files';

    private Encryption $encryption;

    public function __construct(Encryption $encryption)
    {
        parent::__construct();
        $this->encryption = $encryption;
    }

    // ─── CREATE ──────────────────────────────────────────────

    /**
     * Datei verschlüsselt speichern + Referenz anlegen
     *
     * @param int $submissionId  Zugehörige Submission
     * @param string $tempPath   Temporärer Pfad der hochgeladenen Datei
     * @param string $originalName Ursprünglicher Dateiname
     * @param string $mimeType   MIME-Type
     * @return array{success: bool, file_id?: string, error?: string}
     */
    public function storeFile(
        int $submissionId,
        string $tempPath,
        string $originalName,
        string $mimeType
    ): array {
        if (!file_exists($tempPath)) {
            return ['success' => false, 'error' => 'Temp-Datei nicht gefunden'];
        }

        // Dateigröße prüfen
        $fileSize = filesize($tempPath);
        $maxSize = Config::MAX_UPLOAD_SIZE;
        if ($fileSize > $maxSize) {
            return ['success' => false, 'error' => 'Datei zu groß (max. ' . round($maxSize / 1048576) . ' MB)'];
        }

        // MIME-Type prüfen
        $allowedTypes = Config::ALLOWED_UPLOAD_TYPES;
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes, true)) {
            return ['success' => false, 'error' => 'Dateityp nicht erlaubt: ' . $ext];
        }

        // Eindeutige File-ID generieren
        $fileId = bin2hex(random_bytes(32)); // 64 Hex-Zeichen

        // Zielverzeichnis
        $uploadDir = Config::getUploadDir();
        if (!is_dir($uploadDir)) {
            wp_mkdir_p($uploadDir);
            // .htaccess zum Schutz
            $htaccess = $uploadDir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
            // index.php
            $index = $uploadDir . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, "<?php // Silence is golden\n");
            }
        }

        // Datei verschlüsseln
        $encryptedPath = $uploadDir . '/' . $fileId . '.enc';

        try {
            $this->encryption->encryptFile($tempPath, $encryptedPath);
        } catch (\Exception $e) {
            error_log('PP FileRepository: Verschlüsselung fehlgeschlagen: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Dateiverschlüsselung fehlgeschlagen'];
        }

        // Temp-Datei löschen
        if (file_exists($tempPath)) { unlink($tempPath); }

        // DB-Referenz anlegen
        $id = $this->insert([
            'submission_id'          => $submissionId,
            'file_id'                => $fileId,
            'original_name_encrypted' => $this->encryption->encrypt($originalName),
            'mime_type'              => sanitize_mime_type($mimeType),
            'file_size'              => $fileSize,
            'created_at'             => current_time('mysql'),
        ]);

        if (!$id) {
            // Verschlüsselte Datei aufräumen
            if (file_exists($encryptedPath)) { unlink($encryptedPath); }
            return ['success' => false, 'error' => 'DB-Eintrag fehlgeschlagen'];
        }

        return [
            'success' => true,
            'file_id' => $fileId,
            'db_id'   => $id,
        ];
    }

    /**
     * Datei-Referenz ohne physische Datei anlegen
     * (z.B. wenn Datei bereits verschlüsselt hochgeladen wurde)
     */
    public function createReference(int $submissionId, array $fileInfo): int|false
    {
        return $this->insert([
            'submission_id'          => $submissionId,
            'file_id'                => sanitize_text_field($fileInfo['file_id'] ?? ''),
            'original_name_encrypted' => $this->encryption->encrypt($fileInfo['original_name'] ?? 'unnamed'),
            'mime_type'              => sanitize_mime_type($fileInfo['mime_type'] ?? 'application/octet-stream'),
            'file_size'              => (int) ($fileInfo['file_size'] ?? 0),
            'created_at'             => current_time('mysql'),
        ]);
    }

    // ─── READ ────────────────────────────────────────────────

    /**
     * Dateien für eine Submission laden
     */
    public function findBySubmission(int $submissionId): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table()} WHERE submission_id = %d ORDER BY id ASC",
                $submissionId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Datei per file_id laden
     */
    public function findByFileId(string $fileId): ?array
    {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->table()} WHERE file_id = %s LIMIT 1",
                $fileId
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Entschlüsselten Dateiinhalt zurückgeben
     *
     * @return array{content: string, name: string, mime: string}|null
     */
    public function getDecryptedFile(string $fileId): ?array
    {
        $ref = $this->findByFileId($fileId);
        if (!$ref) {
            return null;
        }

        $encryptedPath = Config::getUploadDir() . '/' . $fileId . '.enc';
        if (!file_exists($encryptedPath)) {
            return null;
        }

        try {
            $content = $this->encryption->decryptFile($encryptedPath);
            $originalName = $this->encryption->decrypt($ref['original_name_encrypted']);

            return [
                'content' => $content,
                'name'    => $originalName ?: 'download',
                'mime'    => $ref['mime_type'],
                'size'    => $ref['file_size'],
            ];
        } catch (\Exception $e) {
            error_log('PP FileRepository: Entschlüsselung fehlgeschlagen: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Prüft ob file_id gültiges Format hat (64 Hex-Zeichen)
     */
    public static function isValidFileId(string $fileId): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $fileId);
    }

    // ─── DELETE ──────────────────────────────────────────────

    /**
     * Datei + DB-Referenz löschen
     */
    public function deleteFile(string $fileId): bool
    {
        // Physische Datei löschen
        $path = Config::getUploadDir() . '/' . $fileId . '.enc';
        if (file_exists($path)) {
            if (file_exists($path)) { unlink($path); }
        }

        // DB-Referenz löschen
        return (bool) $this->db->delete($this->table(), ['file_id' => $fileId], ['%s']);
    }

    /**
     * Alle Dateien einer Submission löschen
     */
    public function deleteBySubmission(int $submissionId): int
    {
        $files = $this->findBySubmission($submissionId);
        $count = 0;

        foreach ($files as $file) {
            if ($this->deleteFile($file['file_id'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Verwaiste Dateien finden (physisch, aber keine DB-Referenz)
     */
    public function findOrphanedFiles(): array
    {
        $uploadDir = Config::getUploadDir();
        $orphaned = [];

        if (!is_dir($uploadDir)) {
            return $orphaned;
        }

        foreach (glob($uploadDir . '/*.enc') as $filePath) {
            $fileId = basename($filePath, '.enc');
            if (!self::isValidFileId($fileId)) {
                continue;
            }

            $ref = $this->findByFileId($fileId);
            if (!$ref) {
                $orphaned[] = [
                    'file_id'  => $fileId,
                    'path'     => $filePath,
                    'size'     => filesize($filePath),
                    'modified' => filemtime($filePath),
                ];
            }
        }

        return $orphaned;
    }
    
    /**
     * Alias für findBySubmission()
     */
    public function findBySubmissionId(int $submissionId): array
    {
        return $this->findBySubmission($submissionId);
    }
    
    /**
     * Temporäre Datei einer Einreichung zuordnen
     */
    public function linkToSubmission(
        int $submissionId,
        string $fileId,
        string $fieldName = '',
        string $mimeType = 'application/octet-stream',
        int $size = 0
    ): bool {
        $file = $this->findByFileId($fileId);
        if ($file === null) {
            return false;
        }
        
        return (bool) $this->db->update(
            $this->table(),
            [
                'submission_id' => $submissionId,
                'mime_type'     => $mimeType,
                'file_size'     => $size,
            ],
            ['file_id' => $fileId],
            ['%d', '%s', '%d'],
            ['%s']
        );
    }
    
    /**
     * Verwaiste Dateien finden und löschen
     */
    public function cleanupOrphans(): int
    {
        $orphaned = $this->findOrphanedFiles();
        $deleted  = 0;
        
        foreach ($orphaned as $orphan) {
            if (!empty($orphan['file_id'])) {
                if ($this->deleteFile($orphan['file_id'])) {
                    $deleted++;
                }
            } elseif (!empty($orphan['path']) && file_exists($orphan['path'])) {
                if (file_exists($orphan['path'])) { unlink($orphan['path']); }
                $deleted++;
            }
        }
        
        return $deleted;
    }
}
