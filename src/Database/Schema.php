<?php
/**
 * Datenbank-Schema
 * 
 * Definiert und erstellt alle Plugin-Tabellen.
 * Nutzt dbDelta() für idempotente Schema-Updates.
 *
 * Tabellen:
 *   pp_locations     → Standorte
 *   pp_services      → Services pro Standort
 *   pp_submissions   → Einreichungen (verschlüsselt)
 *   pp_files         → Datei-Uploads (verschlüsselt)
 *   pp_audit_log     → Audit-Trail
 *   pp_portal_users  → Portal-Benutzer
 *   pp_api_keys      → API-Schlüssel
 *   pp_documents     → Dokumente
 *   pp_license_cache → Lizenz-Cache
 *   pp_form_locations→ Fragebogen-Standort-Zuordnung
 *   pp_icd_zuordnungen→ Fragen-ICD-Code-Zuordnung
 *
 * @package PraxisPortal\Database
 * @since   4.0.0
 */

namespace PraxisPortal\Database;

if (!defined('ABSPATH')) {
    exit;
}

class Schema
{
    public const VERSION = '4.2.910';
    public const VERSION_OPTION = 'pp_db_version';
    
    // =========================================================================
    // TABELLENNAMEN
    // =========================================================================
    
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pp_' . $name;
    }
    
    public static function locations(): string     { return self::table('locations'); }
    public static function services(): string      { return self::table('services'); }
    public static function submissions(): string   { return self::table('submissions'); }
    public static function files(): string         { return self::table('files'); }
    public static function auditLog(): string      { return self::table('audit_log'); }
    public static function portalUsers(): string   { return self::table('portal_users'); }
    public static function apiKeys(): string       { return self::table('api_keys'); }
    public static function documents(): string     { return self::table('documents'); }
    public static function licenseCache(): string  { return self::table('license_cache'); }
    public static function medications(): string   { return self::table('medications'); }
    public static function formLocations(): string { return self::table('form_locations'); }
    public static function icdZuordnungen(): string { return self::table('icd_zuordnungen'); }
    
    /**
     * Statischer Wrapper: Tabellen erstellen / aktualisieren
     */
    public static function install(): void
    {
        (new self())->createTables();
    }
    
    // =========================================================================
    // TABELLEN ERSTELLEN
    // =========================================================================
    
    public function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $this->createLocationsTable($charset);
        $this->createServicesTable($charset);
        $this->createSubmissionsTable($charset);
        $this->createFilesTable($charset);
        $this->createAuditTable($charset);
        $this->createPortalUsersTable($charset);
        $this->createApiKeysTable($charset);
        $this->createDocumentsTable($charset);
        $this->createLicenseCacheTable($charset);
        $this->createMedicationsTable($charset);
        $this->createFormLocationsTable($charset);
        
        // Version wird NUR von Migration::run() gesetzt,
        // damit Schema::install() die Migration nicht überspringt.
    }
    
    // =========================================================================
    // EINZELNE TABELLEN
    // =========================================================================
    
    private function createLocationsTable(string $charset): void
    {
        $table = self::locations();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key     VARCHAR(25)     DEFAULT NULL,
            uuid            VARCHAR(36)     DEFAULT NULL,
            name            VARCHAR(100)    NOT NULL,
            slug            VARCHAR(50)     NOT NULL,
            practice_name       VARCHAR(150),
            practice_owner      VARCHAR(100),
            practice_subtitle   VARCHAR(100),
            street              VARCHAR(100),
            postal_code         VARCHAR(10),
            city                VARCHAR(100),
            phone               VARCHAR(50),
            phone_emergency     VARCHAR(50),
            email               VARCHAR(255),
            website             VARCHAR(255),
            opening_hours       TEXT,
            logo_url            VARCHAR(255),
            color_primary       VARCHAR(7)   DEFAULT '#0066cc',
            color_secondary     VARCHAR(7)   DEFAULT '#28a745',
            widget_title        VARCHAR(100) DEFAULT 'Online-Service',
            widget_subtitle     VARCHAR(150),
            widget_welcome      TEXT,
            widget_position     VARCHAR(10)  DEFAULT 'right',
            email_notification  TEXT,
            email_from_name     VARCHAR(100),
            email_from_address  TEXT,
            email_signature     TEXT,
            vacation_mode       TINYINT(1)   DEFAULT 0,
            vacation_message    TEXT,
            vacation_start      DATE,
            vacation_end        DATE,
            widget_status       VARCHAR(20)  DEFAULT 'active',
            widget_pages        TEXT,
            widget_disabled_message TEXT,
            termin_url          VARCHAR(255),
            termin_button_text  VARCHAR(50)  DEFAULT 'Termin vereinbaren',
            privacy_url         VARCHAR(255),
            imprint_url         VARCHAR(255),
            consent_text        TEXT,
            is_active           TINYINT(1)   DEFAULT 1,
            is_default          TINYINT(1)   DEFAULT 0,
            export_format       VARCHAR(10)  DEFAULT 'gdt',
            sort_order          INT          DEFAULT 0,
            created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_slug (slug),
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_license_key (license_key),
            KEY idx_active (is_active),
            KEY idx_default (is_default)
        ) {$charset};");
    }
    
    private function createServicesTable(string $charset): void
    {
        $table = self::services();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id     BIGINT(20) UNSIGNED NOT NULL,
            service_key     VARCHAR(50)     NOT NULL,
            service_type    VARCHAR(20)     DEFAULT 'builtin',
            label           VARCHAR(100)    NOT NULL,
            description     VARCHAR(255),
            icon            VARCHAR(50)     DEFAULT '📋',
            is_active       TINYINT(1)      DEFAULT 1,
            patient_restriction VARCHAR(20) DEFAULT 'all',
            external_url    VARCHAR(255),
            open_in_new_tab TINYINT(1)      DEFAULT 1,
            custom_fields   TEXT,
            sort_order      INT             DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY idx_location_service (location_id, service_key),
            KEY idx_location (location_id),
            KEY idx_active (is_active)
        ) {$charset};");
    }
    
    private function createSubmissionsTable(string $charset): void
    {
        $table = self::submissions();
        dbDelta("CREATE TABLE {$table} (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            service_key         VARCHAR(50)     DEFAULT 'anamnesebogen',
            submission_hash     VARCHAR(64)     NOT NULL,
            name_hash           VARCHAR(64)     DEFAULT NULL,
            encrypted_data      LONGTEXT        NOT NULL,
            signature_data      LONGTEXT        DEFAULT NULL,
            ip_hash             VARCHAR(64)     DEFAULT NULL,
            user_agent_hash     VARCHAR(64)     DEFAULT NULL,
            consent_given       TINYINT(1)      NOT NULL DEFAULT 0,
            consent_timestamp   DATETIME        DEFAULT NULL,
            consent_version     VARCHAR(20)     DEFAULT NULL,
            consent_hash        VARCHAR(64)     DEFAULT NULL,
            request_type        VARCHAR(50)     DEFAULT 'anamnese',
            status              VARCHAR(20)     NOT NULL DEFAULT 'pending',
            response_text       LONGTEXT        DEFAULT NULL,
            response_sent_at    DATETIME,
            response_sent_by    BIGINT(20) UNSIGNED,
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at          DATETIME        DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_submission_hash (submission_hash),
            KEY idx_location (location_id),
            KEY idx_service (service_key),
            KEY idx_name_hash (name_hash),
            KEY idx_status (status),
            KEY idx_request_type (request_type),
            KEY idx_created_at (created_at),
            KEY idx_deleted (deleted_at)
        ) {$charset};");
    }
    
    private function createFilesTable(string $charset): void
    {
        $table = self::files();
        dbDelta("CREATE TABLE {$table} (
            id                      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id           BIGINT(20) UNSIGNED NOT NULL,
            file_id                 VARCHAR(64)     NOT NULL,
            original_name_encrypted VARCHAR(512)    NOT NULL,
            mime_type               VARCHAR(100)    NOT NULL,
            file_size               BIGINT(20) UNSIGNED NOT NULL,
            created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_submission (submission_id),
            KEY idx_file_id (file_id)
        ) {$charset};");
    }
    
    private function createAuditTable(string $charset): void
    {
        $table = self::auditLog();
        dbDelta("CREATE TABLE {$table} (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            action              VARCHAR(50) NOT NULL,
            entity_type         VARCHAR(50) DEFAULT NULL,
            entity_id           BIGINT(20) UNSIGNED DEFAULT NULL,
            wp_user_id          BIGINT(20) UNSIGNED DEFAULT NULL,
            portal_username     TEXT        DEFAULT NULL,
            ip_hash             VARCHAR(64) DEFAULT NULL,
            user_agent_hash     VARCHAR(64) DEFAULT NULL,
            details_encrypted   TEXT        DEFAULT NULL,
            created_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_wp_user (wp_user_id),
            KEY idx_action (action),
            KEY idx_created_at (created_at)
        ) {$charset};");
    }
    
    private function createPortalUsersTable(string $charset): void
    {
        $table = self::portalUsers();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            username        VARCHAR(50)     NOT NULL,
            password_hash   VARCHAR(255)    NOT NULL,
            display_name    TEXT,
            email           TEXT,
            location_id     BIGINT(20) UNSIGNED NOT NULL,
            can_view        TINYINT(1)      DEFAULT 1,
            can_edit        TINYINT(1)      DEFAULT 0,
            can_delete      TINYINT(1)      DEFAULT 0,
            can_export      TINYINT(1)      DEFAULT 1,
            is_active       TINYINT(1)      DEFAULT 1,
            last_login      DATETIME,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_username (username),
            KEY idx_location (location_id),
            KEY idx_active (is_active)
        ) {$charset};");
    }
    
    private function createApiKeysTable(string $charset): void
    {
        $table = self::apiKeys();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id     BIGINT(20) UNSIGNED NOT NULL,
            api_key_hash    VARCHAR(64)     NOT NULL,
            key_prefix      VARCHAR(16)     DEFAULT NULL COMMENT 'Erste 8 Zeichen des Keys für Identifikation',
            name            VARCHAR(100),
            label           VARCHAR(100)    DEFAULT NULL COMMENT 'Alias für name',
            can_fetch_gdt   TINYINT(1)      DEFAULT 1,
            can_fetch_files TINYINT(1)      DEFAULT 1,
            can_download_pdf TINYINT(1)     DEFAULT 1,
            ip_whitelist    TEXT,
            last_used_at    DATETIME,
            last_used_ip    VARCHAR(45),
            use_count       INT             DEFAULT 0,
            is_active       TINYINT(1)      DEFAULT 1,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            created_by      BIGINT(20) UNSIGNED,
            PRIMARY KEY (id),
            UNIQUE KEY idx_api_key_hash (api_key_hash),
            KEY idx_location (location_id),
            KEY idx_active (is_active)
        ) {$charset};");
    }
    
    private function createDocumentsTable(string $charset): void
    {
        $table = self::documents();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id     BIGINT(20) UNSIGNED NOT NULL,
            title           VARCHAR(255)    NOT NULL,
            description     TEXT,
            file_path       VARCHAR(512),
            mime_type       VARCHAR(100),
            file_size       BIGINT(20) UNSIGNED DEFAULT 0,
            is_active       TINYINT(1)      DEFAULT 1,
            sort_order      INT             DEFAULT 0,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_location (location_id),
            KEY idx_active (is_active)
        ) {$charset};");
    }
    
    private function createLicenseCacheTable(string $charset): void
    {
        $table = self::licenseCache();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cache_key       VARCHAR(100)    NOT NULL,
            cache_value     LONGTEXT,
            expires_at      DATETIME,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_cache_key (cache_key),
            KEY idx_expires (expires_at)
        ) {$charset};");
    }
    
    private function createMedicationsTable(string $charset): void
    {
        $table = self::medications();
        dbDelta("CREATE TABLE {$table} (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name                VARCHAR(255)    NOT NULL,
            wirkstoff           VARCHAR(255)    DEFAULT NULL,
            staerke             VARCHAR(100)    DEFAULT NULL,
            einheit             VARCHAR(50)     DEFAULT NULL,
            dosage              VARCHAR(100)    DEFAULT NULL COMMENT 'Legacy: wirkstoff+staerke kombiniert',
            form                VARCHAR(100)    DEFAULT NULL COMMENT 'Legacy: Alias für kategorie',
            pzn                 VARCHAR(20)     DEFAULT NULL,
            standard_dosierung  VARCHAR(100)    DEFAULT NULL,
            einnahme_hinweis    VARCHAR(255)    DEFAULT NULL,
            kategorie           VARCHAR(100)    DEFAULT NULL,
            hinweise            TEXT            DEFAULT NULL,
            ist_aktiv           TINYINT(1)      NOT NULL DEFAULT 1,
            verwendung_count    INT             NOT NULL DEFAULT 0,
            location_id         BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = alle Standorte',
            created_at          DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_name (name(100)),
            KEY idx_wirkstoff (wirkstoff(100)),
            KEY idx_pzn (pzn),
            KEY idx_kategorie (kategorie(50)),
            KEY idx_ist_aktiv (ist_aktiv),
            KEY idx_verwendung (verwendung_count),
            KEY idx_location (location_id)
        ) {$charset};");

        // FULLTEXT-Index für schnelle Medikamenten-Suche
        // dbDelta unterstützt kein FULLTEXT, daher separat.
        // Prüfe erst ob Index existiert (idempotent).
        global $wpdb;
        $indexExists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_ft_search'",
            DB_NAME,
            $table
        ));
        if (!$indexExists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} ADD FULLTEXT idx_ft_search (name, wirkstoff, dosage)");
        }
    }

    private function createFormLocationsTable(string $charset): void
    {
        $table = self::formLocations();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id         VARCHAR(100)    NOT NULL COMMENT 'JSON-Form-ID z.B. augenarzt',
            location_id     BIGINT(20) UNSIGNED NOT NULL,
            is_active       TINYINT(1)      DEFAULT 1,
            sort_order      INT             DEFAULT 0,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_form_location (form_id, location_id),
            KEY idx_location_active (location_id, is_active)
        ) {$charset};");

        // ── ICD-Zuordnungen (Fragen → ICD-10-Codes) ──
        // Multistandort-Säule: location_id (NULL = alle Standorte)
        // Mehrsprachigkeit: bezeichnung über I18n, frage_key ist sprachunabhängig
        $table = self::icdZuordnungen();
        dbDelta("CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id         VARCHAR(100)    NOT NULL COMMENT 'Fragebogen-ID z.B. augenarzt',
            frage_key       VARCHAR(100)    NOT NULL COMMENT 'Feldname im Formular z.B. glaukom',
            icd_code        VARCHAR(20)     NOT NULL COMMENT 'ICD-10-GM Code z.B. H40.9',
            bezeichnung     VARCHAR(255)    DEFAULT NULL COMMENT 'Klartext-Bezeichnung z.B. Glaukom',
            sicherheit      CHAR(1)         DEFAULT 'G' COMMENT 'G=gesichert, V=Verdacht, Z=Zustand nach, A=ausgeschlossen',
            seite_field     VARCHAR(100)    DEFAULT NULL COMMENT 'Feldname für Seitenlokalisation z.B. glaukom_seite',
            location_id     BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = alle Standorte',
            is_active       TINYINT(1)      DEFAULT 1,
            sort_order      INT             DEFAULT 0,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_form_frage_location (form_id, frage_key, location_id),
            KEY idx_form_active (form_id, is_active),
            KEY idx_location (location_id)
        ) {$charset};");
    }
}
