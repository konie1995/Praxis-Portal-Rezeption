# Praxis-Portal

**DSGVO-konformes Patientenportal für WordPress** – Service-Widget, digitale Anamnese, Multi-Standort, AES-256-Verschlüsselung.

> Verwandelt Ihre Praxis-Website in ein vollwertiges Patientenportal. Patienten können Rezepte bestellen, Termine anfragen und Anamnesebögen digital ausfüllen – verschlüsselt und datenschutzkonform.

---

## Features

### Service-Widget
Patienten nutzen ein Sticky-Widget auf Ihrer Website für alltägliche Anfragen:
- **Rezept-Bestellung** – mit Medikamenten-Autocomplete (1.300+ Einträge), Foto-Upload und Kassenwahl
- **Überweisung anfragen** – für Bestandspatienten
- **Brillenverordnung** – inkl. Prismen und HSA-Werte
- **Dokument hochladen** – PDF, JPG, PNG, WebP (max. 10 MB)
- **Termin anfragen** – auch für Neupatienten
- **Termin absagen** – für Bestandspatienten

### Digitale Anamnese
JSON-basierte Fragebögen für 6 Fachrichtungen (564 Felder, 204 konditional):
- Augenarzt, Allgemeinarzt, HNO, Zahnarzt, Dermatologe, Orthopäde
- Eigene Formulare als JSON definierbar
- Unterschrift-Feld mit Signatur-Capture

### Multi-Standort
- Beliebig viele Praxis-Standorte mit eigenen Services, Öffnungszeiten und Lizenzen
- UUID-basierte Standort-Identifikation
- Automatische Standort-Erkennung per URL-Slug

### Portal für MFA
Geschützter Bereich für Medizinische Fachangestellte:
- Eingangs-Übersicht mit Status-Verwaltung (Neu → In Bearbeitung → Erledigt)
- Detail-Ansicht mit entschlüsselten Patientendaten
- PDF-Druck und Datei-Download

### Export für Praxissoftware (PVS)
- **GDT/BDT 3.0** – Medistar, Albis, Turbomed, Duria etc.
- **FHIR** – Moderner Standard (experimentell)
- **HL7** – Klinik-Integration (experimentell)
- **PDF** – Automatische Anamnese-PDFs mit Signatur

### Sicherheit
- **AES-256 Verschlüsselung** – Sodium (XSalsa20-Poly1305) oder OpenSSL AES-256-GCM
- Schlüsseldatei außerhalb des Web-Root
- CSRF-Schutz (Nonce-Validierung) auf allen Endpunkten
- Rate-Limiting gegen Brute-Force
- Verschlüsselte Portal-Passwörter (bcrypt)
- Audit-Log für alle Datenzugriffe

### DSGVO-Konformität
- Einwilligungsverwaltung mit Versionierung
- WordPress Privacy-Export & -Löschung integriert
- Automatische Datenbereinigung (konfigurierbar)
- Soft-Delete mit nachträglicher endgültiger Löschung
- Vollständiger Uninstaller (67 Options, alle Tabellen, verschlüsselte Dateien)

### Mehrsprachigkeit
Deutsch, Englisch, Französisch, Italienisch, Niederländisch

---

## Systemanforderungen

| Komponente | Minimum |
|-----------|---------|
| WordPress | 5.8+ |
| PHP | 8.0+ |
| MySQL | 5.7+ / MariaDB 10.3+ |
| OpenSSL | Extension muss aktiv sein |
| mbstring | Extension muss aktiv sein |
| libsodium | Empfohlen (Fallback: OpenSSL) |

---

## Installation

1. Plugin-ZIP über **WordPress → Plugins → Installieren** hochladen
2. Plugin aktivieren → Der **Einrichtungsassistent** startet automatisch
3. Dem Wizard folgen: Systemcheck → Lizenz → Standort → Sicherheit → Portal
4. Widget per Shortcode auf einer Seite einbinden

### Shortcodes

| Shortcode | Beschreibung |
|-----------|-------------|
| `[pp_widget]` | Service-Widget (auch: `[praxis_widget]`, `[pp_anamnesebogen]`) |
| `[pp_fragebogen]` | Eigenständiger Anamnesebogen |
| `[pp_portal]` | MFA-Portal (auch: `[praxis_portal]`) |

---

## Architektur

```
praxis-portal/
├── praxis-portal.php          # Bootstrap + Autoloader
├── src/
│   ├── Core/                  # Plugin, Container, Config, Hooks
│   ├── Security/              # Encryption, KeyManager, Sanitizer, RateLimiter
│   ├── Database/
│   │   ├── Schema.php         # 11 Tabellen (dbDelta)
│   │   ├── Migration.php      # Versionsbasierte Migrationen
│   │   └── Repository/        # AbstractRepository + 12 konkrete Repos
│   ├── Admin/                 # 10 Admin-Sub-Klassen (lazy-loaded)
│   ├── Widget/                # Widget, WidgetHandler, WidgetRenderer
│   ├── Form/                  # FormLoader, FormValidator, FormHandler
│   ├── Portal/                # Portal, PortalAuth
│   ├── Export/                # GDT, FHIR, HL7, PDF (Anamnese + Widget)
│   ├── License/               # LicenseManager, LicenseClient, FeatureGate
│   ├── Location/              # LocationManager, LocationResolver, ServiceManager
│   ├── Privacy/               # DSGVO Privacy-Handler
│   ├── I18n/                  # Übersetzungs-System
│   └── Update/                # Self-Hosted Updater mit SHA256-Check
├── admin/                     # Admin CSS + JS
├── assets/                    # Frontend CSS + JS
├── data/                      # Medikamenten-CSV
├── forms/                     # 6 JSON-Anamnesebögen
├── languages/                 # 5 Sprachdateien
├── templates/                 # PHP-Templates (Portal + Widget)
├── tests/                     # Diagnose-Tool (22 Testgruppen)
└── uninstall.php              # DSGVO-konforme Deinstallation
```

### Design-Prinzipien
- **Dependency Injection** über eigenen Service-Container mit Zirkulärerkennung
- **Repository-Pattern** für alle Datenbankzugriffe
- **Feature-Gating** trennt Free/Premium sauber
- **PSR-4 Autoloading** via `spl_autoload_register`
- **Namespace**: `PraxisPortal\*`

---

## Konfiguration

### Verschlüsselungsschlüssel

Der Schlüssel wird automatisch generiert. Für maximale Sicherheit kann er manuell konfiguriert werden:

```php
// wp-config.php – Option 1: Pfad zur Schlüsseldatei
define('PP_ENCRYPTION_KEY_PATH', '/home/user/pp-secure/.encryption_key');

// wp-config.php – Option 2: Schlüssel direkt (Base64, 32 Bytes)
define('PP_ENCRYPTION_KEY', 'dein-base64-encodierter-schluessel');
```

**Priorität der Schlüssel-Quellen:**
1. ENV-Variable `PP_ENCRYPTION_KEY`
2. wp-config.php Konstante `PP_ENCRYPTION_KEY`
3. Datei am sicheren Pfad (automatisch ermittelt)

### Upload-Pfad

```php
// wp-config.php – Uploads außerhalb des Web-Root
define('PP_UPLOAD_PATH', '/home/user/pp-encrypted-uploads/');
```

### Weitere Optionen

```php
define('PP_TRUST_PROXY', true);   // Proxy-Header für IP-Erkennung
define('PP_SECURE_BASE', '/path'); // Basis-Pfad für sichere Dateien
```

---

## REST-API

Alle Endpunkte erfordern einen API-Key im Header `X-API-Key`.

| Methode | Endpunkt | Beschreibung |
|---------|----------|-------------|
| GET | `/wp-json/praxis-portal/v1/submissions` | Alle Einreichungen |
| GET | `/wp-json/praxis-portal/v1/submissions/{id}` | Einzelne Einreichung |
| POST | `/wp-json/praxis-portal/v1/submissions/{id}/status` | Status ändern |
| GET | `/wp-json/praxis-portal/v1/submissions/{id}/gdt` | GDT-Export |
| GET | `/wp-json/praxis-portal/v1/submissions/{id}/fhir` | FHIR-Export |
| GET | `/wp-json/praxis-portal/v1/submissions/{id}/pdf` | PDF-Export |
| GET | `/wp-json/praxis-portal/v1/submissions/{id}/files` | Dateien abrufen |
| GET | `/wp-json/praxis-portal/v1/status` | System-Status |

---

## Eigene Formulare erstellen

Formulare werden als JSON definiert. Legen Sie eigene Dateien unter `wp-content/uploads/pp-custom-forms/` ab.

Grundstruktur:
```json
{
  "id": "mein_formular",
  "name": "Mein Anamnesebogen",
  "version": "1.0.0",
  "specialty": "allgemein",
  "sections": [
    {
      "id": "stammdaten",
      "title": "Persönliche Daten",
      "fields": [
        {
          "id": "vorname",
          "type": "text",
          "label": "Vorname",
          "required": true
        }
      ]
    }
  ]
}
```

Unterstützte Feldtypen: `text`, `textarea`, `select`, `radio`, `checkbox`, `date`, `email`, `tel`, `number`, `file`, `signature`, `info`

---

## Lizenzierung

| Plan | Features |
|------|----------|
| **Free** | Widget-Services, PDF-Export, 50 Anfragen/Monat, 1 Standort |
| **Premium** | + GDT-Export, E-Mail-Benachrichtigungen, unbegrenzte Anfragen |
| **Premium Plus** | + FHIR/HL7, API-Zugang, Multi-Standort, White-Label |

Alle Widget-Services (Rezept, Überweisung, Brillenverordnung, Dokument, Termin, Terminabsage) sind **immer kostenlos** verfügbar.

---

## Entwicklung

### Diagnose-Tool
Als Admin unter `?page=pp-system` erreichbar. Enthält 22 Testgruppen mit Runtime-Tests für:
- Verschlüsselung (Grenzwerte, Manipulation, IV-Variation)
- Portal-Login (Passwort-Hashing, Brute-Force)
- Multi-Standort (Datenisolation, UUID-Suche)
- DSGVO (Löschung, Nicht-Auffindbarkeit)
- Export-Pipeline (GDT/FHIR/HL7 mit Testdaten)
- API-Key-Validierung
- Concurrent Submissions

### Technische Details
- **65 PHP-Klassen** in 12 Namespaces
- **11 Datenbank-Tabellen** (alle mit `pp_` Prefix)
- **12 Repository-Klassen** für typisierte DB-Zugriffe
- **Versionsbasierte Migrationen** (v3.0 → v4.2.9)
- **Self-Hosted Updates** mit SHA256-Integritätsprüfung

---

## FAQ

**Brauche ich einen Lizenzschlüssel?**
Für den Free-Plan nicht. Premium-Features erfordern einen Schlüssel pro Standort.

**Wo werden die Daten gespeichert?**
Lokal in Ihrer WordPress-Datenbank (AES-256 verschlüsselt). Keine externen Server außer dem Lizenz-Heartbeat.

**Funktioniert das mit meiner Praxissoftware?**
Über GDT-Export kompatibel mit Medistar, Albis, Turbomed, Duria und anderen deutschen PVS-Systemen.

**WordPress Multisite?**
Nicht offiziell unterstützt. Multi-Standort wird über die eingebaute Standortverwaltung gelöst.

---

## Changelog

Siehe [readme.txt](readme.txt) für den vollständigen Changelog.

### Aktuelle Version: 4.2.908
- Umfangreiche Code-Architektur-Überarbeitung
- 65 PHP-Klassen mit Namespace-Struktur
- Diagnose-Tool mit 22 Testgruppen
- 6 Fachrichtungen für Anamnesebögen

---

## Lizenz

GPL v3 – siehe [LICENSE](LICENSE)

---

**Praxis-Portal** | [praxis-portal.de](https://praxis-portal.de)
