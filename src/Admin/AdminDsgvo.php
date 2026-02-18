<?php
/**
 * AdminDsgvo – DSGVO / Datenschutz-Tools
 *
 * Verantwortlich für:
 *  - Patienten-Suche (Name, Geburtsdatum, E-Mail)
 *  - Daten-Export (alle Daten eines Patienten als ZIP/JSON)
 *  - Endgültige Löschung (unwiderruflich mit Audit-Log)
 *
 * v4-Änderungen:
 *  - Suche durchsucht verschlüsselte Daten per Encryption-Service
 *  - Multi-Standort-Filter
 *  - Export als ZIP mit JSON + Dateien
 *  - Audit-Logging bei jeder DSGVO-Aktion
 *
 * @package PraxisPortal\Admin
 * @since   4.0.0
 */

declare(strict_types=1);

namespace PraxisPortal\Admin;

use PraxisPortal\Core\Container;
use PraxisPortal\Database\Repository\SubmissionRepository;
use PraxisPortal\Database\Repository\FileRepository;
use PraxisPortal\Database\Repository\AuditRepository;
use PraxisPortal\Security\Encryption;
use PraxisPortal\I18n\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class AdminDsgvo
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
        $searchResults = null;
        $searchTerm    = '';
        $message       = '';
        $msgType       = '';

        // Suche
        if (!empty($_POST['pp_dsgvo_search']) && $this->verifyNonce()) {
            $searchTerm    = sanitize_text_field($_POST['search_term'] ?? '');
            $searchResults = $this->performSearch($searchTerm);
        }

        // Endgültige Löschung
        if (!empty($_POST['pp_dsgvo_delete']) && $this->verifyNonce()) {
            $result = $this->performDelete((int) ($_POST['submission_id'] ?? 0));
            $message = $result['message'];
            $msgType = $result['type'];
        }

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-shield" style="font-size:30px;width:30px;height:30px;margin-right:10px;"></span>
                DSGVO – <?php echo esc_html($this->t('Datenschutz')); ?>
            </h1>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($msgType); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <div style="max-width:800px;">
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:15px;margin:20px 0;">
                    ⚠️ <strong><?php echo esc_html($this->t('Hinweis')); ?>:</strong> <?php echo esc_html($this->t('Alle Aktionen auf dieser Seite werden im Audit-Log protokolliert.')); ?>
                    <?php echo esc_html($this->t('Die endgültige Löschung ist')); ?> <strong><?php echo esc_html($this->t('unwiderruflich')); ?></strong>.
                </div>

                <?php $this->renderSearchForm($searchTerm); ?>

                <?php if ($searchResults !== null): ?>
                    <?php $this->renderSearchResults($searchResults, $searchTerm); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
     * AJAX-HANDLER
     * ================================================================== */

    /**
     * Patienten-Suche (AJAX)
     */
    public function ajaxSearch(): void
    {
        $term = sanitize_text_field($_POST['search_term'] ?? '');
        if (strlen($term) < 2) {
            wp_send_json_error(['message' => $this->t('Mindestens 2 Zeichen erforderlich.')], 400);
        }

        $results = $this->performSearch($term);
        wp_send_json_success(['results' => $results, 'count' => count($results)]);
    }

    /**
     * Patienten-Daten-Export (AJAX / admin-post)
     */
    public function ajaxExport(): void
    {
        $submissionId = (int) ($_POST['submission_id'] ?? $_GET['submission_id'] ?? 0);
        if ($submissionId < 1) {
            wp_die($this->t('Ungültige ID.'));
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $fileRepo       = $this->container->get(FileRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $submission = $submissionRepo->findDecrypted($submissionId);
        if (!$submission) {
            wp_die($this->t('Einreichung nicht gefunden.'));
        }

        // Dateien sammeln
        $files = $fileRepo->findBySubmission($submissionId);

        // Audit
        $auditRepo->logDsgvo('dsgvo_export', $submissionId);

        // JSON-Export
        $exportData = [
            'export_date'  => current_time('Y-m-d H:i:s'),
            'export_type'  => 'dsgvo_patient_export',
            'submission'   => $submission,
            'files_count'  => count($files),
        ];

        $jsonContent = wp_json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename    = 'dsgvo-export-' . $submissionId . '-' . date('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($jsonContent));
        echo $jsonContent; // phpcs:ignore
        exit;
    }

    /**
     * Endgültige Löschung (AJAX)
     */
    public function ajaxDelete(): void
    {
        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        if ($submissionId < 1) {
            wp_send_json_error(['message' => $this->t('Ungültige ID.')], 400);
        }

        $result = $this->performDelete($submissionId);

        if ($result['type'] === 'success') {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /* =====================================================================
     * EARLY-ACTION (vor Output für Download)
     * ================================================================== */

    /**
     * DSGVO-Export als Download (POST vor Output)
     */
    public function handleEarlyExport(): void
    {
        if (empty($_POST['pp_dsgvo_export'])) {
            return;
        }
        if (!$this->verifyNonce()) {
            return;
        }

        $this->ajaxExport();
    }

    /* =====================================================================
     * PRIVATE: RENDERING
     * ================================================================== */

    private function renderSearchForm(string $searchTerm): void
    {
        ?>
        <h2>🔍 <?php echo esc_html($this->t('Patienten-Suche')); ?></h2>
        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field('pp_dsgvo_action', 'pp_dsgvo_nonce'); ?>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="text" name="search_term" value="<?php echo esc_attr($searchTerm); ?>"
                       placeholder="<?php echo esc_attr($this->t('Name, Geburtsdatum oder E-Mail')); ?>"
                       class="regular-text" required minlength="2" autofocus>
                <button type="submit" name="pp_dsgvo_search" value="1" class="button button-primary">🔍 <?php echo esc_html($this->t('Suchen')); ?></button>
            </div>
            <p class="description"><?php echo esc_html($this->t('Durchsucht alle (auch verschlüsselten) Einreichungen nach Übereinstimmungen.')); ?></p>
        </form>
        <?php
    }

    private function renderSearchResults(array $results, string $searchTerm): void
    {
        ?>
        <h3><?php echo esc_html($this->t('Ergebnisse für')); ?> „<?php echo esc_html($searchTerm); ?>" (<?php echo count($results); ?>)</h3>

        <?php if (empty($results)): ?>
            <p><?php echo esc_html($this->t('Keine Einreichungen gefunden.')); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php echo esc_html($this->t('Datum')); ?></th>
                        <th><?php echo esc_html($this->t('Name')); ?></th>
                        <th><?php echo esc_html($this->t('Geburtsdatum')); ?></th>
                        <th>Service</th>
                        <th><?php echo esc_html($this->t('Aktionen')); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td>#<?php echo esc_html($r['id']); ?></td>
                        <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($r['created_at']))); ?></td>
                        <td><strong><?php echo esc_html($r['name'] ?? '—'); ?></strong></td>
                        <td><?php echo esc_html($r['geburtsdatum'] ?? '—'); ?></td>
                        <td><?php echo esc_html($r['service_key'] ?? '—'); ?></td>
                        <td style="display:flex;gap:5px;">
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('pp_dsgvo_action', 'pp_dsgvo_nonce'); ?>
                                <input type="hidden" name="submission_id" value="<?php echo esc_attr($r['id']); ?>">
                                <button type="submit" name="pp_dsgvo_export" value="1" class="button button-small" title="Export">📦 Export</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js($this->t('ACHTUNG: Endgültige Löschung! Diese Aktion kann NICHT rückgängig gemacht werden.')); ?>\n\n<?php echo esc_js($this->t('Wirklich löschen?')); ?>');">
                                <?php wp_nonce_field('pp_dsgvo_action', 'pp_dsgvo_nonce'); ?>
                                <input type="hidden" name="submission_id" value="<?php echo esc_attr($r['id']); ?>">
                                <button type="submit" name="pp_dsgvo_delete" value="1" class="button button-small" style="color:#dc3232;" title="<?php echo esc_attr($this->t('Endgültig löschen')); ?>">🗑️ <?php echo esc_html($this->t('Löschen')); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /* =====================================================================
     * PRIVATE: LOGIK
     * ================================================================== */

    /**
     * Verschlüsselte Daten durchsuchen
     */
    private function performSearch(string $term): array
    {
        if (strlen($term) < 2) {
            return [];
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        $results = $submissionRepo->searchDecrypted($term, 200);

        $auditRepo->logDsgvo('dsgvo_search', 0, [
            'term'    => $term,
            'results' => count($results),
        ]);

        return $results;
    }

    /**
     * Einreichung + zugehörige Dateien endgültig löschen
     */
    private function performDelete(int $submissionId): array
    {
        if ($submissionId < 1) {
            return ['message' => $this->t('Ungültige ID.'), 'type' => 'error'];
        }

        $submissionRepo = $this->container->get(SubmissionRepository::class);
        $fileRepo       = $this->container->get(FileRepository::class);
        $auditRepo      = $this->container->get(AuditRepository::class);

        // Dateien zuerst löschen
        $fileRepo->deleteBySubmission($submissionId);

        // Einreichung endgültig löschen (kein Soft-Delete!)
        $result = $submissionRepo->permanentDelete($submissionId);

        if ($result) {
            $auditRepo->logDsgvo('dsgvo_permanent_delete', $submissionId);
            return ['message' => $this->t('Einreichung') . ' #' . $submissionId . ' ' . $this->t('endgültig gelöscht.'), 'type' => 'success'];
        }

        return ['message' => $this->t('Fehler beim Löschen.'), 'type' => 'error'];
    }

    /**
     * Nonce prüfen
     */
    private function verifyNonce(): bool
    {
        return !empty($_POST['pp_dsgvo_nonce'])
            && wp_verify_nonce($_POST['pp_dsgvo_nonce'], 'pp_dsgvo_action');
    }
}
