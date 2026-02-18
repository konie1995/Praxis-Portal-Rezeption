<?php
/**
 * Zentrale Konfiguration
 * 
 * Verwaltet sichere Pfade, Defaults und Plugin-Konstanten.
 * Muss VOR dem Plugin-Start aufgerufen werden.
 *
 * @package PraxisPortal\Core
 * @since   4.0.0
 */

namespace PraxisPortal\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Config
{
    // =========================================================================
    // SICHERE PFADE
    // =========================================================================
    
    /** Pfad zur Schlüssel-Datei */
    private static string $keyFile = '';
    
    /** Pfad für verschlüsselte Uploads */
    private static string $uploadDir = '';
    
    /** Ob Uploads außerhalb des Web-Root liegen */
    private static bool $uploadsOutsideWebroot = false;
    
    // =========================================================================
    // DEFAULTS
    // =========================================================================
    
    /** Standard-Widget-Farben */
    public const COLOR_PRIMARY   = '#0066cc';
    public const COLOR_SECONDARY = '#28a745';
    
    /** Session-Timeout (Minuten) */
    public const DEFAULT_SESSION_TIMEOUT = 60;
    
    /** Minimum Formular-Ausfüllzeit (Sekunden, Spam-Schutz) */
    public const MIN_FORM_TIME = 5;
    
    /** Maximale Dateigröße für Uploads (Bytes) */
    public const MAX_UPLOAD_SIZE = 10 * 1024 * 1024; // 10 MB
    
    /** Erlaubte Dateitypen für Uploads */
    public const ALLOWED_UPLOAD_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
    
    // =========================================================================
    // VERSCHLÜSSELUNG
    // =========================================================================
    
    /** Schlüssellänge in Bytes (256 Bit = 32 Bytes) */
    public const KEY_LENGTH = 32;
    
    /** Schlüssel-Dateiname */
    public const KEY_FILENAME = '.encryption_key';
    
    /** Verzeichnisname für sichere Daten */
    public const SECURE_DIR_NAME = 'pp-portal';
    
    // =========================================================================
    // METHODEN
    // =========================================================================
    
    /**
     * Ermittelt und setzt die sicheren Pfade.
     * Wird einmalig beim Plugin-Start aufgerufen.
     */
    public static function initSecurePaths(): void
    {
        self::resolveKeyPath();
        self::resolveUploadPath();
    }
    
    /**
     * Gibt den Pfad zur Schlüsseldatei zurück
     */
    public static function getKeyFile(): string
    {
        return self::$keyFile;
    }
    
    /**
     * Gibt den Upload-Pfad zurück
     */
    public static function getUploadDir(): string
    {
        return self::$uploadDir;
    }
    
    /**
     * Ob Uploads außerhalb des Web-Root liegen
     */
    public static function isUploadOutsideWebroot(): bool
    {
        return self::$uploadsOutsideWebroot;
    }
    
    // =========================================================================
    // PFAD-AUFLÖSUNG
    // =========================================================================
    
    /**
     * Ermittelt den sichersten Pfad für die Schlüsseldatei
     * 
     * Prioritäten:
     * 1. Manuell in wp-config.php: define('PP_ENCRYPTION_KEY_PATH', '...');
     * 2. ENV-Variable: PP_SECURE_BASE
     * 3. Home-Verzeichnis: ~/pp-portal/secure/
     * 4. Oberhalb WordPress: ../pp-secure/
     * 5. Fallback: wp-content/.pp-secure/ (mit .htaccess-Schutz)
     */
    private static function resolveKeyPath(): void
    {
        // Priorität 1: Manuell konfiguriert
        if (defined('PP_ENCRYPTION_KEY_PATH')) {
            self::$keyFile = PP_ENCRYPTION_KEY_PATH;
            return;
        }
        
        // Priorität 2: ENV-basierter Basis-Pfad
        $envBase = getenv('PP_SECURE_BASE');
        if ($envBase !== false && is_dir($envBase)) {
            $secureDir = rtrim($envBase, '/') . '/secure/';
            if (self::ensureDirectory($secureDir)) {
                self::$keyFile = $secureDir . self::KEY_FILENAME;
                return;
            }
        }
        
        // Priorität 3: Home-Verzeichnis
        $home = self::getHomeDirectory();
        if ($home !== null) {
            $secureDir = $home . '/' . self::SECURE_DIR_NAME . '/secure/';
            if (self::ensureDirectory($secureDir)) {
                self::$keyFile = $secureDir . self::KEY_FILENAME;
                return;
            }
        }
        
        // Priorität 4: Oberhalb WordPress-Root
        $aboveWp = dirname(ABSPATH) . '/pp-secure/';
        if (self::ensureDirectory($aboveWp)) {
            self::$keyFile = $aboveWp . self::KEY_FILENAME;
            self::protectDirectory($aboveWp);
            return;
        }
        
        // Priorität 5: Fallback im wp-content
        $fallback = WP_CONTENT_DIR . '/.pp-secure/';
        self::ensureDirectory($fallback);
        self::$keyFile = $fallback . self::KEY_FILENAME;
        self::protectDirectory($fallback);
    }
    
    /**
     * Ermittelt den sichersten Pfad für verschlüsselte Uploads
     */
    private static function resolveUploadPath(): void
    {
        // Priorität 1: Manuell konfiguriert
        if (defined('PP_UPLOAD_PATH')) {
            self::$uploadDir = rtrim(PP_UPLOAD_PATH, '/') . '/';
            self::$uploadsOutsideWebroot = true;
            return;
        }
        
        // Priorität 2: Home-Verzeichnis
        $home = self::getHomeDirectory();
        if ($home !== null) {
            $uploadDir = $home . '/' . self::SECURE_DIR_NAME . '/uploads/';
            if (self::ensureDirectory($uploadDir)) {
                self::$uploadDir = $uploadDir;
                self::$uploadsOutsideWebroot = true;
                return;
            }
        }
        
        // Priorität 3: Oberhalb WordPress-Root
        $aboveWp = dirname(ABSPATH) . '/pp-encrypted-uploads/';
        if (self::ensureDirectory($aboveWp)) {
            self::$uploadDir = $aboveWp;
            self::$uploadsOutsideWebroot = true;
            self::protectDirectory($aboveWp);
            return;
        }
        
        // Priorität 4: Fallback im wp-content
        $wpUpload = wp_upload_dir();
        $fallback = ($wpUpload['basedir'] ?? WP_CONTENT_DIR . '/uploads')
                  . '/pp-encrypted-files/';
        self::ensureDirectory($fallback);
        self::$uploadDir = $fallback;
        self::$uploadsOutsideWebroot = false;
        self::protectDirectory($fallback);
    }
    
    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================
    
    /**
     * Ermittelt das Home-Verzeichnis des System-Benutzers
     */
    private static function getHomeDirectory(): ?string
    {
        // 1. Umgebungsvariable HOME
        $home = getenv('HOME');
        if ($home !== false && $home !== '' && @is_dir($home)) {
            return rtrim($home, '/');
        }
        
        // 2. POSIX-Funktion
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $userInfo = @posix_getpwuid(posix_getuid());
            if (!empty($userInfo['dir']) && @is_dir($userInfo['dir'])) {
                return rtrim($userInfo['dir'], '/');
            }
        }
        
        // 3. Aus DOCUMENT_ROOT ableiten (Hoster-spezifisch)
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $patterns = [
            '#^(/home/www)(/public)?#',           // Strato
            '#^(/home/[^/]+)/#',                   // Standard Linux
            '#^(/var/www/vhosts/[^/]+)/#',         // Plesk
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $docRoot, $matches) && @is_dir($matches[1])) {
                return $matches[1];
            }
        }
        
        // 4. Aus ABSPATH ableiten
        $abspath = rtrim(ABSPATH, '/');
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $abspath, $matches) && @is_dir($matches[1])) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Stellt sicher, dass ein Verzeichnis existiert und beschreibbar ist
     */
    private static function ensureDirectory(string $path): bool
    {
        if (@is_dir($path) && @is_writable($path)) {
            return true;
        }
        
        $parent = dirname($path);
        if (@is_dir($parent) && @is_writable($parent)) {
            return @mkdir($path, 0700, true);
        }
        
        return false;
    }
    
    /**
     * Schützt ein Verzeichnis mit .htaccess und index.php
     */
    private static function protectDirectory(string $path): void
    {
        $htaccess = $path . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }
        
        $index = $path . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden\n");
        }
    }
}
