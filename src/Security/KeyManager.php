<?php
declare(strict_types=1);
/**
 * Schlüssel-Manager
 * 
 * Verwaltet die Speicherung, das Laden und die Rotation
 * des AES-256 Verschlüsselungsschlüssels.
 * 
 * Schlüssel werden IMMER außerhalb des Web-Root gespeichert:
 * 1. ENV-Variable PP_ENCRYPTION_KEY (sicherste Option)
 * 2. wp-config.php Konstante PP_ENCRYPTION_KEY
 * 3. Datei am von Config ermittelten sicheren Pfad
 *
 * @package PraxisPortal\Security
 * @since   4.0.0
 */

namespace PraxisPortal\Security;

use PraxisPortal\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

class KeyManager
{
    /** Schlüssellänge: 256 Bit = 32 Bytes */
    private const KEY_LENGTH = 32;
    
    /** Geladener Schlüssel (binär) */
    private ?string $key = null;
    
    /** Quelle des Schlüssels */
    private string $keySource = 'none';
    
    /** Warnungen */
    private array $warnings = [];
    
    public function __construct()
    {
        $this->loadKey();
    }
    
    // =========================================================================
    // ÖFFENTLICHE API
    // =========================================================================
    
    /**
     * Gibt den binären Schlüssel zurück
     * 
     * @throws \RuntimeException Wenn kein Schlüssel geladen
     */
    public function getKey(): string
    {
        if ($this->key === null) {
            throw new \RuntimeException(
                'Kein Verschlüsselungsschlüssel geladen. '
                . 'Bitte Systemstatus unter Praxis-Portal → System prüfen.'
            );
        }
        return $this->key;
    }
    
    /**
     * Ob ein gültiger Schlüssel geladen ist
     */
    public function hasKey(): bool
    {
        return $this->key !== null;
    }
    
    /**
     * Woher der Schlüssel geladen wurde
     */
    public function getKeySource(): string
    {
        return $this->keySource;
    }
    
    /**
     * Sicherheitswarnungen
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Cache invalidieren (z.B. vor Key-Reset)
     */
    public function clearCache(): void
    {
        $this->key       = null;
        $this->keySource = '';
        $this->warnings  = [];
    }
    
    /**
     * Stellt sicher, dass ein Schlüssel existiert
     * Generiert einen neuen falls keiner vorhanden.
     */
    public function ensureKeyExists(): bool
    {
        if ($this->hasKey()) {
            return true;
        }
        return $this->generateKey();
    }
    
    /**
     * Migriert einen Schlüssel von einem alten Pfad (v3.x)
     */
    public function migrateOldKeyFile(): void
    {
        $newPath = Config::getKeyFile();
        
        // Wenn am neuen Pfad schon ein Key existiert, nichts tun
        if (file_exists($newPath)) {
            return;
        }
        
        // Alte Pfade durchsuchen
        $oldPaths = [
            // v3.x Pfade
            defined('PP_KEY_FILE') ? PP_KEY_FILE : null,
            ABSPATH . '../.pp_encryption_key',
            WP_CONTENT_DIR . '/.pp_encryption_key',
            WP_CONTENT_DIR . '/uploads/pp-encrypted-files/.encryption_key',
        ];
        
        // Home-basierte alte Pfade
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            $oldPaths[] = $home . '/pp-portal/secure/.encryption_key';
        }
        
        foreach (array_filter($oldPaths) as $oldPath) {
            if (file_exists($oldPath) && is_readable($oldPath)) {
                $content = @file_get_contents($oldPath);
                if ($content !== false) {
                    $keyDir = dirname($newPath);
                    if (!is_dir($keyDir)) {
                        @mkdir($keyDir, 0700, true);
                    }
                    
                    if (@file_put_contents($newPath, $content) !== false) {
                        @chmod($newPath, 0600);
                        error_log('PP KeyManager: Schlüssel migriert von altem Pfad nach neuem sicheren Pfad');
                        
                        // Alte Datei sicher löschen
                        $oldLen = strlen($content);
                        if ($oldLen > 0 && is_writable($oldPath)) {
                            @file_put_contents($oldPath, str_repeat("\0", $oldLen));
                            @unlink($oldPath);
                            error_log('PP KeyManager: Alte Key-Datei gelöscht');
                        }

                        // Inhalt aus dem Speicher entfernen
                        if (function_exists('sodium_memzero')) {
                            sodium_memzero($content);
                        }

                        // Schlüssel neu laden
                        $this->key = null;
                        $this->loadKey();
                        return;
                    }
                }
            }
        }
    }
    
    // =========================================================================
    // INTERNE METHODEN
    // =========================================================================
    
    /**
     * Lädt den Schlüssel aus der sichersten verfügbaren Quelle
     */
    private function loadKey(): void
    {
        // Priorität 1: ENV-Variable
        if ($this->loadFromEnv()) {
            return;
        }
        
        // Priorität 2: wp-config.php Konstante
        if ($this->loadFromConstant()) {
            return;
        }
        
        // Priorität 3: Datei
        if ($this->loadFromFile()) {
            return;
        }
        
        $this->warnings[] = 'Kein Verschlüsselungsschlüssel gefunden. '
                          . 'Wird bei Aktivierung automatisch generiert.';
    }
    
    /**
     * Lädt Schlüssel aus ENV-Variable
     */
    private function loadFromEnv(): bool
    {
        $envKey = getenv('PP_ENCRYPTION_KEY');
        if ($envKey === false || $envKey === '') {
            return false;
        }
        
        $decoded = base64_decode(trim($envKey), true);
        if ($decoded === false || strlen($decoded) !== self::KEY_LENGTH) {
            $this->warnings[] = 'PP_ENCRYPTION_KEY ENV-Variable ungültig (erwartet: '
                              . self::KEY_LENGTH . ' Bytes Base64-kodiert)';
            return false;
        }
        
        $this->key = $decoded;
        $this->keySource = 'env';
        return true;
    }
    
    /**
     * Lädt Schlüssel aus wp-config.php Konstante
     */
    private function loadFromConstant(): bool
    {
        if (!defined('PP_ENCRYPTION_KEY') || PP_ENCRYPTION_KEY === '') {
            return false;
        }
        
        $decoded = base64_decode(trim(PP_ENCRYPTION_KEY), true);
        if ($decoded === false || strlen($decoded) !== self::KEY_LENGTH) {
            $this->warnings[] = 'PP_ENCRYPTION_KEY Konstante ungültig (erwartet: '
                              . self::KEY_LENGTH . ' Bytes Base64-kodiert)';
            return false;
        }
        
        $this->key = $decoded;
        $this->keySource = 'constant';
        return true;
    }
    
    /**
     * Lädt Schlüssel aus Datei
     */
    private function loadFromFile(): bool
    {
        $keyFile = Config::getKeyFile();
        
        if (!file_exists($keyFile)) {
            return false;
        }
        
        // Dateiberechtigungen prüfen (nicht auf Windows – dort immer 0666)
        if (DIRECTORY_SEPARATOR !== '\\') {
            $perms = fileperms($keyFile) & 0777;
            if ($perms > 0600) {
                $this->warnings[] = sprintf(
                    'Schlüsseldatei hat zu offene Berechtigungen (%04o). Korrigiere auf 0600.',
                    $perms
                );
                @chmod($keyFile, 0600);
            }
        }
        
        // Prüfen ob Key im Web-Root liegt
        $keyPath = realpath($keyFile);
        $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
        
        if ($docRoot !== '' && $keyPath !== false && str_starts_with($keyPath, $docRoot)) {
            $this->warnings[] = 'WARNUNG: Schlüsseldatei liegt im DocumentRoot! '
                              . 'Empfehlung: Sicheren Pfad in wp-config.php konfigurieren.';
        }
        
        $content = @file_get_contents($keyFile);
        if ($content === false) {
            $this->warnings[] = 'Schlüsseldatei nicht lesbar: ' . $keyFile;
            return false;
        }
        
        $decoded = base64_decode(trim($content), true);
        if ($decoded === false || strlen($decoded) !== self::KEY_LENGTH) {
            $this->warnings[] = 'Schlüsseldatei enthält ungültigen Schlüssel. '
                              . 'Erwartet: ' . self::KEY_LENGTH . ' Bytes, '
                              . 'Gefunden: ' . (($decoded !== false) ? strlen($decoded) : 'ungültig');
            return false;
        }
        
        $this->key = $decoded;
        $this->keySource = 'file:' . $keyFile;
        return true;
    }
    
    /**
     * Generiert einen neuen Schlüssel und speichert ihn
     */
    private function generateKey(): bool
    {
        $keyFile = Config::getKeyFile();
        $keyDir  = dirname($keyFile);
        
        // Verzeichnis erstellen
        if (!is_dir($keyDir)) {
            if (!@mkdir($keyDir, 0700, true)) {
                $this->warnings[] = 'Schlüssel-Verzeichnis konnte nicht erstellt werden: ' . $keyDir;
                return false;
            }
        }
        
        if (!is_writable($keyDir)) {
            $this->warnings[] = 'Schlüssel-Verzeichnis nicht beschreibbar: ' . $keyDir;
            return false;
        }
        
        // Schlüssel generieren
        if (extension_loaded('sodium') && function_exists('sodium_crypto_secretbox_keygen')) {
            $key = sodium_crypto_secretbox_keygen();
        } else {
            $key = random_bytes(self::KEY_LENGTH);
        }
        
        // Speichern
        if (@file_put_contents($keyFile, base64_encode($key)) === false) {
            $this->warnings[] = 'Schlüsseldatei konnte nicht erstellt werden: ' . $keyFile;
            return false;
        }
        
        @chmod($keyFile, 0600);
        
        $this->key = $key;
        $this->keySource = 'file:' . $keyFile . ' (neu generiert)';
        
        error_log('PP KeyManager: Neuer Verschlüsselungsschlüssel generiert');
        return true;
    }
}
