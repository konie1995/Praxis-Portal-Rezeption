=== Praxis-Portal ===
Contributors: praxisportal
Tags: medical, praxis, anamnese, dsgvo, gdpr, patient, portal, widget
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 4.2.909
Requires PHP: 8.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

DSGVO-konformes Patientenportal für medizinische Praxen – Service-Widget, digitale Anamnese, Multi-Standort, AES-256-Verschlüsselung.

== Description ==

Praxis-Portal verwandelt Ihre Praxis-Website in ein vollwertiges Patientenportal. Patienten können Rezepte bestellen, Termine anfragen und Anamnesebögen digital ausfüllen – verschlüsselt und datenschutzkonform.

= Service-Widget =

Patienten nutzen ein Sticky-Widget auf Ihrer Website für alltägliche Anfragen:

* **Rezept-Bestellung** – mit Medikamenten-Autocomplete (1.300+ Einträge), Foto-Upload und Kassenwahl
* **Überweisung anfragen** – für Bestandspatienten
* **Brillenverordnung** – inkl. Prismen und HSA-Werte
* **Dokument hochladen** – PDF, JPG, PNG, WebP (max. 10 MB)
* **Termin anfragen** – auch für Neupatienten
* **Termin absagen** – für Bestandspatienten

Alle Widget-Services sind im **Free-Plan kostenlos** enthalten.

= Digitale Anamnese =

JSON-basierte Fragebögen für 6 Fachrichtungen (564 Felder, 204 konditional):

* Augenarzt, Allgemeinarzt, HNO, Zahnarzt, Dermatologe, Orthopäde
* Eigene Formulare als JSON definierbar
* Unterschrift-Feld mit Signatur-Capture

= Multi-Standort =

* Beliebig viele Praxis-Standorte mit eigenen Services, Öffnungszeiten und Lizenzen
* UUID-basierte Standort-Identifikation
* Automatische Standort-Erkennung per URL-Slug

= Portal für MFA =

Geschützter Bereich für Medizinische Fachangestellte:

* Eingangs-Übersicht mit Status-Verwaltung (Neu → In Bearbeitung → Erledigt)
* Detail-Ansicht mit entschlüsselten Patientendaten
* PDF-Druck und Datei-Download

= Export für Praxissoftware (PVS) =

* **GDT/BDT 3.0** – Medistar, Albis, Turbomed, Duria u.v.m.
* **FHIR R4** – Moderner Standard (experimentell)
* **HL7** – Klinik-Integration (experimentell)
* **PDF** – Automatische Anamnese-PDFs mit Signatur

= Sicherheit =

* **AES-256 Verschlüsselung** – Sodium (XSalsa20-Poly1305) oder OpenSSL AES-256-GCM
* Schlüsseldatei außerhalb des Web-Root (chmod 0600)
* CSRF-Schutz (Nonce-Validierung) auf allen Endpunkten
* Rate-Limiting gegen Brute-Force
* Verschlüsselte Portal-Passwörter (bcrypt)
* Audit-Log für alle Datenzugriffe

= DSGVO-Konformität =

* Einwilligungsverwaltung mit Versionierung
* WordPress Privacy-Export & -Löschung integriert
* Automatische Datenbereinigung (konfigurierbar)
* Soft-Delete mit nachträglicher endgültiger Löschung
* Vollständiger Uninstaller (67 Options, alle Tabellen, verschlüsselte Dateien)

= Mehrsprachigkeit =

Deutsch, Englisch, Französisch, Italienisch, Niederländisch

== Installation ==

1. Plugin-ZIP über **WordPress → Plugins → Installieren** hochladen
2. Plugin aktivieren – der **Einrichtungsassistent** startet automatisch
3. Dem Wizard folgen: Systemcheck → Lizenz → Standort → Sicherheit → Portal
4. Widget per Shortcode auf einer Seite einbinden: `[pp_widget]`

= Shortcodes =

* `[pp_widget]` – Service-Widget (auch: `[praxis_widget]`, `[pp_anamnesebogen]`)
* `[pp_fragebogen]` – Eigenständiger Anamnesebogen
* `[pp_portal]` – MFA-Portal (auch: `[praxis_portal]`)

= Konfiguration in wp-config.php =

Für maximale Sicherheit kann der Verschlüsselungsschlüssel manuell konfiguriert werden:

`define('PP_ENCRYPTION_KEY_PATH', '/home/user/pp-secure/.encryption_key');`

Uploads außerhalb des Web-Root:

`define('PP_UPLOAD_PATH', '/home/user/pp-encrypted-uploads/');`

== Frequently Asked Questions ==

= Brauche ich einen Lizenzschlüssel? =

Für den Free-Plan nicht. Premium-Features erfordern einen Schlüssel pro Standort.

= Wo werden die Daten gespeichert? =

Lokal in Ihrer WordPress-Datenbank (AES-256 verschlüsselt). Keine externen Server außer dem Lizenz-Heartbeat.

= Funktioniert das mit meiner Praxissoftware? =

Über GDT-Export kompatibel mit Medistar, Albis, Turbomed, Duria und anderen deutschen PVS-Systemen.

= WordPress Multisite? =

Nicht offiziell unterstützt. Multi-Standort wird über die eingebaute Standortverwaltung gelöst.

= Welche PHP-Extensions werden benötigt? =

OpenSSL und mbstring sind Pflicht. libsodium (Sodium) wird empfohlen (Fallback: OpenSSL).

== Screenshots ==

1. Service-Widget – Sticky-Button auf der Praxis-Website
2. Widget-Formular – Rezept-Bestellung mit Medikamenten-Autocomplete
3. MFA-Portal – Eingangsübersicht mit Status-Verwaltung
4. Admin – Standortverwaltung mit Multi-Standort-Übersicht
5. Admin – Einrichtungsassistent (Setup-Wizard)

== Changelog ==

= 4.2.909 =
* Widget: „Online-Rezeption"-Button im Footer entfernt
* Widget: Notfall-Seite mit neuem Karten-Layout (V3-Stil) – zentrierte Cards, Chevron-Navigation, farbige Hover-Effekte
* Widget: Giftnotruf und Telefonseelsorge aus Notfall-Seite entfernt
* Widget: Externe URL bei allen Services korrekt geöffnet (nicht nur Anamnesebogen)
* Admin: Notfall-Konfigurations-Modal öffnet jetzt korrekt (inline style-Override behoben)
* Admin: Giftnotruf und Telefonseelsorge aus Notfall-Admin-Modal entfernt
* Admin: Modal-Footer immer sichtbar – nur Body scrollt (Flex-Layout-Fix)
* Admin: Custom-Service-Fehler behoben (nicht existente DB-Spalten form_id und is_custom entfernt)
* Admin: Custom Services nutzen jetzt service_type = custom/external statt is_custom-Flag
* Admin: „Custom Service hinzufügen"-Button vorerst ausgeblendet (modularer Form-Picker in Planung)

= 4.2.908 =
* Grundlegende Architektur-Überarbeitung: 65 PHP-Klassen in 12 Namespaces
* Diagnose-Tool mit 22 Testgruppen (Encryption, Portal, Multi-Standort, DSGVO, Export, API)
* 6 Fachrichtungen für digitale Anamnesebögen (564 Felder, 204 konditional)
* Self-Hosted Updater mit SHA256-Integritätsprüfung
* Versionsbasierte DB-Migrationen (v3.0 → v4.2.9)

= 4.2.5 =
* ICD-10 Admin-Bereich für Diagnose-Verwaltung
* Formular-Editor im Admin-Backend
* Medikamenten-Verwaltung mit Import-Funktion
* Setup-Wizard überarbeitet (5 Schritte)

= 4.2.4 =
* DSGVO-Admin-Bereich mit Audit-Log-Ansicht
* Lösch-Workflow für Patientendaten verbessert
* Export-Konfiguration pro Standort

= 4.2.3 =
* Multi-Standort-Architektur stabilisiert
* LocationResolver mit 5 Prioritätsstufen
* LocationContext als zentraler Datenträger im Request

= 4.2.2 =
* RateLimiter mit Object Cache Unterstützung (Redis/Memcached)
* Transient-basierter Fallback für Shared Hosting
* Rate-Limit-Hinweise im Admin

= 4.2.1 =
* Widget-Urlaubsmodus mit konfigurierbarer Nachricht
* Standort-spezifische Öffnungszeiten
* Termin-Absage-Formular

= 4.2.0 =
* Multi-Standort UUID-basiert (LOC-xxxxxxx)
* Portal-Authentifizierung mit Session-Timeout
* Audit-Log für alle Datenzugriffe

= 4.1.0 =
* FHIR R4 Export (experimentell)
* HL7 Export (experimentell)
* PVS-REST-API mit API-Key-Authentifizierung

= 4.0.0 =
* Komplette Neuentwicklung auf Namespace-Basis (PraxisPortal\*)
* Dependency Injection Container mit Zirkulärerkennung
* AES-256-GCM / XSalsa20-Poly1305 Verschlüsselung (AEAD)
* Repository-Pattern für alle Datenbankzugriffe
* GDT/BDT 3.0 Export
* DSGVO-konforme Deinstallation

= 3.x =
* Legacy-Version (nicht mehr unterstützt)
* Migration auf v4.0 erfolgt automatisch

== Upgrade Notice ==

= 4.2.909 =
Bugfix-Release: Notfall-Widget überarbeitet, Admin-Modal-Fixes, DB-Fehler beim Anlegen von Custom Services behoben.

= 4.2.908 =
Stabiles Release nach umfangreicher Architektur-Überarbeitung. Update empfohlen. Datenbank-Migration läuft automatisch.

= 4.0.0 =
Vollständige Neuentwicklung. Backup vor dem Update erstellen. Migration von v3.x läuft automatisch.
