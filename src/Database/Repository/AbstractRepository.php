<?php
/**
 * Abstrakte Basis-Repository-Klasse
 * 
 * Bietet gemeinsame DB-Operationen für alle Repositories.
 * Nutzt $wpdb mit Prepared Statements für Sicherheit.
 *
 * @package PraxisPortal\Database\Repository
 * @since 4.0.0
 */

namespace PraxisPortal\Database\Repository;

use PraxisPortal\Database\Schema;

abstract class AbstractRepository
{
    /** @var \wpdb */
    protected \wpdb $db;
    
    /** @var \wpdb Alias – einige Repositories nutzen $this->wpdb */
    protected \wpdb $wpdb;

    /** @var string Tabellenname (ohne Prefix) */
    protected string $tableKey;

    /** @var string Alias für Reflection-Tests */
    protected string $table = '';

    public function __construct()
    {
        global $wpdb;
        $this->db   = $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Vollständiger Tabellenname
     */
    protected function table(): string
    {
        // Subklassen definieren entweder $tableKey (neues Pattern)
        // oder tableName() (Legacy-Pattern)
        if (!empty($this->tableKey)) {
            return Schema::table($this->tableKey);
        }
        if (method_exists($this, 'tableName')) {
            return $this->tableName();
        }
        throw new \RuntimeException('Repository hat keinen Tabellennamen: ' . static::class);
    }

    /**
     * Einzelnen Datensatz per ID holen
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Datensatz einfügen
     *
     * @param array $data Spalte => Wert
     * @return int|false Insert-ID oder false
     */
    protected function insert(array $data): int|false
    {
        $result = $this->db->insert($this->table(), $data);
        return $result ? (int) $this->db->insert_id : false;
    }

    /**
     * Datensatz aktualisieren
     */
    protected function update(int $id, array $data): mixed
    {
        $result = $this->db->update($this->table(), $data, ['id' => $id]);
        return $result !== false;
    }

    /**
     * Datensatz löschen (hart)
     */
    protected function delete(int $id): mixed
    {
        return (bool) $this->db->delete($this->table(), ['id' => $id], ['%d']);
    }

    /**
     * Zählen mit optionalem WHERE
     */
    protected function count(string $where = '1=1', array $params = []): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, ...$params);
        }
        return (int) $this->db->get_var($sql);
    }

    /**
     * Paginierte Abfrage
     *
     * @param int $page Seitennummer (1-basiert)
     * @param int $perPage Einträge pro Seite
     * @param string $orderBy Sortierung
     * @param string $where WHERE-Klausel
     * @param array $params Prepared-Statement-Parameter
     * @return array{items: array, total: int, pages: int, page: int}
     */
    protected function paginate(
        int $page = 1,
        int $perPage = 25,
        string $orderBy = 'id DESC',
        string $where = '1=1',
        array $params = []
    ): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;

        // ORDER BY Whitelist: Nur erlaubte Spalten + Richtungen akzeptieren
        if (!preg_match('/^[a-zA-Z_]+(\s+(ASC|DESC))?$/i', trim($orderBy))) {
            $orderBy = 'id DESC';
        }

        $total = $this->count($where, $params);

        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY {$orderBy} LIMIT %d OFFSET %d";
        $allParams = array_merge($params, [$perPage, $offset]);
        $items = $this->db->get_results(
            $this->db->prepare($sql, ...$allParams),
            ARRAY_A
        ) ?: [];

        return [
            'items' => $items,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
            'page'  => $page,
        ];
    }

    /**
     * Letzte DB-Fehlermeldung
     */
    protected function lastError(): string
    {
        return $this->db->last_error;
    }
}
