<?php
/**
 * AdminSetupWizard – Einrichtungsassistent nach Erstinstallation
 *
 * Führt den Praxis-Admin in 6 Schritten durch die Ersteinrichtung:
 *   1. Willkommen + Systemcheck
 *   2. Lizenzschlüssel
 *   3. Praxis-Standort anlegen
 *   4. Sicherheit (Verschlüsselung + DSGVO)
 *   5. Portal-Zugang einrichten
 *   6. Fertig – Zusammenfassung + Links
 *
 * Wird nach Plugin-Aktivierung automatisch aufgerufen (Transient-Redirect).
 * Kann jederzeit über System-Status erneut gestartet werden.
 *
 * @package PraxisPortal\Admin
 * @since   4.1.8
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Security\Encryption;
use PraxisPortal\Security\KeyManager;
use PraxisPortal\Database\Schema;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Repository\PortalUserRepository;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminSetupWizard
{
    /* =====================================================================
     * KONSTANTEN
     * ================================================================== */

    /** Option: Setup abgeschlossen? */
    public const OPTION_COMPLETE = 'pp_setup_complete';

    /** Alle Wizard-Schritte */
    private function getSteps(): array
    {
        return [
            1 => ['id' => 'willkommen',  'label' => $this->t('Willkommen'),   'icon' => '👋'],
            2 => ['id' => 'lizenz',      'label' => $this->t('Lizenz'),       'icon' => '🔑'],
            3 => ['id' => 'standort',    'label' => $this->t('Standort'),     'icon' => '📍'],
            4 => ['id' => 'sicherheit',  'label' => $this->t('Sicherheit'),   'icon' => '🔐'],
            5 => ['id' => 'portal',      'label' => 'Portal',                 'icon' => '🚪'],
            6 => ['id' => 'fertig',      'label' => $this->t('Fertig'),       'icon' => '✅'],
        ];
    }

    /* =====================================================================
     * PROPERTIES
     * ================================================================== */

    private Container $container;
    private int $currentStep;

    /* =====================================================================
     * CONSTRUCTOR
     * ================================================================== */

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->currentStep = max(1, min(6, (int) ($_GET['step'] ?? 1)));
    }

    /**
     * i18n-Shortcut
     */
    private function t(string $text): string
    {
        return I18n::translate($text);
    }

    /* =====================================================================
     * STATISCHE HELPER
     * ================================================================== */

    /**
     * Wurde das Setup bereits abgeschlossen?
     */
    public static function isComplete(): bool
    {
        return (bool) get_option(self::OPTION_COMPLETE, false);
    }

    /**
     * Setup als abgeschlossen markieren.
     */
    public static function markComplete(): void
    {
        update_option(self::OPTION_COMPLETE, true);
    }

    /**
     * Setup zurücksetzen (für erneute Ausführung).
     */
    public static function reset(): void
    {
        delete_option(self::OPTION_COMPLETE);
    }

    /* =====================================================================
     * RENDERING
     * ================================================================== */

    /**
     * Wizard-Seite rendern.
     */
    public function render(): void
    {
        $this->renderHeader();
        $this->renderStepNav();

        echo '<div class="pp-wizard-content">';

        match ($this->currentStep) {
            1 => $this->renderStepWillkommen(),
            2 => $this->renderStepLizenz(),
            3 => $this->renderStepStandort(),
            4 => $this->renderStepSicherheit(),
            5 => $this->renderStepPortal(),
            6 => $this->renderStepFertig(),
        };

        echo '</div>'; // .pp-wizard-content

        $this->renderFooter();
    }

    /* =====================================================================
     * STEP 1: WILLKOMMEN + SYSTEMCHECK
     * ================================================================== */

    private function renderStepWillkommen(): void
    {
        // Attempt to create missing tables (dbDelta is idempotent)
        if (!$this->checkTablesExist()) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $schema = new Schema();
            $schema->createTables();
        }

        $checks = $this->runSystemChecks();
        $allOk  = !in_array(false, array_column($checks, 'ok'), true);
        ?>
        <div class="pp-wizard-step">
            <h2><?php echo esc_html($this->t('Willkommen beim Praxis-Portal!')); ?></h2>
            <p class="pp-wizard-intro">
                Dieser Assistent führt Sie in wenigen Schritten durch die Ersteinrichtung.
                Zunächst prüfen wir, ob Ihr System alle Voraussetzungen erfüllt.
            </p>

            <div class="pp-wizard-checks">
                <h3><?php echo esc_html($this->t('Systemvoraussetzungen')); ?></h3>
                <table class="pp-check-table">
                    <?php foreach ($checks as $check): ?>
                    <tr class="<?php echo esc_attr($check['ok'] ? 'check-ok' : 'check-fail'); ?>">
                        <td class="check-icon"><?php echo $check['ok'] ? '✅' : '❌'; ?></td>
                        <td class="check-label"><?php echo esc_html($check['label']); ?></td>
                        <td class="check-value"><?php echo esc_html($check['value']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <?php if (!$allOk): ?>
                <div class="pp-wizard-notice pp-notice-warning">
                    <strong>⚠️ Einige Voraussetzungen sind nicht erfüllt.</strong><br>
                    Das Plugin funktioniert möglicherweise eingeschränkt. Bitte kontaktieren Sie
                    Ihren Hosting-Anbieter.
                </div>
            <?php else: ?>
                <div class="pp-wizard-notice pp-notice-success">
                    <strong>✅ Alle Voraussetzungen erfüllt!</strong><br>
                    Ihr System ist bereit für das Praxis-Portal.
                </div>
            <?php endif; ?>

            <?php $this->renderNavButtons(null, 2); ?>
        </div>
        <?php
    }

    /**
     * Systemprüfungen durchführen.
     *
     * @return array<array{label:string,value:string,ok:bool}>
     */
    private function runSystemChecks(): array
    {
        $checks = [];

        // PHP-Version
        $checks[] = [
            'label' => 'PHP-Version',
            'value' => PHP_VERSION . ' (min. 8.0)',
            'ok'    => version_compare(PHP_VERSION, '8.0', '>='),
        ];

        // WordPress-Version
        global $wp_version;
        $checks[] = [
            'label' => $this->t('WordPress-Version'),
            'value' => $wp_version . ' (min. 5.8)',
            'ok'    => version_compare($wp_version, '5.8', '>='),
        ];

        // MySQL/MariaDB
        global $wpdb;
        $dbVersion = $wpdb->db_version();
        $checks[] = [
            'label' => $this->t('Datenbank'),
            'value' => $dbVersion,
            'ok'    => version_compare($dbVersion, '5.7', '>='),
        ];

        // Sodium (Verschlüsselung)
        $hasSodium = extension_loaded('sodium') && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
        $checks[] = [
            'label' => $this->t('Verschlüsselung (libsodium)'),
            'value' => $hasSodium ? $this->t('Verfügbar') : $this->t('Nicht verfügbar'),
            'ok'    => $hasSodium,
        ];

        // OpenSSL Fallback
        if (!$hasSodium) {
            $hasOpenSsl = extension_loaded('openssl');
            $checks[] = [
                'label' => $this->t('Verschlüsselung (OpenSSL Fallback)'),
                'value' => $hasOpenSsl ? $this->t('Verfügbar') : $this->t('Nicht verfügbar'),
                'ok'    => $hasOpenSsl,
            ];
        }

        // MB-String
        $checks[] = [
            'label' => $this->t('Multibyte-String (mbstring)'),
            'value' => extension_loaded('mbstring') ? $this->t('Verfügbar') : $this->t('Nicht verfügbar'),
            'ok'    => extension_loaded('mbstring'),
        ];

        // JSON
        $checks[] = [
            'label' => 'JSON-Support',
            'value' => extension_loaded('json') ? $this->t('Verfügbar') : $this->t('Nicht verfügbar'),
            'ok'    => extension_loaded('json'),
        ];

        // Schlüsseldatei beschreibbar
        $keyDir = defined('PP_KEY_DIR')
            ? PP_KEY_DIR
            : (defined('ABSPATH') ? dirname(ABSPATH) . '/pp-keys' : sys_get_temp_dir());
        $dirWritable = is_dir($keyDir) ? is_writable($keyDir) : is_writable(dirname($keyDir));
        $checks[] = [
            'label' => $this->t('Schlüssel-Verzeichnis'),
            'value' => $dirWritable ? $this->t('Beschreibbar') : $this->t('Nicht beschreibbar') . ' (' . $keyDir . ')',
            'ok'    => $dirWritable,
        ];

        // Plugin-Tabellen
        $tablesExist = $this->checkTablesExist();
        $checks[] = [
            'label' => $this->t('Datenbank-Tabellen'),
            'value' => $tablesExist ? $this->t('Erstellt') : $this->t('Fehlen'),
            'ok'    => $tablesExist,
        ];

        return $checks;
    }

    /**
     * Prüft ob die wichtigsten Plugin-Tabellen existieren.
     */
    private function checkTablesExist(): bool
    {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'pp_locations',
            $wpdb->prefix . 'pp_submissions',
            $wpdb->prefix . 'pp_portal_users',
        ];

        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if (!$exists) {
                return false;
            }
        }
        return true;
    }

    /* =====================================================================
     * STEP 2: LIZENZ
     * ================================================================== */

    private function renderStepLizenz(): void
    {
        $licenseKey  = get_option('pp_license_key', '');
        $licenseStatus = get_option('pp_license_status', '');
        $hasLicense  = !empty($licenseKey);
        ?>
        <div class="pp-wizard-step">
            <h2>🔑 Lizenzschlüssel</h2>
            <p class="pp-wizard-intro">
                Geben Sie Ihren Lizenzschlüssel ein, um alle Funktionen freizuschalten.
                Sie können diesen Schritt auch überspringen und die Lizenz später unter
                <em>Praxis-Portal → Lizenz</em> aktivieren.
            </p>

            <form method="post" class="pp-wizard-form">
                <?php wp_nonce_field('pp_wizard_action', '_pp_wizard_nonce'); ?>
                <input type="hidden" name="pp_wizard_action" value="save_license">

                <div class="pp-form-row">
                    <label for="pp_license_key"><?php echo esc_html($this->t('Lizenzschlüssel')); ?></label>
                    <input type="text" id="pp_license_key" name="license_key"
                           value="<?php echo esc_attr($licenseKey); ?>"
                           placeholder="XXXX-XXXX-XXXX-XXXX"
                           class="pp-input-wide"
                           spellcheck="false"
                           autocomplete="off">
                    <p class="pp-form-hint">
                        Format: XXXX-XXXX-XXXX-XXXX. Erhalten Sie nach dem Kauf per E-Mail.
                    </p>
                </div>

                <?php if ($hasLicense && $licenseStatus === 'valid'): ?>
                    <div class="pp-wizard-notice pp-notice-success">
                        ✅ Lizenz aktiv
                    </div>
                <?php elseif ($hasLicense && $licenseStatus): ?>
                    <div class="pp-wizard-notice pp-notice-warning">
                        ⚠️ Lizenz-Status: <?php echo esc_html($licenseStatus); ?>
                    </div>
                <?php endif; ?>

                <div class="pp-wizard-actions">
                    <button type="submit" class="button button-primary">Lizenz speichern</button>
                </div>
            </form>

            <?php $this->renderNavButtons(1, 3, 'Überspringen →'); ?>
        </div>
        <?php
    }

    /* =====================================================================
     * STEP 3: STANDORT
     * ================================================================== */

    private function renderStepStandort(): void
    {
        /** @var LocationRepository $locationRepo */
        $locationRepo = $this->container->get(LocationRepository::class);
        $locations    = $locationRepo->getAll();
        $hasLocation  = !empty($locations);
        $defaultLoc   = $hasLocation ? $locations[0] : null;
        ?>
        <div class="pp-wizard-step">
            <h2>📍 Praxis-Standort</h2>
            <p class="pp-wizard-intro">
                Konfigurieren Sie Ihren Hauptstandort. Bei mehreren Standorten können Sie
                weitere später unter <em>Praxis-Portal → Standorte</em> anlegen.
            </p>

            <?php if ($hasLocation): ?>
                <div class="pp-wizard-notice pp-notice-info">
                    📍 Es <?php echo count($locations) === 1 ? 'existiert bereits 1 Standort' : 'existieren bereits ' . count($locations) . ' Standorte'; ?>:
                    <strong><?php echo esc_html($defaultLoc['name'] ?? $this->t('Unbenannt')); ?></strong>
                    <?php if (!empty($defaultLoc['uuid'])): ?>
                        <br><small>UUID: <?php echo esc_html($defaultLoc['uuid']); ?></small>
                    <?php endif; ?>
                </div>

                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-location-edit&location_id=' . ($defaultLoc['id'] ?? 0))); ?>"
                       class="button" target="_blank">
                        ✏️ Standort bearbeiten (neues Fenster)
                    </a>
                </p>

            <?php else: ?>
                <form method="post" class="pp-wizard-form" id="pp-location-form">
                    <?php wp_nonce_field('pp_wizard_action', '_pp_wizard_nonce'); ?>
                    <input type="hidden" name="pp_wizard_action" value="save_location">

                    <div class="pp-form-row">
                        <label for="loc_name">Praxis-Name</label>
                        <input type="text" id="loc_name" name="loc_name" class="pp-input-wide"
                               value="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    </div>

                    <div class="pp-form-row-group">
                        <div class="pp-form-row">
                            <label for="loc_strasse"><?php echo esc_html($this->t('Straße + Nr.')); ?></label>
                            <input type="text" id="loc_strasse" name="loc_strasse" class="regular-text">
                        </div>
                        <div class="pp-form-row pp-form-row-short">
                            <label for="loc_plz">PLZ</label>
                            <input type="text" id="loc_plz" name="loc_plz" class="small-text" maxlength="5">
                        </div>
                        <div class="pp-form-row">
                            <label for="loc_ort"><?php echo esc_html($this->t('Ort')); ?></label>
                            <input type="text" id="loc_ort" name="loc_ort" class="regular-text">
                        </div>
                    </div>

                    <div class="pp-form-row">
                        <label for="loc_email">E-Mail</label>
                        <input type="email" id="loc_email" name="loc_email" class="pp-input-wide"
                               value="<?php echo esc_attr(get_option('admin_email')); ?>">
                    </div>

                    <div class="pp-form-row">
                        <label for="loc_telefon"><?php echo esc_html($this->t('Telefon')); ?></label>
                        <input type="tel" id="loc_telefon" name="loc_telefon" class="regular-text">
                    </div>

                    <div class="pp-wizard-nav-buttons">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pp-setup&step=2')); ?>" class="button">← Zurück</a>
                        <button type="submit" class="button button-primary">Weiter →</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($hasLocation): ?>
                <?php $this->renderNavButtons(2, 4); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * STEP 4: SICHERHEIT
     * ================================================================== */

    private function renderStepSicherheit(): void
    {
        $keyManager  = $this->container->get(KeyManager::class);
        $encryption  = $this->container->get(Encryption::class);
        $hasKey      = $keyManager->ensureKeyExists();
        $warnings    = $encryption->getWarnings();
        $method      = $encryption->getMethod();
        ?>
        <div class="pp-wizard-step">
            <h2>🔐 Sicherheit & Datenschutz</h2>
            <p class="pp-wizard-intro">
                Alle Patientendaten werden mit AES-256 Ende-zu-Ende verschlüsselt.
                Hier prüfen wir den Status Ihrer Verschlüsselung.
            </p>

            <div class="pp-wizard-checks">
                <h3><?php echo esc_html($this->t('Verschlüsselungsstatus')); ?></h3>
                <table class="pp-check-table">
                    <tr class="<?php echo esc_attr($hasKey ? 'check-ok' : 'check-fail'); ?>">
                        <td class="check-icon"><?php echo $hasKey ? '✅' : '❌'; ?></td>
                        <td class="check-label">Verschlüsselungsschlüssel</td>
                        <td class="check-value"><?php echo esc_html($hasKey ? 'Vorhanden & sicher gespeichert' : 'Fehlt!'); ?></td>
                    </tr>
                    <tr class="check-ok">
                        <td class="check-icon">✅</td>
                        <td class="check-label">Methode</td>
                        <td class="check-value"><?php echo esc_html($method ?: 'Nicht verfügbar'); ?></td>
                    </tr>
                </table>
            </div>

            <?php if (!empty($warnings)): ?>
                <div class="pp-wizard-notice pp-notice-warning">
                    <strong>⚠️ Hinweise:</strong>
                    <ul>
                        <?php foreach ($warnings as $w): ?>
                            <li><?php echo esc_html($w); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="pp-wizard-info-box">
                <h3>🛡️ DSGVO-Hinweis</h3>
                <p>Das Praxis-Portal speichert Patientendaten gemäß DSGVO:</p>
                <ul>
                    <li><strong>Verschlüsselung:</strong> AES-256 (<?php echo esc_html($method); ?>) für alle Gesundheitsdaten</li>
                    <li><strong>Schlüsselspeicherung:</strong> Außerhalb des Web-Root, nicht über Browser zugänglich</li>
                    <li><strong>Löschfristen:</strong> Konfigurierbar unter <em>Praxis-Portal → DSGVO</em></li>
                    <li><strong>Audit-Log:</strong> Alle Zugriffe werden protokolliert</li>
                    <li><strong>Einwilligung:</strong> Patienten bestätigen die Datenschutzerklärung vor Absendung</li>
                </ul>
                <p class="pp-wizard-hint">
                    💡 Tipp: Konfigurieren Sie Ihre Datenschutzseite und Löschfristen unter
                    <em>Praxis-Portal → DSGVO</em>.
                </p>
            </div>

            <?php $this->renderNavButtons(3, 5); ?>
        </div>
        <?php
    }

    /* =====================================================================
     * STEP 5: PORTAL-ZUGANG
     * ================================================================== */

    private function renderStepPortal(): void
    {
        /** @var LocationRepository $locationRepo */
        $locationRepo = $this->container->get(LocationRepository::class);
        $locations    = $locationRepo->getAll();
        $defaultLoc   = null;
        foreach ($locations as $loc) {
            if (!empty($loc['is_default'])) {
                $defaultLoc = $loc;
                break;
            }
        }
        if (!$defaultLoc && !empty($locations)) {
            $defaultLoc = $locations[0];
        }

        // Prüfen ob bereits Portal-User existieren
        /** @var PortalUserRepository $userRepo */
        $userRepo  = $this->container->get(PortalUserRepository::class);
        $hasUsers  = false;
        if ($defaultLoc) {
            $users = $userRepo->getAll((int) ($defaultLoc['id'] ?? 0));
            $hasUsers = !empty($users);
        }
        ?>
        <div class="pp-wizard-step">
            <h2>🚪 Portal-Zugang</h2>
            <p class="pp-wizard-intro">
                Das Patienten-Portal wird über einen Shortcode in Ihre Website eingebunden.
                Erstellen Sie einen Zugang für Ihr PVS (Praxisverwaltungssystem), um
                eingegangene Fragebögen abzurufen.
            </p>

            <?php if (!$defaultLoc): ?>
                <div class="pp-wizard-notice pp-notice-warning">
                    ⚠️ Bitte erstellen Sie zuerst einen Standort (Schritt 3).
                </div>
            <?php elseif ($hasUsers): ?>
                <div class="pp-wizard-notice pp-notice-success">
                    ✅ Es existieren bereits Portal-Benutzer für <strong><?php echo esc_html($defaultLoc['name']); ?></strong>.
                </div>
                <p>
                    Verwalten Sie Portal-Benutzer unter
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-location-edit&location_id=' . $defaultLoc['id'] . '&tab=portal')); ?>">
                        Standort → Portal-Tab
                    </a>.
                </p>
            <?php else: ?>
                <form method="post" class="pp-wizard-form">
                    <?php wp_nonce_field('pp_wizard_action', '_pp_wizard_nonce'); ?>
                    <input type="hidden" name="pp_wizard_action" value="save_portal_user">
                    <input type="hidden" name="location_id" value="<?php echo esc_attr($defaultLoc['id'] ?? 0); ?>">

                    <div class="pp-form-row">
                        <label for="portal_username">Benutzername *</label>
                        <input type="text" id="portal_username" name="portal_username"
                               class="regular-text" required
                               value="praxis"
                               autocomplete="off">
                        <p class="pp-form-hint">Dieser Benutzer wird für den PVS-Abruf verwendet.</p>
                    </div>

                    <div class="pp-form-row">
                        <label for="portal_password">Passwort *</label>
                        <input type="text" id="portal_password" name="portal_password"
                               class="regular-text" required
                               value="<?php echo esc_attr(wp_generate_password(16, false)); ?>"
                               autocomplete="new-password">
                        <p class="pp-form-hint">
                            Sicheres Passwort. Notieren Sie es für Ihr PVS!
                        </p>
                    </div>

                    <div class="pp-wizard-actions">
                        <button type="submit" class="button button-primary">Portal-Zugang erstellen</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="pp-wizard-info-box" style="margin-top: 24px;">
                <h3>📋 Shortcode-Einbindung</h3>
                <p>Fügen Sie diesen Shortcode auf einer WordPress-Seite ein:</p>
                <code class="pp-code-block">[praxis_portal]</code>
                <p class="pp-wizard-hint">
                    Für das Widget (Online-Dienste) verwenden Sie:
                    <code>[praxis_widget]</code>
                </p>
            </div>

            <?php $this->renderNavButtons(4, 6); ?>
        </div>
        <?php
    }

    /* =====================================================================
     * STEP 6: FERTIG
     * ================================================================== */

    private function renderStepFertig(): void
    {
        // Setup als abgeschlossen markieren
        self::markComplete();

        /** @var LocationRepository $locationRepo */
        $locationRepo = $this->container->get(LocationRepository::class);
        $locations    = $locationRepo->getAll();
        $locCount     = count($locations);

        $licenseKey    = get_option('pp_license_key', '');
        $hasLicense    = !empty($licenseKey);
        $encryption    = $this->container->get(Encryption::class);
        $hasEncryption = !empty($encryption->getMethod());
        ?>
        <div class="pp-wizard-step">
            <h2>✅ Einrichtung abgeschlossen!</h2>
            <p class="pp-wizard-intro">
                Ihr Praxis-Portal ist bereit. Hier eine Zusammenfassung:
            </p>

            <div class="pp-wizard-summary">
                <table class="pp-check-table">
                    <tr class="<?php echo esc_attr($hasEncryption ? 'check-ok' : 'check-fail'); ?>">
                        <td class="check-icon"><?php echo $hasEncryption ? '✅' : '❌'; ?></td>
                        <td class="check-label">Verschlüsselung</td>
                        <td class="check-value"><?php echo esc_html($encryption->getMethod() ?: 'Nicht aktiv'); ?></td>
                    </tr>
                    <tr class="<?php echo esc_attr($hasLicense ? 'check-ok' : 'check-warn'); ?>">
                        <td class="check-icon"><?php echo $hasLicense ? '✅' : '⚠️'; ?></td>
                        <td class="check-label">Lizenz</td>
                        <td class="check-value"><?php echo esc_html($hasLicense ? 'Aktiviert' : 'Noch nicht aktiviert'); ?></td>
                    </tr>
                    <tr class="<?php echo esc_attr($locCount > 0 ? 'check-ok' : 'check-fail'); ?>">
                        <td class="check-icon"><?php echo $locCount > 0 ? '✅' : '❌'; ?></td>
                        <td class="check-label">Standorte</td>
                        <td class="check-value"><?php echo (int) $locCount; ?> konfiguriert</td>
                    </tr>
                </table>
            </div>

            <div class="pp-wizard-next-steps">
                <h3><?php echo esc_html($this->t('Nächste Schritte')); ?></h3>
                <div class="pp-wizard-links">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-standorte')); ?>" class="pp-wizard-link-card">
                        <span class="pp-link-icon">📍</span>
                        <span class="pp-link-title">Standorte verwalten</span>
                        <span class="pp-link-desc">Services, Portal-User und PVS-API konfigurieren</span>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-forms')); ?>" class="pp-wizard-link-card">
                        <span class="pp-link-icon">📝</span>
                        <span class="pp-link-title">Fragebögen</span>
                        <span class="pp-link-desc">Anamnesebögen anpassen und aktivieren</span>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-einstellungen')); ?>" class="pp-wizard-link-card">
                        <span class="pp-link-icon">⚙️</span>
                        <span class="pp-link-title">Einstellungen</span>
                        <span class="pp-link-desc">E-Mail, Export-Format und Benachrichtigungen</span>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-dsgvo')); ?>" class="pp-wizard-link-card">
                        <span class="pp-link-icon">🔒</span>
                        <span class="pp-link-title">DSGVO</span>
                        <span class="pp-link-desc">Löschfristen und Datenschutzseite einrichten</span>
                    </a>
                </div>
            </div>

            <div class="pp-wizard-actions" style="margin-top: 32px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=praxis-portal')); ?>" class="button button-primary button-hero">
                    🚀 Zum Praxis-Portal
                </a>
                <p class="pp-wizard-hint" style="margin-top: 12px;">
                    💡 Sie können diesen Assistenten jederzeit über <em>System-Status → Einrichtungsassistent</em> erneut starten.
                </p>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
     * ACTION HANDLER
     * ================================================================== */

    /**
     * POST-Aktionen verarbeiten (wird von Admin::handleEarlyActions aufgerufen,
     * BEVOR WordPress den HTML-Header ausgibt).
     */
    public function handlePostAction(): void
    {
        if (!check_admin_referer('pp_wizard_action', '_pp_wizard_nonce')) {
            wp_die($this->t('Sicherheitsprüfung fehlgeschlagen.'));
        }

        $action = sanitize_text_field($_POST['pp_wizard_action']);

        $redirectStep = match ($action) {
            'save_license'     => $this->handleSaveLicense(),
            'save_location'    => $this->handleSaveLocation(),
            'save_portal_user' => $this->handleSavePortalUser(),
            default            => $this->currentStep,
        };

        wp_safe_redirect(admin_url('admin.php?page=pp-setup&step=' . $redirectStep));
        exit;
    }

    /**
     * Lizenzschlüssel speichern.
     */
    private function handleSaveLicense(): int
    {
        $key = sanitize_text_field($_POST['license_key'] ?? '');

        if (!empty($key)) {
            update_option('pp_license_key', $key);

            // Lizenz-Validierung versuchen (falls LicenseClient verfügbar)
            try {
                if ($this->container->has(\PraxisPortal\License\LicenseClient::class)) {
                    $client = $this->container->get(\PraxisPortal\License\LicenseClient::class);
                    $client->activate($key);
                }
            } catch (\Throwable $e) {
                // Fehler ignorieren – Lizenz kann später validiert werden
            }
        }

        return 3; // Weiter zu Standort
    }

    /**
     * Standort anlegen (nur wenn Felder ausgefüllt sind).
     */
    private function handleSaveLocation(): int
    {
        $name = sanitize_text_field($_POST['loc_name'] ?? '');

        // Wenn Name leer ist, keinen Standort anlegen (Skip)
        if (empty($name)) {
            return 4; // Weiter zu Sicherheit ohne Standort
        }

        /** @var LocationRepository $locationRepo */
        $locationRepo = $this->container->get(LocationRepository::class);

        $locationRepo->create([
            'name'       => $name,
            'slug'       => sanitize_title($name),
            'uuid'       => wp_generate_uuid4(),
            'email'      => sanitize_email($_POST['loc_email'] ?? get_option('admin_email')),
            'phone'      => sanitize_text_field($_POST['loc_telefon'] ?? ''),
            'street'     => sanitize_text_field($_POST['loc_strasse'] ?? ''),
            'postal_code' => sanitize_text_field($_POST['loc_plz'] ?? ''),
            'city'       => sanitize_text_field($_POST['loc_ort'] ?? ''),
            'is_active'  => 1,
            'is_default' => 1,
        ]);

        return 4; // Weiter zu Sicherheit
    }

    /**
     * Portal-Benutzer anlegen.
     */
    private function handleSavePortalUser(): int
    {
        /** @var PortalUserRepository $userRepo */
        $userRepo = $this->container->get(PortalUserRepository::class);

        $username   = sanitize_text_field($_POST['portal_username'] ?? 'praxis');
        $password   = $_POST['portal_password'] ?? wp_generate_password(16, false);
        $locationId = (int) ($_POST['location_id'] ?? 0);

        if (!empty($username) && $locationId > 0) {
            $userRepo->create([
                'username'    => $username,
                'password'    => $password,
                'location_id' => $locationId,
                'is_active'   => 1,
            ]);
        }

        return 6; // Weiter zu Fertig
    }

    /* =====================================================================
     * UI COMPONENTS
     * ================================================================== */

    /**
     * Navigationsleiste (Schritte).
     */
    private function renderStepNav(): void
    {
        echo '<div class="pp-wizard-nav">';
        foreach ($this->getSteps() as $num => $step) {
            $classes = ['pp-wizard-nav-item'];
            if ($num === $this->currentStep) {
                $classes[] = 'current';
            } elseif ($num < $this->currentStep) {
                $classes[] = 'completed';
            }

            $url = admin_url('admin.php?page=pp-setup&step=' . $num);
            echo '<a href="' . esc_url($url) . '" class="' . implode(' ', $classes) . '">';
            echo '<span class="pp-nav-number">' . $num . '</span>';
            echo '<span class="pp-nav-label">' . esc_html($step['label']) . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }

    /**
     * Zurück/Weiter Buttons.
     */
    private function renderNavButtons(?int $prevStep, ?int $nextStep, string $nextLabel = 'Weiter →'): void
    {
        echo '<div class="pp-wizard-nav-buttons">';
        if ($prevStep !== null) {
            $url = admin_url('admin.php?page=pp-setup&step=' . $prevStep);
            echo '<a href="' . esc_url($url) . '" class="button">← Zurück</a>';
        }
        if ($nextStep !== null) {
            $url = admin_url('admin.php?page=pp-setup&step=' . $nextStep);
            echo '<a href="' . esc_url($url) . '" class="button button-primary">' . esc_html($nextLabel) . '</a>';
        }
        echo '</div>';
    }

    /**
     * Seiten-Header mit CSS.
     */
    private function renderHeader(): void
    {
        ?>
        <div class="wrap pp-wizard-wrap">
            <style>
                /* ── Wizard Layout ── */
                .pp-wizard-wrap {
                    max-width: 860px;
                    margin: 20px auto;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                }
                .pp-wizard-wrap h1 {
                    font-size: 26px;
                    font-weight: 600;
                    color: #1d2327;
                    margin-bottom: 8px;
                }
                .pp-wizard-subtitle {
                    color: #646970;
                    font-size: 14px;
                    margin: 0 0 24px 0;
                }

                /* ── Step Navigation ── */
                .pp-wizard-nav {
                    display: flex;
                    gap: 2px;
                    margin-bottom: 32px;
                    background: #f0f0f1;
                    border-radius: 8px;
                    padding: 4px;
                }
                .pp-wizard-nav-item {
                    flex: 1;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 12px 14px;
                    text-decoration: none;
                    color: #646970;
                    border-radius: 6px;
                    font-size: 13px;
                    transition: all 0.2s;
                }
                .pp-wizard-nav-item:hover {
                    background: #fff;
                    color: #1d2327;
                }
                .pp-wizard-nav-item.current {
                    background: #fff;
                    color: #2271b1;
                    font-weight: 600;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                .pp-wizard-nav-item.completed {
                    color: #00a32a;
                }
                .pp-nav-number {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 24px;
                    height: 24px;
                    border-radius: 50%;
                    font-size: 12px;
                    font-weight: 600;
                    flex-shrink: 0;
                }
                .pp-wizard-nav-item .pp-nav-number {
                    background: #dcdcde;
                    color: #646970;
                }
                .pp-wizard-nav-item.current .pp-nav-number {
                    background: #2271b1;
                    color: #fff;
                }
                .pp-wizard-nav-item.completed .pp-nav-number {
                    background: #00a32a;
                    color: #fff;
                }
                .pp-nav-label {
                    white-space: nowrap;
                }
                @media (max-width: 782px) {
                    .pp-nav-label { display: none; }
                    .pp-wizard-nav-item { justify-content: center; padding: 10px; }
                }

                /* ── Content Area ── */
                .pp-wizard-content {
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 8px;
                    padding: 32px;
                }
                .pp-wizard-step h2 {
                    font-size: 22px;
                    font-weight: 600;
                    color: #1d2327;
                    margin: 0 0 8px 0;
                }
                .pp-wizard-intro {
                    color: #646970;
                    font-size: 14px;
                    line-height: 1.6;
                    margin-bottom: 24px;
                }

                /* ── Check Table ── */
                .pp-check-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 12px 0 20px;
                }
                .pp-check-table tr {
                    border-bottom: 1px solid #f0f0f1;
                }
                .pp-check-table td {
                    padding: 10px 12px;
                    font-size: 14px;
                }
                .check-icon { width: 30px; text-align: center; }
                .check-label { font-weight: 500; color: #1d2327; }
                .check-value { color: #646970; text-align: right; }
                .check-fail .check-label { color: #d63638; }
                .check-warn .check-label { color: #dba617; }

                /* ── Notices ── */
                .pp-wizard-notice {
                    padding: 14px 18px;
                    border-radius: 6px;
                    margin: 16px 0;
                    font-size: 14px;
                    line-height: 1.5;
                }
                .pp-notice-success { background: #edfaef; border-left: 4px solid #00a32a; color: #1e4620; }
                .pp-notice-warning { background: #fef8ee; border-left: 4px solid #dba617; color: #6e4e00; }
                .pp-notice-info    { background: #f0f6fc; border-left: 4px solid #2271b1; color: #1d3557; }

                /* ── Forms ── */
                .pp-wizard-form { margin: 20px 0; }
                .pp-form-row {
                    margin-bottom: 18px;
                }
                .pp-form-row label {
                    display: block;
                    font-weight: 600;
                    font-size: 14px;
                    margin-bottom: 6px;
                    color: #1d2327;
                }
                .pp-form-row input[type="text"],
                .pp-form-row input[type="email"],
                .pp-form-row input[type="tel"],
                .pp-form-row input[type="password"] {
                    width: 100%;
                    max-width: 420px;
                    padding: 8px 12px;
                    border: 1px solid #8c8f94;
                    border-radius: 4px;
                    font-size: 14px;
                }
                .pp-input-wide {
                    max-width: 100% !important;
                }
                .pp-form-hint {
                    font-size: 12px;
                    color: #646970;
                    margin: 4px 0 0;
                }
                .pp-form-row-group {
                    display: grid;
                    grid-template-columns: 2fr 1fr 2fr;
                    gap: 16px;
                }
                .pp-form-row-short input { max-width: 100px; }
                @media (max-width: 600px) {
                    .pp-form-row-group { grid-template-columns: 1fr; }
                }

                /* ── Info Box ── */
                .pp-wizard-info-box {
                    background: #f9f9f9;
                    border: 1px solid #e2e4e7;
                    border-radius: 6px;
                    padding: 20px 24px;
                    margin: 20px 0;
                }
                .pp-wizard-info-box h3 {
                    font-size: 15px;
                    margin: 0 0 10px;
                }
                .pp-wizard-info-box ul {
                    margin: 8px 0 8px 20px;
                }
                .pp-wizard-info-box li {
                    margin-bottom: 4px;
                    font-size: 13px;
                }
                .pp-wizard-hint {
                    font-size: 13px;
                    color: #646970;
                    font-style: italic;
                }

                /* ── Code Block ── */
                .pp-code-block {
                    display: block;
                    background: #1d2327;
                    color: #50c878;
                    padding: 12px 16px;
                    border-radius: 4px;
                    font-family: Consolas, Monaco, monospace;
                    font-size: 15px;
                    margin: 8px 0;
                    user-select: all;
                }

                /* ── Navigation Buttons ── */
                .pp-wizard-nav-buttons {
                    display: flex;
                    justify-content: space-between;
                    margin-top: 32px;
                    padding-top: 20px;
                    border-top: 1px solid #f0f0f1;
                }
                .pp-wizard-actions {
                    margin: 16px 0;
                }

                /* ── Fertig: Link Cards ── */
                .pp-wizard-links {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 16px;
                    margin: 16px 0;
                }
                @media (max-width: 600px) {
                    .pp-wizard-links { grid-template-columns: 1fr; }
                }
                .pp-wizard-link-card {
                    display: flex;
                    flex-direction: column;
                    padding: 18px;
                    background: #f9f9f9;
                    border: 1px solid #e2e4e7;
                    border-radius: 8px;
                    text-decoration: none;
                    transition: all 0.2s;
                }
                .pp-wizard-link-card:hover {
                    border-color: #2271b1;
                    background: #f0f6fc;
                    transform: translateY(-2px);
                    box-shadow: 0 4px 8px rgba(0,0,0,0.06);
                }
                .pp-link-icon { font-size: 24px; margin-bottom: 8px; }
                .pp-link-title { font-weight: 600; color: #1d2327; font-size: 14px; margin-bottom: 4px; }
                .pp-link-desc { font-size: 12px; color: #646970; }
            </style>

            <h1>🏥 Praxis-Portal Einrichtung</h1>
            <p class="pp-wizard-subtitle">
                Version <?php echo esc_html(defined('PP_VERSION') ? PP_VERSION : '4.x'); ?>
                — Schritt <?php echo (int) $this->currentStep; ?> von <?php echo (int) count($this->getSteps()); ?>
            </p>
        <?php
    }

    /**
     * Seiten-Footer.
     */
    private function renderFooter(): void
    {
        ?>
        </div><!-- .pp-wizard-wrap -->
        <?php
    }
}
