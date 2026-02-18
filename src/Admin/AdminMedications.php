<?php
/**
 * AdminMedications – Medikamenten-Verwaltung im Backend
 *
 * Verantwortlich für:
 *  - Medikamenten-Liste mit Suche + Pagination
 *  - CSV-Import (Standard + benutzerdefiniert)
 *  - Einzelnes Anlegen / Bearbeiten / Löschen
 *  - Datenbank-Bereinigung (Kommentarzeilen, fehlerhafte Einträge)
 *  - Standort-Filter für Medikamenten-Zuordnung (Multi-Location)
 *
 * v4.2.8-Änderungen:
 *  - Aus Admin.php extrahiert in eigene Sub-Controller-Klasse
 *  - Standort-Dropdown für Medikamenten-Zuordnung (#21)
 *  - Alle i18n-Strings über $this->t()
 *
 * @package PraxisPortal\Admin
 * @since   4.2.8
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\MedicationRepository;
use PraxisPortal\Database\Repository\LocationRepository;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminMedications
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
        $wpdb  = $GLOBALS['wpdb'];
        $table = \PraxisPortal\Database\Schema::medications();

        // ── Tabelle sicherstellen (kein Auto-Import der CSV) ──
        $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$tableExists) {
            \PraxisPortal\Database\Schema::install();
        }

        $currentTab = sanitize_key($_GET['tab'] ?? 'liste');
        $perPage    = 50;
        $paged      = max(1, (int) ($_GET['paged'] ?? 1));
        $search     = sanitize_text_field($_GET['s'] ?? '');
        $offset     = ($paged - 1) * $perPage;

        // ── Standorte für Location-Dropdown (Multistandort-Säule) ──
        $locationRepo = $this->container->get(LocationRepository::class);
        $locations    = $locationRepo->getAll();

        // Sicher: alle Queries einzeln mit prepare() bauen
        if ($search !== '') {
            // ── FULLTEXT-Suche (schnell) ──
            $useFulltext = strlen($search) >= 3;
            if ($useFulltext) {
                // Prüfe ob Index existiert
                $ftExists = (bool) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_ft_search'",
                    DB_NAME, $table
                ));
                $useFulltext = $ftExists;
            }

            if ($useFulltext) {
                $ftTerm = '+' . preg_replace('/[^\p{L}\p{N}\s]/u', '', $search) . '*';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE name NOT LIKE %s AND name NOT LIKE %s AND MATCH(name, wirkstoff, dosage) AGAINST(%s IN BOOLEAN MODE)",
                    '#%', '=%', $ftTerm
                ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $medications = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, name, dosage, form, pzn, created_at,
                            MATCH(name, wirkstoff, dosage) AGAINST(%s IN BOOLEAN MODE) AS relevance
                     FROM {$table}
                     WHERE name NOT LIKE %s AND name NOT LIKE %s AND MATCH(name, wirkstoff, dosage) AGAINST(%s IN BOOLEAN MODE)
                     ORDER BY relevance DESC, name ASC LIMIT %d OFFSET %d",
                    $ftTerm, '#%', '=%', $ftTerm, $perPage, $offset
                ), ARRAY_A) ?: [];
            } else {
                // ── Fallback: LIKE-Suche ──
                $like = '%' . $wpdb->esc_like($search) . '%';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE name NOT LIKE %s AND name NOT LIKE %s AND (name LIKE %s OR dosage LIKE %s OR pzn LIKE %s)",
                    '#%', '=%', $like, $like, $like
                ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $medications = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, name, dosage, form, pzn, created_at FROM {$table} WHERE name NOT LIKE %s AND name NOT LIKE %s AND (name LIKE %s OR dosage LIKE %s OR pzn LIKE %s) ORDER BY name ASC LIMIT %d OFFSET %d",
                    '#%', '=%', $like, $like, $like, $perPage, $offset
                ), ARRAY_A) ?: [];
            }
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE name NOT LIKE %s AND name NOT LIKE %s",
                '#%', '=%'
            ));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $medications = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, dosage, form, pzn, created_at FROM {$table} WHERE name NOT LIKE %s AND name NOT LIKE %s ORDER BY name ASC LIMIT %d OFFSET %d",
                '#%', '=%', $perPage, $offset
            ), ARRAY_A) ?: [];
        }

        $pages       = (int) ceil($count / $perPage);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $badCount    = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE name LIKE %s OR name LIKE %s",
            '#%', '=%'
        ));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $brokenCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE name LIKE %s AND (dosage IS NULL OR dosage = '') AND name NOT LIKE %s",
            '%,%', '#%'
        ));

        $baseUrl = admin_url('admin.php?page=pp-medications');
        ?>
        <div class="wrap pp-medication-admin">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-pills"></span>
                Medikamenten-Datenbank
            </h1>
            <hr class="wp-header-end">

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="<?php echo esc_url(add_query_arg('tab', 'liste', $baseUrl)); ?>"
                   class="nav-tab <?php echo $currentTab === 'liste' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php echo esc_html($this->t('Liste')); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'import', $baseUrl)); ?>"
                   class="nav-tab <?php echo $currentTab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-upload"></span> <?php echo esc_html($this->t('Importieren')); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'datenbank', $baseUrl)); ?>"
                   class="nav-tab <?php echo $currentTab === 'datenbank' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-database"></span> <?php echo esc_html($this->t('Datenbank')); ?>
                </a>
            </nav>

            <div class="pp-tab-content active">

            <?php if ($currentTab === 'liste'): ?>

                <?php if ($count === 0 && $badCount === 0 && $brokenCount === 0): ?>
                <!-- LEERE DATENBANK: Import-Button prominent zeigen -->
                <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; margin-top:20px;">
                    <span style="font-size:48px; display:block; margin-bottom:16px;">💊</span>
                    <h2 style="font-size:20px; margin:0 0 8px;"><?php echo esc_html($this->t('Noch keine Medikamente vorhanden')); ?></h2>
                    <p style="color:#666; font-size:14px; margin:0 0 20px;">
                        <?php echo esc_html($this->t('Importieren Sie die mitgelieferte Augen-Medikamenten-Datenbank oder laden Sie eine eigene CSV-Datei hoch.')); ?>
                    </p>
                    <button type="button" class="button button-primary button-hero" id="pp-import-standard-meds" style="font-size:16px; padding:8px 24px;">
                        📥 <?php echo esc_html($this->t('Standard-Medikamente importieren')); ?>
                    </button>
                    <p style="margin-top:16px;">
                        <a href="<?php echo esc_url($baseUrl . '&tab=import'); ?>" class="button">
                            📁 <?php echo esc_html($this->t('Eigene CSV-Datei importieren')); ?>
                        </a>
                    </p>
                </div>

                <?php else: ?>
                <!-- DATENBANK HAT EINTRÄGE: Normale Ansicht -->

                <?php if ($brokenCount > 0): ?>
                <div class="notice notice-error" style="padding:10px 15px;">
                    <p><strong>🔧 <?php echo (int) $brokenCount; ?> <?php echo esc_html($this->t('fehlerhafte Einträge gefunden')); ?></strong> — <?php echo esc_html($this->t('CSV-Daten wurden nicht korrekt aufgeteilt.')); ?></p>
                    <p>
                        <button type="button" class="button button-primary" id="pp-repair-medications">🔧 <?php echo esc_html($this->t('Automatisch reparieren')); ?></button>
                        <button type="button" class="button" id="pp-delete-all-broken" style="margin-left:8px;">🗑️ <?php echo esc_html($this->t('Alle löschen')); ?></button>
                    </p>
                </div>
                <?php endif; ?>

                <?php if ($badCount > 0): ?>
                <div class="notice notice-warning" style="padding:10px 15px;">
                    <p><strong>⚠️ <?php echo (int) $badCount; ?> <?php echo esc_html($this->t('Kommentarzeilen gefunden')); ?></strong>
                    <button type="button" class="button button-small" id="pp-cleanup-comments" style="margin-left:10px;">🧹 <?php echo esc_html($this->t('Bereinigen')); ?></button></p>
                </div>
                <?php endif; ?>

                <!-- Filter-Leiste -->
                <div class="pp-filter-bar">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="pp-medications">
                        <input type="hidden" name="tab" value="liste">
                        <div class="pp-filter-row">
                            <div class="pp-filter-item pp-search-box">
                                <label for="filter-search"><?php echo esc_html($this->t('Suche:')); ?></label>
                                <input type="search" name="s" id="filter-search"
                                       value="<?php echo esc_attr($search); ?>"
                                       placeholder="<?php echo esc_attr($this->t('Name, Dosierung oder PZN…')); ?>">
                            </div>
                            <div class="pp-filter-item">
                                <button type="submit" class="button">🔍 <?php echo esc_html($this->t('Suchen')); ?></button>
                                <?php if ($search): ?>
                                    <a href="<?php echo esc_url($baseUrl . '&tab=liste'); ?>" class="button-link" style="margin-left:10px;"><?php echo esc_html($this->t('Zurücksetzen')); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Statistiken -->
                <div class="pp-stats-bar">
                    <span class="pp-stat">
                        <strong><?php echo number_format($count); ?></strong> <?php echo esc_html($this->t('Medikamente')); ?>
                        <?php if ($search): ?> <?php echo esc_html($this->t('für')); ?> „<?php echo esc_html($search); ?>"<?php endif; ?>
                    </span>
                    <?php if ($count > 0): ?>
                    <button type="button" id="pp-delete-all-medications" class="button button-link-delete" style="color:#d63638;">
                        <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span>
                        <?php echo esc_html($this->t('Alle löschen')); ?>
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Medikamenten-Tabelle -->
                <?php if ($count > 0): ?>
                <table class="wp-list-table widefat fixed striped pp-medications-table">
                    <thead>
                        <tr>
                            <th class="column-name" style="width:30%;"><?php echo esc_html($this->t('Bezeichnung')); ?></th>
                            <th class="column-dosage" style="width:18%;"><?php echo esc_html($this->t('Dosierung')); ?></th>
                            <th class="column-form" style="width:18%;"><?php echo esc_html($this->t('Darreichungsform')); ?></th>
                            <th class="column-pzn" style="width:12%;">PZN</th>
                            <th class="column-actions" style="width:22%;"><?php echo esc_html($this->t('Aktionen')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($medications as $med): ?>
                        <tr data-id="<?php echo (int) $med['id']; ?>">
                            <td class="column-name">
                                <strong>
                                    <span class="pp-med-display"><?php echo esc_html($med['name']); ?></span>
                                    <input type="text" name="name" value="<?php echo esc_attr($med['name']); ?>" class="pp-med-edit regular-text" style="display:none;width:95%;">
                                </strong>
                            </td>
                            <td>
                                <span class="pp-med-display"><?php echo esc_html($med['dosage'] ?: '—'); ?></span>
                                <input type="text" name="dosage" value="<?php echo esc_attr($med['dosage'] ?? ''); ?>" class="pp-med-edit" style="display:none;width:95%;">
                            </td>
                            <td>
                                <span class="pp-med-display"><?php echo esc_html($med['form'] ?: '—'); ?></span>
                                <input type="text" name="form" value="<?php echo esc_attr($med['form'] ?? ''); ?>" class="pp-med-edit" style="display:none;width:95%;">
                            </td>
                            <td>
                                <span class="pp-med-display"><code><?php echo esc_html($med['pzn'] ?: '—'); ?></code></span>
                                <input type="text" name="pzn" value="<?php echo esc_attr($med['pzn'] ?? ''); ?>" class="pp-med-edit" style="display:none;width:95%;">
                            </td>
                            <td>
                                <div class="pp-med-display row-actions" style="visibility:visible;">
                                    <span class="edit"><a href="#" class="pp-edit-medication"><?php echo esc_html($this->t('Bearbeiten')); ?></a></span> |
                                    <span class="delete"><a href="#" class="pp-delete-medication" data-id="<?php echo (int) $med['id']; ?>" data-name="<?php echo esc_attr($med['name']); ?>"><?php echo esc_html($this->t('Löschen')); ?></a></span>
                                </div>
                                <div class="pp-med-edit" style="display:none;">
                                    <button type="button" class="button button-small button-primary pp-save-medication">💾 <?php echo esc_html($this->t('Speichern')); ?></button>
                                    <button type="button" class="button button-small pp-cancel-edit"><?php echo esc_html($this->t('Abbrechen')); ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format($count); ?> <?php echo esc_html($this->t('Einträge')); ?></span>
                        <span class="pagination-links">
                        <?php
                        echo paginate_links([
                            'base'    => add_query_arg('paged', '%#%'),
                            'format'  => '',
                            'total'   => $pages,
                            'current' => $paged,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ]);
                        ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div style="text-align:center; padding:40px 20px;">
                    <p style="font-size:16px; color:#666;"><?php echo esc_html($this->t('Keine Medikamente gefunden')); ?><?php if ($search): ?> <?php echo esc_html($this->t('für')); ?> „<?php echo esc_html($search); ?>"<?php endif; ?>.</p>
                    <p>
                        <button type="button" class="button button-primary" id="pp-import-standard-meds">
                            📥 <?php echo esc_html($this->t('Standard-Medikamente importieren')); ?>
                        </button>
                    </p>
                </div>
                <?php endif; ?>

                <?php endif; ?><!-- Ende: count === 0 else -->

            <?php elseif ($currentTab === 'import'): ?>
                <!-- IMPORT TAB -->
                <div class="pp-import-section" style="max-width:700px;">
                    <h2>📥 <?php echo esc_html($this->t('CSV-Import')); ?></h2>
                    <p class="description">
                        <?php echo esc_html($this->t('Importieren Sie Medikamente aus einer CSV-Datei. Die Datei kann mit Komma (,) oder Semikolon (;) getrennt sein.')); ?>
                    </p>

                    <div class="pp-import-info">
                        <h4><?php echo esc_html($this->t('Erwartete Spalten:')); ?></h4>
                        <ul>
                            <li><strong>name</strong> <?php echo esc_html($this->t('oder')); ?> <strong>bezeichnung</strong> (<?php echo esc_html($this->t('Pflichtfeld')); ?>)</li>
                            <li>dosierung / dosage / wirkstoff / staerke</li>
                            <li>form / darreichungsform</li>
                            <li>pzn / pharmazentralnummer</li>
                        </ul>
                    </div>

                    <div class="pp-import-zone">
                        <p style="margin:0;font-size:14px;">📂 <?php echo esc_html($this->t('CSV-Datei hierher ziehen oder')); ?> <strong><?php echo esc_html($this->t('klicken')); ?></strong> <?php echo esc_html($this->t('zum Auswählen')); ?></p>
                        <p class="description" style="margin:5px 0 0;"><?php echo esc_html($this->t('Kommentarzeilen mit # werden automatisch übersprungen.')); ?></p>
                        <input type="file" id="pp-import-file" accept=".csv,.txt" style="display:none;">
                    </div>
                    <div class="pp-import-progress" style="margin-top:10px;">
                        <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:24px;">
                            <div class="pp-progress-bar-fill" style="background:#2271b1;height:100%;width:0%;text-align:center;color:#fff;line-height:24px;font-size:12px;transition:width .3s;">0%</div>
                        </div>
                    </div>
                </div>

            <?php elseif ($currentTab === 'datenbank'): ?>
                <!-- DATENBANK TAB -->
                <div class="pp-database-section" style="max-width:700px;">
                    <h2>🗄️ <?php echo esc_html($this->t('Datenbank-Verwaltung')); ?></h2>

                    <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;padding:15px;margin-bottom:20px;">
                        <h3 style="margin-top:0;">📊 <?php echo esc_html($this->t('Aktuelle Statistik')); ?></h3>
                        <table class="widefat" style="background:transparent;border:none;">
                            <tr><td><strong><?php echo esc_html($this->t('Gesamt:')); ?></strong></td><td><?php echo number_format($count); ?> <?php echo esc_html($this->t('Medikamente')); ?></td></tr>
                            <?php if ($badCount > 0): ?>
                            <tr><td><strong><?php echo esc_html($this->t('Kommentarzeilen:')); ?></strong></td><td><?php echo (int) $badCount; ?> (<?php echo esc_html($this->t('werden nicht angezeigt')); ?>)</td></tr>
                            <?php endif; ?>
                            <?php if ($brokenCount > 0): ?>
                            <tr><td><strong><?php echo esc_html($this->t('Fehlerhaft:')); ?></strong></td><td style="color:#d63638;"><?php echo (int) $brokenCount; ?> <?php echo esc_html($this->t('Einträge')); ?></td></tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <hr>

                    <h3>📦 <?php echo esc_html($this->t('Neues Medikament hinzufügen')); ?></h3>
                    <form id="pp-medication-form" class="pp-medication-form">
                        <div class="pp-form-row">
                            <div class="pp-form-group pp-form-group-wide">
                                <label for="med-name"><strong><?php echo esc_html($this->t('Bezeichnung')); ?> *</strong></label>
                                <input type="text" id="med-name" name="name" placeholder="<?php echo esc_attr($this->t('z.B. Ibuprofen 400mg')); ?>" required>
                            </div>
                        </div>
                        <div class="pp-form-row">
                            <div class="pp-form-group">
                                <label for="med-dosage"><?php echo esc_html($this->t('Dosierung')); ?></label>
                                <input type="text" id="med-dosage" name="dosage" placeholder="<?php echo esc_attr($this->t('z.B. 400mg')); ?>">
                            </div>
                            <div class="pp-form-group">
                                <label for="med-form"><?php echo esc_html($this->t('Darreichungsform')); ?></label>
                                <input type="text" id="med-form" name="form" placeholder="<?php echo esc_attr($this->t('z.B. Tabletten')); ?>">
                            </div>
                            <div class="pp-form-group">
                                <label for="med-pzn">PZN</label>
                                <input type="text" id="med-pzn" name="pzn" placeholder="<?php echo esc_attr($this->t('z.B. 01234567')); ?>">
                            </div>
                        </div>
                        <?php if (count($locations) > 1): ?>
                        <div class="pp-form-row">
                            <div class="pp-form-group pp-form-group-wide">
                                <label for="med-location"><?php echo esc_html($this->t('Standort')); ?></label>
                                <select id="med-location" name="location_id">
                                    <option value=""><?php echo esc_html($this->t('Alle Standorte (global)')); ?></option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?php echo esc_attr($loc['id']); ?>"><?php echo esc_html($loc['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php echo esc_html($this->t('Globale Medikamente sind für alle Standorte verfügbar.')); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="pp-form-actions">
                            <button type="button" id="pp-medication-save" class="button button-primary">
                                <span class="dashicons dashicons-saved" style="font-size:16px;width:16px;height:16px;vertical-align:middle;margin-right:4px;"></span>
                                <?php echo esc_html($this->t('Speichern')); ?>
                            </button>
                        </div>
                    </form>

                    <hr>

                    <?php if ($brokenCount > 0): ?>
                    <h3>🔧 <?php echo esc_html($this->t('Fehlerhafte Einträge reparieren')); ?></h3>
                    <p class="description"><?php echo (int) $brokenCount; ?> <?php echo esc_html($this->t('Einträge mit CSV-Daten im Namensfeld gefunden.')); ?></p>
                    <p>
                        <button type="button" class="button button-primary" id="pp-repair-medications">🔧 <?php echo esc_html($this->t('Automatisch reparieren')); ?></button>
                        <button type="button" class="button" id="pp-delete-all-broken" style="margin-left:8px;">🗑️ <?php echo esc_html($this->t('Fehlerhafte löschen')); ?></button>
                    </p>
                    <hr>
                    <?php endif; ?>

                    <h3>📥 <?php echo esc_html($this->t('Standard-Medikamente laden')); ?></h3>
                    <p class="description"><?php echo esc_html($this->t('Importiert die mitgelieferte Augen-Medikamenten-Datenbank aus der CSV-Datei.')); ?></p>
                    <button type="button" class="button button-primary" id="pp-seed-standard-meds">
                        📥 <?php echo esc_html($this->t('Standard-Medikamente importieren')); ?>
                    </button>

                    <hr>

                    <h3>⚠️ <?php echo esc_html($this->t('Datenbank leeren')); ?></h3>
                    <div class="notice notice-warning inline" style="margin:10px 0;">
                        <p><strong><?php echo esc_html($this->t('Achtung:')); ?></strong> <?php echo esc_html(sprintf($this->t('Dies löscht ALLE %s Medikamente unwiderruflich!'), number_format($count))); ?></p>
                    </div>
                    <button type="button" class="button button-link-delete" id="pp-delete-all-medications" style="color:#d63638;">
                        <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span>
                        <?php echo esc_html($this->t('Alle Medikamente löschen')); ?>
                    </button>
                </div>
            <?php endif; ?>

            </div><!-- .pp-tab-content -->
        </div><!-- .wrap -->
        <?php
    }

    /* =====================================================================
     * AJAX-HANDLER
     * ================================================================== */

    private function getMedicationRepo(): MedicationRepository
    {
        return $this->container->get(MedicationRepository::class);
    }

    public function ajaxMedicationCreate(): void
    {
        $data = [
            'name'               => sanitize_text_field($_POST['name'] ?? ''),
            'pzn'                => sanitize_text_field($_POST['pzn'] ?? ''),
            'wirkstoff'          => sanitize_text_field($_POST['wirkstoff'] ?? $_POST['dosage'] ?? ''),
            'staerke'            => sanitize_text_field($_POST['staerke'] ?? ''),
            'einheit'            => sanitize_text_field($_POST['einheit'] ?? ''),
            'kategorie'          => sanitize_text_field($_POST['kategorie'] ?? $_POST['form'] ?? ''),
            'standard_dosierung' => sanitize_text_field($_POST['standard_dosierung'] ?? ''),
            'einnahme_hinweis'   => sanitize_text_field($_POST['einnahme_hinweis'] ?? ''),
        ];

        // Standort-Zuordnung (Multistandort-Säule)
        $locationId = isset($_POST['location_id']) && $_POST['location_id'] !== ''
            ? (int) $_POST['location_id']
            : null;
        if ($locationId !== null) {
            $data['location_id'] = $locationId;
        }

        if (empty($data['name'])) {
            wp_send_json_error(['message' => $this->t('Name ist erforderlich.')], 400);
        }

        $id = $this->getMedicationRepo()->create($data);
        $id ? wp_send_json_success(['id' => $id]) : wp_send_json_error(['message' => $this->t('Fehler beim Anlegen.')]);
    }

    public function ajaxMedicationUpdate(): void
    {
        $id   = (int) ($_POST['medication_id'] ?? $_POST['id'] ?? 0);
        $data = [
            'name'               => sanitize_text_field($_POST['name'] ?? ''),
            'pzn'                => sanitize_text_field($_POST['pzn'] ?? ''),
            'wirkstoff'          => sanitize_text_field($_POST['wirkstoff'] ?? $_POST['dosage'] ?? ''),
            'staerke'            => sanitize_text_field($_POST['staerke'] ?? ''),
            'einheit'            => sanitize_text_field($_POST['einheit'] ?? ''),
            'kategorie'          => sanitize_text_field($_POST['kategorie'] ?? $_POST['form'] ?? ''),
            'standard_dosierung' => sanitize_text_field($_POST['standard_dosierung'] ?? ''),
            'einnahme_hinweis'   => sanitize_text_field($_POST['einnahme_hinweis'] ?? ''),
        ];

        if ($id < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $result = $this->getMedicationRepo()->update($id, $data);
        $result ? wp_send_json_success() : wp_send_json_error(['message' => $this->t('Fehler beim Aktualisieren.')]);
    }

    public function ajaxMedicationDelete(): void
    {
        $id = (int) ($_POST['medication_id'] ?? $_POST['id'] ?? 0);
        if ($id < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $result = $this->getMedicationRepo()->delete($id);
        $result ? wp_send_json_success() : wp_send_json_error(['message' => $this->t('Fehler beim Löschen.')]);
    }

    public function ajaxMedicationDeleteAll(): void
    {
        $count = $this->getMedicationRepo()->deleteAll();
        wp_send_json_success(['deleted' => $count]);
    }

    public function ajaxMedicationImportBatch(): void
    {
        $rows = json_decode(wp_unslash($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows) || empty($rows)) {
            wp_send_json_error(['message' => $this->t('Keine Daten empfangen.')], 400);
        }

        // Sanitize each row, skip comment/category lines
        $clean = [];
        foreach ($rows as $row) {
            $name = sanitize_text_field($row['name'] ?? '');
            // Skip empty or comment lines (# ...)
            if (empty($name) || str_starts_with($name, '#') || str_starts_with($name, '=')) {
                continue;
            }
            $clean[] = [
                'name'   => $name,
                'dosage' => sanitize_text_field($row['dosage'] ?? ''),
                'form'   => sanitize_text_field($row['form'] ?? ''),
                'pzn'    => sanitize_text_field($row['pzn'] ?? ''),
            ];
        }

        if (empty($clean)) {
            wp_send_json_error(['message' => $this->t('Keine gültigen Einträge gefunden.')], 400);
        }

        $imported = $this->getMedicationRepo()->bulkInsert($clean);
        wp_send_json_success(['imported' => $imported]);
    }

    /**
     * AJAX: Ungültige Kommentarzeilen aus Medikamenten-Tabelle löschen
     */
    public function ajaxMedicationCleanupComments(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $table = \PraxisPortal\Database\Schema::medications();
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE name LIKE %s OR name LIKE %s",
            '#%',
            '=%'
        ));
        wp_send_json_success([
            'deleted' => (int) $deleted,
            'message' => (int) $deleted . ' ' . $this->t('ungültige Einträge gelöscht.'),
        ]);
    }

    /**
     * AJAX: Fehlerhafte Medikamenten-Einträge automatisch reparieren
     * Erkennt Einträge wo die gesamte CSV-Zeile im name-Feld steht
     * CSV-Format: name,wirkstoff,stärke,pzn,kategorie,dosierung,hinweis
     */
    public function ajaxMedicationRepairBroken(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = \PraxisPortal\Database\Schema::medications();

        // Broken entries: name contains commas, dosage is empty
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name FROM {$table} WHERE name LIKE %s AND (dosage IS NULL OR dosage = %s) AND name NOT LIKE %s LIMIT 5000",
                '%,%',
                '',
                '#%'
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            wp_send_json_success(['repaired' => 0, 'message' => $this->t('Keine fehlerhaften Einträge gefunden.')]);
            return;
        }

        $repaired = 0;
        foreach ($rows as $row) {
            $parts = array_map('trim', explode(',', $row['name']));

            // Expected CSV: name,wirkstoff,stärke,pzn,kategorie,dosierung,hinweis
            $name    = $parts[0] ?? '';
            $dosage  = '';
            $form    = '';
            $pzn     = '';

            if (count($parts) >= 2) {
                // Wirkstoff + Stärke → dosage
                $wirkstoff = $parts[1] ?? '';
                $staerke   = $parts[2] ?? '';
                $dosage    = trim($wirkstoff . ' ' . $staerke);

                // PZN (index 3)
                $pzn = $parts[3] ?? '';

                // Kategorie → form (index 4)
                $form = $parts[4] ?? '';
            }

            if (empty($name)) continue;

            $wpdb->update(
                $table,
                [
                    'name'   => sanitize_text_field($name),
                    'dosage' => sanitize_text_field($dosage),
                    'form'   => sanitize_text_field($form),
                    'pzn'    => sanitize_text_field($pzn),
                ],
                ['id' => (int) $row['id']],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            $repaired++;
        }

        wp_send_json_success([
            'repaired' => $repaired,
            'message'  => $repaired . ' ' . $this->t('Einträge repariert.'),
        ]);
    }

    /**
     * AJAX: Alle fehlerhaften Medikamenten-Einträge löschen
     */
    public function ajaxMedicationDeleteBroken(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = \PraxisPortal\Database\Schema::medications();
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE name LIKE %s AND (dosage IS NULL OR dosage = %s) AND name NOT LIKE %s",
            '%,%',
            '',
            '#%'
        ));
        wp_send_json_success([
            'deleted' => (int) $deleted,
            'message' => (int) $deleted . ' ' . $this->t('fehlerhafte Einträge gelöscht.'),
        ]);
    }

    /**
     * Standard-Medikamente aus mitgelieferter CSV importieren
     */
    public function ajaxMedicationImportStandard(): void
    {
        $csvPath = PP_PLUGIN_DIR . 'data/medicationspraxis.csv';
        if (!file_exists($csvPath)) {
            wp_send_json_error(['message' => $this->t('CSV-Datei nicht gefunden:') . ' ' . $csvPath]);
        }

        $wpdb  = $GLOBALS['wpdb'];
        $table = \PraxisPortal\Database\Schema::medications();

        $imported = $this->seedMedicationsFromCsv($csvPath, $table, $wpdb);

        wp_send_json_success([
            'imported' => $imported,
            'message'  => $imported . ' ' . $this->t('Medikamente importiert.'),
        ]);
    }

    /**
     * CSV → DB-Import (gemeinsame Logik für Auto-Seed und AJAX-Import)
     *
     * @return int Anzahl importierter Einträge
     */
    private function seedMedicationsFromCsv(string $csvPath, string $table, \wpdb $wpdb): int
    {
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return 0;
        }

        // Header überspringen
        $header = fgetcsv($handle, 0, ',');
        $imported = 0;
        $batch = [];

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $name = trim($row[0] ?? '');
            if ($name === '' || str_starts_with($name, '#')) {
                continue;
            }

            // CSV: name(0), wirkstoff(1), staerke(2), pzn(3), kategorie(4),
            //      standard_dosierung(5), einnahme_hinweis(6)
            $wirkstoff         = trim($row[1] ?? '');
            $staerke           = trim($row[2] ?? '');
            $pzn               = trim($row[3] ?? '');
            $kategorie         = trim($row[4] ?? '');
            $standardDosierung = trim($row[5] ?? '');
            $einnahmeHinweis   = trim($row[6] ?? '');
            $dosage            = trim($wirkstoff . ($staerke ? ' ' . $staerke : ''));

            $batch[] = $wpdb->prepare(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())',
                $name,
                $wirkstoff ?: null,
                $staerke ?: null,
                $dosage,
                $kategorie,
                $pzn ?: null,
                $standardDosierung ?: null,
                $einnahmeHinweis ?: null,
                $kategorie ?: null
            );

            if (count($batch) >= 500) {
                $wpdb->query(
                    "INSERT INTO {$table} (name, wirkstoff, staerke, dosage, form, pzn, standard_dosierung, einnahme_hinweis, kategorie, created_at) VALUES "
                    . implode(',', $batch)
                );
                $imported += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $wpdb->query(
                "INSERT INTO {$table} (name, wirkstoff, staerke, dosage, form, pzn, standard_dosierung, einnahme_hinweis, kategorie, created_at) VALUES "
                . implode(',', $batch)
            );
            $imported += count($batch);
        }

        fclose($handle);
        return $imported;

    }
}
