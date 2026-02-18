<?php
/**
 * Widget – Downloads
 *
 * Zeigt öffentliche Praxis-Dokumente zum Download an.
 * Multistandort: Zeigt nur Dokumente des aktuellen Standorts.
 *
 * @package PraxisPortal\Widget
 * @since   4.2.9
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */
/** @var \PraxisPortal\Widget\Widget $widget */

use PraxisPortal\Database\Repository\DocumentRepository;

// Dokumente für aktuellen Standort laden
$locationId = (int) $renderer->get('location_id', 0);
$documents  = [];

try {
    $docRepo   = $widget->getContainer()->get(DocumentRepository::class);
    $documents = $docRepo->getActiveByLocation($locationId);
} catch (\Throwable $e) {
    // Repository nicht verfügbar → leere Liste
}
?>

<div class="pp-service-form" data-service="downloads" style="padding: 0;">

    <h4 style="margin: 0 0 6px; font-size: var(--pp-text-lg); font-weight: 600;">📥 <?php echo esc_html($renderer->t('Downloads')); ?></h4>
    <p style="color: var(--pp-text); font-size: var(--pp-text-base); margin: 0 0 20px; line-height: 1.5;">
        <?php echo esc_html($renderer->t('Dokumente und Formulare zum Herunterladen.')); ?>
    </p>

    <?php if (empty($documents)): ?>

        <div style="text-align: center; padding: 24px 16px; color: var(--pp-text-light);">
            <span style="font-size: 32px; display: block; margin-bottom: 8px;">📂</span>
            <?php echo esc_html($renderer->t('Aktuell keine Downloads verfügbar.')); ?>
        </div>

    <?php else: ?>

        <div class="pp-downloads-list" style="display: flex; flex-direction: column; gap: 8px;">
            <?php foreach ($documents as $doc):
                $fileSize = DocumentRepository::formatFileSize((int) ($doc['file_size'] ?? 0));
                $icon     = DocumentRepository::getMimeIcon($doc['mime_type'] ?? '');
                $downloadUrl = add_query_arg([
                    'pp_download' => (int) $doc['id'],
                    'pp_nonce'    => wp_create_nonce('pp_download_' . $doc['id']),
                ], home_url('/'));
            ?>
                <a href="<?php echo esc_url($downloadUrl); ?>"
                   class="pp-download-item"
                   target="_blank"
                   rel="noopener"
                   style="display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: inherit; transition: border-color 0.15s, background 0.15s;">

                    <span style="font-size: 24px; flex-shrink: 0;"><?php echo esc_html($icon); ?></span>

                    <span style="flex: 1; min-width: 0;">
                        <span style="display: block; font-weight: 500; font-size: 14px; color: var(--pp-primary, #2563eb);">
                            <?php echo esc_html($doc['title']); ?>
                        </span>
                        <?php if (!empty($doc['description'])): ?>
                            <span style="display: block; font-size: 12px; color: var(--pp-text-light, #64748b); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo esc_html($doc['description']); ?>
                            </span>
                        <?php endif; ?>
                        <span style="display: block; font-size: 11px; color: #94a3b8; margin-top: 2px;">
                            <?php echo esc_html($fileSize); ?>
                        </span>
                    </span>

                    <span style="flex-shrink: 0; color: var(--pp-primary, #2563eb); font-size: 18px;">⬇</span>
                </a>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<style>
    .pp-download-item:hover {
        border-color: var(--pp-primary, #2563eb) !important;
        background: #f8fafc !important;
    }
</style>
