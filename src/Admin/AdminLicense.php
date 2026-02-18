<?php
/**
 * AdminLicense – Lizenzverwaltung im Backend
 *
 * Verantwortlich für:
 *  - Lizenzschlüssel-Eingabe + Aktivierung pro Standort
 *  - Lizenz-Status-Anzeige (Plan, Features, Ablauf)
 *  - Admin-Bar-Badge (Lizenz-Status)
 *  - Kommunikation mit dem Lizenzserver
 *
 * v4-Änderungen:
 *  - Lizenz pro Standort (PLACE-ID basiert)
 *  - License-Key + PLACE-ID Validierung
 *  - FeatureGate-Integration
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\License\LicenseManager;
use PraxisPortal\License\FeatureGate;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminLicense
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
        $locationRepo   = $this->container->get(LocationRepository::class);
        $licenseManager = $this->container->get(LicenseManager::class);
        $featureGate    = $this->container->get(FeatureGate::class);

        $locations = $locationRepo->getAll();
        $message   = '';
        $msgType   = '';

        // POST-Handling: Lizenz-Key speichern
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pp_license_nonce'])) {
            if (wp_verify_nonce($_POST['pp_license_nonce'], 'pp_save_license')) {
                $result  = $this->handleSaveLicense($_POST);
                $message = $result['message'];
                $msgType = $result['type'];
                // Locations neu laden
                $locations = $locationRepo->getAll();
            }
        }

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-lock" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                <?php echo esc_html($this->t('Lizenz-Verwaltung')); ?>
            </h1>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($msgType); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <div style="max-width:900px;">
                <?php $this->renderOverview($featureGate); ?>
                <?php $this->renderLocationLicenses($locations, $licenseManager); ?>
                <?php $this->renderNewLicenseForm($locations); ?>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
     * AJAX-HANDLER
     * ================================================================== */

    /**
     * Lizenz-Key speichern + aktivieren (AJAX)
     */
    public function ajaxSaveLicenseKey(): void
    {
        $result = $this->handleSaveLicense($_POST);

        if ($result['type'] === 'success') {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * Lizenz aktivieren / Server-Ping (AJAX)
     */
    public function ajaxActivateLicense(): void
    {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        if ($locationId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültiger Standort.')], 400);
        }

        $licenseManager = $this->container->get(LicenseManager::class);
        $result = $licenseManager->activateForLocation($locationId);

        if ($result['success'] ?? false) {
            wp_send_json_success([
                'message' => $this->t('Lizenz aktiviert.'),
                'plan'    => $result['plan'] ?? 'unknown',
            ]);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? $this->t('Aktivierung fehlgeschlagen.')]);
        }
    }

    /**
     * Lizenz-Key speichern (Early-Action, vor HTML-Output).
     */
    public function handleSaveLicenseKey(): void
    {
        $result = $this->handleSaveLicense($_POST);

        $type = ($result['type'] === 'success') ? 'updated' : 'error';
        add_settings_error('pp_license', 'pp_license_msg', $result['message'], $type);
        set_transient('settings_errors', get_settings_errors(), 30);

        wp_safe_redirect(admin_url('admin.php?page=pp-license&settings-updated=true'));
        exit;
    }

    /* =====================================================================
     * ADMIN-BAR
     * ================================================================== */

    /**
     * Lizenz-Status in Admin-Bar anzeigen
     */
    public function renderAdminBarItem(\WP_Admin_Bar $adminBar): void
    {
        $featureGate = $this->container->get(FeatureGate::class);
        $plan        = $featureGate->getCurrentPlan();

        $planLabels = [
            'free'      => ['🆓 Free', '#999'],
            'basic'     => ['📋 Basic', '#0073aa'],
            'premium'   => ['⭐ Premium', '#d63638'],
            'premium+'  => ['🏆 Premium+', '#00a32a'],
            'unlimited' => ['💎 Unlimited', '#8c66dc'],
        ];

        $info  = $planLabels[$plan] ?? ['❓ Unbekannt', '#999'];
        $label = $info[0];
        $color = $info[1];

        $adminBar->add_node([
            'id'    => 'pp-license',
            'title' => '<span style="color:' . $color . ';">Praxis-Portal: ' . $label . '</span>',
            'href'  => admin_url('admin.php?page=pp-license'),
            'meta'  => ['title' => $this->t('Praxis-Portal Lizenz-Status')],
        ]);
    }

    /* =====================================================================
     * PRIVATE: RENDERING
     * ================================================================== */

    /**
     * Übersicht: Aktueller Plan + Features
     */
    private function renderOverview(FeatureGate $featureGate): void
    {
        $plan     = $featureGate->getCurrentPlan();
        $features = $featureGate->getAvailableFeatures();

        $planLabels = [
            'free'      => '🆓 Free',
            'basic'     => '📋 Basic',
            'premium'   => '⭐ Premium',
            'premium+'  => '🏆 Premium+',
            'unlimited' => '💎 Unlimited',
        ];

        ?>
        <div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:20px;margin:20px 0;">
            <h2 style="margin-top:0;"><?php echo esc_html($this->t('Aktueller Plan')); ?>: <?php echo esc_html($planLabels[$plan] ?? $plan); ?></h2>

            <h4><?php echo esc_html($this->t('Verfügbare Features')); ?>:</h4>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
                <?php
                $allFeatures = [
                    'widget'            => 'Widget',
                    'portal'            => 'Praxis-Portal',
                    'email_notify'      => $this->t('E-Mail-Benachrichtigung'),
                    'gdt_export'        => 'GDT/BDT-Export',
                    'pdf_export'        => 'PDF-Export',
                    'multi_location'    => 'Multi-Standort',
                    'custom_forms'      => $this->t('Eigene Formulare'),
                    'api_access'        => $this->t('API-Zugang'),
                    'priority_support'  => 'Priority-Support',
                ];
                foreach ($allFeatures as $featureKey => $featureLabel):
                    $has = in_array($featureKey, $features, true);
                ?>
                    <div style="padding:6px;<?php echo esc_attr($has ? 'color:#155724;' : 'color:#999;'); ?>">
                        <?php echo $has ? '✅' : '❌'; ?> <?php echo esc_html($featureLabel); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Lizenz-Status pro Standort
     */
    private function renderLocationLicenses(array $locations, LicenseManager $licenseManager): void
    {
        if (empty($locations)) {
            return;
        }

        ?>
        <h2><?php echo esc_html($this->t('Standort-Lizenzen')); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html($this->t('Standort')); ?></th>
                    <th>PLACE-ID</th>
                    <th><?php echo esc_html($this->t('Lizenzschlüssel')); ?></th>
                    <th>Status</th>
                    <th><?php echo esc_html($this->t('Aktionen')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($locations as $loc):
                $licenseKey = $loc['license_key'] ?? '';
                $placeId    = $loc['uuid'] ?? '';
                $status     = $licenseManager->getStatusForLocation((int) $loc['id']);
            ?>
                <tr>
                    <td><strong><?php echo esc_html($loc['name']); ?></strong></td>
                    <td>
                        <?php if ($placeId): ?>
                            <code style="font-size:11px;"><?php echo esc_html($placeId); ?></code>
                        <?php else: ?>
                            <span style="color:orange;">⚠ <?php echo esc_html($this->t('Nicht gesetzt')); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($licenseKey): ?>
                            <code style="font-size:11px;"><?php echo esc_html(substr($licenseKey, 0, 8) . '…' . substr($licenseKey, -4)); ?></code>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $statusLabel = $status['label'] ?? $this->t('Unbekannt');
                        $statusColor = $status['color'] ?? '#999';
                        echo '<span style="color:' . esc_attr($statusColor) . ';font-weight:600;">' . esc_html($statusLabel) . '</span>';
                        ?>
                    </td>
                    <td>
                        <button type="button" class="button button-small pp-refresh-license"
                                data-location-id="<?php echo esc_attr($loc['id']); ?>">
                            🔄 <?php echo esc_html($this->t('Prüfen')); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Formular: Neuen Lizenzschlüssel zuweisen
     */
    private function renderNewLicenseForm(array $locations): void
    {
        ?>
        <h2 style="margin-top:30px;"><?php echo esc_html($this->t('Lizenzschlüssel eingeben')); ?></h2>
        <form method="post">
            <?php wp_nonce_field('pp_save_license', 'pp_license_nonce'); ?>
            <table class="form-table" style="max-width:700px;">
                <tr>
                    <th><label for="license_location_id"><?php echo esc_html($this->t('Standort')); ?></label></th>
                    <td>
                        <select id="license_location_id" name="location_id" required>
                            <option value="">— <?php echo esc_html($this->t('Standort wählen')); ?> —</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo esc_attr($loc['id']); ?>"><?php echo esc_html($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="license_key"><?php echo esc_html($this->t('Lizenzschlüssel')); ?></label></th>
                    <td>
                        <input type="text" id="license_key" name="license_key"
                               class="regular-text" style="font-family:monospace;" required
                               placeholder="XXXX-XXXX-XXXX-XXXX">
                    </td>
                </tr>
            </table>
            <?php submit_button($this->t('Lizenz speichern & aktivieren'), 'primary', 'pp_save_license_submit'); ?>
        </form>
        <?php
    }

    /* =====================================================================
     * PRIVATE: LOGIK
     * ================================================================== */

    /**
     * Lizenz-Key speichern und aktivieren
     */
    private function handleSaveLicense(array $post): array
    {
        $locationId = (int) ($post['location_id'] ?? 0);
        $licenseKey = sanitize_text_field($post['license_key'] ?? '');

        if ($locationId < 1) {
            return ['message' => $this->t('Bitte Standort wählen.'), 'type' => 'error'];
        }
        if (empty($licenseKey)) {
            return ['message' => $this->t('Lizenzschlüssel erforderlich.'), 'type' => 'error'];
        }

        $locationRepo   = $this->container->get(LocationRepository::class);
        $licenseManager = $this->container->get(LicenseManager::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        // Key speichern
        $locationRepo->update($locationId, ['license_key' => $licenseKey]);

        // Beim Lizenzserver aktivieren
        $result = $licenseManager->activateForLocation($locationId);

        $auditRepo->logSettings('license_saved', [
            'location_id' => $locationId,
            'success'     => $result['success'] ?? false,
        ]);

        if ($result['success'] ?? false) {
            return ['message' => $this->t('Lizenz gespeichert und aktiviert') . ' (' . ($result['plan'] ?? 'unknown') . ').', 'type' => 'success'];
        }

        return ['message' => $this->t('Lizenz gespeichert, aber Aktivierung fehlgeschlagen') . ': ' . ($result['error'] ?? $this->t('Unbekannter Fehler')), 'type' => 'warning'];
    }
}
