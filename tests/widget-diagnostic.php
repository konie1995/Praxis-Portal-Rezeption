<?php
/**
 * Widget-Diagnose-Tool
 * 
 * Aufruf: /wp-admin/admin.php?page=pp-widget-diagnostic
 * Zeigt alle Checkpoints der Widget-Rendering-Kette.
 * 
 * @since 4.2.8
 */

// Sicherheit
if (!defined('ABSPATH')) {
    exit;
}

// Nur für Admins
add_action('admin_menu', function () {
    add_submenu_page(
        null, // Versteckt im Menü
        'Widget-Diagnose',
        'Widget-Diagnose',
        'manage_options',
        'pp-widget-diagnostic',
        'pp_render_widget_diagnostic'
    );
});

function pp_render_widget_diagnostic(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }

    $results = [];
    $fatal   = false;

    // ── 1. Plugin geladen? ──
    $pluginActive = defined('PP_VERSION');
    $results[] = [
        'check'  => 'Plugin geladen',
        'status' => $pluginActive,
        'detail' => $pluginActive ? 'PP_VERSION = ' . PP_VERSION : 'PP_VERSION nicht definiert',
    ];
    if (!$pluginActive) { $fatal = true; }

    // ── 2. Widget-Status (Option) ──
    $widgetStatus = get_option('pp_widget_status', '(nicht gesetzt)');
    $statusOk     = ($widgetStatus === 'active');
    $results[] = [
        'check'  => 'pp_widget_status',
        'status' => $statusOk,
        'detail' => "Wert: <code>{$widgetStatus}</code>" . (!$statusOk && $widgetStatus !== 'vacation'
            ? ' — <strong>Widget ist deaktiviert!</strong> Muss "active" oder "vacation" sein.'
            : ($widgetStatus === 'vacation' ? ' — Urlaubsmodus aktiv (Widget zeigt nur Urlaubs-Nachricht)' : '')),
    ];
    if ($widgetStatus === 'disabled' || $widgetStatus === '(nicht gesetzt)') { $fatal = true; }

    // ── 3. Widget-Seiten ──
    $widgetPages = get_option('pp_widget_pages', '(nicht gesetzt)');
    $pagesOk     = ($widgetPages === 'all');
    $results[] = [
        'check'  => 'pp_widget_pages',
        'status' => $pagesOk || $widgetPages !== 'none',
        'detail' => "Wert: <code>{$widgetPages}</code>" . ($widgetPages === 'none'
            ? ' — <strong>Widget auf KEINER Seite aktiv!</strong>'
            : ($widgetPages === 'all' ? ' — Auf allen Seiten aktiv ✓' : ' — Nur auf bestimmten Seiten (IDs: ' . $widgetPages . ')')),
    ];

    // ── 4. Container / DI ──
    try {
        $container = \PraxisPortal\Core\Plugin::getInstance()->getContainer();
        $results[] = [
            'check'  => 'DI-Container',
            'status' => true,
            'detail' => 'Container verfügbar',
        ];
    } catch (\Throwable $e) {
        $results[] = [
            'check'  => 'DI-Container',
            'status' => false,
            'detail' => 'FEHLER: ' . esc_html($e->getMessage()),
        ];
        $fatal = true;
    }

    // ── 5. LocationContext ──
    if (!$fatal) {
        try {
            $context = $container->get(\PraxisPortal\Location\LocationContext::class);
            $locId   = $context->getLocationId();
            $locUuid = $context->getLocationUuid();
            $via     = $context->getResolvedVia();
            $results[] = [
                'check'  => 'LocationContext',
                'status' => $locId > 0,
                'detail' => "Location-ID: <code>{$locId}</code>, UUID: <code>" . ($locUuid ?: 'null') . "</code>, aufgelöst via: <code>{$via}</code>"
                    . ($locId === 0 ? ' — <strong>Kein Standort aufgelöst!</strong>' : ''),
            ];
        } catch (\Throwable $e) {
            $results[] = [
                'check'  => 'LocationContext',
                'status' => false,
                'detail' => 'FEHLER: ' . esc_html($e->getMessage()),
            ];
        }
    }

    // ── 6. Standorte in DB ──
    if (!$fatal) {
        try {
            $locMgr    = $container->get(\PraxisPortal\Location\LocationManager::class);
            $active    = $locMgr->getActive();
            $allCount  = count($locMgr->getAll());
            $actCount  = count($active);
            $isMulti   = $actCount > 1;

            $results[] = [
                'check'  => 'Standorte (DB)',
                'status' => $actCount > 0,
                'detail' => "<code>{$actCount}</code> aktiv von <code>{$allCount}</code> gesamt"
                    . ($actCount === 0 ? ' — <strong>Kein aktiver Standort!</strong>' : '')
                    . ($isMulti ? " — Multi-Standort-Modus" : " — Einzel-Standort"),
            ];

            // Detail pro Standort
            foreach ($active as $loc) {
                $name  = $loc['name'] ?? '(kein Name)';
                $pName = $loc['practice_name'] ?? '';
                $email = $loc['email_notification'] ?? '';
                $uuid  = $loc['uuid'] ?? '';
                $hasName  = !empty($pName);
                $hasEmail = !empty($email) && is_email($email);

                $results[] = [
                    'check'  => '&nbsp;&nbsp;↳ Standort: ' . esc_html($name),
                    'status' => $hasName && $hasEmail,
                    'detail' => 'Praxisname: ' . ($hasName ? '✅ ' . esc_html($pName) : '❌ <strong>leer</strong>')
                        . ' | E-Mail: ' . ($hasEmail ? '✅ ' . esc_html($email) : '❌ <strong>leer/ungültig</strong>')
                        . ' | UUID: <code>' . esc_html($uuid) . '</code>',
                ];
            }
        } catch (\Throwable $e) {
            $results[] = [
                'check'  => 'Standorte (DB)',
                'status' => false,
                'detail' => 'FEHLER: ' . esc_html($e->getMessage()),
            ];
        }
    }

    // ── 7. Services ──
    if (!$fatal && isset($context) && $context->getLocationId() > 0) {
        try {
            $serviceMgr = $container->get(\PraxisPortal\Location\ServiceManager::class);
            $services   = $serviceMgr->getActiveServices($context->getLocationId());
            $svcCount   = count($services);

            $results[] = [
                'check'  => 'Services (Standort ' . $context->getLocationId() . ')',
                'status' => $svcCount > 0,
                'detail' => "<code>{$svcCount}</code> aktive Services"
                    . ($svcCount === 0 ? ' — <strong>Keine Services konfiguriert!</strong> (Default-Services werden trotzdem geladen)' : ''),
            ];
        } catch (\Throwable $e) {
            $results[] = [
                'check'  => 'Services',
                'status' => false,
                'detail' => 'FEHLER: ' . esc_html($e->getMessage()),
            ];
        }
    }

    // ── 8. Widget-Klasse ──
    if (!$fatal) {
        try {
            $widget = $container->get(\PraxisPortal\Widget\Widget::class);
            $results[] = [
                'check'  => 'Widget-Klasse',
                'status' => $widget !== null,
                'detail' => 'Widget-Instanz vorhanden ✓',
            ];
        } catch (\Throwable $e) {
            $results[] = [
                'check'  => 'Widget-Klasse',
                'status' => false,
                'detail' => '<strong>FEHLER:</strong> ' . esc_html($e->getMessage()),
            ];
            $fatal = true;
        }
    }

    // ── 9. Lizenz / FeatureGate ──
    if (!$fatal) {
        try {
            $gate = $container->get(\PraxisPortal\License\FeatureGate::class);
            $plan = $gate->getPlan();
            $results[] = [
                'check'  => 'Lizenz / FeatureGate',
                'status' => true,
                'detail' => "Plan: <code>{$plan}</code>",
            ];
        } catch (\Throwable $e) {
            $results[] = [
                'check'  => 'Lizenz',
                'status' => false,
                'detail' => 'FEHLER: ' . esc_html($e->getMessage()),
            ];
        }
    }

    // ── 10. Template-Dateien ──
    $tplDir   = PP_PLUGIN_DIR . 'templates/widget/';
    $tplOk    = file_exists($tplDir . 'main.php');
    $results[] = [
        'check'  => 'Templates',
        'status' => $tplOk,
        'detail' => 'main.php: ' . ($tplOk ? '✅' : '❌ FEHLT')
            . ' | vacation.php: ' . (file_exists($tplDir . 'vacation.php') ? '✅' : '❌')
            . ' | Pfad: <code>' . esc_html($tplDir) . '</code>',
    ];

    // ── 11. Asset-Dateien ──
    $cssOk = file_exists(PP_PLUGIN_DIR . 'assets/css/widget.css');
    $jsOk  = file_exists(PP_PLUGIN_DIR . 'assets/js/widget.js');
    $results[] = [
        'check'  => 'Assets (CSS/JS)',
        'status' => $cssOk && $jsOk,
        'detail' => 'widget.css: ' . ($cssOk ? '✅' : '❌ FEHLT')
            . ' | widget.js: ' . ($jsOk ? '✅' : '❌ FEHLT')
            . ' | PP_PLUGIN_URL: <code>' . esc_html(PP_PLUGIN_URL) . '</code>',
    ];

    // ── 12. wp_footer Hook registriert? ──
    global $wp_filter;
    $footerHooked = false;
    if (isset($wp_filter['wp_footer'])) {
        foreach ($wp_filter['wp_footer']->callbacks as $priority => $hooks) {
            foreach ($hooks as $hook) {
                if (is_array($hook['function'] ?? null)) {
                    $obj = $hook['function'][0] ?? null;
                    $method = $hook['function'][1] ?? '';
                    if ($obj instanceof \PraxisPortal\Widget\Widget && $method === 'renderWidget') {
                        $footerHooked = true;
                        break 2;
                    }
                }
            }
        }
    }
    $results[] = [
        'check'  => 'wp_footer Hook (renderWidget)',
        'status' => $footerHooked,
        'detail' => $footerHooked
            ? 'Widget::renderWidget() ist am wp_footer-Hook registriert ✓'
            : '<strong>NICHT registriert!</strong> Widget::register() wurde nicht aufgerufen.',
    ];

    // ── 13. wp_enqueue_scripts Hook ──
    $enqueueHooked = false;
    if (isset($wp_filter['wp_enqueue_scripts'])) {
        foreach ($wp_filter['wp_enqueue_scripts']->callbacks as $priority => $hooks) {
            foreach ($hooks as $hook) {
                if (is_array($hook['function'] ?? null)) {
                    $obj = $hook['function'][0] ?? null;
                    $method = $hook['function'][1] ?? '';
                    if ($obj instanceof \PraxisPortal\Widget\Widget && $method === 'enqueueAssets') {
                        $enqueueHooked = true;
                        break 2;
                    }
                }
            }
        }
    }
    $results[] = [
        'check'  => 'wp_enqueue_scripts Hook (enqueueAssets)',
        'status' => $enqueueHooked,
        'detail' => $enqueueHooked
            ? 'Widget::enqueueAssets() ist registriert ✓'
            : '<strong>NICHT registriert!</strong> Widget::register() wurde nicht aufgerufen.',
    ];

    // ── 14. isLocationConfigured Simulation ──
    if (!$fatal && isset($widget)) {
        try {
            $settings = $widget->getLocationSettings();
            $services = $widget->getLocationServices();

            // Fallbacks wie in renderWidget
            if (empty($settings['practice_name'])) {
                $settings['practice_name'] = get_bloginfo('name') ?: 'Praxis';
            }
            if (empty($settings['email_notification']) || !is_email($settings['email_notification'] ?? '')) {
                $settings['email_notification'] = get_option('admin_email');
            }

            $configured = !empty($settings['practice_name'])
                && !empty($settings['email_notification'])
                && is_email($settings['email_notification'])
                && !empty($services);

            $results[] = [
                'check'  => 'isLocationConfigured (simuliert)',
                'status' => $configured,
                'detail' => 'practice_name: <code>' . esc_html($settings['practice_name'] ?? '') . '</code>'
                    . ' | email: <code>' . esc_html($settings['email_notification'] ?? '') . '</code>'
                    . ' | Services: <code>' . count($services) . '</code>'
                    . (!$configured ? ' — <strong>Widget wird nicht angezeigt!</strong>' : ''),
            ];
        } catch (\Throwable $e) {
            $results[] = [
                'check'  => 'isLocationConfigured',
                'status' => false,
                'detail' => 'FEHLER: ' . esc_html($e->getMessage()),
            ];
        }
    }

    // ── 15. PHP-Fehler im Error-Log ──
    $errorLog = ini_get('error_log');
    $recentErrors = '';
    if ($errorLog && file_exists($errorLog)) {
        $lines = file($errorLog);
        $ppErrors = array_filter(array_slice($lines, -50), fn($l) => stripos($l, 'PraxisPortal') !== false || stripos($l, 'pp_') !== false);
        $recentErrors = implode('', array_slice($ppErrors, -5));
    }
    $results[] = [
        'check'  => 'PHP-Fehlerlog (letzte PP-Fehler)',
        'status' => empty($recentErrors),
        'detail' => empty($recentErrors) ? 'Keine PP-Fehler gefunden' : '<pre style="font-size:11px;max-height:150px;overflow:auto;background:#fff3f3;padding:8px;">' . esc_html($recentErrors) . '</pre>',
    ];

    // ── OUTPUT ──
    ?>
    <div class="wrap">
        <h1>🔍 Widget-Diagnose <small style="font-size:14px;color:#666;">Praxis-Portal v<?php echo PP_VERSION; ?></small></h1>
        <p>Prüft alle Bedingungen, die erfüllt sein müssen, damit das Widget im Frontend angezeigt wird.</p>

        <table class="widefat fixed" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:30%;">Prüfung</th>
                    <th style="width:6%;">Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                <tr style="background: <?php echo $r['status'] ? '#f0fdf4' : '#fef2f2'; ?>;">
                    <td><strong><?php echo $r['check']; ?></strong></td>
                    <td style="text-align:center; font-size:18px;"><?php echo $r['status'] ? '✅' : '❌'; ?></td>
                    <td><?php echo $r['detail']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px; padding:15px; background:#f0f6fc; border:1px solid #c3c4c7; border-radius:4px; max-width:900px;">
            <h3 style="margin-top:0;">💡 Häufige Ursachen für "Widget wird nicht angezeigt"</h3>
            <ol>
                <li><strong>pp_widget_status</strong> steht auf "disabled" → Unter Einstellungen → Widget auf "active" setzen</li>
                <li><strong>Kein Standort konfiguriert</strong> → Unter Standorte mindestens einen Standort mit Praxisname + E-Mail anlegen</li>
                <li><strong>pp_widget_pages</strong> steht auf "none" → Widget ist auf keiner Seite aktiv</li>
                <li><strong>Theme ruft wp_footer() nicht auf</strong> → Im Theme footer.php prüfen ob <code>&lt;?php wp_footer(); ?&gt;</code> vorhanden ist</li>
                <li><strong>JavaScript-Fehler</strong> → Browser-Konsole (F12) auf rote Fehler prüfen</li>
                <li><strong>CSS-Konflikt</strong> → Theme überschreibt Widget-Stile mit <code>display:none</code></li>
            </ol>
        </div>
    </div>
    <?php
}
