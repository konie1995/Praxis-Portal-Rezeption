<?php
declare(strict_types=1);
/**
 * Einfacher Service-Container
 * 
 * Verwaltet alle Plugin-Services als Singletons.
 * Verhindert, dass Klassen ihre Abhängigkeiten selbst instanziieren.
 *
 * @package PraxisPortal\Core
 * @since   4.0.0
 */

namespace PraxisPortal\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Container
{
    /** @var array<string, object> Registrierte Service-Instanzen */
    private array $instances = [];
    
    /** @var array<string, callable> Factory-Funktionen */
    private array $factories = [];

    /** @var array<string, true> Aktuell aufgelöste Services (Zirkelerkennung) */
    private array $resolving = [];
    
    /** @var self|null Singleton */
    private static ?self $instance = null;
    
    private function __construct() {}
    
    /**
     * Gibt die Container-Instanz zurück
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Registriert eine Factory-Funktion für einen Service
     * 
     * @param string   $id      Service-Identifier (Klassenname oder Alias)
     * @param callable $factory  Funktion die den Service erstellt
     */
    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        // Vorherige Instanz entfernen (ermöglicht Re-Registrierung)
        unset($this->instances[$id]);
    }
    
    /**
     * Gibt einen Service zurück (erstellt ihn bei Bedarf)
     * 
     * @template T
     * @param class-string<T> $id Service-Identifier
     * @return T
     * @throws \RuntimeException Wenn Service nicht registriert
     */
    public function get(string $id): object
    {
        // Singleton: Bereits erstellt?
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        
        // Factory vorhanden?
        if (isset($this->factories[$id])) {
            // Zirkuläre Abhängigkeit erkennen
            if (isset($this->resolving[$id])) {
                $chain = implode(' → ', array_keys($this->resolving)) . ' → ' . $id;
                throw new \RuntimeException(
                    sprintf('Zirkuläre Abhängigkeit erkannt: %s', $chain)
                );
            }

            $this->resolving[$id] = true;
            try {
                $this->instances[$id] = ($this->factories[$id])($this);
            } finally {
                unset($this->resolving[$id]);
            }
            return $this->instances[$id];
        }
        
        throw new \RuntimeException(
            sprintf('Service "%s" ist nicht registriert.', $id)
        );
    }
    
    /**
     * Prüft ob ein Service registriert ist
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }
    
    /**
     * Setzt eine bereits erstellte Instanz
     */
    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }
}
