<?php
/**
 * Widget Step – Service-Auswahl
 *
 * Zeigt verfügbare Services als vertikale Menü-Liste (v3-Stil).
 *
 * @package PraxisPortal\Widget
 * @since   4.0.0
 * @updated 4.2.5 – Menü-Listenansicht
 */

if (!defined('ABSPATH')) exit;

/** @var \PraxisPortal\Widget\WidgetRenderer $renderer */

$services     = $renderer->get('services', []);
$locationUuid = $renderer->get('location_uuid', '');

// Service-Icons (v3-Stil)
$icons = [
    'kontakt'             => '💬',
    'rezept'              => '💊',
    'ueberweisung'        => '📋',
    'brillenverordnung'   => '👓',
    'dokument'            => '📎',
    'termin'              => '📅',
    'terminabsage'        => '❌',
    'notfall'             => '🚨',
    'downloads'           => '⬇️',
    'anamnese'            => '📝',
];
?>

<?php if (empty($services)): ?>
    <p style="color: var(--pp-text-light); text-align: center; padding: 20px 0;">
        <?php echo esc_html($renderer->t('Derzeit sind keine Services verfügbar.')); ?>
    </p>
<?php else: ?>
    <div class="pp-services-grid">
        <?php foreach ($services as $service): ?>
            <?php
            // DB-Spalte: service_key (nicht 'key')
            $key       = $service['service_key'] ?? '';
            $name      = $service['label'] ?? ucfirst($key);
            $desc      = $service['description'] ?? '';
            $icon      = $service['icon'] ?? ($icons[$key] ?? '📄');
            // DB-Spalte: patient_restriction ('all'|'patients_only')
            $restriction = $service['patient_restriction'] ?? 'all';
            $patOnly = (in_array($restriction, ['patients_only', 'patient_only'], true) || !empty($service['is_patient_only'])) ? '1' : '0';
            $extUrl    = $service['external_url'] ?? '';
            ?>
            <div class="pp-service-card"
                 data-service="<?php echo esc_attr($key); ?>"
                 data-location="<?php echo esc_attr($locationUuid); ?>"
                 data-patient-only="<?php echo $patOnly; ?>"
                 <?php if (!empty($extUrl)): ?>data-url="<?php echo esc_url($extUrl); ?>"<?php endif; ?>
                 role="button"
                 tabindex="0"
                 title="<?php echo esc_attr($name); ?>">
                <span class="pp-service-icon"><?php echo esc_html($icon); ?></span>
                <span class="pp-service-name"><?php echo esc_html($name); ?></span>
                <span class="pp-service-chevron">›</span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
