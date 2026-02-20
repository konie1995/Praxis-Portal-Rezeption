<?php
/**
 * Portal Template mit Server-seitiger Authentifizierungsprüfung
 * SICHERHEIT: Portal-Inhalt wird NUR gerendert wenn authentifiziert
 */

if (!defined('ABSPATH')) {
    exit;
}

$is_authenticated = $this->auth->isAuthenticated();
$export_format = get_option('pp_export_format', 'both'); // gdt, pdf, both
?>
<div class="pp-portal-wrapper">

<?php if (!$is_authenticated): ?>
<!-- ============================================ -->
<!-- LOGIN-FORMULAR - NUR wenn NICHT authentifiziert -->
<!-- ============================================ -->
<div id="pp-login-overlay" class="pp-login-overlay">
    <div class="pp-login-container">
        <div class="pp-login-header">
            <svg class="pp-login-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>
            </svg>
            <h1>Praxis-Portal</h1>
            <p><?php echo esc_html(get_option('pp_praxis_name', get_bloginfo('name'))); ?></p>
        </div>
        
        <form id="pp-login-form" class="pp-login-form">
            <div class="pp-form-group">
                <label for="pp-login-username">Benutzername</label>
                <input type="text" id="pp-login-username" name="username" required autocomplete="username">
            </div>
            
            <div class="pp-form-group">
                <label for="pp-login-password">Passwort</label>
                <input type="password" id="pp-login-password" name="password" required autocomplete="current-password">
            </div>
            
            <div id="pp-login-error" class="pp-login-error" style="display:none;"></div>
            
            <button type="submit" class="pp-btn-primary pp-btn-login">
                <span class="btn-text">Anmelden</span>
            </button>
        </form>
        
        <div class="pp-login-footer">
            <small>Verschlüsselte Verbindung • Nur für autorisiertes Personal</small>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ============================================ -->
<!-- PORTAL-HAUPTBEREICH - NUR wenn authentifiziert -->
<!-- ============================================ -->
<div id="pp-portal-main" class="pp-portal-main">
    
    <!-- Header -->
    <header class="pp-portal-header">
        <div class="pp-header-left">
            <h1>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span><?php echo esc_html(get_option('pp_praxis_name', get_bloginfo('name'))); ?></span>
            </h1>
        </div>
        <div class="pp-header-right">
            <span class="pp-user-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                Portal-Benutzer
            </span>
            <button type="button" id="pp-logout-btn" class="pp-btn-logout">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                Abmelden
            </button>
        </div>
    </header>
    
    <!-- Drei-Spalten-Layout -->
    <div class="pp-portal-content">
        
        <!-- Linke Spalte: Kategorien -->
        <aside class="pp-sidebar">
            <div class="pp-sidebar-section">
                <h3>Kategorien</h3>
                <nav class="pp-category-nav">
                    <button type="button" class="pp-category-btn active" data-category="all">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        <span>Alle</span>
                        <span class="pp-count">0</span>
                    </button>
                    <button type="button" class="pp-category-btn" data-category="anamnese">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span>Anamnese</span>
                        <span class="pp-count">0</span>
                    </button>
                    <button type="button" class="pp-category-btn" data-category="rezept">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                        </svg>
                        <span>Rezept</span>
                        <span class="pp-count">0</span>
                    </button>
                    <button type="button" class="pp-category-btn" data-category="ueberweisung">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>Überweisung</span>
                        <span class="pp-count">0</span>
                    </button>
                    <button type="button" class="pp-category-btn" data-category="brillenverordnung">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="6" cy="12" r="4"/><circle cx="18" cy="12" r="4"/><path d="M10 12h4M2 12h2m16 0h2"/>
                        </svg>
                        <span>Brillenverordnung</span>
                        <span class="pp-count">0</span>
                    </button>
                    <button type="button" class="pp-category-btn" data-category="dokument">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <span>Dokument-Upload</span>
                        <span class="pp-count">0</span>
                    </button>
                    <button type="button" class="pp-category-btn" data-category="termin">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>Terminanfrage</span>
                        <span class="pp-count">0</span>
                    </button>
                    <button type="button" class="pp-category-btn" data-category="terminabsage">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            <path d="M9 15l6-6M9 9l6 6"/>
                        </svg>
                        <span>Terminabsage</span>
                        <span class="pp-count">0</span>
                    </button>
                </nav>
            </div>
            
            <div class="pp-sidebar-section">
                <h3>Filter</h3>
                <nav class="pp-filter-nav">
                    <button type="button" class="pp-filter-btn active" data-filter="all">
                        Alle anzeigen
                    </button>
                    <button type="button" class="pp-filter-btn" data-filter="unread">
                        <span class="pp-badge-unread"></span>
                        Ungelesen
                        <span class="pp-count" id="pp-unread-count">0</span>
                    </button>
                </nav>
            </div>
            
            <!-- Standort-Filter (nur bei Multi-Standort) -->
            <div class="pp-sidebar-section pp-location-filter" id="pp-location-filter-section" style="display: none;">
                <h3>Standort</h3>
                <select id="pp-location-filter" class="pp-location-select">
                    <option value="0">Alle Standorte</option>
                </select>
            </div>
        </aside>
        
        <!-- Mittlere Spalte: Liste -->
        <main class="pp-list-panel">
            <div class="pp-list-header">
                <div class="pp-search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="search" id="pp-search-input" placeholder="Suchen...">
                </div>
                <button type="button" id="pp-refresh-btn" class="pp-btn-icon" title="Aktualisieren">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </div>
            
            <!-- Loading State -->
            <div class="pp-list-loading" style="display: flex;">
                <svg class="pp-spinner-large" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" stroke-dasharray="30 70"/>
                </svg>
            </div>
            
            <!-- Empty State -->
            <div class="pp-list-empty" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p>Keine Einträge gefunden.</p>
            </div>
            
            <!-- Table -->
            <div class="pp-list-table-wrapper" style="display: none;">
                <table class="pp-list-table">
                    <thead>
                        <tr>
                            <th class="col-date">Datum</th>
                            <th class="col-patient">Patient</th>
                            <th class="col-type">Typ</th>
                            <th class="col-location pp-multi-location-only" style="display:none;">Standort</th>
                        </tr>
                    </thead>
                    <tbody id="pp-list-body">
                    </tbody>
                </table>
            </div>
            
            <div id="pp-pagination" class="pp-pagination"></div>
        </main>
        
        <!-- Rechte Spalte: Detailansicht -->
        <aside class="pp-preview-panel">
            <!-- Empty State -->
            <div class="pp-preview-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p>Eintrag auswählen</p>
            </div>
            
            <!-- Loading State -->
            <div class="pp-preview-loading" style="display:none;">
                <svg class="pp-spinner-large" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" stroke-dasharray="30 70"/>
                </svg>
            </div>
            
            <!-- Content -->
            <div class="pp-preview-content" style="display:none;">
                <div class="pp-preview-header">
                    <div class="pp-preview-title">
                        <span class="pp-type-badge"></span>
                        <h2></h2>
                    </div>
                    <div class="pp-preview-meta"></div>
                </div>
                
                <div class="pp-preview-tabs">
                    <button type="button" class="pp-tab-btn active" data-tab="details">Details</button>
                    <button type="button" class="pp-tab-btn" data-tab="files">Dateien</button>
                    <button type="button" class="pp-tab-btn" data-tab="response">Antwort</button>
                </div>
                
                <div class="pp-preview-body">
                    <!-- Details Tab -->
                    <div id="pp-tab-details" class="pp-tab-content active">
                        <div id="pp-details-content"></div>
                    </div>
                    
                    <!-- Dateien Tab -->
                    <div id="pp-tab-files" class="pp-tab-content">
                        <div id="pp-files-content"></div>
                    </div>
                    
                    <!-- Antwort Tab -->
                    <div id="pp-tab-response" class="pp-tab-content">
                        <div class="pp-response-section">
                            <h4>Schnellantwort senden</h4>
                            
                            <div class="pp-response-buttons">
                                <button type="button" class="pp-response-btn pp-response-ready" data-response="ready">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Zur Abholung bereit
                                </button>
                                <button type="button" class="pp-response-btn pp-response-insurance-card" data-response="insurance_card">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="2" y="5" width="20" height="14" rx="2"/>
                                        <path d="M2 10h20"/>
                                        <path d="M7 15h4"/>
                                    </svg>
                                    Versichertenkarte einreichen
                                </button>
                                <button type="button" class="pp-response-btn pp-response-sent" data-response="sent">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    Per Post versandt
                                </button>
                                <button type="button" class="pp-response-btn pp-response-appointment" data-response="appointment">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    Termin erforderlich
                                </button>
                                <button type="button" class="pp-response-btn pp-response-info" data-response="need_info">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Rückfrage
                                </button>
                                <button type="button" class="pp-response-btn pp-response-rejected" data-response="rejected">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    Ablehnen
                                </button>
                            </div>
                            
                            <div class="pp-response-text-wrapper" style="display:none;">
                                <label for="pp-response-text">Nachricht an Patient:</label>
                                <textarea id="pp-response-text" rows="4" placeholder="Ihre Nachricht..."></textarea>
                            </div>
                            
                            <button type="button" id="pp-send-response" class="pp-btn-primary" disabled>
                                ✓ Antwort senden
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="pp-preview-footer">
                    <?php if ($export_format === 'gdt' || $export_format === 'both'): ?>
                    <button type="button" id="pp-export-bdt" class="pp-btn-secondary" title="GDT Export für Praxissoftware">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        GDT Export
                    </button>
                    <?php endif; ?>
                    <?php if ($export_format === 'pdf' || $export_format === 'both'): ?>
                    <button type="button" id="pp-export-pdf" class="pp-btn-secondary" title="PDF herunterladen">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10,9 9,9 8,9"/>
                        </svg>
                        PDF Download
                    </button>
                    <?php endif; ?>
                    <button type="button" id="pp-delete-btn" class="pp-btn-danger">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Löschen
                    </button>
                </div>
            </div>
        </aside>
        
    </div>
</div>

<!-- Response Modal -->
<div id="pp-response-modal" class="pp-modal-overlay" style="display:none;">
    <div class="pp-modal">
        <div class="pp-modal-header">
            <h3 id="pp-response-modal-title">Antwort senden</h3>
            <button type="button" class="pp-modal-close">&times;</button>
        </div>
        <div class="pp-modal-body">
            <p id="pp-response-modal-message"></p>
            <div id="pp-modal-text-wrapper" style="display:none;">
                <label for="pp-response-modal-text">Nachricht:</label>
                <textarea id="pp-response-modal-text" rows="4"></textarea>
            </div>
        </div>
        <div class="pp-modal-footer">
            <button type="button" class="pp-btn-secondary pp-modal-cancel">Abbrechen</button>
            <button type="button" id="pp-response-modal-confirm" class="pp-btn-primary">Senden</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="pp-delete-modal" class="pp-modal-overlay" style="display:none;">
    <div class="pp-modal pp-modal-danger">
        <div class="pp-modal-header">
            <h3>Eintrag löschen?</h3>
            <button type="button" class="pp-modal-close">&times;</button>
        </div>
        <div class="pp-modal-body">
            <p>Möchten Sie diesen Eintrag wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.</p>
        </div>
        <div class="pp-modal-footer">
            <button type="button" class="pp-btn-secondary pp-modal-cancel">Abbrechen</button>
            <button type="button" id="pp-delete-modal-confirm" class="pp-btn-danger">Löschen</button>
        </div>
    </div>
</div>

<!-- Image Preview Modal -->
<div id="pp-image-modal" class="pp-modal-overlay" style="display:none;">
    <div class="pp-modal pp-modal-image">
        <div class="pp-modal-header">
            <h3 id="pp-image-modal-title">Bildvorschau</h3>
            <button type="button" class="pp-modal-close">&times;</button>
        </div>
        <div class="pp-modal-body">
            <div class="pp-image-container">
                <img id="pp-image-preview" src="" alt="Vorschau">
            </div>
        </div>
        <div class="pp-modal-footer">
            <button type="button" class="pp-btn-secondary pp-modal-cancel">Schließen</button>
            <button type="button" id="pp-image-download" class="pp-btn-primary">Herunterladen</button>
        </div>
    </div>
</div>

<?php endif; ?>

</div>
