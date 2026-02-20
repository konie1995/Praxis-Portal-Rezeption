# Praxis-Portal v4.0 – Architektur-Dokumentation

## Überblick

Praxis-Portal v4.0 ist ein DSGVO-konformes WordPress-Plugin für medizinische Praxen.
Es bietet Anamnese-Fragebögen, ein Service-Widget, ein Praxis-Portal und Multi-Standort-Verwaltung.

**Kernprinzipien:**
- Konsequente AES-256-Bit Verschlüsselung aller Patientendaten
- Sensible Daten (Keys, verschlüsselte Uploads) außerhalb des Web-Root
- Saubere Multistandort-Logik mit LocationResolver
- Definierte REST-Endpunkte für den Lizenzserver
- Klare Benennung: `license_key`, `location_uuid`, `place_id`
- Service-Container für zentrale Dependency Injection

---

## 1. Verzeichnisstruktur

```
praxis-portal/
├── praxis-portal.php                 ← Schlanker Bootstrap (~80 Zeilen)
├── uninstall.php                     ← Deinstallation
│
├── src/                              ← Gesamte Plugin-Logik
│   ├── Core/
│   │   ├── Plugin.php                ← Singleton, Boot-Sequenz
│   │   ├── Container.php             ← Service-Container (DI)
│   │   ├── Config.php                ← Konstanten, Pfade, Defaults
│   │   └── Hooks.php                 ← Zentrale Hook-Registrierung
│   │
│   ├── Security/
│   │   ├── Encryption.php            ← AES-256-GCM / Sodium AEAD
│   │   ├── KeyManager.php            ← Schlüssel-Speicherung & Rotation
│   │   ├── Sanitizer.php             ← Input-Sanitization
│   │   └── RateLimiter.php           ← Rate-Limiting für Formulare/API
│   │
│   ├── Location/
│   │   ├── LocationManager.php       ← CRUD für Standorte
│   │   ├── LocationResolver.php      ← Ermittelt aktuellen Standort
│   │   ├── LocationContext.php        ← Trägt Standort-Daten im Request
│   │   └── ServiceManager.php        ← Services pro Standort
│   │
│   ├── License/
│   │   ├── LicenseManager.php        ← Lizenz-Validierung & Caching
│   │   ├── LicenseClient.php         ← HTTP-Client für Lizenzserver
│   │   ├── LicenseEndpoints.php      ← Alle definierten API-Endpunkte
│   │   └── FeatureGate.php           ← Feature-Verfügbarkeit
│   │
│   ├── Database/
│   │   ├── Schema.php                ← Tabellen-Definitionen
│   │   ├── Migration.php             ← Versions-basierte Migrationen
│   │   └── Repository/
│   │       ├── LocationRepository.php
│   │       ├── SubmissionRepository.php
│   │       ├── ServiceRepository.php
│   │       ├── PortalUserRepository.php
│   │       ├── ApiKeyRepository.php
│   │       ├── FileRepository.php
│   │       └── AuditRepository.php
│   │
│   ├── Form/
│   │   ├── FormConfig.php            ← Formular-Definitionen
│   │   ├── FormLoader.php            ← JSON-Formulare laden
│   │   ├── FormHandler.php           ← Formular-Verarbeitung
│   │   └── FormValidator.php         ← Eingabe-Validierung
│   │
│   ├── Export/
│   │   ├── ExportBase.php            ← Abstrakte Basis
│   │   ├── GdtExport.php             ← BDT/GDT 3.0 Export
│   │   ├── FhirExport.php            ← FHIR R4 Export
│   │   ├── Hl7Export.php             ← HL7 Export
│   │   ├── ExportConfig.php          ← Export-Einstellungen
│   │   └── Pdf/
│   │       ├── PdfBase.php
│   │       ├── PdfAnamnese.php
│   │       └── PdfWidget.php
│   │
│   ├── Widget/
│   │   ├── Widget.php                ← Widget-Hauptklasse
│   │   ├── WidgetHandler.php         ← AJAX-Handler
│   │   └── WidgetRenderer.php        ← Template-Rendering
│   │
│   ├── Portal/
│   │   ├── Portal.php                ← Portal-Frontend
│   │   ├── PortalAuth.php            ← Session-Management
│   │   └── PortalApi.php             ← Portal AJAX-Endpunkte
│   │
│   ├── Api/
│   │   ├── PvsApi.php                ← PVS REST-API
│   │   ├── PvsApiBdt.php             ← BDT-Endpunkte
│   │   ├── PvsApiWidget.php          ← Widget-Endpunkte
│   │   └── PvsApiAnamnese.php        ← Anamnese-Endpunkte
│   │
│   └── I18n/
│       └── I18n.php                  ← Mehrsprachigkeit
│
├── admin/                            ← Admin-Bereich
│   ├── views/                        ← Admin-Templates
│   ├── css/
│   └── js/
│
├── assets/                           ← Frontend-Assets
│   ├── css/
│   └── js/
│
├── data/                             ← Statische Daten
│   └── medications-praxis.csv
│
├── forms/                            ← JSON-Formulare (pro Fachrichtung)
│   ├── augenarzt_de.json
│   ├── hausarzt_de.json
│   └── ...
│
├── languages/                        ← Übersetzungen
├── templates/                        ← Frontend-Templates
│   ├── portal.php
│   └── widget/
└── uploads/                          ← Temporäre Uploads (Fallback)
```

---

## 2. Namenskonventionen

### Datenbank-Felder & Variablen

| Alt (v3.x)     | Neu (v4.0)         | Bedeutung                                    |
|----------------|--------------------|----------------------------------------------|
| `doc_key`      | `license_key`      | Lizenzschlüssel (z.B. `DOC-26-001-ABC`)     |
| `place_id`     | `location_uuid`    | Server-vergebene Standort-UUID (z.B. `LOC-a1b2c3`) |
| `location_id`  | `location_id`      | Lokale Auto-Increment DB-ID                  |
| `pp_widget_*`  | via `LocationContext` | Standort-Einstellungen nicht mehr global   |

### PHP-Klassen

Alle Klassen nutzen den Namespace `PraxisPortal\`:
```php
namespace PraxisPortal\Core;
namespace PraxisPortal\Security;
namespace PraxisPortal\Location;
// etc.
```

### Konstanten

```php
PP_VERSION          = '4.1.0'
PP_PLUGIN_DIR       = plugin_dir_path(__FILE__)
PP_PLUGIN_URL       = plugin_dir_url(__FILE__)
PP_MIN_PHP          = '8.0'
PP_MIN_WP           = '5.8'
```

---

## 3. Verschlüsselung (AES-256-Bit)

### Strategie

**Jedes** Patientendatum wird verschlüsselt – in der Datenbank und im Dateisystem.

```
┌─────────────────────────────────────────────────┐
│                VERSCHLÜSSELUNGSKETTE             │
│                                                   │
│  Klartext-Daten                                   │
│       │                                           │
│       ▼                                           │
│  Encryption::encrypt($data, $context)             │
│       │                                           │
│       ├── Methode: libsodium (bevorzugt)          │
│       │   └── XSalsa20-Poly1305 (AEAD)            │
│       │                                           │
│       └── Fallback: OpenSSL AES-256-GCM (AEAD)    │
│                                                   │
│  Ergebnis: PREFIX:Base64(nonce + [tag] + cipher)  │
│  Format:   "S:" für Sodium, "O:" für OpenSSL      │
│                                                   │
│  Schlüssel-Speicherung:                           │
│  1. ENV-Variable PP_ENCRYPTION_KEY (bevorzugt)    │
│  2. wp-config.php Konstante PP_ENCRYPTION_KEY     │
│  3. Datei: ~/pp-portal/secure/.encryption_key     │
│  4. Datei: ../pp-encryption/.encryption_key       │
│  5. Fallback: wp-content/.pp_encryption_key       │
│     (mit .htaccess-Schutz)                        │
└─────────────────────────────────────────────────┘
```

### Verschlüsselte Felder in der Datenbank

| Tabelle          | Feld                     | Verschlüsselt |
|------------------|--------------------------|:-------------:|
| pp_submissions   | encrypted_data           | ✅ AES-256    |
| pp_submissions   | signature_data           | ✅ AES-256    |
| pp_submissions   | response_text            | ✅ AES-256    |
| pp_submissions   | ip_hash                  | SHA-256 Hash  |
| pp_submissions   | name_hash                | SHA-256 Hash  |
| pp_files         | original_name_encrypted  | ✅ AES-256    |
| pp_files         | Datei auf Disk           | ✅ AES-256    |
| pp_audit_log     | details_encrypted        | ✅ AES-256    |
| pp_audit_log     | portal_username          | ✅ AES-256    |
| pp_portal_users  | display_name             | ✅ AES-256    |
| pp_portal_users  | email                    | ✅ AES-256    |
| pp_api_keys      | api_key                  | SHA-256 Hash  |
| pp_api_keys      | ip_whitelist             | ✅ AES-256    |
| pp_locations     | email_notification       | ✅ AES-256    |
| pp_locations     | email_from_address       | ✅ AES-256    |

### Schlüssel-Sicherheit

```
~/pp-portal/                    ← Außerhalb Web-Root
├── secure/
│   ├── .encryption_key         ← chmod 0600, 32 Bytes Base64
│   └── .htaccess               ← deny from all
└── uploads/
    ├── .htaccess               ← deny from all
    └── {uuid}.enc              ← Verschlüsselte Dateien
```

---

## 4. Multistandort-Architektur

### Das Problem in v3.x

Die Location-Auflösung war über viele Klassen verstreut:
- `$GLOBALS['pp_current_location_id']` (globale Variable)
- `$_GET['location_id']` (URL-Parameter)
- `PP_Database::get_default_location_id()` (DB-Fallback)
- Jede Klasse hat eigene Fallback-Logik

### Die Lösung in v4.0: LocationResolver + LocationContext

```
┌──────────────────────────────────────────────────────────┐
│                    REQUEST-LIFECYCLE                       │
│                                                            │
│  1. WordPress lädt Plugin                                 │
│       │                                                    │
│  2. LocationResolver::resolve()                           │
│       │                                                    │
│       ├── Priorität 1: Expliziter Parameter               │
│       │   └── ?location=<slug> oder ?lid=<id>             │
│       │                                                    │
│       ├── Priorität 2: Shortcode-Attribut                 │
│       │   └── [pp_widget location="hauptpraxis"]          │
│       │                                                    │
│       ├── Priorität 3: Portal-Session                     │
│       │   └── Eingeloggter Portal-User → sein Standort    │
│       │                                                    │
│       ├── Priorität 4: Cookie-Präferenz                   │
│       │   └── pp_location_slug (verschlüsselter Cookie)   │
│       │                                                    │
│       └── Priorität 5: Default-Standort                   │
│           └── is_default = 1 in DB                        │
│                                                            │
│  3. LocationContext wird erstellt                          │
│       │                                                    │
│       ├── location_id (lokal)                             │
│       ├── location_uuid (Server-UUID)                     │
│       ├── license_key (Lizenz)                            │
│       ├── settings (Name, Farben, etc.)                   │
│       └── services (aktive Services)                      │
│                                                            │
│  4. Alle Klassen nutzen LocationContext                    │
│       └── Kein direkter DB-Zugriff für Location-Daten     │
└──────────────────────────────────────────────────────────┘
```

### Multi-Standort Datenfluss

```
┌──────────┐    ┌──────────┐    ┌──────────┐
│ Standort │    │ Standort │    │ Standort │
│ Kamen    │    │ Unna     │    │ Dortmund │
│ (Default)│    │          │    │          │
└────┬─────┘    └────┬─────┘    └────┬─────┘
     │               │               │
     │  license_key: DOC-26-001-ABC  │
     │  (eine Lizenz für alle)       │
     │               │               │
     ▼               ▼               ▼
┌──────────────────────────────────────┐
│         pp_locations Tabelle          │
│                                       │
│  id │ license_key    │ location_uuid  │
│  1  │ DOC-26-001-ABC │ LOC-a1b2c3    │
│  2  │ DOC-26-001-ABC │ LOC-d4e5f6    │
│  3  │ DOC-26-001-ABC │ LOC-g7h8i9    │
└──────────────────────────────────────┘
     │
     ▼
┌──────────────────────────────────────┐
│       JEDE Tabelle hat location_id    │
│                                       │
│  pp_submissions.location_id           │
│  pp_services.location_id              │
│  pp_portal_users.location_id          │
│  pp_api_keys.location_id              │
│  pp_audit_log.location_id             │
│  pp_files → via submission.location_id│
└──────────────────────────────────────┘
```

---

## 5. Lizenzserver-Endpunkte

### Basis-URL

```
PRODUCTION:  https://api.praxis-portal.de/v1
STAGING:     https://staging-api.praxis-portal.de/v1
LEGACY:      https://augenarztkamen.de/wp-json/pp-license/v1
```

### Definierte Endpunkte

#### Lizenz-Management

| Methode | Endpunkt                      | Beschreibung                      | Auth        |
|---------|-------------------------------|-----------------------------------|-------------|
| POST    | `/license/activate`           | Lizenz aktivieren                 | license_key |
| POST    | `/license/validate`           | Lizenz-Status prüfen              | license_key |
| POST    | `/license/deactivate`         | Lizenz deaktivieren               | license_key |
| GET     | `/license/status`             | Aktueller Lizenz-Status           | license_key |
| GET     | `/license/features`           | Verfügbare Features & Limits      | license_key |
| POST    | `/license/heartbeat`          | Keep-Alive / Token-Refresh        | license_key |

#### Standort-Sync

| Methode | Endpunkt                      | Beschreibung                      | Auth        |
|---------|-------------------------------|-----------------------------------|-------------|
| POST    | `/locations/sync`             | Alle Standorte synchronisieren    | license_key |
| POST    | `/locations/register`         | Neuen Standort registrieren       | license_key |
| PUT     | `/locations/{uuid}`           | Standort-Daten aktualisieren      | license_key |
| DELETE  | `/locations/{uuid}`           | Standort deregistrieren           | license_key |
| GET     | `/locations`                  | Alle Standorte abrufen            | license_key |

#### Sicherheit & Updates

| Methode | Endpunkt                      | Beschreibung                      | Auth        |
|---------|-------------------------------|-----------------------------------|-------------|
| GET     | `/security/public-key`        | Aktuellen Public Key abrufen      | keine       |
| GET     | `/updates/check`              | Plugin-Update prüfen              | license_key |
| GET     | `/updates/download`           | Update-Paket herunterladen        | license_key |

### Request-Format

```http
POST /v1/license/activate HTTP/1.1
Host: api.praxis-portal.de
Content-Type: application/json
X-License-Key: DOC-26-001-ABC
X-Plugin-Version: 4.1.0
X-Site-URL: https://praxis-muster.de
X-PHP-Version: 8.2.0

{
  "license_key": "DOC-26-001-ABC",
  "site_url": "https://praxis-muster.de",
  "site_name": "Praxis Dr. Muster",
  "admin_email": "admin@praxis-muster.de",
  "plugin_version": "4.1.0",
  "php_version": "8.2.0",
  "wp_version": "6.4.0",
  "locations": [
    {
      "location_uuid": "LOC-a1b2c3",
      "name": "Hauptpraxis Kamen",
      "slug": "kamen"
    }
  ]
}
```

### Response-Format

```json
{
  "success": true,
  "data": {
    "license_key": "DOC-26-001-ABC",
    "plan": "premium_plus",
    "status": "active",
    "valid_until": "2026-12-31T23:59:59Z",
    "features": ["basic_forms", "gdt_export", "pdf_export", "fhir_export",
                  "hl7_export", "api_access", "email_notifications",
                  "multi_location", "white_label"],
    "limits": {
      "locations": 3,
      "requests_per_month": -1
    },
    "locations": [
      {
        "location_uuid": "LOC-a1b2c3",
        "status": "active"
      }
    ],
    "token": "eyJ...",
    "token_expires_at": "2026-02-10T00:00:00Z",
    "public_key": "-----BEGIN PUBLIC KEY-----\n..."
  }
}
```

---

## 6. Lizenz-Pläne & Features

| Feature              | Free | Premium | Premium Plus |
|---------------------|:----:|:-------:|:------------:|
| Basis-Formulare     |  ✅  |   ✅    |      ✅      |
| PDF-Export          |  ✅  |   ✅    |      ✅      |
| GDT/BDT-Export      |  ❌  |   ✅    |      ✅      |
| FHIR-Export         |  ❌  |   ✅    |      ✅      |
| HL7-Export          |  ❌  |   ✅    |      ✅      |
| PVS-API             |  ❌  |   ✅    |      ✅      |
| E-Mail-Benachr.     |  ❌  |   ✅    |      ✅      |
| Multi-Standort      |  ❌  |   ❌    |      ✅      |
| White-Label         |  ❌  |   ❌    |      ✅      |
| **Standort-Limit**  |  1   |    1    |      3       |
| **Anfragen/Monat**  |  50  |   ∞     |      ∞       |

---

## 7. Datenbank-Schema v4.0

### pp_locations

```sql
CREATE TABLE {prefix}pp_locations (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_key     VARCHAR(25)     DEFAULT NULL,     -- DOC-26-001-ABC
    location_uuid   VARCHAR(36)     UNIQUE,           -- LOC-a1b2c3 (vom Server)
    name            VARCHAR(100)    NOT NULL,
    slug            VARCHAR(50)     NOT NULL UNIQUE,
    -- Praxis-Stammdaten
    practice_name       VARCHAR(150),
    practice_owner      VARCHAR(100),
    practice_subtitle   VARCHAR(100),
    street              VARCHAR(100),
    postal_code         VARCHAR(10),
    city                VARCHAR(100),
    phone               VARCHAR(50),
    phone_emergency     VARCHAR(50),
    email               VARCHAR(255),      -- verschlüsselt
    website             VARCHAR(255),
    opening_hours       TEXT,
    logo_url            VARCHAR(255),
    -- Widget-Einstellungen
    color_primary       VARCHAR(7)   DEFAULT '#0066cc',
    color_secondary     VARCHAR(7)   DEFAULT '#28a745',
    widget_title        VARCHAR(100) DEFAULT 'Online-Service',
    widget_subtitle     VARCHAR(150),
    widget_welcome      TEXT,
    widget_position     VARCHAR(10)  DEFAULT 'right',
    -- E-Mail (verschlüsselt)
    email_notification  TEXT,               -- verschlüsselt
    email_from_name     VARCHAR(100),
    email_from_address  TEXT,               -- verschlüsselt
    email_signature     TEXT,
    -- Urlaub
    vacation_mode       TINYINT(1)   DEFAULT 0,
    vacation_message    TEXT,
    vacation_start      DATE,
    vacation_end        DATE,
    -- Links
    termin_url          VARCHAR(255),
    termin_button_text  VARCHAR(50)  DEFAULT 'Termin vereinbaren',
    privacy_url         VARCHAR(255),
    imprint_url         VARCHAR(255),
    consent_text        TEXT,
    -- Status
    is_active           TINYINT(1)   DEFAULT 1,
    is_default          TINYINT(1)   DEFAULT 0,
    export_format       VARCHAR(10)  DEFAULT 'gdt',
    sort_order          INT          DEFAULT 0,
    -- Timestamps
    created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Indizes
    INDEX idx_license_key  (license_key),
    INDEX idx_location_uuid(location_uuid),
    INDEX idx_active        (is_active),
    INDEX idx_default       (is_default)
);
```

---

## 8. Sichere Pfade

```
PRIORITÄT 1: Manuell (wp-config.php)
  define('PP_SECURE_BASE', '/pfad/ausserhalb/webroot/');

PRIORITÄT 2: Home-Verzeichnis
  ~/pp-portal/secure/         ← Schlüssel
  ~/pp-portal/uploads/        ← Verschlüsselte Dateien

PRIORITÄT 3: Oberhalb WordPress
  ../pp-secure/               ← Schlüssel
  ../pp-uploads/              ← Verschlüsselte Dateien

PRIORITÄT 4: Fallback (mit Schutz)
  wp-content/.pp-secure/      ← .htaccess: deny from all
```

---

## 9. Migration v3.x → v4.0

Die Migration erfolgt automatisch beim Plugin-Update:

1. **Datenbank**: `doc_key` → `license_key`, `place_id` → `location_uuid`
2. **Verschlüsselung**: Bereits verschlüsselte Daten bleiben kompatibel (Prefix S:/O:)
3. **Schlüssel**: Bestehende Schlüssel werden an neuen sicheren Ort kopiert
4. **Options**: Alte `pp_*` Options werden zu Standort-Daten migriert
5. **Dateien**: Verschlüsselte Uploads werden an neuen Pfad verschoben

---

## 10. Sicherheits-Checkliste

- [x] AES-256-GCM / XSalsa20-Poly1305 (AEAD) für alle Patientendaten
- [x] Schlüssel außerhalb Web-Root (chmod 0600)
- [x] CSRF-Schutz (WordPress Nonces) auf allen Formularen
- [x] Rate-Limiting auf öffentlichen Endpunkten
- [x] Honeypot-Felder gegen Spam
- [x] Input-Sanitization mit pp_sanitize_* Wrappern
- [x] Prepared Statements für alle SQL-Queries
- [x] Content Security Policy Headers
- [x] Session-Timeout konfigurierbar
- [x] Audit-Log für alle sensiblen Aktionen
- [x] IP-Hashing (kein Klartext-Speichern)
- [x] Lizenz-Token mit RSA-Signatur validiert
