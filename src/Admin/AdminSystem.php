<?php
/**
 * AdminSystem – System-Verwaltung im Backend
 *
 * Vereint die v3-Klassen: System, Backup
 *
 * Verantwortlich für:
 *  - System-Status / Health-Checks (Verschlüsselung, Tabellen, PHP-Version, …)
 *  - Backup (erstellen, herunterladen, wiederherstellen, löschen)
 *  - Test-E-Mail senden
 *  - Manuelle Bereinigung
 *  - Verschlüsselungs-Reset
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Security\Encryption;
use PraxisPortal\Database\Schema;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Migration;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminSystem
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * i18n-Shortcut
     */
    private function t(string $text): string
    {
        return I18n::translate($text);
    }

    /* =====================================================================
     * SEITEN-RENDERING
     * ================================================================== */

    public function renderPage(): void
    {
        $activeTab = sanitize_text_field($_GET['tab'] ?? 'health');

        $tabs = [
            'health'      => '❤️ System-Status',
            'backup'      => '💾 Backup',
            'cleanup'     => '🧹 Bereinigung',
        ];

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-admin-tools" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                System
            </h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-system&tab=' . $tabKey)); ?>"
                       class="nav-tab <?php echo esc_attr($activeTab === $tabKey ? 'nav-tab-active' : ''); ?>">
                        <?php echo esc_html($tabLabel); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div style="max-width:900px;">
                <?php
                switch ($activeTab) {
                    case 'backup':      $this->renderBackupTab(); break;
                    case 'cleanup':     $this->renderCleanupTab(); break;
                    default:            $this->renderHealthTab(); break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
     * AJAX-HANDLER
     * ================================================================== */

    /**
     * Test-E-Mail senden
     */
    public function ajaxTestEmail(): void
    {
        $email = sanitize_email($_POST['email'] ?? '');
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => $this->t('Ungültige E-Mail-Adresse.')], 400);
        }

        $praxisName = get_option('pp_praxis_name', get_bloginfo('name'));
        $subject    = '[' . $praxisName . '] Test-E-Mail';
        $body       = "Dies ist eine Test-E-Mail vom Praxis-Portal v4.\n\n"
                    . "Wenn Sie diese E-Mail erhalten, funktioniert die E-Mail-Benachrichtigung korrekt.\n\n"
                    . "Gesendet am: " . current_time('d.m.Y H:i:s') . "\n"
                    . "Von: " . home_url() . "\n";
        $headers    = ['Content-Type: text/plain; charset=UTF-8'];

        $result = wp_mail($email, $subject, $body, $headers);

        if ($result) {
            wp_send_json_success(['message' => $this->t('Test-E-Mail gesendet an') . ' ' . $email]);
        } else {
            wp_send_json_error(['message' => 'E-Mail-Versand fehlgeschlagen. Prüfen Sie Ihre WordPress-Mailkonfiguration.']);
        }
    }

    /**
     * Manuelle Bereinigung starten
     */
    public function ajaxRunCleanup(): void
    {
        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $retentionDays = (int) get_option('pp_retention_days', 90);
        $deleted       = $submissionRepo->cleanupOlderThan($retentionDays);

        $auditRepo->logSettings('manual_cleanup', ['deleted' => $deleted, 'days' => $retentionDays]);

        wp_send_json_success([
            'message' => $deleted . ' Einreichungen (älter als ' . $retentionDays . ' Tage) gelöscht.',
            'deleted' => $deleted,
        ]);
    }

    /**
     * Backup erstellen
     */
    public function ajaxCreateBackup(): void
    {
        $auditRepo = $this->container->get(AuditRepository::class);

        $backupDir = $this->getBackupDir();
        if (!$backupDir) {
            wp_send_json_error(['message' => $this->t('Backup-Verzeichnis konnte nicht erstellt werden.')]);
        }

        $wpdb = $GLOBALS['wpdb'];
        $prefix    = $wpdb->prefix . 'pp_';
        $tables    = $wpdb->get_col($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        ));
        $timestamp = current_time('Y-m-d_H-i-s');
        $filename  = 'pp-backup-' . $timestamp . '.sql';
        $filepath  = $backupDir . '/' . $filename;

        $sql = "-- Praxis-Portal v4 Backup\n-- Erstellt: " . current_time('Y-m-d H:i:s') . "\n-- Tabellen: " . count($tables) . "\n\n";

        foreach ($tables as $table) {
            // Tabellennamen stammen aus SHOW TABLES (intern, kein User-Input)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create[1] . ";\n\n";

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
            foreach ($rows as $row) {
                $values = array_map(function ($v) use ($wpdb) {
                    return $v === null ? 'NULL' : $wpdb->prepare('%s', $v);
                }, array_values($row));
                $sql .= "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");\n";
            }
            $sql .= "\n";
        }

        if (file_put_contents($filepath, $sql) === false) {
            wp_send_json_error(['message' => $this->t('Backup-Datei konnte nicht geschrieben werden.')]);
        }

        $auditRepo->logSettings('backup_created', ['file' => $filename, 'size' => filesize($filepath)]);

        wp_send_json_success([
            'message'  => $this->t('Backup erstellt') . ': ' . $filename,
            'filename' => $filename,
            'size'     => size_format(filesize($filepath)),
        ]);
    }

    /**
     * Backup löschen
     */
    public function ajaxDeleteBackup(): void
    {
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $filepath = $this->getBackupDir() . '/' . $filename;

        if (!file_exists($filepath) || pathinfo($filepath, PATHINFO_EXTENSION) !== 'sql') {
            wp_send_json_error(['message' => $this->t('Datei nicht gefunden.')]);
        }

        if (unlink($filepath)) {
            $auditRepo = $this->container->get(AuditRepository::class);
            $auditRepo->logSettings('backup_deleted', ['file' => $filename]);
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => $this->t('Löschen fehlgeschlagen.')]);
        }
    }

    /**
     * Backup wiederherstellen
     */
    public function ajaxRestoreBackup(): void
    {
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $filepath = $this->getBackupDir() . '/' . $filename;

        if (!file_exists($filepath) || pathinfo($filepath, PATHINFO_EXTENSION) !== 'sql') {
            wp_send_json_error(['message' => $this->t('Datei nicht gefunden.')]);
        }

        $wpdb = $GLOBALS['wpdb'];
        $sql        = file_get_contents($filepath);
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));
        $errors     = 0;
        $skipped    = 0;
        $prefix     = $wpdb->prefix . 'pp_';

        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }
            
            // Sicherheitsprüfung: nur erlaubte Statements auf eigene Tabellen
            if (!$this->isAllowedRestoreStatement($statement, $prefix)) {
                $skipped++;
                continue;
            }
            
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query($statement);
            if ($result === false) {
                $errors++;
            }
        }

        $auditRepo = $this->container->get(AuditRepository::class);
        $auditRepo->logSettings('backup_restored', ['file' => $filename, 'errors' => $errors, 'skipped' => $skipped]);

        if ($errors === 0) {
            wp_send_json_success(['message' => $this->t('Backup erfolgreich wiederhergestellt.')]);
        } else {
            wp_send_json_success(['message' => $this->t('Backup wiederhergestellt mit') . ' ' . $errors . ' ' . $this->t('Fehlern.')]);
        }
    }

    /**
     * Verschlüsselung zurücksetzen
     */
    public function ajaxResetEncryption(): void
    {
        $encryption = $this->container->get(Encryption::class);
        $auditRepo  = $this->container->get(AuditRepository::class);

        $result = $encryption->resetKey();

        if (!empty($result['success'])) {
            $auditRepo->logSecurity('encryption_reset', []);
            wp_send_json_success(['message' => $this->t('Neuer Verschlüsselungsschlüssel generiert. ACHTUNG: Bereits verschlüsselte Daten sind nicht mehr lesbar!')]);
        } else {
            wp_send_json_error(['message' => $this->t('Fehler beim Zurücksetzen.')]);
        }
    }

    /* =====================================================================
     * DOWNLOAD HANDLERS (admin-post.php)
     * ================================================================== */

    /**
     * Audit-Log als CSV herunterladen.
     */
    public function handleDownloadLog(): void
    {
        $auditRepo = $this->container->get(AuditRepository::class);
        $entries   = $auditRepo->list([], 5000, 0);
        $rows      = $entries['items'] ?? $entries;

        $filename = 'pp-audit-log-' . current_time('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', $this->t('Typ'), $this->t('Aktion'), $this->t('Benutzer'), 'IP-Hash', $this->t('Details'), $this->t('Erstellt')]);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'] ?? '',
                $row['type'] ?? '',
                $row['action'] ?? '',
                $row['user_id'] ?? '',
                $row['ip_hash'] ?? '',
                $row['details'] ?? '',
                $row['created_at'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Backup-Datei zum Download senden.
     */
    public function handleExportBackup(): void
    {
        $filename = sanitize_file_name($_GET['file'] ?? '');

        if (empty($filename) || pathinfo($filename, PATHINFO_EXTENSION) !== 'sql') {
            wp_die($this->t('Ungültige Datei.'));
        }

        $backupDir = $this->getBackupDir();
        if (!$backupDir) {
            wp_die($this->t('Backup-Verzeichnis nicht verfügbar.'));
        }

        $filepath = $backupDir . '/' . $filename;

        // Sicherheitsprüfung: realpath muss im Backup-Verzeichnis liegen
        $realPath = realpath($filepath);
        $realDir  = realpath($backupDir);
        if (!$realPath || !$realDir || strpos($realPath, $realDir) !== 0) {
            wp_die($this->t('Datei nicht gefunden.'));
        }

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($realPath));
        readfile($realPath);
        exit;
    }

    /* =====================================================================
     * PRIVATE: TAB-RENDERING
     * ================================================================== */

    private function renderHealthTab(): void
    {
        $encryption   = $this->container->get(Encryption::class);
        $locationRepo = $this->container->get(LocationRepository::class);

        $checks = $this->getHealthChecks($encryption, $locationRepo);

        ?>
        <h2><?php echo esc_html($this->t('System-Status')); ?></h2>
        <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
            <thead>
                <tr>
                    <th style="width:200px;"><?php echo esc_html($this->t('Prüfung')); ?></th>
                    <th style="width:60px;">Status</th>
                    <th><?php echo esc_html($this->t('Details')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <td><strong><?php echo esc_html($check['label']); ?></strong></td>
                    <td><?php echo $check['ok'] ? '✅' : '❌'; ?></td>
                    <td><?php echo esc_html($check['detail']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:30px;"><?php echo esc_html($this->t('Server-Info')); ?></h3>
        <table class="form-table" style="max-width:700px;">
            <tr><th>PHP-Version</th><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
            <tr><th>WordPress</th><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
            <tr><th><?php echo esc_html($this->t('Plugin-Version')); ?></th><td><?php echo esc_html(defined('PP_VERSION') ? PP_VERSION : '?'); ?></td></tr>
            <tr><th>OpenSSL</th><td><?php echo esc_html(OPENSSL_VERSION_TEXT); ?></td></tr>
            <tr><th>DB-Präfix</th><td><code><?php echo esc_html($GLOBALS['wpdb']->prefix); ?>pp_</code></td></tr>
            <tr><th>Upload-Limit</th><td><?php echo esc_html(ini_get('upload_max_filesize')); ?></td></tr>
            <tr><th>Memory-Limit</th><td><?php echo esc_html(ini_get('memory_limit')); ?></td></tr>
        </table>

        <h3 style="margin-top:30px;"><?php echo esc_html($this->t('Verschlüsselung')); ?></h3>
        <?php
            $encryption = $this->container->get(Encryption::class);
            $method     = $encryption->getMethod();
            $algoLabel  = match ($method) {
                'sodium'  => 'libsodium (XSalsa20-Poly1305 AEAD)',
                'openssl' => 'OpenSSL AES-256-GCM (AEAD)',
                default   => $this->t('Nicht verfügbar'),
            };
        ?>
        <p>
            <strong><?php echo esc_html($this->t('Algorithmus')); ?>:</strong> <?php echo esc_html($algoLabel); ?><br>
            <strong><?php echo esc_html($this->t('Key-Speicher')); ?>:</strong> <code><?php echo esc_html(ABSPATH . '../'); ?></code> (außerhalb Web-Root)
        </p>
        <p>
            <button type="button" class="button pp-reset-encryption" style="color:#dc3232;"
                    onclick="if(!confirm('⚠️ WARNUNG: Dadurch werden alle verschlüsselten Daten UNLESBAR!\n\nDies ist nur nötig bei Kompromittierung des Schlüssels.\n\nWirklich fortfahren?')) return false;">
                🔐 Verschlüsselungsschlüssel neu generieren
            </button>
        </p>

        <h3 style="margin-top:30px;"><?php echo esc_html($this->t('Einrichtungsassistent')); ?></h3>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-setup')); ?>" class="button">
                🏥 Einrichtungsassistent erneut starten
            </a>
            <span style="margin-left:10px;color:#646970;">
                <?php echo AdminSetupWizard::isComplete() ? '✅ Setup abgeschlossen' : '⚠️ Setup nicht abgeschlossen'; ?>
            </span>
        </p>
        <?php
    }

    private function renderBackupTab(): void
    {
        $backupDir = $this->getBackupDir();
        $backups   = [];

        if ($backupDir && is_dir($backupDir)) {
            $files = glob($backupDir . '/*.sql');
            if ($files) {
                rsort($files); // neueste zuerst
                foreach ($files as $file) {
                    $backups[] = [
                        'filename' => basename($file),
                        'size'     => size_format(filesize($file)),
                        'date'     => date_i18n('d.m.Y H:i', filemtime($file)),
                    ];
                }
            }
        }

        ?>
        <h2>Backups</h2>
        <p class="description">Datenbank-Backup der Plugin-Tabellen. Dateien werden außerhalb des Web-Zugriffs gespeichert.</p>

        <p style="margin-bottom:20px;">
            <button type="button" class="button button-primary pp-create-backup">💾 Neues Backup erstellen</button>
            <span id="pp-backup-status" style="margin-left:10px;"></span>
        </p>

        <?php if (empty($backups)): ?>
            <p><?php echo esc_html($this->t('Keine Backups vorhanden.')); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
                <thead>
                    <tr><th><?php echo esc_html($this->t('Datei')); ?></th><th><?php echo esc_html($this->t('Größe')); ?></th><th><?php echo esc_html($this->t('Datum')); ?></th><th><?php echo esc_html($this->t('Aktionen')); ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><code style="font-size:12px;"><?php echo esc_html($b['filename']); ?></code></td>
                        <td><?php echo esc_html($b['size']); ?></td>
                        <td><?php echo esc_html($b['date']); ?></td>
                        <td>
                            <button type="button" class="button button-small pp-restore-backup" data-file="<?php echo esc_attr($b['filename']); ?>">Wiederherstellen</button>
                            <button type="button" class="button button-small pp-delete-backup" data-file="<?php echo esc_attr($b['filename']); ?>">Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    private function renderCleanupTab(): void
    {
        $retentionDays = (int) get_option('pp_retention_days', 90);

        ?>
        <h2><?php echo esc_html($this->t('Bereinigung')); ?></h2>
        <p><?php echo esc_html($this->t('Einreichungen älter als')); ?> <strong><?php echo (int) $retentionDays; ?></strong> <?php echo esc_html($this->t('Tage werden bei der Bereinigung gelöscht.')); ?></p>
        <p class="description">Die automatische Bereinigung läuft täglich als WP-Cron. Hier können Sie sie manuell starten.</p>

        <p style="margin-top:20px;">
            <button type="button" class="button button-primary pp-run-cleanup">🧹 Jetzt bereinigen</button>
            <span id="pp-cleanup-status" style="margin-left:10px;"></span>
        </p>

        <h3 style="margin-top:30px;">E-Mail-Test</h3>
        <p>
            <input type="email" id="pp-test-email-input" value="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:300px;">
            <button type="button" class="button pp-send-test-email">📧 Test-E-Mail senden</button>
            <span id="pp-test-email-status" style="margin-left:10px;"></span>
        </p>
        <?php
    }

    /* =====================================================================
     * PRIVATE: HELPER
     * ================================================================== */

    /**
     * Health-Checks durchführen
     */
    private function getHealthChecks(Encryption $encryption, LocationRepository $locationRepo): array
    {
        $wpdb = $GLOBALS['wpdb'];

        $checks = [];

        // PHP-Version
        $checks[] = [
            'label'  => 'PHP-Version',
            'ok'     => version_compare(PHP_VERSION, '8.1', '>='),
            'detail' => PHP_VERSION . (version_compare(PHP_VERSION, '8.1', '>=') ? '' : ' (mind. 8.1 empfohlen)'),
        ];

        // OpenSSL
        $checks[] = [
            'label'  => 'OpenSSL',
            'ok'     => extension_loaded('openssl'),
            'detail' => extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : $this->t('Nicht installiert!'),
        ];

        // Verschlüsselung
        $encMethod = $encryption->getMethod();
        $encLabel  = match ($encMethod) {
            'sodium'  => 'Sodium AEAD',
            'openssl' => 'AES-256-GCM',
            default   => $this->t('Keine'),
        };
        $checks[] = [
            'label'  => $encLabel . ' ' . $this->t('Schlüssel'),
            'ok'     => $encryption->isKeyValid(),
            'detail' => $encryption->isKeyValid() ? $this->t('Gültig (außerhalb Web-Root)') : $this->t('FEHLT — Daten nicht verschlüsselbar!'),
        ];

        // Tabellen
        $prefix   = $wpdb->prefix . 'pp_';
        $expected = ['submissions', 'files', 'locations', 'services', 'portal_users', 'audit_log', 'api_keys', 'medications', 'form_locations', 'icd_zuordnungen'];
        $missing  = [];
        foreach ($expected as $t) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $prefix . $t));
            if (!$exists) {
                $missing[] = $t;
            }
        }
        $checks[] = [
            'label'  => $this->t('Datenbank-Tabellen'),
            'ok'     => empty($missing),
            'detail' => empty($missing) ? count($expected) . ' / ' . count($expected) . ' ' . $this->t('vorhanden') : $this->t('Fehlend') . ': ' . implode(', ', $missing),
        ];

        // Standort
        $defaultLoc = $locationRepo->getDefault();
        $checks[] = [
            'label'  => $this->t('Standard-Standort'),
            'ok'     => $defaultLoc !== null,
            'detail' => $defaultLoc ? $defaultLoc['name'] : 'Nicht konfiguriert',
        ];

        // Backup-Verzeichnis
        $backupDir = $this->getBackupDir();
        $checks[] = [
            'label'  => 'Backup-Verzeichnis',
            'ok'     => $backupDir && is_writable($backupDir),
            'detail' => $backupDir ? (is_writable($backupDir) ? 'Beschreibbar' : 'Nicht beschreibbar!') : 'Konnte nicht erstellt werden',
        ];

        // WP-Cron
        $nextCron = wp_next_scheduled('pp_daily_cleanup');
        $checks[] = [
            'label'  => 'Cron-Job (Bereinigung)',
            'ok'     => $nextCron !== false,
            'detail' => $nextCron ? 'Nächste Ausführung: ' . date_i18n('d.m.Y H:i', $nextCron) : 'Nicht geplant',
        ];

        // Object Cache (wichtig für Portal-Sessions bei vielen Nutzern)
        $hasObjectCache = wp_using_ext_object_cache();
        $checks[] = [
            'label'  => 'Object Cache',
            'ok'     => $hasObjectCache,
            'detail' => $hasObjectCache
                ? 'Aktiv (empfohlen für Portal-Sessions)'
                : 'Nicht aktiv — bei vielen Portal-Nutzern empfohlen (Redis/Memcached)',
        ];

        return $checks;
    }

    /**
     * Prüft ob ein SQL-Statement beim Restore erlaubt ist.
     * 
     * Nur DROP TABLE, CREATE TABLE und INSERT auf eigene pp_-Tabellen.
     * Verhindert Manipulation durch modifizierte Backup-Dateien.
     */
    private function isAllowedRestoreStatement(string $statement, string $prefix): bool
    {
        $normalized = strtoupper(trim($statement));
        
        // Erlaubte Befehle
        $allowedPatterns = [
            'DROP TABLE IF EXISTS',
            'CREATE TABLE',
            'INSERT INTO',
        ];
        
        $isAllowedCommand = false;
        foreach ($allowedPatterns as $pattern) {
            if (str_starts_with($normalized, $pattern)) {
                $isAllowedCommand = true;
                break;
            }
        }
        
        if (!$isAllowedCommand) {
            return false;
        }
        
        // Prefix-Check: Statement muss unseren Tabellennamen enthalten
        if (strpos($statement, $prefix) === false) {
            return false;
        }
        
        return true;
    }

    /**
     * Backup-Verzeichnis (außerhalb Web-Root) ermitteln/erstellen
     */
    private function getBackupDir(): ?string
    {
        $dir = ABSPATH . '../pp-backups';

        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return null;
            }
            // .htaccess für Zugriffssperre
            file_put_contents($dir . '/.htaccess', "Deny from all\n");
            // index.php
            file_put_contents($dir . '/index.php', '<?php // Silence is golden.');
        }

        return $dir;
    }
}
