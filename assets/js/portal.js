/**
 * Praxis-Portal v4 – Portal JavaScript (v3 UI Design)
 *
 * Handles login, three-column layout, submissions list, preview, and responses
 * Adapted for v4 AJAX endpoints with portal_action dispatcher
 *
 * @package PraxisPortal
 * @since   4.2.0
 */

(function() {
    'use strict';

    // State
    const state = {
        currentCategory: 'all',
        currentStatus: 'all',
        currentLocation: 0, // 0 = Alle Standorte
        currentSubmission: null,
        submissions: [],
        searchQuery: '',
        isLoading: false,
        fileToken: null,
        fileTokenExpiry: null,
        permissions: {
            can_view: true,
            can_edit: true,
            can_delete: true,
            can_export: true,
            location_id: null
        }
    };

    // DOM Elements
    let elements = {};

    // Initialize
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        cacheElements();
        bindEvents();
        initLocationFilter();

        if (pp_portal.is_authenticated) {
            // Hole Datei-Token für Bild-Vorschauen
            refreshFileToken().then(() => {
                loadSubmissions();
            });
        }
    }

    /**
     * Initialisiert den Standort-Filter (Multi-Standort)
     */
    function initLocationFilter() {
        if (!pp_portal.multi_location || !pp_portal.locations || pp_portal.locations.length <= 1) {
            return;
        }

        const section = document.getElementById('pp-location-filter-section');
        const select = document.getElementById('pp-location-filter');

        if (!section || !select) return;

        // Event-Listener
        select.addEventListener('change', (e) => {
            state.currentLocation = parseInt(e.target.value, 10);
            loadSubmissions();
        });

        // Prüfen ob User nur einen Standort hat
        if (pp_portal.user_location_id && pp_portal.user_location_id > 0) {
            state.currentLocation = pp_portal.user_location_id;
            select.value = pp_portal.user_location_id;
            select.disabled = true; // User kann nicht wechseln
        }
    }

    /**
     * Location-Filter Sichtbarkeit aktualisieren
     */
    function updateLocationFilterVisibility() {
        const section = document.getElementById('pp-location-filter-section');
        const select = document.getElementById('pp-location-filter');

        if (!section || !select) return;

        if (state.permissions.location_id && state.permissions.location_id > 0) {
            // User hat gebundenen Standort - Filter deaktivieren
            select.value = state.permissions.location_id;
            select.disabled = true;
        } else {
            // User kann alle Standorte sehen
            select.disabled = false;
        }
    }

    /**
     * Holt einen neuen Datei-Zugriffs-Token
     */
    async function refreshFileToken() {
        try {
            const response = await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'pp_portal_action',
                    portal_action: 'get_file_token',
                    nonce: pp_portal.nonce
                })
            });

            const result = await response.json();
            if (result.success && result.data.token) {
                state.fileToken = result.data.token;
                state.fileTokenExpiry = Date.now() + 240000; // 4 Minuten (vor Ablauf erneuern)
            }
        } catch (error) {
            console.error('Token-Fehler:', error);
        }
    }

    /**
     * Prüft ob Token gültig ist, erneuert wenn nötig
     */
    async function ensureValidToken() {
        if (!state.fileToken || Date.now() > state.fileTokenExpiry) {
            await refreshFileToken();
        }
        return state.fileToken;
    }

    function cacheElements() {
        elements = {
            // Login
            loginOverlay: document.getElementById('pp-login-overlay'),
            loginForm: document.getElementById('pp-login-form'),
            loginError: document.getElementById('pp-login-error'),
            loginBtn: document.querySelector('.pp-btn-login'),

            // Header
            logoutBtn: document.getElementById('pp-logout-btn'),

            // Sidebar
            categoryBtns: document.querySelectorAll('.pp-category-btn'),
            filterBtns: document.querySelectorAll('.pp-filter-btn'),

            // List
            searchInput: document.getElementById('pp-search-input'),
            refreshBtn: document.getElementById('pp-refresh-btn'),
            listTableWrapper: document.querySelector('.pp-list-table-wrapper'),
            listTableBody: document.getElementById('pp-list-body'),
            listEmpty: document.querySelector('.pp-list-empty'),
            listLoading: document.querySelector('.pp-list-loading'),

            // Preview
            previewEmpty: document.querySelector('.pp-preview-empty'),
            previewLoading: document.querySelector('.pp-preview-loading'),
            previewContent: document.querySelector('.pp-preview-content'),
            previewTitle: document.querySelector('.pp-preview-title h2'),
            previewTypeBadge: document.querySelector('.pp-type-badge'),
            previewMeta: document.querySelector('.pp-preview-meta'),
            tabBtns: document.querySelectorAll('.pp-tab-btn'),
            tabContents: document.querySelectorAll('.pp-tab-content'),
            detailsContent: document.getElementById('pp-details-content'),
            filesContent: document.getElementById('pp-files-content'),
            responseBtns: document.querySelectorAll('.pp-response-btn'),
            responseText: document.getElementById('pp-response-text'),
            responseTextWrapper: document.querySelector('.pp-response-text-wrapper'),
            sendResponseBtn: document.getElementById('pp-send-response'),
            exportBdtBtn: document.getElementById('pp-export-bdt'),
            exportPdfBtn: document.getElementById('pp-export-pdf'),
            deleteBtn: document.getElementById('pp-delete-btn'),

            // Modals
            responseModal: document.getElementById('pp-response-modal'),
            responseModalTitle: document.getElementById('pp-response-modal-title'),
            responseModalText: document.getElementById('pp-response-modal-text'),
            responseModalConfirm: document.getElementById('pp-response-modal-confirm'),
            deleteModal: document.getElementById('pp-delete-modal'),
            deleteModalConfirm: document.getElementById('pp-delete-modal-confirm')
        };
    }

    function bindEvents() {
        // Login
        if (elements.loginForm) {
            elements.loginForm.addEventListener('submit', handleLogin);
        }

        // Logout
        if (elements.logoutBtn) {
            elements.logoutBtn.addEventListener('click', handleLogout);
        }

        // Categories
        elements.categoryBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                setActiveCategory(btn.dataset.category);
            });
        });

        // Filters
        elements.filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                setActiveFilter(btn.dataset.filter);
            });
        });

        // Search
        if (elements.searchInput) {
            let searchTimeout;
            elements.searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    state.searchQuery = e.target.value;
                    loadSubmissions();
                }, 300);
            });
        }

        // Refresh
        if (elements.refreshBtn) {
            elements.refreshBtn.addEventListener('click', () => {
                loadSubmissions();
            });
        }

        // Tabs
        elements.tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                setActiveTab(btn.dataset.tab);
            });
        });

        // Response buttons
        elements.responseBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                selectResponse(btn.dataset.response);
            });
        });

        // Send response
        if (elements.sendResponseBtn) {
            elements.sendResponseBtn.addEventListener('click', showResponseModal);
        }

        // Export BDT
        if (elements.exportBdtBtn) {
            elements.exportBdtBtn.addEventListener('click', exportBdt);
        }

        // Export PDF
        if (elements.exportPdfBtn) {
            elements.exportPdfBtn.addEventListener('click', exportPdf);
        }

        // Delete
        if (elements.deleteBtn) {
            elements.deleteBtn.addEventListener('click', showDeleteModal);
        }

        // Modal events
        document.querySelectorAll('.pp-modal-close, .pp-modal-cancel').forEach(btn => {
            btn.addEventListener('click', closeModals);
        });

        if (elements.responseModalConfirm) {
            elements.responseModalConfirm.addEventListener('click', confirmResponse);
        }

        if (elements.deleteModalConfirm) {
            elements.deleteModalConfirm.addEventListener('click', confirmDelete);
        }

        // Close modals on overlay click
        document.querySelectorAll('.pp-modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    closeModals();
                }
            });
        });

        // Event delegation für Anamnese-Download-Buttons
        if (elements.detailsContent) {
            elements.detailsContent.addEventListener('click', (e) => {
                const btn = e.target.closest('.pp-download-btn');
                if (!btn) return;

                const id = btn.dataset.id;
                if (btn.classList.contains('pp-download-bdt')) {
                    downloadAnamneseBdt(id);
                } else if (btn.classList.contains('pp-download-medplan')) {
                    downloadMedplan(id);
                } else if (btn.classList.contains('pp-download-pdf')) {
                    downloadAnamnesePdf(id);
                } else if (btn.classList.contains('pp-download-fhir')) {
                    downloadAnamneseFhir(id);
                }
            });

            // Status-Change Handler
            elements.detailsContent.addEventListener('change', (e) => {
                if (e.target.id === 'pp-status-select') {
                    changeStatus(e.target.dataset.id, e.target.value);
                }
            });
        }
    }

    // ==========================================
    // Login/Logout
    // ==========================================

    async function handleLogin(e) {
        e.preventDefault();

        const username = document.getElementById('pp-login-username').value;
        const password = document.getElementById('pp-login-password').value;

        if (!username || !password) {
            showLoginError('Bitte Benutzername und Passwort eingeben.');
            return;
        }

        setLoginLoading(true);

        try {
            const response = await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'pp_portal_login',
                    nonce: pp_portal.nonce,
                    username: username,
                    password: password
                })
            });

            const data = await response.json();

            if (data.success) {
                // Page-Reload damit Server das Portal rendert
                window.location.reload();
            } else {
                showLoginError(getErrorMessage(data.data, 'Login fehlgeschlagen.'));
                setLoginLoading(false);
            }
        } catch (error) {
            showLoginError('Verbindungsfehler. Bitte versuchen Sie es erneut.');
            setLoginLoading(false);
        }
    }

    async function handleLogout() {
        try {
            await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'pp_portal_action',
                    portal_action: 'logout',
                    nonce: pp_portal.nonce
                })
            });
        } catch (error) {
            // Ignore errors
        }

        window.location.reload();
    }

    function showLoginError(message) {
        if (elements.loginError) {
            elements.loginError.textContent = message;
            elements.loginError.style.display = 'block';
        }
    }

    function setLoginLoading(loading) {
        if (elements.loginBtn) {
            elements.loginBtn.disabled = loading;
            const btnText = elements.loginBtn.querySelector('.btn-text');
            if (btnText) {
                btnText.textContent = loading ? 'Wird angemeldet...' : 'Anmelden';
            }
        }
    }

    // ==========================================
    // Categories & Filters
    // ==========================================

    function setActiveCategory(category) {
        state.currentCategory = category;

        elements.categoryBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.category === category);
        });

        loadSubmissions();
    }

    function setActiveFilter(filter) {
        state.currentStatus = filter;

        elements.filterBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.filter === filter);
        });

        loadSubmissions();
    }

    // ==========================================
    // Load Submissions
    // ==========================================

    async function loadSubmissions() {
        if (state.isLoading) return;
        state.isLoading = true;

        showListLoading();

        try {
            const params = new URLSearchParams({
                action: 'pp_portal_action',
                portal_action: 'get_submissions',
                nonce: pp_portal.nonce,
                type: state.currentCategory,
                status: state.currentStatus,
                search: state.searchQuery,
                location_id: state.currentLocation
            });

            const response = await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: params
            });

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON Parse Error:', e);
                console.error('Response Text:', text);
                showListEmpty('Server-Fehler: Keine gültige JSON-Antwort. Siehe Console für Details.');
                state.isLoading = false;
                return;
            }

            if (data.success) {
                state.submissions = data.data.submissions;

                // Berechtigungen speichern
                if (data.data.permissions) {
                    state.permissions = data.data.permissions;
                    // Location-Filter einschränken wenn User nur einen Standort hat
                    if (state.permissions.location_id && state.permissions.location_id > 0) {
                        state.currentLocation = state.permissions.location_id;
                        updateLocationFilterVisibility();
                    }
                }

                renderSubmissionsList();
                updateCounts(data.data.counts);
            } else {
                showListEmpty(getErrorMessage(data.data, 'Fehler beim Laden der Daten.'));
            }
        } catch (error) {
            console.error('Load submissions error:', error);
            showListEmpty('Verbindungsfehler.');
        }

        state.isLoading = false;
    }

    function renderSubmissionsList() {
        if (!state.submissions || state.submissions.length === 0) {
            showListEmpty('Keine Einträge gefunden.');
            return;
        }

        elements.listLoading.style.display = 'none';
        elements.listEmpty.style.display = 'none';
        elements.listTableWrapper.style.display = 'block';

        // Standort-Name Helper
        const getLocationName = (locationId) => {
            if (!pp_portal.multi_location || !pp_portal.locations) return '';
            const loc = pp_portal.locations.find(l => l.id === locationId);
            return loc ? loc.name : '';
        };

        elements.listTableBody.innerHTML = state.submissions.map(sub => `
            <tr class="${sub.is_read ? '' : 'unread'} ${state.currentSubmission?.id === sub.id ? 'active' : ''}"
                data-id="${sub.id}">
                <td class="col-date">${formatDate(sub.created_at)}</td>
                <td class="col-patient">${escapeHtml(sub.patient_name)}</td>
                <td class="col-type">
                    <span class="pp-type-label type-${sub.service_type}">${getTypeLabel(sub.service_type)}</span>
                    ${sub.file_count > 0 ? `<span class="pp-file-indicator" title="${sub.file_count} Datei(en)">📎</span>` : ''}
                </td>
                ${pp_portal.multi_location ? `<td class="col-location"><span class="pp-location-badge">${escapeHtml(getLocationName(sub.location_id))}</span></td>` : ''}
            </tr>
        `).join('');

        // Bind row click events
        elements.listTableBody.querySelectorAll('tr').forEach(row => {
            row.addEventListener('click', () => {
                loadSubmission(row.dataset.id);
            });
        });
    }

    function showListLoading() {
        elements.listTableWrapper.style.display = 'none';
        elements.listEmpty.style.display = 'none';
        elements.listLoading.style.display = 'flex';
    }

    function showListEmpty(message = 'Keine Einträge gefunden.') {
        elements.listTableWrapper.style.display = 'none';
        elements.listLoading.style.display = 'none';
        elements.listEmpty.style.display = 'flex';
        elements.listEmpty.querySelector('p').textContent = message;
    }

    function updateCounts(counts) {
        if (!counts) return;

        elements.categoryBtns.forEach(btn => {
            const category = btn.dataset.category;
            const countEl = btn.querySelector('.pp-count');
            if (countEl && counts[category] !== undefined) {
                countEl.textContent = counts[category];
            }
        });

        // Ungelesen-Count und Badge aktualisieren
        const unreadCountEl = document.getElementById('pp-unread-count');
        const unreadBadge = document.querySelector('.pp-badge-unread');
        if (unreadCountEl) {
            const unreadCount = counts.unread !== undefined ? counts.unread : 0;
            unreadCountEl.textContent = unreadCount;
            if (unreadBadge) {
                unreadBadge.classList.toggle('has-unread', unreadCount > 0);
            }
        }
    }

    // ==========================================
    // Load Single Submission
    // ==========================================

    async function loadSubmission(id) {
        showPreviewLoading();

        // Update active row
        elements.listTableBody.querySelectorAll('tr').forEach(row => {
            row.classList.toggle('active', row.dataset.id === id);
        });

        try {
            const response = await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'pp_portal_action',
                    portal_action: 'get_submission',
                    nonce: pp_portal.nonce,
                    id: id
                })
            });

            const data = await response.json();

            if (data.success) {
                state.currentSubmission = data.data;
                // Token erneuern falls nötig (für Bild-Vorschauen)
                await ensureValidToken();
                renderPreview();
                markAsRead(id);
            } else {
                // Spezifische Fehlermeldung anzeigen
                const errorMsg = data.data?.message || 'Fehler beim Laden.';
                showPreviewEmpty(errorMsg);
                console.error('Portal Error:', data.data);
            }
        } catch (error) {
            console.error('Portal Connection Error:', error);
            showPreviewEmpty('Verbindungsfehler: ' + error.message);
        }
    }

    function renderPreview() {
        const sub = state.currentSubmission;
        if (!sub) return;

        elements.previewLoading.style.display = 'none';
        elements.previewEmpty.style.display = 'none';
        elements.previewContent.style.display = 'flex';

        // Header
        elements.previewTypeBadge.textContent = getTypeLabel(sub.service_type);
        elements.previewTitle.textContent = sub.patient_name || 'Unbekannt';
        elements.previewMeta.textContent = `Eingegangen: ${formatDateTime(sub.created_at)} • Status: ${getStatusLabel(sub.status)}`;

        // Details
        renderDetails(sub);

        // Files
        renderFiles(sub.files);

        // Reset response selection
        resetResponseSelection();

        // Show first tab
        setActiveTab('details');

        // GDT Export - mit Berechtigungs-Check
        if (elements.exportBdtBtn) {
            elements.exportBdtBtn.style.display = state.permissions.can_export ? '' : 'none';
        }

        // PDF Export - mit Berechtigungs-Check
        if (elements.exportPdfBtn) {
            elements.exportPdfBtn.style.display = state.permissions.can_export ? '' : 'none';
        }

        // Delete Button - mit Berechtigungs-Check
        if (elements.deleteBtn) {
            elements.deleteBtn.style.display = state.permissions.can_delete ? '' : 'none';
        }
    }

    function renderDetails(sub) {
        const data = sub.data || {};
        let html = '';

        // Referenz-ID + Status
        html += `
            <div class="pp-detail-section pp-reference-section">
                <div class="pp-detail-grid">
                    ${detailItem('Referenz-ID', sub.reference_id || '#' + sub.id)}
                    <div class="pp-detail-item pp-status-changer">
                        <label>Status</label>
                        <select id="pp-status-select" data-id="${sub.id}">
                            <option value="pending" ${sub.status === 'pending' ? 'selected' : ''}>Neu (pending)</option>
                            <option value="read" ${sub.status === 'read' ? 'selected' : ''}>Gelesen (read)</option>
                            <option value="responded" ${sub.status === 'responded' ? 'selected' : ''}>Beantwortet (responded)</option>
                            <option value="pdf_downloaded" ${sub.status === 'pdf_downloaded' ? 'selected' : ''}>PDF heruntergeladen</option>
                            <option value="exported_to_pvs" ${sub.status === 'exported_to_pvs' ? 'selected' : ''}>An PVS exportiert</option>
                        </select>
                    </div>
                </div>
            </div>
        `;

        // Stammdaten
        html += `
            <div class="pp-detail-section">
                <h4>Stammdaten</h4>
                <div class="pp-detail-grid">
                    ${detailItem('Name', data.nachname || data.name || '-')}
                    ${detailItem('Vorname', data.vorname || '-')}
                    ${detailItem('Geburtsdatum', data.geburtsdatum || '-')}
                    ${detailItem('Email', data.email || '-')}
                    ${detailItem('Telefon', data.telefon || '-')}
                    ${detailItem('Adresse', formatAddress(data))}
                </div>
            </div>
        `;

        // Service-specific data (simplified - full implementation would match v3 exactly)
        if (sub.service_type === 'anamnese') {
            html += renderAnamneseDetails(sub, data);
        } else if (sub.service_type === 'rezept') {
            html += renderRezeptDetails(data);
        } else if (sub.service_type === 'ueberweisung') {
            html += renderUeberweisungDetails(data);
        } else if (sub.service_type === 'brillenverordnung') {
            html += renderBrillenDetails(data);
        } else if (sub.service_type === 'dokument') {
            html += renderDokumentDetails(data);
        } else if (sub.service_type === 'termin') {
            html += renderTerminDetails(data);
        } else if (sub.service_type === 'terminabsage') {
            html += renderTerminabsageDetails(data);
        }

        // Signature
        if (sub.signature && sub.signature.startsWith('data:image')) {
            html += `
                <div class="pp-signature-preview">
                    <h4>Unterschrift</h4>
                    <img src="${sub.signature}" alt="Unterschrift">
                </div>
            `;
        }

        elements.detailsContent.innerHTML = html;
    }

    function renderAnamneseDetails(sub, data) {
        let html = `
            <div class="pp-detail-section">
                <h4>Versicherung</h4>
                <div class="pp-detail-grid">
                    ${detailItem('Kasse', data.kasse || '-')}
                </div>
            </div>
        `;

        // Download-Buttons
        const canGdt = pp_portal.can_gdt_export !== false;
        const canPdf = pp_portal.can_pdf_export !== false;
        const userCanExport = state.permissions.can_export !== false;

        html += `
            <div class="pp-detail-section pp-anamnese-downloads">
                <h4>Downloads</h4>
                <div class="pp-download-buttons">
                    ${canGdt && userCanExport ? `
                    <button type="button" class="pp-download-btn pp-download-bdt" data-id="${sub.id}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        GDT Export
                    </button>
                    ` : ''}
                    ${canPdf && userCanExport ? `
                    <button type="button" class="pp-download-btn pp-download-pdf" data-id="${sub.id}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        PDF Export
                    </button>
                    ` : ''}
                </div>
            </div>
        `;

        return html;
    }

    function renderRezeptDetails(data) {
        let html = `
            <div class="pp-detail-section">
                <h4>Rezeptanfrage</h4>
                <div class="pp-detail-grid">
                    ${detailItem('Medikament', data.medikament || '-', true)}
                    ${data.anmerkung ? detailItem('Anmerkung', data.anmerkung, true) : ''}
                </div>
            </div>
        `;
        return html;
    }

    function renderUeberweisungDetails(data) {
        return `
            <div class="pp-detail-section">
                <h4>Überweisung</h4>
                <div class="pp-detail-grid">
                    ${detailItem('Facharzt', data.facharzt || '-')}
                    ${detailItem('Grund', data.grund || '-', true)}
                </div>
            </div>
        `;
    }

    function renderBrillenDetails(data) {
        return `
            <div class="pp-detail-section">
                <h4>Brillenverordnung</h4>
                <div class="pp-detail-grid">
                    ${detailItem('Brillenart', data.brillenart || '-')}
                </div>
            </div>
        `;
    }

    function renderDokumentDetails(data) {
        return `
            <div class="pp-detail-section">
                <h4>Dokument-Upload</h4>
                <div class="pp-detail-grid">
                    ${detailItem('Dokumententyp', data.dokument_typ || '-')}
                    ${data.bemerkung ? detailItem('Bemerkung', data.bemerkung, true) : ''}
                </div>
            </div>
        `;
    }

    function renderTerminDetails(data) {
        return `
            <div class="pp-detail-section">
                <h4>📅 Terminanfrage</h4>
                <div class="pp-detail-grid">
                    ${data.termin_anliegen ? detailItem('Anliegen', data.termin_anliegen, true) : ''}
                    ${data.termin_grund ? detailItem('Grund', data.termin_grund, true) : ''}
                    ${data.anmerkungen ? detailItem('Anmerkungen', data.anmerkungen, true) : ''}
                </div>
            </div>
        `;
    }

    function renderTerminabsageDetails(data) {
        return `
            <div class="pp-detail-section pp-terminabsage-section">
                <h4>❌ Terminabsage</h4>
                <div class="pp-detail-grid">
                    ${detailItem('Datum', data.absage_datum || '-')}
                    ${data.absage_uhrzeit ? detailItem('Uhrzeit', data.absage_uhrzeit) : ''}
                    ${data.anmerkungen ? detailItem('Anmerkungen', data.anmerkungen, true) : ''}
                </div>
            </div>
        `;
    }

    function renderFiles(files) {
        if (!files || files.length === 0) {
            elements.filesContent.innerHTML = '<p class="pp-no-files">Keine Dateien vorhanden.</p>';
            return;
        }

        elements.filesContent.innerHTML = `
            <div class="pp-file-list">
                ${files.map(file => {
                    const isImage = file.file_type && file.file_type.startsWith('image/');
                    const previewUrl = isImage ? getPreviewUrl(file.id) : '';

                    return `
                    <div class="pp-file-item">
                        ${isImage ? `
                            <div class="pp-file-preview">
                                <img src="${previewUrl}" alt="${escapeHtml(file.original_name)}">
                            </div>
                        ` : `
                            <div class="pp-file-icon">
                                ${getFileIcon(file.file_type)}
                            </div>
                        `}
                        <div class="pp-file-info">
                            <div class="pp-file-name">${escapeHtml(file.original_name)}</div>
                            <div class="pp-file-size">${formatFileSize(file.file_size)}</div>
                        </div>
                        <div class="pp-file-actions">
                            <button class="pp-file-download" data-file-id="${file.id}">
                                Download
                            </button>
                        </div>
                    </div>
                `}).join('')}
            </div>
        `;

        // Bind download events
        elements.filesContent.querySelectorAll('.pp-file-download').forEach(btn => {
            btn.addEventListener('click', () => downloadFile(btn.dataset.fileId));
        });
    }

    function getPreviewUrl(fileId) {
        const params = {
            action: 'pp_portal_action',
            portal_action: 'preview_file',
            file_id: fileId
        };

        if (state.fileToken) {
            params.token = state.fileToken;
        }

        return pp_portal.ajax_url + '?' + new URLSearchParams(params);
    }

    function showPreviewLoading() {
        elements.previewEmpty.style.display = 'none';
        elements.previewContent.style.display = 'none';
        elements.previewLoading.style.display = 'flex';
    }

    function showPreviewEmpty(message = 'Eintrag auswählen') {
        elements.previewLoading.style.display = 'none';
        elements.previewContent.style.display = 'none';
        elements.previewEmpty.style.display = 'flex';
        elements.previewEmpty.querySelector('p').textContent = message;
    }

    // ==========================================
    // Tabs
    // ==========================================

    function setActiveTab(tab) {
        elements.tabBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });

        elements.tabContents.forEach(content => {
            content.classList.toggle('active', content.id === `pp-tab-${tab}`);
        });
    }

    // ==========================================
    // Mark as Read
    // ==========================================

    async function markAsRead(id) {
        try {
            await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'pp_portal_action',
                    portal_action: 'mark_read',
                    nonce: pp_portal.nonce,
                    id: id
                })
            });

            // Update UI
            const row = elements.listTableBody.querySelector(`tr[data-id="${id}"]`);
            if (row) {
                row.classList.remove('unread');
            }
        } catch (error) {
            // Ignore
        }
    }

    // ==========================================
    // Response Handling
    // ==========================================

    let selectedResponse = null;

    function selectResponse(type) {
        selectedResponse = type;

        elements.responseBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.response === type);
        });

        // Show text field for certain responses
        const needsText = ['need_info', 'rejected', 'appointment'].includes(type);
        elements.responseTextWrapper.style.display = needsText ? 'block' : 'none';

        // Enable send button
        if (elements.sendResponseBtn) {
            elements.sendResponseBtn.disabled = false;
        }

        if (needsText) {
            elements.responseText.focus();
        }
    }

    function resetResponseSelection() {
        selectedResponse = null;
        elements.responseBtns.forEach(btn => btn.classList.remove('active'));
        elements.responseTextWrapper.style.display = 'none';
        elements.responseText.value = '';
        if (elements.sendResponseBtn) {
            elements.sendResponseBtn.disabled = true;
        }
    }

    function showResponseModal() {
        if (!selectedResponse) {
            alert('Bitte wählen Sie eine Antwort aus.');
            return;
        }

        elements.responseModalTitle.textContent = getResponseTitle(selectedResponse);
        elements.responseModal.style.display = 'flex';
    }

    async function confirmResponse() {
        const customText = elements.responseText.value.trim();

        elements.responseModalConfirm.disabled = true;
        elements.responseModalConfirm.textContent = 'Wird gesendet...';

        try {
            const response = await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'pp_portal_action',
                    portal_action: 'send_response',
                    nonce: pp_portal.nonce,
                    id: state.currentSubmission.id,
                    response_type: selectedResponse,
                    custom_text: customText
                })
            });

            const data = await response.json();

            if (data.success) {
                closeModals();
                loadSubmissions();
                loadSubmission(state.currentSubmission.id);
                showToast('Antwort wurde erfolgreich gesendet!', 'success');
            } else {
                showToast(getErrorMessage(data.data, 'Fehler beim Senden der Antwort.'), 'error');
            }
        } catch (error) {
            alert('Verbindungsfehler.');
        }

        elements.responseModalConfirm.disabled = false;
        elements.responseModalConfirm.textContent = 'Senden';
    }

    // ==========================================
    // Export Functions
    // ==========================================

    async function exportBdt() {
        if (!state.currentSubmission) return;

        elements.exportBdtBtn.disabled = true;

        try {
            await ensureValidToken();

            const params = {
                action: 'pp_portal_action',
                portal_action: 'export_gdt',
                nonce: pp_portal.nonce,
                id: state.currentSubmission.id
            };

            if (state.fileToken) {
                params.token = state.fileToken;
            }

            const url = pp_portal.ajax_url + '?' + new URLSearchParams(params);
            window.open(url, '_blank');

        } catch (error) {
            alert('Verbindungsfehler beim BDT-Export.');
        }

        elements.exportBdtBtn.disabled = false;
    }

    async function exportPdf() {
        if (!state.currentSubmission) return;

        if (elements.exportPdfBtn) {
            elements.exportPdfBtn.disabled = true;
        }

        try {
            await ensureValidToken();

            const params = {
                action: 'pp_portal_action',
                portal_action: 'export_pdf',
                nonce: pp_portal.nonce,
                id: state.currentSubmission.id
            };

            if (state.fileToken) {
                params.token = state.fileToken;
            }

            const url = pp_portal.ajax_url + '?' + new URLSearchParams(params);
            window.open(url, '_blank');

        } catch (error) {
            alert('Fehler beim PDF-Export.');
        }

        if (elements.exportPdfBtn) {
            elements.exportPdfBtn.disabled = false;
        }
    }

    async function downloadAnamneseBdt(id) {
        try {
            await ensureValidToken();

            const params = {
                action: 'pp_portal_action',
                portal_action: 'export_gdt',
                nonce: pp_portal.nonce,
                id: id
            };

            if (state.fileToken) {
                params.token = state.fileToken;
            }

            const url = pp_portal.ajax_url + '?' + new URLSearchParams(params);
            window.open(url, '_blank');
        } catch (error) {
            alert('Fehler beim BDT-Export.');
        }
    }

    async function downloadMedplan(id) {
        try {
            await ensureValidToken();

            const sub = state.currentSubmission;
            if (!sub || !sub.files) {
                alert('Keine Medikamentenplan-Datei gefunden.');
                return;
            }

            const medplanFile = sub.files.find(f =>
                f.original_name && f.original_name.toLowerCase().includes('medplan')
            );

            if (medplanFile) {
                const params = {
                    action: 'pp_portal_action',
                    portal_action: 'download_file',
                    nonce: pp_portal.nonce,
                    file_id: medplanFile.id
                };
                if (state.fileToken) {
                    params.token = state.fileToken;
                }
                const url = pp_portal.ajax_url + '?' + new URLSearchParams(params);
                window.open(url, '_blank');
            } else {
                alert('Keine Medikamentenplan-Datei gefunden.');
            }
        } catch (error) {
            alert('Fehler beim Herunterladen des Medikamentenplans.');
        }
    }

    async function downloadAnamnesePdf(id) {
        try {
            await ensureValidToken();

            const params = {
                action: 'pp_portal_action',
                portal_action: 'export_pdf',
                nonce: pp_portal.nonce,
                id: id
            };

            if (state.fileToken) {
                params.token = state.fileToken;
            }

            const url = pp_portal.ajax_url + '?' + new URLSearchParams(params);
            window.open(url, '_blank');
        } catch (error) {
            alert('Fehler beim PDF-Export.');
        }
    }

    async function downloadAnamneseFhir(id) {
        try {
            await ensureValidToken();

            const params = {
                action: 'pp_portal_action',
                portal_action: 'export_fhir',
                nonce: pp_portal.nonce,
                id: id
            };

            if (state.fileToken) {
                params.token = state.fileToken;
            }

            const url = pp_portal.ajax_url + '?' + new URLSearchParams(params);
            window.open(url, '_blank');
        } catch (error) {
            alert('Fehler beim FHIR-Export.');
        }
    }

    async function changeStatus(id, newStatus) {
        const select = document.getElementById('pp-status-select');
        if (select) select.disabled = true;

        try {
            const response = await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'pp_portal_action',
                    portal_action: 'change_status',
                    nonce: pp_portal.nonce,
                    id: id,
                    status: newStatus
                })
            });

            const result = await response.json();

            if (result.success) {
                if (state.currentSubmission) {
                    state.currentSubmission.status = newStatus;
                }
                if (select) {
                    select.style.borderColor = '#22c55e';
                    setTimeout(() => {
                        select.style.borderColor = '';
                    }, 1000);
                }
                loadSubmissions();
            } else {
                alert('Fehler: ' + (result.data?.message || 'Status konnte nicht geändert werden'));
                if (select && state.currentSubmission) {
                    select.value = state.currentSubmission.status;
                }
            }
        } catch (error) {
            alert('Fehler beim Ändern des Status');
            if (select && state.currentSubmission) {
                select.value = state.currentSubmission.status;
            }
        } finally {
            if (select) select.disabled = false;
        }
    }

    // ==========================================
    // Delete
    // ==========================================

    function showDeleteModal() {
        if (!state.currentSubmission) return;

        if (!state.permissions.can_delete) {
            alert('Sie haben keine Berechtigung zum Löschen.');
            return;
        }

        elements.deleteModal.style.display = 'flex';
    }

    async function confirmDelete() {
        elements.deleteModalConfirm.disabled = true;
        elements.deleteModalConfirm.textContent = 'Wird gelöscht...';

        try {
            const response = await fetch(pp_portal.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'pp_portal_action',
                    portal_action: 'delete_submission',
                    nonce: pp_portal.nonce,
                    id: state.currentSubmission.id
                })
            });

            const data = await response.json();

            if (data.success) {
                closeModals();
                state.currentSubmission = null;
                showPreviewEmpty();
                loadSubmissions();
            } else {
                alert(getErrorMessage(data.data, 'Fehler beim Löschen.'));
            }
        } catch (error) {
            alert('Verbindungsfehler.');
        }

        elements.deleteModalConfirm.disabled = false;
        elements.deleteModalConfirm.textContent = 'Löschen';
    }

    // ==========================================
    // File Download
    // ==========================================

    async function downloadFile(fileId) {
        await ensureValidToken();

        const params = {
            action: 'pp_portal_action',
            portal_action: 'download_file',
            nonce: pp_portal.nonce,
            file_id: fileId
        };

        if (state.fileToken) {
            params.token = state.fileToken;
        }

        const url = pp_portal.ajax_url + '?' + new URLSearchParams(params);
        window.open(url, '_blank');
    }

    // ==========================================
    // Modals
    // ==========================================

    function closeModals() {
        document.querySelectorAll('.pp-modal-overlay').forEach(modal => {
            modal.style.display = 'none';
        });
    }

    // ==========================================
    // Helper Functions
    // ==========================================

    function detailItem(label, value, fullWidth = false) {
        return `
            <div class="pp-detail-item ${fullWidth ? 'full-width' : ''}">
                <div class="pp-detail-label">${escapeHtml(label)}</div>
                <div class="pp-detail-value">${escapeHtml(value)}</div>
            </div>
        `;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('de-DE');
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('de-DE') + ' ' + date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    }

    function formatAddress(data) {
        const parts = [];
        if (data.strasse) parts.push(data.strasse);
        if (data.plz || data.ort) parts.push([data.plz, data.ort].filter(Boolean).join(' '));
        return parts.join(', ') || '-';
    }

    function formatFileSize(bytes) {
        bytes = parseInt(bytes, 10);
        if (!bytes || isNaN(bytes)) return '-';

        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    function getTypeLabel(type) {
        const normalizedType = type?.replace(/^widget_/, '') || 'anamnese';

        const labels = {
            'anamnese': 'Anamnese',
            'rezept': 'Rezept',
            'ueberweisung': 'Überweisung',
            'brillenverordnung': 'Brillen',
            'dokument': 'Dokument',
            'termin': 'Terminanfrage',
            'terminabsage': 'Terminabsage'
        };
        return labels[normalizedType] || normalizedType;
    }

    function getStatusLabel(status) {
        const labels = {
            'pending': 'Ausstehend',
            'read': 'Gelesen',
            'responded': 'Beantwortet',
            'completed': 'Abgeschlossen'
        };
        return labels[status] || status;
    }

    function getResponseTitle(type) {
        const titles = {
            'ready': 'Zur Abholung bereit',
            'insurance_card': 'Versichertenkarte einreichen',
            'sent': 'Per Post versandt',
            'appointment': 'Termin erforderlich',
            'need_info': 'Rückfrage',
            'rejected': 'Ablehnen'
        };
        return titles[type] || type;
    }

    function getFileIcon(fileType) {
        if (fileType && fileType.startsWith('image/')) {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
        }
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function getErrorMessage(data, fallback = 'Ein Fehler ist aufgetreten.') {
        if (!data) return fallback;
        if (typeof data === 'string') return data;
        if (typeof data === 'object' && data.message) return data.message;
        return fallback;
    }

    // Toast notification
    function showToast(message, type = 'info') {
        document.querySelectorAll('.pp-toast').forEach(t => t.remove());

        const toast = document.createElement('div');
        toast.className = `pp-toast pp-toast-${type}`;
        toast.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                ${type === 'success'
                    ? '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>'
                    : '<circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/>'}
            </svg>
            <span>${escapeHtml(message)}</span>
        `;
        document.body.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

})();
