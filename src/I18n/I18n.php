<?php
/**
 * Internationalisierung (i18n)
 * 
 * Verwaltet Übersetzungen für DE, EN, FR, IT, NL.
 * Erkennt die Sprache automatisch aus WordPress-Einstellungen.
 *
 * @package PraxisPortal\I18n
 * @since   4.0.0
 */

namespace PraxisPortal\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class I18n
{
    /** Unterstützte Sprachen */
    public const SUPPORTED_LANGUAGES = ['de_DE', 'en_US', 'fr_FR', 'it_IT', 'nl_NL'];
    
    /** Aktuelle Sprache */
    private static string $currentLanguage = 'de_DE';
    
    /** Geladene Übersetzungen */
    private static array $translations = [];
    
    public function __construct()
    {
        self::$currentLanguage = self::detectLanguage();
        self::loadTranslations();
    }
    
    /**
     * Text übersetzen
     *
     * @param string $text    Zu übersetzender Text
     * @param string $context Optionaler Kontext (für zukünftige kontextbezogene Übersetzungen)
     */
    public static function translate(string $text, string $context = ''): string
    {
        // Kontextbezogene Übersetzung: "context.text" hat Vorrang vor "text"
        if ($context !== '' && isset(self::$translations[$context . '.' . $text])) {
            return self::$translations[$context . '.' . $text];
        }
        return self::$translations[$text] ?? $text;
    }

    /**
     * Instanz-Methode für translate() – wird von WidgetRenderer etc. genutzt
     */
    public function t(string $text): string
    {
        return self::translate($text);
    }
    
    /**
     * Aktuelle Sprache
     */
    public static function getLanguage(): string
    {
        return self::$currentLanguage;
    }
    
    /**
     * Sprach-Code (2 Buchstaben)
     */
    public static function getLanguageCode(): string
    {
        return substr(self::$currentLanguage, 0, 2);
    }
    
    /**
     * Aktuelles Locale (z.B. de_DE) – Instanz-Methode für DI
     */
    public function getLocale(): string
    {
        return self::$currentLanguage;
    }
    
    /**
     * Sprache erkennen
     */
    private static function detectLanguage(): string
    {
        // 1. URL-Parameter
        $lang = sanitize_text_field($_GET['lang'] ?? '');
        if ($lang !== '' && self::isSupported($lang)) {
            return $lang;
        }
        
        // 2. WordPress-Locale
        $locale = get_locale();
        if (self::isSupported($locale)) {
            return $locale;
        }
        
        // 3. Nur Sprach-Code prüfen
        $langCode = substr($locale, 0, 2);
        foreach (self::SUPPORTED_LANGUAGES as $supported) {
            if (str_starts_with($supported, $langCode)) {
                return $supported;
            }
        }
        
        return 'de_DE';
    }
    
    /**
     * Prüft ob eine Sprache unterstützt wird
     */
    private static function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LANGUAGES, true);
    }
    
    /**
     * Übersetzungen laden
     */
    private static function loadTranslations(): void
    {
        if (self::$currentLanguage === 'de_DE') {
            self::$translations = [];
            return;
        }
        
        $file = PP_PLUGIN_DIR . 'languages/' . self::$currentLanguage . '.php';
        if (file_exists($file)) {
            self::$translations = require $file;
        }
    }
}

// =========================================================================
// GLOBALE HILFSFUNKTIONEN
// =========================================================================

/**
 * Shortcut: Text übersetzen
 */
function pp__(string $text): string
{
    return I18n::translate($text);
}
