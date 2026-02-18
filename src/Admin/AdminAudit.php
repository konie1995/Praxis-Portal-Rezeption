<?php
/**
 * AdminAudit – Audit-Log Ansicht im Backend
 *
 * Zeigt alle protokollierten Aktionen (Logins, Datenänderungen,
 * Exporte, DSGVO-Aktionen, etc.) mit Filtern.
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminAudit
{
    private Container $container;

    /**
     * Lesbare Aktions-Labels (übersetzt)
     */
    private function getActionLabels(): array
    {
        return [
            'portal_login'           => '🔓 ' . $this->t('Portal-Login'),
            'portal_login_failed'    => '🔒 ' . $this->t('Login fehlgeschlagen'),
            'portal_logout'          => '🚪 ' . $this->t('Portal-Logout'),
            'portal_user_created'    => '👤 ' . $this->t('Portal-User angelegt'),
            'portal_user_updated'    => '👤 ' . $this->t('Portal-User bearbeitet'),
            'portal_user_deleted'    => '👤 ' . $this->t('Portal-User gelöscht'),
            'admin_view'             => '👁️ ' . $this->t('Eingang angesehen'),
            'status_changed'         => '📌 ' . $this->t('Status geändert'),
            'submission_deleted'     => '🗑️ ' . $this->t('Eingang gelöscht'),
            'file_downloaded'        => '📎 ' . $this->t('Datei heruntergeladen'),
            'csv'                    => '📊 CSV-Export',
            'bdt'                    => '🖥️ BDT-Export',
            'pdf_print'              => '🖨️ PDF/Druck',
            'dsgvo_search'           => '🔍 ' . $this->t('DSGVO-Suche'),
            'dsgvo_export'           => '📦 ' . $this->t('DSGVO-Export'),
            'dsgvo_permanent_delete' => '⚠️ ' . $this->t('Endgültig gelöscht'),
            'settings_updated'       => '⚙️ ' . $this->t('Einstellungen geändert'),
            'location_created'       => '📍 ' . $this->t('Standort angelegt'),
            'location_updated'       => '📍 ' . $this->t('Standort bearbeitet'),
            'location_deleted'       => '📍 ' . $this->t('Standort gelöscht'),
            'api_key_generated'      => '🔑 ' . $this->t('API-Key generiert'),
            'encryption_reset'       => '🔐 ' . $this->t('Verschlüsselung zurückgesetzt'),
        ];
    }

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
        $auditRepo    = $this->container->get(AuditRepository::class);
        $locationRepo = $this->container->get(LocationRepository::class);

        $locations    = $locationRepo->getAll();
        $perPage      = 100;
        $currentPage  = max(1, (int) ($_GET['paged'] ?? 1));
        $offset       = ($currentPage - 1) * $perPage;

        // Filter
        $filterAction   = sanitize_text_field($_GET['action_type'] ?? '');
        $filterLocation = (int) ($_GET['location_id'] ?? 0);
        $filterFrom     = sanitize_text_field($_GET['date_from'] ?? '');
        $filterTo       = sanitize_text_field($_GET['date_to'] ?? '');

        $filters = array_filter([
            'action'      => $filterAction,
            'location_id' => $filterLocation ?: null,
            'date_from'   => $filterFrom,
            'date_to'     => $filterTo,
        ]);

        $entries    = $auditRepo->list($filters, $perPage, $offset);
        $totalCount = $auditRepo->countEntries($filters);
        $totalPages = (int) ceil($totalCount / $perPage);

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-list-view" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                <?php echo esc_html($this->t('Audit-Log')); ?>
            </h1>

            <?php $this->renderFilterBar($locations, $filterAction, $filterLocation, $filterFrom, $filterTo, $totalCount); ?>

            <?php if (empty($entries)): ?>
                <div style="text-align:center;padding:60px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
                    <span style="font-size:48px;">📜</span>
                    <h2><?php echo esc_html($this->t('Keine Log-Einträge')); ?></h2>
                    <p><?php echo esc_html($this->t('Es wurden noch keine Aktionen protokolliert.')); ?></p>
                </div>
            <?php else: ?>
                <?php $this->renderTable($entries, count($locations) > 1); ?>
                <?php $this->renderPagination($currentPage, $totalPages); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * PRIVATE: RENDERING
     * ================================================================== */

    private function renderFilterBar(
        array  $locations,
        string $filterAction,
        int    $filterLocation,
        string $filterFrom,
        string $filterTo,
        int    $totalCount
    ): void {
        ?>
        <div style="margin:20px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="page" value="pp-audit">

                <select name="action_type" onchange="this.form.submit()">
                    <option value=""><?php echo esc_html($this->t('Alle Aktionen')); ?></option>
                    <?php foreach ($this->getActionLabels() as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($filterAction, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if (count($locations) > 1): ?>
                <select name="location_id" onchange="this.form.submit()">
                    <option value=""><?php echo esc_html($this->t('Alle Standorte')); ?></option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo esc_attr($loc['id']); ?>" <?php selected($filterLocation, (int) $loc['id']); ?>><?php echo esc_html($loc['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <label><?php echo esc_html($this->t('Von')); ?>: <input type="date" name="date_from" value="<?php echo esc_attr($filterFrom); ?>" onchange="this.form.submit()"></label>
                <label><?php echo esc_html($this->t('Bis')); ?>: <input type="date" name="date_to" value="<?php echo esc_attr($filterTo); ?>" onchange="this.form.submit()"></label>
            </form>

            <span style="margin-left:auto;color:#666;"><?php echo number_format($totalCount); ?> <?php echo esc_html($this->t('Einträge')); ?></span>
        </div>
        <?php
    }

    private function renderTable(array $entries, bool $showLocation): void
    {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:160px;"><?php echo esc_html($this->t('Zeitpunkt')); ?></th>
                    <th style="width:200px;"><?php echo esc_html($this->t('Aktion')); ?></th>
                    <th><?php echo esc_html($this->t('Benutzer')); ?></th>
                    <th>IP</th>
                    <?php if ($showLocation): ?><th><?php echo esc_html($this->t('Standort')); ?></th><?php endif; ?>
                    <th><?php echo esc_html($this->t('Details')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?php echo esc_html(date_i18n('d.m.Y H:i:s', strtotime($entry['created_at'] ?? 'now'))); ?></td>
                    <td>
                        <?php
                        $actionKey = $entry['action'] ?? '';
                        $labels = $this->getActionLabels();
                        $label = $labels[$actionKey] ?? $actionKey;
                        echo esc_html($label);
                        ?>
                    </td>
                    <td><?php echo esc_html($entry['user_display'] ?? $entry['user_id'] ?? '—'); ?></td>
                    <td><code style="font-size:11px;"><?php echo esc_html($entry['ip_address'] ?? '—'); ?></code></td>
                    <?php if ($showLocation): ?>
                    <td><?php echo esc_html($entry['location_name'] ?? '—'); ?></td>
                    <?php endif; ?>
                    <td>
                        <?php
                        $meta = $entry['meta'] ?? null;
                        if ($meta) {
                            if (is_string($meta)) {
                                $meta = json_decode($meta, true);
                            }
                            if (is_array($meta)) {
                                $parts = [];
                                foreach ($meta as $k => $v) {
                                    if (is_array($v)) $v = implode(', ', $v);
                                    $parts[] = esc_html($k) . ': ' . esc_html((string) $v);
                                }
                                echo '<small>' . implode(' | ', $parts) . '</small>';
                            }
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderPagination(int $currentPage, int $totalPages): void
    {
        if ($totalPages <= 1) {
            return;
        }

        $baseUrl = remove_query_arg('paged');
        ?>
        <div class="tablenav" style="margin-top:10px;">
            <div class="tablenav-pages">
                <span class="pagination-links">
                    <?php if ($currentPage > 1): ?>
                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $currentPage - 1, $baseUrl)); ?>">‹</a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <?php echo esc_html($this->t('Seite')); ?> <strong><?php echo (int) $currentPage; ?></strong> <?php echo esc_html($this->t('von')); ?> <strong><?php echo (int) $totalPages; ?></strong>
                    </span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $currentPage + 1, $baseUrl)); ?>">›</a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    }
}
