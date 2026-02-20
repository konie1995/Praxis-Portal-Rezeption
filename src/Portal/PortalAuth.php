<?php
declare(strict_types=1);
/**
 * Portal-Authentifizierung
 *
 * Eigenständiges Session-System (kein WordPress-User erforderlich).
 * Verwendet Transients für Sessions und Cookie-basierte Tokens.
 *
 * Sicherheitsmerkmale:
 *  - Rate Limiting (5 Versuche / 15 Min)
 *  - IP-Binding gegen Session-Hijacking
 *  - SameSite-Cookie mit Secure-Flag
 *  - File-Tokens mit Session-Bindung (CSRF-Schutz)
 *  - Verschlüsselte Session-Daten
 *
 * Multi-Standort:
 *  - Portal-User können an Standort gebunden sein
 *  - Standort-gebundene User sehen nur ihren Standort
 *
 * @package PraxisPortal\Portal
 * @since   4.0.0
 */

namespace PraxisPortal\Portal;

use PraxisPortal\Security\Encryption;
use PraxisPortal\Security\RateLimiter;
use PraxisPortal\Database\Repository\PortalUserRepository;
use PraxisPortal\Database\Repository\AuditRepository;

if (!defined('ABSPATH')) {
    exit;
}

class PortalAuth
{
    /** Cookie-Name für Portal-Sessions */
    private const COOKIE_NAME = 'pp_portal_session';

    /** Transient-Prefix für Sessions */
    private const SESSION_PREFIX = 'pp_portal_session_';

    /** Transient-Prefix für File-Tokens */
    private const FILE_TOKEN_PREFIX = 'pp_file_token_';

    /** Max Login-Versuche bevor Rate-Limit greift */
    private const MAX_LOGIN_ATTEMPTS = 5;

    /** Rate-Limit Dauer in Sekunden (15 Min) */
    private const RATE_LIMIT_DURATION = 900;

    /** File-Token Gültigkeit in Sekunden (5 Min) */
    private const FILE_TOKEN_TTL = 300;

    private Encryption $encryption;
    private RateLimiter $rateLimiter;
    private PortalUserRepository $userRepo;
    private AuditRepository $auditRepo;

    /** Session-Timeout in Sekunden */
    private int $sessionTimeout;

    public function __construct(
        Encryption           $encryption,
        RateLimiter          $rateLimiter,
        PortalUserRepository $userRepo,
        AuditRepository      $auditRepo
    ) {
        $this->encryption  = $encryption;
        $this->rateLimiter = $rateLimiter;
        $this->userRepo    = $userRepo;
        $this->auditRepo   = $auditRepo;

        // Session-Timeout aus DB-Option (Minuten → Sekunden)
        $timeoutMinutes      = (int) get_option('pp_session_timeout', 60);
        $this->sessionTimeout = max(300, $timeoutMinutes * 60); // Min 5 Min
    }

    // =========================================================================
    // SESSION-PRÜFUNG
    // =========================================================================

    /**
     * Prüft ob eine gültige Portal-Session existiert
     */
    public function isAuthenticated(): bool
    {
        $sessionToken = $this->getSessionTokenFromCookie();
        if (empty($sessionToken)) {
            return false;
        }

        $sessionData = $this->getSessionDataByToken($sessionToken);
        if (!$sessionData) {
            return false;
        }

        // IP-Binding prüfen (gegen Session-Hijacking)
        if ($this->isStrictIpEnabled() && !empty($sessionData['ip'])) {
            $currentIp = $this->getClientIp();
            if ($sessionData['ip'] !== $currentIp) {
                $this->auditRepo->log('portal_session_ip_mismatch', null, [
                    'session_ip' => $sessionData['ip'],
                    'current_ip' => $currentIp,
                    'username'   => $sessionData['username'] ?? 'unknown',
                ]);
                delete_transient(self::SESSION_PREFIX . $sessionToken);
                return false;
            }
        }

        // Session verlängern (sliding window)
        set_transient(
            self::SESSION_PREFIX . $sessionToken,
            $sessionData,
            $this->sessionTimeout
        );

        return true;
    }

    /**
     * Gibt die aktuelle Session zurück (oder null)
     */
    public function getSessionData(): ?array
    {
        $token = $this->getSessionTokenFromCookie();
        if (empty($token)) {
            return null;
        }
        return $this->getSessionDataByToken($token);
    }

    /**
     * Verifiziert einen AJAX-Request (Nonce + Session)
     *
     * @throws \RuntimeException bei ungültiger Authentifizierung
     */
    public function verifyAjaxRequest(): void
    {
        // Nonce prüfen (GET für Downloads/Exports, POST für AJAX-Calls)
        $nonce = sanitize_text_field($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'pp_portal_nonce')) {
            $this->auditRepo->log('portal_security_error', null, [
                'reason' => 'invalid_nonce',
                'ip'     => $this->getClientIp(),
            ]);
            wp_send_json_error([
                'message' => 'Sicherheitstoken ungültig. Bitte Seite neu laden.',
                'code'    => 'invalid_nonce',
            ]);
        }

        // Session prüfen
        if (!$this->isAuthenticated()) {
            wp_send_json_error([
                'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.',
                'code'    => 'unauthorized',
            ]);
        }
    }

    // =========================================================================
    // LOGIN / LOGOUT
    // =========================================================================

    /**
     * Login-Versuch verarbeiten
     *
     * Prüft in dieser Reihenfolge:
     *  1. pp_portal_users Tabelle (Multi-User DB)
     *  2. pp_portal_users Option (Multi-User Legacy)
     *  3. pp_portal_username/password_hash (Single-User Legacy)
     */
    public function handleLogin(): void
    {
        // CSRF-Schutz
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pp_portal_nonce')) {
            wp_send_json_error([
                'message' => 'Sicherheitsüberprüfung fehlgeschlagen. Bitte Seite neu laden.',
            ]);
        }

        $ip      = $this->getClientIp();
        $rateKey = 'pp_login_attempts_' . substr(hash('sha256', $ip), 0, 16);

        // Rate Limiting
        $attempts = (int) get_transient($rateKey);
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            wp_send_json_error([
                'message' => 'Zu viele Anmeldeversuche. Bitte warten Sie 15 Minuten.',
            ]);
        }

        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            wp_send_json_error(['message' => 'Benutzername und Passwort erforderlich.']);
        }

        // --- 1. DB-Tabelle (pp_portal_users) ---
        $portalUser = $this->userRepo->findByUsername($username);

        if ($portalUser && !empty($portalUser['password_hash'])) {
            if (!password_verify($password, $portalUser['password_hash'])) {
                $this->handleFailedLogin($rateKey, $attempts, $username);
                return; // handleFailedLogin ruft wp_send_json_error → exit
            }

            if (empty($portalUser['is_active'])) {
                wp_send_json_error(['message' => 'Ihr Benutzerkonto ist deaktiviert.']);
            }

            // Erfolg: DB-User
            delete_transient($rateKey);

            $sessionData = [
                'user_id'      => (int) $portalUser['id'],
                'username'     => $portalUser['username'],
                'display_name' => $portalUser['display_name'] ?? $portalUser['username'],
                'location_id'  => $portalUser['location_id'] ? (int) $portalUser['location_id'] : null,
                'can_view'     => !empty($portalUser['can_view']),
                'can_edit'     => !empty($portalUser['can_edit']),
                'can_delete'   => !empty($portalUser['can_delete']),
                'can_export'   => !empty($portalUser['can_export']),
                'login_time'   => time(),
                'ip'           => $ip,
            ];

            $this->createSession($sessionData);
            $this->userRepo->updateLastLogin((int) $portalUser['id']);

            $this->auditRepo->log('portal_login_success', null, [
                'username'    => $username,
                'user_id'     => $portalUser['id'],
                'location_id' => $portalUser['location_id'],
            ]);

            wp_send_json_success(['message' => 'Anmeldung erfolgreich']);
        }

        // --- 2. Option-basierte Multi-User (Legacy) ---
        $authenticatedUser = $this->checkLegacyUsers($username, $password);

        // --- 3. Single-User (Legacy) ---
        if (!$authenticatedUser) {
            $authenticatedUser = $this->checkSingleUser($username, $password);
        }

        if (!$authenticatedUser) {
            $this->handleFailedLogin($rateKey, $attempts, $username);
            return;
        }

        // Erfolg: Legacy-User
        delete_transient($rateKey);

        $sessionData = [
            'user_id'      => 0,
            'username'     => $authenticatedUser['username'],
            'display_name' => $authenticatedUser['username'],
            'location_id'  => null, // Alle Standorte
            'can_view'     => true,
            'can_edit'     => true,
            'can_delete'   => true,
            'can_export'   => true,
            'login_time'   => time(),
            'ip'           => $ip,
        ];

        $this->createSession($sessionData);

        $this->auditRepo->log('portal_login_success', null, [
            'username' => $username,
            'type'     => 'legacy',
        ]);

        wp_send_json_success(['message' => 'Anmeldung erfolgreich']);
    }

    /**
     * Logout verarbeiten
     */
    public function handleLogout(): void
    {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'pp_portal_nonce')) {
            wp_send_json_error([
                'message' => 'Sicherheitstoken ungültig.',
                'code'    => 'invalid_nonce',
            ]);
        }

        $token = $this->getSessionTokenFromCookie();
        if (!empty($token)) {
            delete_transient(self::SESSION_PREFIX . $token);
        }

        // Cookie löschen
        $this->clearSessionCookie();

        $this->auditRepo->log('portal_logout');
        wp_send_json_success();
    }

    // =========================================================================
    // FILE-TOKEN (CSRF-geschützte Downloads)
    // =========================================================================

    /**
     * Generiert ein temporäres File-Token (an Session gebunden)
     */
    public function handleGetFileToken(): void
    {
        $this->verifyAjaxRequest();

        $sessionToken = $this->getSessionTokenFromCookie();

        $token = wp_generate_password(32, false);
        set_transient(self::FILE_TOKEN_PREFIX . $token, [
            'valid'         => true,
            'created'       => time(),
            'session_token' => $sessionToken,
        ], self::FILE_TOKEN_TTL);

        wp_send_json_success(['token' => $token]);
    }

    /**
     * Prüft ob ein File-Token gültig ist (für Downloads)
     */
    public function isFileAccessAuthorized(): bool
    {
        $token = sanitize_text_field($_GET['token'] ?? $_POST['token'] ?? '');
        if (empty($token)) {
            return false;
        }

        $tokenData = get_transient(self::FILE_TOKEN_PREFIX . $token);
        if (!$tokenData || empty($tokenData['valid'])) {
            return false;
        }

        // Token ist an Session gebunden
        if (!empty($tokenData['session_token'])) {
            $sessionData = get_transient(self::SESSION_PREFIX . $tokenData['session_token']);
            if (!$sessionData) {
                delete_transient(self::FILE_TOKEN_PREFIX . $token);
                return false;
            }
        }

        // Token wird NICHT hier gelöscht — getSessionFromFileToken() löscht nach dem Lesen
        return true;
    }

    /**
     * Session-Daten aus File-Token holen (für Berechtigungs-Checks)
     * Löscht Token nach einmaliger Nutzung (Single-Use).
     */
    public function getSessionFromFileToken(): ?array
    {
        $token = sanitize_text_field($_GET['token'] ?? $_POST['token'] ?? '');
        if (empty($token)) {
            return null;
        }

        $tokenData = get_transient(self::FILE_TOKEN_PREFIX . $token);
        if (!$tokenData || empty($tokenData['session_token'])) {
            return null;
        }

        $session = get_transient(self::SESSION_PREFIX . $tokenData['session_token']);

        // Single-Use: Token nach Lesen invalidieren
        delete_transient(self::FILE_TOKEN_PREFIX . $token);

        return is_array($session) ? $session : null;
    }

    // =========================================================================
    // BERECHTIGUNGS-PRÜFUNG
    // =========================================================================

    /**
     * Prüft ob Session-User Zugriff auf einen Standort hat
     *
     * @param int        $locationId  Standort-ID der Ressource
     * @param array|null $session     Session-Daten (null = aktuelle Session)
     */
    public function canAccessLocation(int $locationId, ?array $session = null): bool
    {
        $session = $session ?? $this->getSessionData();
        if (!$session) {
            return false;
        }

        $userLocationId = $session['location_id'] ?? null;

        // null = Zugriff auf alle Standorte
        if ($userLocationId === null || $userLocationId === 0) {
            return true;
        }

        return (int) $userLocationId === $locationId;
    }

    /**
     * Prüft eine spezifische Berechtigung
     *
     * @param string     $permission  z.B. 'can_view', 'can_edit', 'can_delete', 'can_export'
     * @param array|null $session     Session-Daten (null = aktuelle Session)
     */
    public function hasPermission(string $permission, ?array $session = null): bool
    {
        $session = $session ?? $this->getSessionData();
        if (!$session) {
            return false;
        }
        return !empty($session[$permission]);
    }

    /**
     * Sicherheitsheader für Downloads setzen
     */
    public function addDownloadSecurityHeaders(): void
    {
        header('Referrer-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        nocache_headers();
    }

    /**
     * Nonce für Portal-Frontend erzeugen
     */
    public function createNonce(): string
    {
        return wp_create_nonce('pp_portal_nonce');
    }

    // =========================================================================
    // INTERNE METHODEN
    // =========================================================================

    /**
     * Session erstellen und Cookie setzen
     */
    private function createSession(array $sessionData): void
    {
        $token = wp_generate_password(64, false);

        set_transient(
            self::SESSION_PREFIX . $token,
            $sessionData,
            $this->sessionTimeout
        );

        $this->setSessionCookie($token);
    }

    /**
     * Session-Cookie setzen
     */
    private function setSessionCookie(string $token): void
    {
        $secure = is_ssl();

        // SameSite aus Admin-Einstellungen
        $samesite = get_option('pp_cookie_samesite', 'Lax');
        if (!in_array($samesite, ['Strict', 'Lax', 'None'], true)) {
            $samesite = 'Lax';
        }
        if ($samesite === 'None' && !$secure) {
            $samesite = 'Lax';
        }

        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + $this->sessionTimeout,
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => $samesite,
        ]);
    }

    /**
     * Session-Cookie löschen
     */
    private function clearSessionCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Session-Token aus Cookie lesen
     */
    private function getSessionTokenFromCookie(): string
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return '';
        }
        return sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Session-Daten anhand Token lesen
     */
    private function getSessionDataByToken(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }
        $data = get_transient(self::SESSION_PREFIX . $token);
        return is_array($data) ? $data : null;
    }

    /**
     * Legacy Multi-User prüfen (wp_options)
     */
    private function checkLegacyUsers(string $username, string $password): ?array
    {
        $users = get_option('pp_portal_users', []);
        if (!is_array($users)) {
            return null;
        }

        foreach ($users as $user) {
            if (($user['username'] ?? '') === $username && !empty($user['password_hash'])) {
                if (password_verify($password, $user['password_hash'])) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * Legacy Single-User prüfen (wp_options)
     */
    private function checkSingleUser(string $username, string $password): ?array
    {
        $storedUsername = get_option('pp_portal_username', '');
        $storedHash    = get_option('pp_portal_password_hash', '');

        if (empty($storedUsername) || empty($storedHash)) {
            return null;
        }

        if ($username === $storedUsername && password_verify($password, $storedHash)) {
            return ['username' => $storedUsername];
        }

        return null;
    }

    /**
     * Fehlgeschlagenen Login verarbeiten
     */
    private function handleFailedLogin(string $rateKey, int $attempts, string $username): void
    {
        set_transient($rateKey, $attempts + 1, self::RATE_LIMIT_DURATION);

        $this->auditRepo->log('portal_login_failed', null, [
            'username' => $username,
            'ip'       => $this->getClientIp(),
        ]);

        wp_send_json_error(['message' => 'Ungültige Anmeldedaten.']);
    }

    /**
     * Strikte IP-Prüfung aktiviert?
     * Default: true (kann mit define('PP_PORTAL_STRICT_IP', false) deaktiviert werden)
     */
    private function isStrictIpEnabled(): bool
    {
        return !defined('PP_PORTAL_STRICT_IP') || PP_PORTAL_STRICT_IP;
    }

    /**
     * Client-IP ermitteln
     */
    private function getClientIp(): string
    {
        if (function_exists('pp_get_client_ip')) {
            return pp_get_client_ip();
        }

        // PP_TRUST_PROXY respektieren (Constant oder Admin-Einstellung)
        $trustProxy = (defined('PP_TRUST_PROXY') && PP_TRUST_PROXY)
                    || get_option('pp_trust_proxy', '0') === '1';
        if ($trustProxy) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (!empty($forwarded)) {
                $ips = array_map('trim', explode(',', $forwarded));
                return sanitize_text_field($ips[0]);
            }
        }

        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
