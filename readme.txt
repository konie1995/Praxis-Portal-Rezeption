=== Praxis-Portal ===
Contributors: praxisportal
Tags: arztpraxis, patientenportal, anamnese, dsgvo, medizin
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 4.2.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DSGVO-konformes Patientenportal für medizinische Praxen – Service-Widget, digitale Anamnese, Multi-Standort, AES-256-Verschlüsselung.

== Description ==

**Praxis-Portal** verwandelt Ihre WordPress-Seite in ein vollwertiges Patientenportal. Patienten können Rezepte bestellen, Überweisungen anfragen, Termine vereinbaren und digitale Anamnesebögen ausfüllen – alles DSGVO-konform und end-to-end verschlüsselt.

= Hauptfunktionen =

* **Service-Widget** – Rezept, Überweisung, Brillenverordnung, Dokument, Termin, Terminabsage
* **Digitale Anamnese** – Augenarzt, Allgemeinarzt, HNO (weitere über JSON erweiterbar)
* **Multi-Standort** – Beliebig viele Praxis-Standorte mit eigenen Services, Öffnungszeiten und Lizenzen
* **AES-256-GCM Verschlüsselung** – Alle sensiblen Patientendaten werden verschlüsselt gespeichert
* **DSGVO-konform** – Einwilligungsverwaltung, Lösch-Workflow, Audit-Log, Datenexport
* **Portal für MFA** – Geschützter Bereich für MFA mit Statusverwaltung und GDT-Export
* **PDF-Erstellung** – Automatische PDF-Anamnese mit Signatur
* **GDT / HL7 / FHIR** – Export für Praxisverwaltungssysteme
* **Medikamenten-Datenbank** – Autocomplete mit über 1.300 Medikamenten
* **Mehrsprachig** – Deutsch, Englisch, Französisch, Italienisch, Niederländisch
* **Self-Hosted Updates** – Automatische Updates über Ihren Lizenzserver
* **Urlaubsmodus** – Pro Standort aktivierbar

= Für wen? =

* Augenarztpraxen
* Allgemeinarztpraxen / Hausärzte
* HNO-Praxen
* Jede Facharztpraxis (eigene Formulare über JSON definierbar)

= Sicherheit =

* AES-256-GCM Verschlüsselung aller Patientendaten
* Schlüsseldatei außerhalb des Web-Root
* CSRF-Schutz (Nonce-Validierung)
* Rate-Limiting gegen Brute-Force
* Content Security Policy Headers
* Verschlüsselte Portal-Passwörter (bcrypt)
* Audit-Log für alle Zugriffe

== Installation ==

1. Plugin-ZIP über WordPress → Plugins → Installieren hochladen
2. Plugin aktivieren → Der Einrichtungsassistent startet automatisch
3. Dem Wizard folgen: Systemcheck → Lizenz → Standort → Sicherheit → Portal
4. Widget per Shortcode `[praxis_portal]` auf einer Seite einbinden
5. Optional: Weitere Standorte und Services konfigurieren

= Systemanforderungen =

* WordPress 5.8+
* PHP 8.0+
* MySQL 5.7+ / MariaDB 10.3+
* OpenSSL-Extension (für AES-256-GCM)
* mbstring-Extension

== Frequently Asked Questions ==

= Brauche ich einen Lizenzschlüssel? =

Ja, für den vollen Funktionsumfang ist ein Lizenzschlüssel erforderlich. Dieser wird pro Standort vergeben.

= Wo werden die Daten gespeichert? =

Alle Daten werden in der WordPress-Datenbank gespeichert (AES-256 verschlüsselt). Hochgeladene Dateien liegen im verschlüsselten Upload-Verzeichnis. Es werden keine Daten an externe Server übermittelt – außer dem Lizenz-Heartbeat.

= Kann ich eigene Formulare erstellen? =

Ja, Formulare werden als JSON-Dateien definiert. Sie können eigene Anamnesebögen in `wp-content/uploads/pp-custom-forms/` ablegen.

= Funktioniert das Plugin mit meiner Praxissoftware? =

Über den GDT-Export können Daten in gängige PVS-Systeme übernommen werden. Zusätzlich werden HL7 und FHIR (experimentell) unterstützt.

= Unterstützt das Plugin Multisite? =

Aktuell wird WordPress Multisite nicht offiziell unterstützt. Multi-Standort wird über die eingebaute Standortverwaltung gelöst.

== Screenshots ==

1. Service-Widget auf der Patienten-Seite
2. Digitaler Anamnesebogen (Augenarzt)
3. Portal-Dashboard für MFA
4. Admin-Einstellungen mit Standortverwaltung
5. Medikamenten-Autocomplete

== Changelog ==

= 4.2.6 =
* FIX: Widget-Flow v3-Stil: "Sind Sie Patient?" → Standort → Services → Formular
* FIX: Rezept-Formular v3-Logik: Privat→Abholung/Versand, Gesetzlich→EVN-Checkbox
* FIX: Medikamenten-Eingabe v3-Stil: Art-Dropdown + "Weiteres Medikament" Button (max 3)
* FIX: Foto-Upload für Medikamentenpackung wiederhergestellt
* FIX: Medikamenten-Suche nutzt jetzt DB statt CSV (eine Datenquelle für Widget + Admin)
* FIX: CSV-Import Spalten-Mapping korrigiert (Wirkstoff+Stärke → Dosierung, Kategorie → Form)
* FIX: Medikationsliste (.pp-medication-list Container) fehlte im Rezept-Formular
* FIX: service_key Mapping in services.php (war 'key' statt 'service_key')
* FIX: JS Submit-Selektor (.pp-submit-form → .pp-service-form)
* FIX: patient_restriction Mapping (DB: String vs. Code: Boolean)
* FIX: CSS-Klasse pp-medication-input-wrapper passt jetzt zum Template
* Entfernt: Footer-Link "Praxis-Portal" (wie v3)
* Verbesserung: Standard-Medikamente Import-Button im Datenbank-Tab
* Verbesserung: Progress-Bar und Back-Button werden korrekt aktualisiert
* Neue Regressions-Tests in Diagnose-Tool (Gruppen 21)

= 4.2.5 =
* FIX: 26+ Bugs behoben (Widget, SQL, Hooks, Templates)
* FIX: PP4_ → PP_ Migration (1072 Ersetzungen, 39 Dateien)
* FIX: SQL %% in LIKE-Klauseln (WordPress 6.x Kompatibilität)
* FIX: Hook-Duplikate aufgelöst (Admin.php, Hooks.php)
* NEU: Diagnose-Tool mit 22 Testgruppen inkl. Runtime-Tests
* NEU: Medikamenten-Seite mit Tabs, CSV-Import, Inline-Edit
* Widget: Vertikale Menü-Listenansicht (v3-Stil)
* Uninstaller: 67 Optionen, DSGVO-konform

= 4.2.4 =
* FIX: Anamnesebogen-Service öffnet konfigurierte URL in neuem Tab
* Widget: anamnesebogen_url an JavaScript übergeben
* Redirect-Logik statt AJAX für Anamnesebogen

= 4.2.3 =
* FIX: Widget::render() Methode für Shortcode-Unterstützung hinzugefügt
* FIX: Widget::register() wird jetzt in Plugin.php aufgerufen
* FIX: Shortcodes direkt in Plugin.php statt totem Hooks.php Code

= 4.2.2 =
* Augenarzt: Doppelte Order-Werte in allgemein-Section behoben
* HNO: 8 Info-Tooltips ergänzt (Tonsillektomie, Asthma, Rauchen etc.)
* Alle 6 Formulare: Qualitäts-Audit bestanden ✓

= 4.2.1 =
* NEU: Zahnarzt-Anamnesebogen (110 Felder, 48 konditional)
* NEU: Dermatologischer Anamnesebogen (95 Felder, 36 konditional)
* NEU: Orthopädischer Anamnesebogen (102 Felder, 37 konditional)
* 6 Fachrichtungen insgesamt: Augenarzt, Allgemeinarzt, HNO, Zahnarzt, Dermatologe, Orthopäde
* 564 Formularfelder gesamt, 204 konditionale Felder
* Fachspezifische Besonderheiten:
  - Zahnarzt: Bisphosphonat-Warnung, Endokarditisprophylaxe, CMD/Bruxismus, Implantate
  - Dermatologe: Hautkrebsvorsorge (ABCDE), Fitzpatrick-Hauttyp, Biologika, STI
  - Orthopäde: BG-Unfallversicherung, Wirbelsäule, Gelenkprothesen, Gehstrecke, Hilfsmittel
* Tests aktualisiert: 6 Fachrichtungen-Validierung

= 4.2.4 =
* FIX: Anamnesebogen-Service öffnet konfigurierte URL in neuem Tab
* Widget: anamnesebogen_url an JavaScript übergeben
* Redirect-Logik statt AJAX für Anamnesebogen

= 4.2.3 =
* FIX: Widget::render() Methode für Shortcode-Unterstützung hinzugefügt
* FIX: Widget::register() wird jetzt in Plugin.php aufgerufen
* FIX: Shortcodes direkt in Plugin.php statt totem Hooks.php Code

= 4.2.2 =
* Augenarzt: Doppelte Order-Werte in allgemein-Section behoben
* HNO: 8 Info-Tooltips ergänzt (Tonsillektomie, Asthma, Rauchen etc.)
* Alle 6 Formulare: Qualitäts-Audit bestanden ✓

= 4.2.1 =
* 6 Fachrichtungen: Augenarzt, Allgemeinarzt, HNO, Zahnarzt, Dermatologe, Orthopäde
* Allgemeinarzt + HNO auf Standard gebracht: Hauptversicherter, privat_art, File-Upload
* HNO: Titel-Feld + Medikamentenplan-Upload nachgerüstet
* Alle 6 Formulare: Konsistenz-Check ✓ (Sections, Stammdaten, Unterschrift, Signatur)
* Tests: Erwartungen für aufgerüstete Formulare angepasst

= 4.2.0 =
* NEU: 16 funktionale Testgruppen (vorher statisch)
* Portal-Login: Passwort-Hashing, falsches PW, Deaktivierung
* Multi-Standort: Datenisolation, UUID-Suche, Slug-Duplikate
* FormValidator: E-Mail, PLZ, Privatpatient-Signatur, Konditionslogik
* Medikamenten-Suche: SQL-Injection-Schutz, Umlautsuche
* DSGVO: Verschlüsselung, Löschung, Nicht-Auffindbarkeit
* Verschlüsselung: Grenzwerte, Manipulation, IV-Variation
* Export-Pipeline: GDT/FHIR/HL7 mit echten Patientendaten
* FormHandler E2E: Verarbeitung, XSS-Bereinigung, DB-Check
* LocationContext: Resolution, Slug-Duplikate, UUID-Lookup
* API-Key: Erstellen, Validieren, Deaktivieren, Berechtigungen
* Concurrent Submissions: 10 parallele Einreichungen
* Admin-AJAX: Hook-Registrierung, searchDecrypted, Audit-Log
* Nonce/CSRF: Cross-Action-Validierung
* Sanitizer: XSS, SQL-Injection, Sonderzeichen

= 4.1.9 =
* Versionsnummern synchronisiert (Plugin-Header, Konstante, readme.txt)
* Setup-Wizard Feinschliff und Stabilität

= 4.1.8 =
* NEU: Setup-Wizard – geführte Ersteinrichtung in 6 Schritten (Systemcheck, Lizenz, Standort, Sicherheit, Portal, Fertig)
* Automatischer Redirect zum Wizard nach Plugin-Aktivierung
* Erneut startbar über System-Status → Einrichtungsassistent
* Migration: Default-Standort und Medikamenten-Import laufen jetzt idempotent (unabhängig von DB-Version)
* FormValidator: Widget akzeptiert jetzt alle DSGVO-Feldnamen (datenschutz, dsgvo_consent, datenschutz_einwilligung)
* KeyManager: Dateiberechtigungs-Prüfung wird auf Windows übersprungen (chmod nicht unterstützt)
* Test-Suite: PP_VERSION-Test nicht mehr hardcoded (version_compare >= 4.1.0)

= 4.1.5 =
* Behebt Fatal Error: PdfWidget fehlende abstrakte Methoden (render, getMimeType, getFileExtension)
* ZIP-Build-Prozess mit MD5-Verifizierung aller kritischen Dateien

= 4.1.4 =
* Behebt Fatal Error: Fehlende Migration-Klasse Import in Plugin.php
* Behebt Fatal Error: Migration-Constructor ohne $wpdb Parameter
* Widget: Settings-Key location_uuid statt uuid
* Migration: MySQL 5.7-kompatibles DROP INDEX (kein IF EXISTS)
* Migration: Default-Standort mit UUID-Generierung

= 4.1.2 =
* Medikamenten-Datenbank: CSV-Import (1.355 Einträge) mit Autocomplete-Suche
* MedicationRepository mit bulkInsert, search, CRUD
* Schema: pp_medications Tabelle mit Index
* Migration: Spalte location_uuid → uuid (v4.1.0)
* Sanitizer: UTF-8 safe (mb_substr statt substr)
* I18n: getLocale() Rückgabetyp-Fix
* Test-Suite: Erweitert auf 28 Testgruppen

= 4.1.0 =
* Umfassende Test-Suite mit 34 Testgruppen und 496 Assertions
* 100% Klassen-Abdeckung aller 56 PHP-Klassen
* Tests für alle 3 Formulare (Augenarzt, Allgemeinarzt, HNO)
* Repository-Detail-Tests (AbstractRepository + 8 konkrete Repositories)
* Hooks-Registrierungs-Tests (AJAX, Shortcodes, Cron, Enqueue)
* RateLimiter-Funktionstests (Throttling, Lockout, Cleanup)
* Plugin-Bootstrap-Tests (Konstanten, Autoloader, Aktivierung)
* Template- und Asset-Existenzprüfungen (13 Templates, 8 Assets)
* Uninstall-DSGVO-Compliance-Tests

= 4.0.0 =
* Komplette Neuentwicklung auf Basis moderner PHP 8.0+ Architektur
* Namespaced PSR-4 Klassenstruktur (56 PHP-Klassen)
* AES-256-GCM Verschlüsselung (statt AES-256-CBC)
* Multi-Standort-System mit location_uuid und license_key
* Self-Hosted Update-System mit SHA256-Integritätsprüfung
* Neue Formulare: Allgemeinarzt, HNO
* 5 Sprachen: DE, EN, FR, IT, NL
* GDT / HL7 / FHIR Export
* Automatische v3→v4 Migration
* Rate-Limiting und Audit-Log

= 3.9.60 =
* Erweiterte encrypted-Spalten für große Formulardaten

= 3.9.40 =
* Terminabsage als neuer Service

= 3.3.2 =
* Documents-Tabelle für Dateianhänge

= 3.2.19 =
* Downloads-Service hinzugefügt

= 3.0.0 =
* Erstveröffentlichung mit Grundfunktionen

== Upgrade Notice ==

= 4.1.8 =
Neuer Setup-Wizard, Bugfixes für Migration und Windows-Kompatibilität. Keine Breaking Changes.

= 4.1.0 =
Qualitätssicherung – umfassende Test-Suite mit 34 Gruppen und 100% Klassenabdeckung. Keine Breaking Changes.

= 4.0.0 =
Großes Update – bitte vorher Backup erstellen. Die Migration von v3 auf v4 läuft automatisch. Bestehende Daten werden übernommen und re-verschlüsselt.
