# Phase 6: Vollständige WebApp

Status: **lokal implementiert und am 13. Juli 2026 abgenommen.** Die
Kernoberflächen, das verbindliche Verbindungs-Onboarding und ihre lokalen Tests
sind umgesetzt. Der reproduzierbare Nachweis steht in
[`phase-6-local-acceptance.md`](phase-6-local-acceptance.md). Deployment,
Live-PVE-/PBS-Matrix und produktionsnahe Abnahme bleiben der noch nicht
begonnenen Phase 7 vorbehalten.

Dieses Dokument konkretisiert Phase 6 aus dem
[`rewrite-plan.md`](rewrite-plan.md). Es baut auf den versionierten APIs und
Sicherheitsgrenzen aus Phase 4 sowie den Operationsmodellen aus
[`phase-5-backup-worker-plan.md`](phase-5-backup-worker-plan.md) auf.

## Ziel

Phase 6 macht die vier verpflichtenden V2-Funktionen vollständig bedienbar und
ergänzt die Betriebs- und Administrationsoberflächen:

- Dashboard;
- Systeme, Inventar und Collector-Betrieb;
- Verbindungen und Credentials;
- Backupziele, Auswahlhierarchie und Policies;
- Shadow-Auswertungen und erklärbare Entscheidungen;
- Queue, manuelle Requests, Historie, Events, Logs und Cancel;
- Health, Benutzer, Rollen und Audit.
- Benachrichtigungsstatus einschließlich jeder einzelnen Matrix-
  Fehlversuchsmeldung mit Versuchsnummer und späterer Entwarnung, ohne den
  Webhook oder andere Secrets offenzulegen.

Die WebApp ist eine Vue-3-/PrimeVue-4-SPA und spricht ausschließlich die
versionierte PHP-API. Sie ruft PVE oder PBS niemals direkt auf. Es gibt weder
einen manuellen Scan-Endpunkt noch einen Button „Jetzt scannen“.

## Lokaler Abschlussstand

Der lokal vorhandene Phase-6-Umfang umfasst:

- lokale Session-Authentifizierung, CSRF, geschlossene Permissions, Benutzer,
  Rollen, Last-Admin-Schutz und unveränderbares administratives Audit; nur
  anonyme Login-Fehlereignisse unterliegen einer begrenzten Retention;
- Verbindungs- und write-only Credential-Verwaltung, Ziele, Auswahlhierarchie,
  Gast-Overrides und Policies mit Revisionen und serverseitiger Validierung;
- Dashboard, Inventar-/Worker-Health, Shadow-Entscheidungen, Queue, manuelle
  Requests, Runs, Events, Logs, Cancel und Matrix-Zustellung;
- versionierter OpenAPI-Vertrag samt generiertem TypeScript-Client;
- responsive PrimeVue-Sichten mit geschlossenen Status-/Label-Mappings und
  verständlichen Warnzuständen für stale Evidenz, Worker-Ausfälle,
  `reconcile_required`, `unknown` und `dispatch_unknown`;
- isolierte QA-Seed-CLI und Playwright-Flows gegen die echte PHP-API und eine
  disposable MariaDB-11.4 für Desktop und Mobile.

Der vollständige
[`proxmox-connection-onboarding-plan.md`](proxmox-connection-onboarding-plan.md)
ist lokal umgesetzt: Befehlshilfe, getrennte Scan-/Backup-Credentials,
fail-closed TLS-, Produkt-, Versions-, Berechtigungs- und
Propagationsprüfung sowie verified-only Aktivierung, Rotation und
Endpoint-Änderung gehören zum getesteten Phase-6-Vertrag.

Frontend-Unit-/Component-Tests, Backend-/MariaDB-Tests, OpenAPI-Driftprüfung
und die lokalen Browserflüsse bilden die Abnahmegrundlage. Der vollständige
Gate-Satz und die visuelle Prüfung des gestarteten Stacks werden auf dem
jeweiligen dokumentierten Abschlussarbeitsstand ausgeführt. Die lokale QA-Evidenz ist kein
Nachweis für reale PVE-/PBS-Versionen oder ein Deployment; Phase 7 wurde nicht
begonnen und Backupausführung bleibt standardmäßig deaktiviert.

## Sicherheits- und Datenvertrag

- Login verwendet ein `HttpOnly`, `Secure`, `SameSite=Strict` Session-Cookie.
- Der öffentliche Login akzeptiert höchstens 4096 Request-Bytes. IP- und
  installationsweite Fehlbudgets werden unter DB-Row-Lock vor Argon2 geprüft;
  gesperrte Requests erzeugen weder weitere Hasharbeit noch unbegrenzt neue
  Audit-Zeilen. Alte Login-Throttles und anonyme Fehlereignisse werden
  gebatcht über eine eng berechtigte Retention-Prozedur entfernt.
- CSRF-Werte bleiben ausschließlich im Arbeitsspeicher.
- Jede schreibende Route prüft serverseitig Permission, CSRF,
  Idempotency-Key, erwartete Revision und Audit-Kontext.
- `inventory.read` erlaubt read-only Betriebssichten.
- `backup_configuration.manage` erlaubt Verbindungen, Ziele, Policies und
  Auswahl.
- `backup_operations.manage` erlaubt manuelle Requests und Cancel.
- `audit.read` erlaubt Audit- und sicherheitsrelevante Ansichten.
- Benutzer- und Rollenzuweisungen benötigen eine eigene administrative
  Permission; ein Administrator darf sich nicht als letzten aktiven Admin
  selbst aussperren.
- Secret-Felder sind write-only. API, DOM, Logs, Audit und Fehlermeldungen
  liefern weder Klartext noch reversible Maskierungen zurück.
- Collector- und Backup-Credential-Views schließen Credentials deaktivierter
  Verbindungen bereits in der Datenbank aus.
- Optimistic-Concurrency-Konflikte zeigen den aktuellen Serverstand und
  verlangen bewusstes Neu-Laden beziehungsweise erneutes Anwenden.

## Informationsarchitektur

### Übersicht

Das Dashboard zeigt echte serverseitige Kennzahlen statt statischer Karten:

- Collector- und Backup-Worker-Health;
- letzter erfolgreicher und nächster Collector-Zyklus;
- veraltete Inventar-/Kapazitäts-/Executor-Evidenz;
- aktive Systeme, Nodes, Gäste, Ziele und Policies;
- Queue nach Zustand und Priorität;
- laufende, fehlgeschlagene, unbekannte und zuletzt erfolgreiche Backups;
- offene Shadow-Blocker und letzte Audit-Ereignisse entsprechend Permission.

### Systeme und Konfiguration

- PVE-/PBS-Verbindungen anlegen, ändern, deaktivieren und Credentials rotieren;
- Produkt, unterstützte Version, TLS-Vertrauen und letzter erfolgreicher Scan
  sichtbar machen;
- niemals ein manuelles Scannen anbieten;
- Backupziel-Kandidaten mit Node-, Kapazitäts-, PBS- und
  Executor-Evidenz erklären;
- Ziele revisioniert konfigurieren und nur bei bestandener Aktivierungsprüfung
  aktivieren;
- Policies, Trigger, Modus, Kompression, Retention und Zielzuordnung verwalten;
- globale, Connection-, Cluster-, Node- und Gast-Auswahl als bounded
  Massenaktion verwalten; explizite Excludes sichtbar hervorheben;
- QEMU und LXC gleichwertig darstellen.

### Shadow und Operations

- Evaluation-Runs cursor-paginiert anzeigen;
- Decisions nach Outcome, Grund, Policy, Ziel und Gast filtern;
- sämtliche bestandenen und blockierenden Gates in stabiler Reihenfolge mit
  Evidenzzeitpunkt darstellen;
- Queue-Priorität und FIFO-Schlüssel transparent zeigen;
- manuelle Requests nur persistieren, niemals synchron starten;
- Run-Zustand, UPID, Provenienz, Events und paginierte Logs anzeigen;
- Cancel als bestätigte, revisionierte Anforderung ablegen;
- `reconcile_required` und `unknown` als eigenständige Warnzustände erklären.

### Administration

Die Administration verwendet eine linke Sekundärnavigation für:

- Benutzer;
- Rollen und Permissions;
- Audit-Log;
- Worker- und Datenbank-Health;
- Laufzeitinformationen ohne Secrets.

## API- und Frontendregeln

- Alle Listen verwenden geschlossene Filter und opaque Cursor-Pagination.
- Der OpenAPI-Vertrag ist die einzige Quelle für den generierten
  TypeScript-Client.
- Stores besitzen Loading-, Empty-, Error-, Permission- und Konfliktzustände.
- Composables enthalten Formatierung und geschlossene Label-Mappings; keine
  lose Interpretation unbekannter Serverwerte.
- Mutationscontrols werden anhand der Principal-Permissions verborgen oder
  deaktiviert, aber die serverseitige Autorisierung bleibt maßgeblich.
- Alle Zeitwerte kommen als UTC aus der API und werden erst in der Oberfläche
  lokalisiert.
- Responsive Tabellen erhalten auf schmalen Viewports eine nutzbare
  Karten-/Scroll-Darstellung; Dialoge und Formulare bleiben per Tastatur
  bedienbar.

## Test- und Abnahmevertrag

- jede Store- und Composable-Verzweigung 100 Prozent Line/Branch/Function;
- Komponenten für Lade-, Leer-, Fehler-, Permission-, Konflikt-, Validierungs-
  und Erfolgszustände;
- OpenAPI-Drift, TypeScript, ESLint und Prettier als harte Gates;
- Playwright auf einer dedizierten QA-Seed-CLI, niemals über einen versteckten
  Produktions-Testendpoint;
- Browser-Kernflüsse: Login/RBAC, Verbindung plus Secret, Zielblocker,
  Node-/QEMU-/LXC-Auswahl, Policy create/update, Revision-Konflikt,
  `never_backed_up`-Shadow-Entscheidung, manueller Request, Queue, Run, Log,
  Cancel, Worker-Ausfall, Audit-Nachweis;
- negative Browser-Assertions: kein manueller Scan und keine direkte
  Start-/Stop-PVE-Aktion in Phase-4-Sichten;
- responsive Abnahme mindestens für Desktop und einen schmalen mobilen
  Viewport;
- visuelle Browserprüfung des tatsächlich gestarteten Dev-Stacks, nicht nur
  Component-Tests.

## Umsetzungswellen

### 6.1 – Shadow- und Konfigurationsoberflächen

Status: lokal abgeschlossen.

- Shadow API/Store/View und Gate-Details;
- vollständige Ziel-, Policy- und Auswahlformulare;
- Permission-, Validation- und Revision-Konflikte.

### 6.2 – Operationsoberflächen

Status: lokal abgeschlossen.

- echte Dashboard-Projektion;
- Queue, manuelle Requests, Runs, Events, Logs und Cancel;
- Health- und Worker-Warnzustände.

### 6.3 – Administration und Verbindungen

Status: lokal abgeschlossen einschließlich verbindlichem
PVE-/PBS-Verbindungs-Onboarding.

- PVE-/PBS-Verbindungs- und Credential-Verwaltung;
- Benutzer, Rollen und Permissions;
- Audit-Liste und Detailansicht;
- Last-Admin- und Secret-Sicherheitsregeln.

### 6.4 – Playwright und Browserabnahme

Status: auf dem lokalen Abschlussarbeitsstand mit 19/19 Playwright-Tests und
manueller Desktop-/Mobile-Prüfung abgeschlossen; auf jedem späteren
dokumentierten Release-Kandidaten vollständig neu auszuführen.

- reproduzierbare QA-Seed-CLI und isolierte E2E-Datenbank;
- kritische Desktop-/Mobile-Flows;
- vollständige Frontend- und Backend-Gates;
- Dev-Stack starten und im echten Browser visuell sowie funktional prüfen.
