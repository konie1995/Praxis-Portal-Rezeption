<?php
declare(strict_types=1);
/**
 * Input-Sanitizer
 * 
 * PHP 8.x-sichere Wrapper für WordPress Sanitize-Funktionen.
 * Verhindert "Passing null to parameter" Deprecation-Warnungen.
 *
 * @package PraxisPortal\Security
 * @since   4.0.0
 */

namespace PraxisPortal\Security;

if (!defined('ABSPATH')) {
    exit;
}

class Sanitizer
{
    /**
     * Text-Feld sanitieren (einzeilig)
     */
    public static function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        // sanitize_text_field() entfernt bereits HTML-Tags und unerwünschte Zeichen
        return sanitize_text_field($value);
    }
    
    /**
     * Textarea sanitieren (mehrzeilig)
     */
    public static function textarea(?string $value): string
    {
        return sanitize_textarea_field($value ?? '');
    }
    
    /**
     * E-Mail sanitieren und normalisieren
     */
    public static function email(?string $value): string
    {
        return strtolower(sanitize_email($value ?? ''));
    }
    
    /**
     * URL sanitieren
     */
    public static function url(?string $value): string
    {
        return esc_url_raw($value ?? '');
    }
    
    /**
     * HTML-sicher ausgeben
     */
    public static function html(?string $value): string
    {
        return esc_html($value ?? '');
    }
    
    /**
     * Attribut-sicher ausgeben
     */
    public static function attr(?string $value): string
    {
        return esc_attr($value ?? '');
    }
    
    /**
     * Integer sanitieren
     */
    public static function int(mixed $value): int
    {
        return absint($value ?? 0);
    }
    
    /**
     * Slug sanitieren
     */
    public static function slug(?string $value): string
    {
        return sanitize_title($value ?? '');
    }
    
    /**
     * Key sanitieren (lowercase, alphanumeric + dashes)
     */
    public static function key(?string $value): string
    {
        return sanitize_key($value ?? '');
    }
    
    /**
     * Rekursive Sanitization für Arrays
     */
    public static function recursive(mixed $data, string $mode = 'text'): mixed
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $cleanKey = is_string($key) ? sanitize_key($key) : $key;
                $sanitized[$cleanKey] = self::recursive($value, $mode);
            }
            return $sanitized;
        }
        
        if (is_string($data)) {
            return match ($mode) {
                'textarea' => sanitize_textarea_field($data),
                'html'     => wp_kses_post($data),
                default    => self::text($data),
            };
        }
        
        return $data;
    }
    
    /**
     * POST-Parameter sicher abrufen
     */
    public static function post(string $key, string $mode = 'text'): string
    {
        $value = $_POST[$key] ?? '';
        return match ($mode) {
            'email'    => self::email($value),
            'url'      => self::url($value),
            'textarea' => self::textarea($value),
            'int'      => (string) self::int($value),
            default    => self::text($value),
        };
    }

    /**
     * POST-Parameter als Integer abrufen
     */
    public static function postInt(string $key, int $default = 0): int
    {
        return self::int($_POST[$key] ?? $default);
    }
    
    /**
     * GET-Parameter sicher abrufen
     */
    public static function get(string $key, string $mode = 'text'): string
    {
        $value = $_GET[$key] ?? '';
        return match ($mode) {
            'email' => self::email($value),
            'url'   => self::url($value),
            'int'   => (string) self::int($value),
            default => self::text($value),
        };
    }

    /**
     * GET-Parameter als Integer abrufen
     */
    public static function getInt(string $key, int $default = 0): int
    {
        return self::int($_GET[$key] ?? $default);
    }
    
    /**
     * Instanz-Alias für text() (für DI-Nutzung)
     */
    public function sanitizeText(?string $value): string
    {
        return self::text($value);
    }
    
    /**
     * Telefonnummer bereinigen – nur Ziffern, +, Leerzeichen, -
     */
    public static function phone(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        return preg_replace('/[^0-9+\-\s()]/', '', $value) ?? '';
    }
    
    /**
     * Instanz-Alias für phone() (für DI-Nutzung)
     */
    public function sanitizePhone(?string $value): string
    {
        return self::phone($value);
    }
}
