<?php
/**
 * AdminSettings – Einstellungen-Seite im Backend
 *
 * Tabs: Allgemein | Widget | E-Mail | Portal | Sicherheit | Export | PVS-Archiv
 *
 * v4-Änderungen:
 *  - pp_ Prefix für alle Options
 *  - Settings-API statt manuelles update_option
 *  - Multi-Standort: Widget-Status pro Standort möglich
 *  - Audit-Logging bei Einstellungsänderungen
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Security\Encryption;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminSettings
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

    /**
     * Einstellungsseite rendern
     */
    public function renderPage(): void
    {
        $activeTab = sanitize_text_field($_GET['tab'] ?? 'general');
        $message   = '';
        $msgType   = '';

        // POST-Handling
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pp_settings_nonce'])) {
            if (wp_verify_nonce($_POST['pp_settings_nonce'], 'pp_settings')) {
                $result  = $this->saveSettings($activeTab, $_POST);
                $message = $result['message'];
                $msgType = $result['type'];
            } else {
                $message = $this->t('Sicherheitsprüfung fehlgeschlagen.');
                $msgType = 'error';
            }
        }

        $tabs = [
            'general'  => '⚙️ Allgemein',
            'email'    => '📧 E-Mail',
            'portal'   => '🏥 Portal',
            'security' => '🔒 Sicherheit',
            'export'   => '📤 Export',
            'pvs'      => '🖥️ PVS-Archiv',
        ];

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-admin-generic" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                Einstellungen
            </h1>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($msgType); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <?php $this->renderStatusBox(); ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-einstellungen&tab=' . $tabKey)); ?>"
                       class="nav-tab <?php echo esc_attr($activeTab === $tabKey ? 'nav-tab-active' : ''); ?>">
                        <?php echo esc_html($tabLabel); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" style="max-width:800px;">
                <?php wp_nonce_field('pp_settings', 'pp_settings_nonce'); ?>
                <input type="hidden" name="active_tab" value="<?php echo esc_attr($activeTab); ?>">

                <?php
                switch ($activeTab) {
                    case 'email':    $this->renderEmailTab(); break;
                    case 'portal':   $this->renderPortalTab(); break;
                    case 'security': $this->renderSecurityTab(); break;
                    case 'export':   $this->renderExportTab(); break;
                    case 'pvs':      $this->renderPvsTab(); break;
                    default:         $this->renderGeneralTab(); break;
                }
                ?>

                <?php submit_button($this->t('Einstellungen speichern')); ?>
            </form>
        </div>
        <?php
    }

    /* =====================================================================
     * STATUS-BOX
     * ================================================================== */

    private function renderStatusBox(): void
    {
        $encryption  = $this->container->get(Encryption::class);
        $locationRepo = $this->container->get(LocationRepository::class);
        $defaultLoc   = $locationRepo->getDefault();
        $portalEnabled = get_option('pp_portal_enabled', '0');

        ?>
        <div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:20px;margin:20px 0;max-width:800px;">
            <h3 style="margin-top:0;border-bottom:1px solid #ddd;padding-bottom:10px;">📊 Status</h3>
            <table style="width:100%;">
                <tr>
                    <td style="padding:6px 0;width:180px;"><strong><?php echo esc_html($this->t('Verschlüsselung')); ?></strong></td>
                    <td>
                        <?php if ($encryption->isKeyValid()): ?>
                            <span style="color:green;">✓ AES-256 aktiv</span>
                        <?php else: ?>
                            <span style="color:red;">✗ Schlüssel fehlt</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 0;"><strong><?php echo esc_html($this->t('Standard-Standort')); ?></strong></td>
                    <td>
                        <?php if ($defaultLoc): ?>
                            <?php echo esc_html($defaultLoc['name']); ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-location-edit&location_id=' . $defaultLoc['id'])); ?>" style="margin-left:8px;">→</a>
                        <?php else: ?>
                            <span style="color:orange;">⚠ Nicht gesetzt</span>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-standorte')); ?>">einrichten</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 0;"><strong>Portal-URL</strong></td>
                    <td>
                        <?php if ($portalEnabled === '1'): ?>
                            <code style="font-size:12px;"><?php echo esc_html(home_url('/praxis-portal/')); ?></code>
                        <?php else: ?>
                            <span style="color:#999;">— deaktiviert —</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 0;"><strong>Shortcodes</strong></td>
                    <td>
                        <code style="font-size:12px;">[pp_fragebogen]</code>
                        <code style="font-size:12px;">[pp_praxis_portal]</code>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /* =====================================================================
     * TAB-RENDERING
     * ================================================================== */

    private function renderGeneralTab(): void
    {
        $retentionDays = (int) get_option('pp_retention_days', 90);
        $insuranceMode = get_option('pp_insurance_mode', 'de');
        ?>
        <table class="form-table">
            <tr>
                <th><?php echo esc_html($this->t('Versicherungs-Modus')); ?></th>
                <td>
                    <fieldset>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="radio" name="insurance_mode" value="de" <?php checked($insuranceMode, 'de'); ?>>
                            <strong>🇩🇪 Deutschland</strong> — Detaillierte Versicherungsabfrage (Beihilfe, Post B, etc.) mit ICD-Zuordnungen
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="insurance_mode" value="international" <?php checked($insuranceMode, 'international'); ?>>
                            <strong>🌍 International</strong> — Vereinfachte Abfrage (nur Privat/Gesetzlich) ohne deutsche Spezialoptionen
                        </label>
                    </fieldset>
                    <p class="description">
                        <?php echo esc_html($this->t('Legt fest, welche Versicherungsoptionen in Fragebögen angezeigt werden. Deutschland-Modus zeigt alle deutschen Versicherungsarten, International-Modus vereinfacht auf Privat/Gesetzlich (z.B. für Niederlande).')); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="retention_days"><?php echo esc_html($this->t('Aufbewahrungsfrist')); ?></label></th>
                <td>
                    <input type="number" id="retention_days" name="retention_days"
                           value="<?php echo (int) $retentionDays; ?>" min="7" max="365" style="width:80px;"> Tage
                    <p class="description">Eingänge werden nach dieser Frist automatisch gelöscht (7–365 Tage, Standard: 90)</p>
                </td>
            </tr>
            <tr>
                <th><label for="anamnesebogen_url">Anamnesebogen-URL</label></th>
                <td>
                    <input type="url" id="anamnesebogen_url" name="anamnesebogen_url"
                           value="<?php echo esc_attr(get_option('pp_anamnesebogen_url', '')); ?>" class="regular-text">
                    <p class="description">Direkt-Link zum Anamnesebogen (z.B. für QR-Codes)</p>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html($this->t('Deinstallation')); ?></th>
                <td>
                    <?php $keepData = get_option('pp_keep_data_on_uninstall', '0'); ?>
                    <label>
                        <input type="checkbox" name="keep_data_on_uninstall" value="1" <?php checked($keepData, '1'); ?>>
                        <strong>Daten bei Deinstallation behalten</strong>
                    </label>
                    <p class="description">
                        Wenn aktiviert, bleiben bei der Plugin-Löschung alle Daten erhalten:<br>
                        ✓ Datenbank-Tabellen (Standorte, Eingänge, Services, Medikamente)<br>
                        ✓ Hochgeladene &amp; verschlüsselte Dateien<br>
                        ✓ Verschlüsselungsschlüssel<br>
                        ✓ Plugin-Einstellungen
                    </p>
                    <p class="description" style="margin-top:10px; padding:10px; background:#fff3cd; border-radius:4px;">
                        💡 <strong><?php echo esc_html($this->t('Empfohlen für Entwickler/Tester')); ?></strong> — <?php echo esc_html($this->t('verhindert Datenverlust bei Plugin-Updates oder Tests.')); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function renderEmailTab(): void
    {
        $emailEnabled = get_option('pp_email_enabled', '1');
        $emailAddress = get_option('pp_notification_email', get_option('admin_email'));
        $emailSubject = get_option('pp_email_subject_template', $this->t('Neue Einreichung') . ': {service}');
        ?>
        <table class="form-table">
            <tr>
                <th>E-Mail-Benachrichtigung</th>
                <td>
                    <label>
                        <input type="checkbox" name="email_enabled" value="1" <?php checked($emailEnabled, '1'); ?>>
                        E-Mail bei neuen Eingängen senden
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="notification_email"><?php echo esc_html($this->t('Empfänger-Adresse')); ?></label></th>
                <td>
                    <input type="email" id="notification_email" name="notification_email"
                           value="<?php echo esc_attr($emailAddress); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="email_subject"><?php echo esc_html($this->t('Betreff-Vorlage')); ?></label></th>
                <td>
                    <input type="text" id="email_subject" name="email_subject"
                           value="<?php echo esc_attr($emailSubject); ?>" class="regular-text">
                    <p class="description">Platzhalter: {service}, {name}, {location}</p>
                </td>
            </tr>
            <tr>
                <th>Test-E-Mail</th>
                <td>
                    <button type="button" class="button pp-send-test-email" id="pp-test-email-btn">📧 Test-E-Mail senden</button>
                    <span id="pp-test-email-status" style="margin-left:10px;"></span>
                </td>
            </tr>
        </table>
        <?php
    }

    private function renderPortalTab(): void
    {
        $portalEnabled = get_option('pp_portal_enabled', '0');
        $sessionTimeout = (int) get_option('pp_session_timeout', 60);
        ?>
        <table class="form-table">
            <tr>
                <th>Praxis-Portal</th>
                <td>
                    <label>
                        <input type="checkbox" name="portal_enabled" value="1" <?php checked($portalEnabled, '1'); ?>>
                        Portal aktivieren
                    </label>
                    <p class="description">Das Portal ermöglicht den Zugriff auf Eingänge über <code><?php echo esc_html(home_url('/praxis-portal/')); ?></code></p>
                </td>
            </tr>
            <tr>
                <th><label for="session_timeout">Session-Timeout</label></th>
                <td>
                    <select id="session_timeout" name="session_timeout">
                        <?php foreach ([15, 30, 60, 120, 240] as $min): ?>
                            <option value="<?php echo (int) $min; ?>" <?php selected($sessionTimeout, $min); ?>><?php echo (int) $min; ?> Minuten</option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <p class="description">Portal-Benutzer werden pro Standort verwaltet: → <a href="<?php echo esc_url(admin_url('admin.php?page=pp-standorte')); ?>">Standorte</a></p>
        <?php
    }

    private function renderSecurityTab(): void
    {
        $cookieSamesite = get_option('pp_cookie_samesite', 'Strict');
        $trustProxy     = get_option('pp_trust_proxy', '0');
        $rateLimit      = (int) get_option('pp_rate_limit_submissions', 10);
        ?>
        <table class="form-table">
            <tr>
                <th>Cookie SameSite</th>
                <td>
                    <select name="cookie_samesite">
                        <option value="Strict" <?php selected($cookieSamesite, 'Strict'); ?>>Strict (<?php echo esc_html($this->t('empfohlen')); ?>)</option>
                        <option value="Lax" <?php selected($cookieSamesite, 'Lax'); ?>>Lax</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html($this->t('Proxy-Vertrauen')); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="trust_proxy" value="1" <?php checked($trustProxy, '1'); ?>>
                        X-Forwarded-For Header vertrauen
                    </label>
                    <p class="description">Nur aktivieren wenn ein Reverse-Proxy (z.B. Cloudflare, nginx) vorgeschaltet ist.</p>
                </td>
            </tr>
            <tr>
                <th><label for="rate_limit">Rate-Limit</label></th>
                <td>
                    <input type="number" id="rate_limit" name="rate_limit" value="<?php echo (int) $rateLimit; ?>" min="1" max="100" style="width:80px;">
                    <span><?php echo esc_html($this->t('Einreichungen pro Stunde pro IP')); ?></span>
                </td>
            </tr>
        </table>
        <?php
    }

    private function renderExportTab(): void
    {
        $wFmt   = get_option('pp_export_widget_format', 'pdf');
        $wDel   = get_option('pp_export_widget_delete_after', '0');
        $akFmt  = get_option('pp_export_anamnese_kasse_format', 'pdf');
        $akPdf  = get_option('pp_export_anamnese_kasse_pdf_type', 'full');
        $akDel  = get_option('pp_export_anamnese_kasse_delete_after', '0');
        $apFmt  = get_option('pp_export_anamnese_privat_format', 'pdf');
        $apPdf  = get_option('pp_export_anamnese_privat_pdf_type', 'full');
        $apDel  = get_option('pp_export_anamnese_privat_delete_after', '0');

        $formats  = [
            'pdf'       => 'PDF',
            'gdt'       => 'GDT / BDT',
            'gdt_image' => 'GDT + Archiv',
            'hl7'       => 'HL7 v2.5',
            'fhir'      => 'FHIR R4',
        ];
        $pdfTypes = [
            'full'       => $this->t('Vollständig (A4)'),
            'stammdaten' => $this->t('Stammdaten'),
        ];
        ?>
        <h3><?php echo esc_html($this->t('Widget-Eingänge')); ?></h3>
        <table class="form-table">
            <tr>
                <th>Format</th>
                <td>
                    <select name="pp_export_widget_format">
                        <?php foreach ($formats as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($wFmt, $k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html($this->t('Nach Export löschen')); ?></th>
                <td><label><input type="checkbox" name="pp_export_widget_delete_after" value="1" <?php checked($wDel, '1'); ?>> Einreichung nach Export automatisch löschen</label></td>
            </tr>
        </table>

        <h3><?php echo esc_html($this->t('Anamnesebogen – Kasse')); ?></h3>
        <table class="form-table">
            <tr>
                <th>Format</th>
                <td>
                    <select name="pp_export_anamnese_kasse_format">
                        <?php foreach ($formats as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($akFmt, $k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th>PDF-Typ</th>
                <td>
                    <select name="pp_export_anamnese_kasse_pdf_type">
                        <?php foreach ($pdfTypes as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($akPdf, $k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html($this->t('Nach Export löschen')); ?></th>
                <td><label><input type="checkbox" name="pp_export_anamnese_kasse_delete_after" value="1" <?php checked($akDel, '1'); ?>> Automatisch löschen</label></td>
            </tr>
        </table>

        <h3><?php echo esc_html($this->t('Anamnesebogen – Privat')); ?></h3>
        <table class="form-table">
            <tr>
                <th>Format</th>
                <td>
                    <select name="pp_export_anamnese_privat_format">
                        <?php foreach ($formats as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($apFmt, $k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th>PDF-Typ</th>
                <td>
                    <select name="pp_export_anamnese_privat_pdf_type">
                        <?php foreach ($pdfTypes as $k => $v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($apPdf, $k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html($this->t('Nach Export löschen')); ?></th>
                <td><label><input type="checkbox" name="pp_export_anamnese_privat_delete_after" value="1" <?php checked($apDel, '1'); ?>> Automatisch löschen</label></td>
            </tr>
        </table>
        <?php
    }

    private function renderPvsTab(): void
    {
        $gdtPath    = get_option('pp_pvs_archive_gdt_path', '');
        $imagePath  = get_option('pp_pvs_archive_image_path', '');
        $senderId   = get_option('pp_pvs_archive_sender_id', '');
        $receiverId = get_option('pp_pvs_archive_receiver_id', '');
        ?>
        <p class="description">Einstellungen für den PVS-Archivierungspfad (GDT-Dateitransfer).</p>
        <table class="form-table">
            <tr>
                <th><label for="gdt_path">GDT-Pfad</label></th>
                <td>
                    <input type="text" id="gdt_path" name="pp_pvs_archive_gdt_path" value="<?php echo esc_attr($gdtPath); ?>" class="regular-text" style="font-family:monospace;">
                    <p class="description">Verzeichnis für GDT-Dateien (z.B. <code>C:\GDT\Import</code>)</p>
                </td>
            </tr>
            <tr>
                <th><label for="image_path"><?php echo esc_html($this->t('Bild-Pfad')); ?></label></th>
                <td>
                    <input type="text" id="image_path" name="pp_pvs_archive_image_path" value="<?php echo esc_attr($imagePath); ?>" class="regular-text" style="font-family:monospace;">
                    <p class="description">Verzeichnis für Bild-Dateien (z.B. <code>C:\GDT\Images</code>)</p>
                </td>
            </tr>
            <tr>
                <th><label for="sender_id">Sender-ID (GDT)</label></th>
                <td><input type="text" id="sender_id" name="pp_pvs_archive_sender_id" value="<?php echo esc_attr($senderId); ?>" style="width:120px;font-family:monospace;"></td>
            </tr>
            <tr>
                <th><label for="receiver_id">Empfänger-ID (GDT)</label></th>
                <td><input type="text" id="receiver_id" name="pp_pvs_archive_receiver_id" value="<?php echo esc_attr($receiverId); ?>" style="width:120px;font-family:monospace;"></td>
            </tr>
        </table>
        <?php
    }

    /* =====================================================================
     * SETTINGS-SPEICHERN
     * ================================================================== */

    /**
     * Einstellungen pro Tab speichern
     */
    private function saveSettings(string $tab, array $post): array
    {
        $auditRepo = $this->container->get(AuditRepository::class);

        switch ($tab) {
            case 'general':
                $insuranceMode = sanitize_text_field($post['insurance_mode'] ?? 'de');
                if (in_array($insuranceMode, ['de', 'international'], true)) {
                    update_option('pp_insurance_mode', $insuranceMode);
                }
                update_option('pp_retention_days', max(7, min(365, (int) ($post['retention_days'] ?? 90))));
                update_option('pp_anamnesebogen_url', esc_url_raw($post['anamnesebogen_url'] ?? ''));
                update_option('pp_keep_data_on_uninstall', isset($post['keep_data_on_uninstall']) ? '1' : '0');
                break;

            case 'email':
                update_option('pp_email_enabled', isset($post['email_enabled']) ? '1' : '0');
                update_option('pp_notification_email', sanitize_email($post['notification_email'] ?? ''));
                update_option('pp_email_subject_template', sanitize_text_field($post['email_subject'] ?? ''));
                break;

            case 'portal':
                update_option('pp_portal_enabled', isset($post['portal_enabled']) ? '1' : '0');
                $timeout = (int) ($post['session_timeout'] ?? 60);
                if (in_array($timeout, [15, 30, 60, 120, 240], true)) {
                    update_option('pp_session_timeout', $timeout);
                }
                break;

            case 'security':
                $samesite = sanitize_text_field($post['cookie_samesite'] ?? 'Strict');
                if (in_array($samesite, ['Strict', 'Lax'], true)) {
                    update_option('pp_cookie_samesite', $samesite);
                }
                update_option('pp_trust_proxy', isset($post['trust_proxy']) ? '1' : '0');
                update_option('pp_rate_limit_submissions', max(1, min(100, (int) ($post['rate_limit'] ?? 10))));
                break;

            case 'export':
                $validFormats = ['pdf', 'gdt', 'gdt_image', 'hl7', 'fhir'];
                $validPdf     = ['full', 'stammdaten'];

                $wFmt = sanitize_text_field($post['pp_export_widget_format'] ?? 'pdf');
                if (in_array($wFmt, $validFormats, true)) update_option('pp_export_widget_format', $wFmt);
                update_option('pp_export_widget_delete_after', isset($post['pp_export_widget_delete_after']) ? '1' : '0');

                $akFmt = sanitize_text_field($post['pp_export_anamnese_kasse_format'] ?? 'pdf');
                if (in_array($akFmt, $validFormats, true)) update_option('pp_export_anamnese_kasse_format', $akFmt);
                $akPdf = sanitize_text_field($post['pp_export_anamnese_kasse_pdf_type'] ?? 'full');
                if (in_array($akPdf, $validPdf, true)) update_option('pp_export_anamnese_kasse_pdf_type', $akPdf);
                update_option('pp_export_anamnese_kasse_delete_after', isset($post['pp_export_anamnese_kasse_delete_after']) ? '1' : '0');

                $apFmt = sanitize_text_field($post['pp_export_anamnese_privat_format'] ?? 'pdf');
                if (in_array($apFmt, $validFormats, true)) update_option('pp_export_anamnese_privat_format', $apFmt);
                $apPdf = sanitize_text_field($post['pp_export_anamnese_privat_pdf_type'] ?? 'full');
                if (in_array($apPdf, $validPdf, true)) update_option('pp_export_anamnese_privat_pdf_type', $apPdf);
                update_option('pp_export_anamnese_privat_delete_after', isset($post['pp_export_anamnese_privat_delete_after']) ? '1' : '0');
                break;

            case 'pvs':
                update_option('pp_gdt_path', sanitize_text_field($post['gdt_path'] ?? ''));
                update_option('pp_image_path', sanitize_text_field($post['image_path'] ?? ''));
                update_option('pp_sender_id', sanitize_text_field($post['sender_id'] ?? ''));
                update_option('pp_receiver_id', sanitize_text_field($post['receiver_id'] ?? ''));
                break;
        }

        $auditRepo->logSettings('settings_updated', ['tab' => $tab]);

        return ['message' => $this->t('Einstellungen gespeichert.'), 'type' => 'success'];
    }
}
