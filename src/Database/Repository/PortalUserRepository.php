<?php
declare(strict_types=1);
/**
 * PortalUserRepository – CRUD für pp_portal_users
 *
 * Portal-Benutzer sind Praxis-Mitarbeiter, die sich am
 * Praxis-Portal anmelden können (nicht WordPress-User).
 *
 * @package PraxisPortal\Database\Repository
 * @since   4.0.0
 */

namespace PraxisPortal\Database\Repository;

class PortalUserRepository extends AbstractRepository
{
    /* =========================================================================
     * TABLE
     * ====================================================================== */

    protected function tableName(): string
    {
        return $this->wpdb->prefix . 'pp_portal_users';
    }

    /* =========================================================================
     * READ
     * ====================================================================== */

    /**
     * Alle Portal-Benutzer laden
     *
     * @param int|null $locationId Filter nach Standort (null = alle)
     * @return array
     */
    public function getAll(?int $locationId = null): array
    {
        $table = $this->tableName();

        if ($locationId !== null) {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$table} WHERE location_id = %d ORDER BY username",
                    $locationId
                ),
                ARRAY_A
            );
        } else {
            $results = $this->wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY username",
                ARRAY_A
            );
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Portal-Benutzer per ID laden
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Aktiven Portal-Benutzer per Username laden
     *
     * Für Login-Validierung.
     *
     * @param string $username
     * @return array|null
     */
    public function findByUsername(string $username): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE username = %s AND is_active = 1 LIMIT 1",
                $username
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Prüfen ob Username bereits existiert
     *
     * @param string   $username
     * @param int|null $excludeId Eigene ID ausschließen (bei Update)
     * @return bool
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $table = $this->tableName();

        if ($excludeId !== null) {
            return (bool) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE username = %s AND id != %d",
                    $username,
                    $excludeId
                )
            );
        }

        return (bool) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE username = %s LIMIT 1",
                $username
            )
        );
    }

    /* =========================================================================
     * CREATE
     * ====================================================================== */

    /**
     * Portal-Benutzer erstellen
     *
     * Hasht das Passwort automatisch falls 'password' statt
     * 'password_hash' übergeben wird.
     *
     * @param array $data {username, password|password_hash, location_id, ...}
     * @return int|false User-ID oder false
     */
    public function create(array $data)
    {
        // Passwort hashen
        if (!empty($data['password']) && empty($data['password_hash'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }

        $data['created_at'] = current_time('mysql');

        // Defaults
        $data = array_merge([
            'can_view'   => 1,
            'can_edit'   => 0,
            'can_delete' => 0,
            'can_export' => 1,
            'is_active'  => 1,
        ], $data);

        $result = $this->wpdb->insert($this->tableName(), $data);

        return $result ? (int) $this->wpdb->insert_id : false;
    }

    /* =========================================================================
     * UPDATE
     * ====================================================================== */

    /**
     * Portal-Benutzer aktualisieren
     *
     * @param int   $id
     * @param array $data
     * @return int|false
     */
    public function update(int $id, array $data): mixed
    {
        // Passwort hashen falls mitgeschickt
        if (!empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }

        return $this->wpdb->update(
            $this->tableName(),
            $data,
            ['id' => $id]
        );
    }

    /**
     * Last-Login-Zeitstempel setzen
     *
     * @param int $id
     * @return int|false
     */
    public function updateLastLogin(int $id)
    {
        return $this->wpdb->update(
            $this->tableName(),
            ['last_login' => current_time('mysql')],
            ['id' => $id]
        );
    }

    /**
     * Benutzer aktivieren / deaktivieren
     *
     * @param int  $id
     * @param bool $active
     * @return int|false
     */
    public function toggle(int $id, bool $active)
    {
        return $this->wpdb->update(
            $this->tableName(),
            ['is_active' => $active ? 1 : 0],
            ['id' => $id]
        );
    }

    /* =========================================================================
     * DELETE
     * ====================================================================== */

    /**
     * Portal-Benutzer löschen
     *
     * @param int $id
     * @return int|false
     */
    public function delete(int $id): mixed
    {
        return $this->wpdb->delete(
            $this->tableName(),
            ['id' => $id],
            ['%d']
        );
    }

    /* =========================================================================
     * AUTH HELPERS
     * ====================================================================== */

    /**
     * Passwort verifizieren
     *
     * @param string $password   Klartext-Passwort
     * @param string $hash       Gespeicherter Hash
     * @return bool
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Login-Versuch validieren
     *
     * Prüft Username + Passwort, aktualisiert last_login bei Erfolg.
     *
     * @param string $username
     * @param string $password
     * @return array|null User-Daten bei Erfolg, null bei Fehler
     */
    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->findByUsername($username);

        if (!$user) {
            return null;
        }

        if (!$this->verifyPassword($password, $user['password_hash'] ?? '')) {
            return null;
        }

        // Last login aktualisieren
        $this->updateLastLogin((int) $user['id']);

        // Passwort-Hash nicht zurückgeben
        unset($user['password_hash']);

        return $user;
    }

    /**
     * Prüfen ob Benutzer eine bestimmte Berechtigung hat
     *
     * @param array  $user       User-Daten (aus findById/findByUsername)
     * @param string $permission z.B. "can_view", "can_edit", "can_delete", "can_export"
     * @return bool
     */
    public function hasPermission(array $user, string $permission): bool
    {
        return !empty($user[$permission]);
    }
    
    /**
     * Portal-Benutzer per E-Mail finden (für DSGVO-Auskunft)
     * 
     * Durchsucht alle Standorte, da DSGVO-Anfragen standortübergreifend sind.
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE email = %s LIMIT 1",
                $email
            ),
            ARRAY_A
        );
        
        return $row ?: null;
    }
    
    /**
     * Portal-Benutzer permanent löschen (für DSGVO Art. 17)
     */
    public function permanentDelete(int $id): bool
    {
        return (bool) $this->delete($id);
    }
}
