<?php
declare(strict_types=1);
/**
 * Verschlüsselungs-Service
 * 
 * Konsequente AES-256-Bit Verschlüsselung aller Patientendaten.
 * Unterstützt libsodium (bevorzugt) und OpenSSL AES-256-GCM als Fallback.
 * Beide bieten authentifizierte Verschlüsselung (AEAD).
 *
 * Format der verschlüsselten Daten:
 *   "S:" + Base64(nonce + ciphertext)              → Sodium
 *   "O:" + Base64(nonce + tag + ciphertext)         → OpenSSL
 *
 * @package PraxisPortal\Security
 * @since   4.0.0
 */

namespace PraxisPortal\Security;

use PraxisPortal\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

class Encryption
{
    // =========================================================================
    // KONSTANTEN
    // =========================================================================
    
    private const METHOD_SODIUM  = 'sodium';
    private const METHOD_OPENSSL = 'openssl';
    
    private const PREFIX_SODIUM  = 'S:';
    private const PREFIX_OPENSSL = 'O:';
    
    private const OPENSSL_CIPHER     = 'aes-256-gcm';
    private const OPENSSL_TAG_LENGTH = 16;
    
    // =========================================================================
    // PROPERTIES
    // =========================================================================
    
    private KeyManager $keyManager;
    private ?string $method = null;
    private array $warnings = [];
    
    // =========================================================================
    // KONSTRUKTOR
    // =========================================================================
    
    public function __construct(KeyManager $keyManager)
    {
        $this->keyManager = $keyManager;
        $this->detectMethod();
    }
    
    // =========================================================================
    // ÖFFENTLICHE API
    // =========================================================================
    
    /**
     * Verschlüsselt Daten
     * 
     * Akzeptiert Strings, Arrays und Objekte.
     * Arrays/Objekte werden automatisch zu JSON serialisiert.
     * 
     * @param mixed $data Zu verschlüsselnde Daten
     * @return string Verschlüsselte Daten mit Methoden-Prefix
     * @throws \RuntimeException Bei Fehler
     */
    public function encrypt(mixed $data): string
    {
        $this->assertReady();
        
        // Array/Objekt → JSON
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        
        $data = (string) $data;
        
        return match ($this->method) {
            self::METHOD_SODIUM  => self::PREFIX_SODIUM  . $this->encryptSodium($data),
            self::METHOD_OPENSSL => self::PREFIX_OPENSSL . $this->encryptOpenSSL($data),
            default => throw new \RuntimeException('Keine Verschlüsselungsmethode verfügbar.'),
        };
    }
    
    /**
     * Entschlüsselt Daten
     * 
     * @param string $encrypted Verschlüsselte Daten
     * @param bool   $asArray   Ob das Ergebnis als Array zurückgegeben werden soll
     * @return mixed Entschlüsselte Daten
     * @throws \RuntimeException Bei Fehler
     */
    public function decrypt(string $encrypted, bool $asArray = false): mixed
    {
        $this->assertReady();
        
        // Methode aus Prefix erkennen
        if (str_starts_with($encrypted, self::PREFIX_SODIUM)) {
            $plaintext = $this->decryptSodium(substr($encrypted, 2));
        } elseif (str_starts_with($encrypted, self::PREFIX_OPENSSL)) {
            $plaintext = $this->decryptOpenSSL(substr($encrypted, 2));
        } else {
            // Legacy-Daten ohne Prefix (versuche beide Methoden)
            $plaintext = $this->decryptLegacy($encrypted);
        }
        
        if ($plaintext === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen – Daten manipuliert oder falscher Schlüssel.');
        }
        
        // JSON parsen wenn gewünscht
        if ($asArray) {
            $decoded = json_decode($plaintext, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $plaintext;
        }
        
        return $plaintext;
    }
    
    /**
     * Erstellt einen nicht-umkehrbaren Hash (z.B. für IP, Name)
     * 
     * Nutzt HMAC-SHA256 mit dem Verschlüsselungsschlüssel als Key,
     * sodass Hashes ohne Schlüssel nicht reproduzierbar sind.
     */
    public function hash(string $data): string
    {
        $key = $this->keyManager->getKey();
        return hash_hmac('sha256', $data, $key);
    }
    
    /**
     * Verschlüsselt eine Datei
     * 
     * @param string $sourcePath  Pfad zur Quelldatei (Klartext)
     * @param string $destPath    Pfad zur Zieldatei (verschlüsselt)
     * @return bool Erfolg
     */
    public function encryptFile(string $sourcePath, string $destPath): bool
    {
        $this->assertReady();
        
        $data = @file_get_contents($sourcePath);
        if ($data === false) {
            return false;
        }
        
        $encrypted = $this->encrypt($data);
        $result = @file_put_contents($destPath, $encrypted);
        
        // Klartext aus dem Speicher löschen
        if (function_exists('sodium_memzero')) {
            sodium_memzero($data);
        }
        
        return $result !== false;
    }
    
    /**
     * Entschlüsselt eine Datei
     */
    public function decryptFile(string $encryptedPath): string|false
    {
        $data = @file_get_contents($encryptedPath);
        if ($data === false) {
            return false;
        }
        
        try {
            return $this->decrypt($data);
        } catch (\RuntimeException $e) {
            return false;
        }
    }
    
    /**
     * Aktive Verschlüsselungsmethode
     */
    public function getMethod(): ?string
    {
        return $this->method;
    }
    
    /**
     * Ob Verschlüsselung verfügbar ist
     */
    public function isAvailable(): bool
    {
        return $this->method !== null && $this->keyManager->hasKey();
    }
    
    /**
     * Warnungen abrufen
     */
    public function getWarnings(): array
    {
        return array_merge($this->warnings, $this->keyManager->getWarnings());
    }
    
    // =========================================================================
    // SODIUM (XSalsa20-Poly1305)
    // =========================================================================
    
    private function encryptSodium(string $data): string
    {
        $key   = $this->keyManager->getKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $ciphertext = sodium_crypto_secretbox($data, $nonce, $key);
        
        // Klartext aus Speicher löschen
        sodium_memzero($data);
        
        return base64_encode($nonce . $ciphertext);
    }
    
    private function decryptSodium(string $encoded): string|false
    {
        $key     = $this->keyManager->getKey();
        $decoded = base64_decode($encoded, true);
        
        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        
        $nonce      = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        
        return $plaintext !== false ? $plaintext : false;
    }
    
    // =========================================================================
    // OPENSSL AES-256-GCM
    // =========================================================================
    
    private function encryptOpenSSL(string $data): string
    {
        $key   = $this->keyManager->getKey();
        $nonce = random_bytes(openssl_cipher_iv_length(self::OPENSSL_CIPHER));
        $tag   = '';
        
        $ciphertext = openssl_encrypt(
            $data,
            self::OPENSSL_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::OPENSSL_TAG_LENGTH
        );
        
        if ($ciphertext === false) {
            throw new \RuntimeException('OpenSSL AES-256-GCM Verschlüsselung fehlgeschlagen.');
        }
        
        // Format: nonce + tag + ciphertext
        return base64_encode($nonce . $tag . $ciphertext);
    }
    
    private function decryptOpenSSL(string $encoded): string|false
    {
        $key     = $this->keyManager->getKey();
        $decoded = base64_decode($encoded, true);
        
        if ($decoded === false) {
            return false;
        }
        
        $nonceLen = openssl_cipher_iv_length(self::OPENSSL_CIPHER);
        $minLen   = $nonceLen + self::OPENSSL_TAG_LENGTH;
        
        if (strlen($decoded) < $minLen) {
            return false;
        }
        
        $nonce      = substr($decoded, 0, $nonceLen);
        $tag        = substr($decoded, $nonceLen, self::OPENSSL_TAG_LENGTH);
        $ciphertext = substr($decoded, $nonceLen + self::OPENSSL_TAG_LENGTH);
        
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::OPENSSL_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        
        return $plaintext !== false ? $plaintext : false;
    }
    
    // =========================================================================
    // LEGACY-KOMPATIBILITÄT
    // =========================================================================
    
    /**
     * Versucht Legacy-Daten ohne Prefix zu entschlüsseln
     * (Abwärtskompatibilität mit v3.x Daten die ohne Prefix gespeichert wurden)
     */
    private function decryptLegacy(string $encrypted): string|false
    {
        // Erst Sodium versuchen
        if ($this->method === self::METHOD_SODIUM || $this->method === null) {
            $result = $this->decryptSodium($encrypted);
            if ($result !== false) {
                return $result;
            }
        }
        
        // Dann OpenSSL
        $result = $this->decryptOpenSSL($encrypted);
        if ($result !== false) {
            return $result;
        }
        
        return false;
    }
    
    // =========================================================================
    // INTERNE METHODEN
    // =========================================================================
    
    /**
     * Erkennt die beste verfügbare Verschlüsselungsmethode
     */
    private function detectMethod(): void
    {
        // 1. libsodium (bevorzugt)
        if (
            extension_loaded('sodium')
            && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
        ) {
            $this->method = self::METHOD_SODIUM;
            return;
        }
        
        // 2. OpenSSL AES-256-GCM
        if (
            extension_loaded('openssl')
            && in_array(self::OPENSSL_CIPHER, openssl_get_cipher_methods(), true)
        ) {
            $this->method = self::METHOD_OPENSSL;
            $this->warnings[] = 'libsodium nicht verfügbar. Nutze OpenSSL AES-256-GCM.';
            return;
        }
        
        // Keine Verschlüsselung möglich
        $this->method = null;
        $this->warnings[] = 'KRITISCH: Weder libsodium noch OpenSSL AES-256-GCM verfügbar!';
    }
    
    /**
     * Stellt sicher, dass Verschlüsselung verfügbar ist
     */
    private function assertReady(): void
    {
        if ($this->method === null) {
            throw new \RuntimeException(
                'Keine Verschlüsselungsmethode verfügbar. '
                . 'Bitte libsodium oder OpenSSL installieren.'
            );
        }
        
        // Stellt sicher, dass Key geladen ist (wirft Exception wenn nicht)
        $this->keyManager->getKey();
    }
    
    /**
     * Prüft ob der Verschlüsselungsschlüssel vorhanden und gültig ist
     */
    public function isKeyValid(): bool
    {
        try {
            $key = $this->keyManager->getKey();
            return !empty($key) && $this->isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * Schlüssel neu generieren (Achtung: bestehende Daten werden unlesbar!)
     */
    public function resetKey(): array
    {
        try {
            // Key-Datei am konfigurierten Pfad löschen
            $keyFile = Config::getKeyFile();
            if (!empty($keyFile) && file_exists($keyFile)) {
                // Inhalt sicher überschreiben bevor gelöscht wird
                $len = filesize($keyFile);
                if ($len > 0) {
                    $fh = fopen($keyFile, 'r+');
                    if ($fh) {
                        fwrite($fh, str_repeat("\0", $len));
                        fclose($fh);
                    }
                }
                unlink($keyFile);
            }

            // KeyManager-Cache invalidieren und neuen Key generieren
            $this->keyManager->clearCache();
            $this->keyManager->ensureKeyExists();
            $this->detectMethod();
            
            return [
                'success' => true,
                'message' => 'Schlüssel wurde neu generiert.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
