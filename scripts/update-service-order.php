<?php
/**
 * Update Service Reihenfolge
 *
 * Dieses Skript aktualisiert die sort_order aller Services gemäß der neuen Reihenfolge.
 * Aufruf: wp-admin aufrufen und dann diese Datei direkt im Browser öffnen
 * URL: /wp-content/plugins/praxis-portal/update-service-order.php
 */

// WordPress laden
require_once __DIR__ . '/../../../wp-load.php';

if (!current_user_can('manage_options')) {
    die('Keine Berechtigung');
}

global $wpdb;
$table = $wpdb->prefix . 'pp_services';

// Neue Reihenfolge definieren
$order_map = [
    'termin' => 1,
    'terminabsage' => 2,
    'rezept' => 3,
    'ueberweisung' => 4,
    'brillenverordnung' => 5,
    'dokument' => 6,
    'downloads' => 7,
    'anamnesebogen' => 8,
    'notfall' => 9,
];

echo "<h2>Service-Reihenfolge Update</h2>";
echo "<p>Aktualisiere sort_order für alle Services...</p>";
echo "<pre>";

$updated = 0;
$errors = 0;

foreach ($order_map as $service_key => $sort_order) {
    // Update für alle Standorte
    $result = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table} SET sort_order = %d WHERE service_key = %s",
            $sort_order,
            $service_key
        )
    );

    if ($result !== false) {
        $affected = $wpdb->rows_affected;
        echo "✓ {$service_key}: sort_order = {$sort_order} ({$affected} Einträge aktualisiert)\n";
        $updated += $affected;
    } else {
        echo "✗ Fehler bei {$service_key}\n";
        $errors++;
    }
}

echo "\n";
echo "Fertig! {$updated} Services aktualisiert, {$errors} Fehler.\n";
echo "</pre>";

echo "<h3>Aktuelle Services (nach Update):</h3>";
echo "<pre>";

$services = $wpdb->get_results(
    "SELECT id, location_id, service_key, label, sort_order FROM {$table} ORDER BY location_id ASC, sort_order ASC",
    ARRAY_A
);

foreach ($services as $service) {
    printf(
        "ID: %3d | Location: %d | %-20s | %-30s | sort_order: %d\n",
        $service['id'],
        $service['location_id'],
        $service['service_key'],
        $service['label'],
        $service['sort_order']
    );
}

echo "</pre>";
echo "<p><strong>Bitte diese Datei jetzt löschen oder aus dem Webroot verschieben!</strong></p>";
