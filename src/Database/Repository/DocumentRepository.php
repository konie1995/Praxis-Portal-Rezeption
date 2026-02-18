<?php
/**
 * Document Repository
 *
 * Verwaltet öffentliche Praxis-Dokumente (Downloads für Patienten).
 * Multistandort: Dokumente sind an location_id gebunden.
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.2.9
 */

namespace PraxisPortal\Database\Repository;

if (!defined('ABSPATH')) {
    exit;
}

class DocumentRepository extends AbstractRepository
{
    protected string $tableKey = 'documents';

    /**
     * Aktive Dokumente für einen Standort laden
     *
     * @param int $locationId Standort-ID
     * @return array Liste der aktiven Dokumente, sortiert nach sort_order
     */
    public function getActiveByLocation(int $locationId): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT id, title, description, file_path, mime_type, file_size, sort_order
                 FROM {$this->table()}
                 WHERE location_id = %d AND is_active = 1
                 ORDER BY sort_order ASC, title ASC",
                $locationId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Alle Dokumente für einen Standort (inkl. inaktive, für Admin)
     */
    public function getAllByLocation(int $locationId): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE location_id = %d
                 ORDER BY sort_order ASC, title ASC",
                $locationId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Dokument erstellen
     */
    public function createDocument(array $data): ?int
    {
        $result = $this->db->insert($this->table(), [
            'location_id' => (int) ($data['location_id'] ?? 0),
            'title'       => sanitize_text_field($data['title'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'file_path'   => sanitize_text_field($data['file_path'] ?? ''),
            'mime_type'   => sanitize_text_field($data['mime_type'] ?? ''),
            'file_size'   => (int) ($data['file_size'] ?? 0),
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d']);

        return $result ? (int) $this->db->insert_id : null;
    }

    /**
     * Dokument aktualisieren
     */
    public function updateDocument(int $id, array $data): bool
    {
        $allowed = ['title', 'description', 'file_path', 'mime_type', 'file_size', 'is_active', 'sort_order'];
        $update  = [];
        $formats = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
                $formats[]      = in_array($field, ['location_id', 'file_size', 'is_active', 'sort_order']) ? '%d' : '%s';
            }
        }

        if (empty($update)) {
            return false;
        }

        return (bool) $this->db->update($this->table(), $update, ['id' => $id], $formats, ['%d']);
    }

    /**
     * Dokument löschen
     */
    public function deleteDocument(int $id): bool
    {
        return (bool) $this->db->delete($this->table(), ['id' => $id], ['%d']);
    }

    /**
     * Aktives Dokument per ID laden (für Download-Validierung)
     */
    public function findActiveById(int $id): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->table()} WHERE id = %d AND is_active = 1 LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Dateigröße menschenlesbar formatieren
     */
    public static function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Icon basierend auf MIME-Type
     */
    public static function getMimeIcon(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'application/pdf')   => '📄',
            str_starts_with($mimeType, 'image/')            => '🖼️',
            str_contains($mimeType, 'word')                 => '📝',
            str_contains($mimeType, 'spreadsheet')          => '📊',
            str_contains($mimeType, 'excel')                => '📊',
            str_contains($mimeType, 'presentation')         => '📽️',
            str_contains($mimeType, 'zip')                  => '📦',
            default                                         => '📎',
        };
    }
}
