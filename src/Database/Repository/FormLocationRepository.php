<?php
/**
 * FormLocationRepository – Fragebogen-Standort-Zuordnung
 *
 * Verwaltet, welche Fragebögen an welchen Standorten aktiv sind.
 * Jeder Fragebogen kann mehreren Standorten zugeordnet werden.
 * Ein Standort kann mehrere aktive Fragebögen haben (Patient wählt).
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.2.7
 */

declare(strict_types=1);

namespace PraxisPortal\Database\Repository;

use PraxisPortal\Database\Schema;

if (!defined('ABSPATH')) {
    exit;
}

class FormLocationRepository
{
    /**
     * Alle Zuordnungen für einen Fragebogen
     */
    public function getByFormId(string $formId): array
    {
        global $wpdb;
        $table = Schema::formLocations();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT fl.*, l.name AS location_name, l.slug AS location_slug
                 FROM {$table} fl
                 JOIN " . Schema::locations() . " l ON l.id = fl.location_id
                 WHERE fl.form_id = %s
                 ORDER BY l.name ASC",
                $formId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Alle aktiven Fragebögen für einen Standort
     */
    public function getActiveByLocationId(int $locationId): array
    {
        global $wpdb;
        $table = Schema::formLocations();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT fl.form_id, fl.sort_order
                 FROM {$table} fl
                 WHERE fl.location_id = %d AND fl.is_active = 1
                 ORDER BY fl.sort_order ASC, fl.form_id ASC",
                $locationId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Alle aktiven Fragebögen für einen Standort (via Slug)
     */
    public function getActiveByLocationSlug(string $slug): array
    {
        global $wpdb;
        $table    = Schema::formLocations();
        $locTable = Schema::locations();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT fl.form_id, fl.sort_order
                 FROM {$table} fl
                 JOIN {$locTable} l ON l.id = fl.location_id
                 WHERE l.slug = %s AND fl.is_active = 1
                 ORDER BY fl.sort_order ASC, fl.form_id ASC",
                $slug
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Zuordnung setzen (upsert)
     */
    public function assign(string $formId, int $locationId, bool $isActive = true, int $sortOrder = 0): bool
    {
        global $wpdb;
        $table = Schema::formLocations();

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE form_id = %s AND location_id = %d",
                $formId,
                $locationId
            )
        );

        if ($existing) {
            return (bool) $wpdb->update(
                $table,
                ['is_active' => (int) $isActive, 'sort_order' => $sortOrder],
                ['id' => (int) $existing],
                ['%d', '%d'],
                ['%d']
            );
        }

        return (bool) $wpdb->insert(
            $table,
            [
                'form_id'     => $formId,
                'location_id' => $locationId,
                'is_active'   => (int) $isActive,
                'sort_order'  => $sortOrder,
            ],
            ['%s', '%d', '%d', '%d']
        );
    }

    /**
     * Zuordnung entfernen
     */
    public function unassign(string $formId, int $locationId): bool
    {
        global $wpdb;
        $table = Schema::formLocations();

        return (bool) $wpdb->delete(
            $table,
            ['form_id' => $formId, 'location_id' => $locationId],
            ['%s', '%d']
        );
    }

    /**
     * Aktiv-Status umschalten
     */
    public function toggleActive(string $formId, int $locationId): bool
    {
        global $wpdb;
        $table = Schema::formLocations();

        return (bool) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET is_active = 1 - is_active
                 WHERE form_id = %s AND location_id = %d",
                $formId,
                $locationId
            )
        );
    }

    /**
     * Alle Zuordnungen für einen Fragebogen löschen
     */
    public function removeAllForForm(string $formId): int
    {
        global $wpdb;
        $table = Schema::formLocations();

        return (int) $wpdb->delete(
            $table,
            ['form_id' => $formId],
            ['%s']
        );
    }

    /**
     * Zusammenfassung: Wie viele Standorte sind zugeordnet (aktiv/gesamt)?
     */
    public function getAssignmentSummary(): array
    {
        global $wpdb;
        $table = Schema::formLocations();

        $rows = $wpdb->get_results(
            "SELECT form_id,
                    COUNT(*) AS total,
                    SUM(is_active) AS active
             FROM {$table}
             GROUP BY form_id",
            ARRAY_A
        ) ?: [];

        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['form_id']] = [
                'total'  => (int) $row['total'],
                'active' => (int) $row['active'],
            ];
        }

        return $summary;
    }
}
