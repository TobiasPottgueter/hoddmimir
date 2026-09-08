# 5. Fehlende Arbeiten und empfohlene Reihenfolge

## Nachträgliche Benutzerentscheidungen vom 7. September 2026

Nach Abschluss dieses Audits wurden zwei bisherige Anforderungen geändert:

- [ADR 0005](../../adr/0005-automatic-backup-recovery.md): automatische Wiederfreigabe nach vollständiger frischer Taskklärung ohne eindeutige Zuordnung, ohne Sonderwartefrist oder mehrere Pflichtprüfzyklen; vor jedem Start gilt das allgemeine Remote-Task-Gate einschließlich manueller/externer Backups. Ein zusätzliches nachfolgendes Backup ist akzeptiert. Keine zwingende manuelle Auflösung; kein blinder POST-Retry.
- [ADR 0006](../../adr/0006-maintenance-upgrade-database-restore.md): Wartungsupgrade mit DB-Sicherung, Funktionstests bei gesperrtem Betrieb und Restore samt alter Anwendung bei Fehlern vor Freigabe.

**Nachtrag 8. September: Die geänderten Verträge und F03/F07–F11 sind implementiert. Wartung und Backup-Recovery wurden auf realen DEV-Systemen abgenommen; Details und Grenzen stehen in [Bericht 09](09-followup-implementation.md) und [Bericht 10](10-existing-dev-upgrade-and-backup-acceptance.md). Abschließende Veröffentlichungsgates und Registry-Pins stehen noch aus. Die folgenden Befunde dokumentieren den ursprünglichen Auditstand.**
Die folgenden Analysen und Testergebnisse beschreiben den ursprünglichen Auditstand.
Zum ursprünglichen Auditstand erfüllte die Folgepromotion die neuen
Klärungs-/Startbedingungen noch nicht (F01). Der Nachtrag dokumentiert die
Umsetzung der automatischen Wiederfreigabe nach ADR 0005.
F02 kann durch den neuen getesteten Restorepfad behoben werden; Expand/Contract
ist dann nicht die einzige Lösung. Solange das bestehende Image-Rollbackverfahren
verwendet wird, bleibt dessen Kompatibilitätsproblem bestehen.
Die Restarbeiten sind mit diesen beiden ADRs als maßgeblichem Zielvertrag zu lesen.


Die Liste unterscheidet Korrekturen, fehlende Funktionen, explizite Scopeentscheidungen und Abnahmen. Sie ist ein Vorschlag für die Fortsetzung, keine Freigabe zu Produktivänderungen. Die Analyse hat keinen Anwendungscode geändert.

## 5.1 Sofort vor der nächsten Backup-Abnahme

| Reihenfolge | Arbeit | Fertig, wenn |
| --- | --- | --- |
| 1 — P0 | F01: Ungeklärte Submission gastweit absichern | unknown/forbidden_ambiguous überlebt Folgezyklen, neue Request-IDs, Worker-Neustart und Parallelität ohne erneute Startfreigabe. Eine kontrollierte Auflösung ist explizit berechtigt und auditiert. |
| 2 — P1 | F02: Benachrichtigungsschema rollbackfähig machen | Vorheriger und neuer Writer/Reader funktionieren während Expand; echter Image-Recovery-Vertrag gegen neues Schema getestet. |
| 3 — P1 | F03: Byte-Evidenzfrische durchsetzen | Fehlende/veraltete/zukünftige Counterdaten erzeugen keinen unberechtigten Byte-Grund; Grenzen und Resetsemantik getestet. |
| 4 — P1 | F04/F06: Benachrichtigungen vollständig integrieren | Reale Scheduler-/Queueblockade erzeugt atomaren Problemzustand und Outbox, Wiederholungen sind idempotent, Entwarnung funktioniert; minimale DB-Rechte und Rechtetests passen dazu. |
| 5 — P1 | F05: API-Client und Darstellung synchronisieren | Generierungscheck grün; nullable attempt und checkNumber werden korrekt typisiert und angezeigt. |

Die reproduzierenden Patches sind beobachtende Tests des fehlerhaften Zustands. Für Regressionstests müssen ihre Erwartungen auf das gewünschte sichere Verhalten umgestellt werden. Nicht einfach die heute beobachtete erneute Freigabe als akzeptiertes Verhalten konservieren.

Für die alte Version separat: Die in test.php gefundenen Authentifizierungsgeheimnisse als offengelegt behandeln, Gültigkeit/Ablösung durch die zuständige Administration klären und gegebenenfalls rotieren. Die Analyse hat weder Authentifizierungsversuche damit durchgeführt noch die Werte in die Auditunterlagen übernommen. Historienbereinigung ist eine gesonderte Maßnahme und ersetzt keine Rotation.

## 5.2 Fachliche Vollständigkeit und Architektur

| Arbeit | Typ | Abnahmekriterium |
| --- | --- | --- |
| Queue-Zeitreihe (F10) | Fehlende Funktion | Schema, idempotenter Sampler, Aufbewahrung/Aggregation, API und UI; Zeitgrenzen und große Datenmengen getestet. |
| Storage-Defaults (F08) | Funktion oder Scopeentscheidung | Entweder Ziel → Policy → Gast nachvollziehbar implementieren oder ausdrücklich dokumentieren, dass Policy → Gast die V2-Anforderung ersetzt. |
| PBS-Taskdetail/-log (F09) | Funktion oder Scopeentscheidung | Unterstützte PBS-3/4-Adapter, Pagination/ACL/Redaction und Darstellung; alternativ explizite Beschränkung des V2-Scope. |
| Gemeinsame Gate-Services (F07) | Architekturkorrektur | Shadow, manuelle Requests, Claim und Submission nutzen dieselben Fachentscheidungen; DB-Transaktionen laden und sichern nur die erforderliche Evidenz. |
| Worker-Bootstrap (F11) | Architekturkorrektur oder ADR | Minimales Wiring erfüllt den Plan oder dokumentierte Entscheidung begründet den gemeinsamen Kernel. |
| Separater Connect-Timeout-Nachweis | Offener technischer Vertrag | Reale Verbindungsaufbaugrenze unter reproduzierbarer Verzögerung belegt; nicht mit Read-/Gesamttimeout verwechseln. |
| Anforderungen/Status vereinheitlichen | Dokumentationskorrektur | Hauptplan, Phasenpläne und Abnahmen nennen denselben Scope und eindeutig gebundene Kandidaten. |

Neue Scopeentscheidungen sollten vor ihrer Umsetzung kurz festgehalten werden. Sie lassen sich aus diesem Audit nicht als bereits vom Benutzer genehmigte Streichungen ableiten.

## 5.3 Neuen Kandidaten lokal absichern

Nach Abschluss der Korrekturen den vorgesehenen Commit beziehungsweise Quellmanifest eindeutig festhalten. Bestehende fremde Arbeitsbaumänderungen weder verwerfen noch pauschal mitcommitten.

1. Fokussierte Regressionen für F01–F06; danach vollständige Backend-/Frontend-Suites wegen des schichtenübergreifenden Start-/Schema-/Notificationvertrags.
2. Echte MariaDB einschließlich frischer Installation, interner Migrationen, altem Writer gegen neues Schema, Rechteprüfung, Locks/Leases/Fencing und Races.
3. Coverage und Mutation nach den verbindlichen Schwellen; Regelpfade in Infrastruktur bei der Bewertung nicht durch hohe Domain-Coverage verdecken.
4. OpenAPI-/Clientgenerierung, PHPStan, Typecheck, Lint/Format und Contractmatrix.
5. Frische AMD64-Anwendungsimages aus Lockfiles, Container-/Secret-/Dependency-Scans, SBOM und Schema-Drift-Vertrag.
6. Playwright-Kernflüsse einschließlich neuer Pre-POST-Notifications und bestehender Administration/Operations.
7. Deploymentänderungen, falls erforderlich, durch die isolierten Inventory-, Compose-, Preflight-, Secret-Permission- und Transaction-Rollback-Tests führen.

Prüfungen an unveränderten Eingaben nicht ohne Anlass erneut ausführen. Veröffentlichungs- und Deploynachweise müssen aber auf genau dem neuen Kandidaten beruhen.

## 5.4 Phase 7 abschließen

Die im historischen Bericht bereits belegten Lab-Tokens, Ziel-/Policykonfigurationen und Grundzustellung sind keine fehlenden neuen Funktionen. Ihre aktuelle Gültigkeit und Kompatibilität mit dem neuen Kandidaten müssen vor Fortsetzung geprüft werden.

Verbleibende repräsentative Abnahme:

- Je ein QEMU- und ein LXC-Backup auf PVE 7, 8 und 9.
- Hoddmímir-Run, Remote-Task, sanitisiertes Log, Endzustand, Zielartefakt, Audit und Outbox eindeutig korrelieren.
- Definitiven Fehler mit dauerhaftem Versuchszähler und Retry-Planung zeigen.
- Blockierendes Gate ohne Remote-Start nachweisen.
- Kontrollierten Cancel mit genau einem Remote-Abbruch zeigen.
- Problemzustellung je Versuch/Prüfung und spätere Entwarnung im vorgesehenen Matrix-Kanal bestätigen.
- V2-Historie beginnt ausschließlich mit neuen V2-Läufen.

Ein erfolgreicher Schattenlauf beweist keine POST-, Monitoring-, Cancel- oder Notification-Ende-zu-Ende-Sicherheit.

## 5.5 Phase 8 und produktive Einsatzbereitschaft

Weiterhin erforderlich:

- Vollständige unterstützte PVE/PBS-Kombinationsmatrix mit Backup-/Snapshotnachweis.
- System-CA, Custom-CA, korrekter/falscher Fingerprint und negative ACL-/Token-Revoke-Fälle.
- Endpoint-/Node-Ausfall und Wiederkehr, Placementwechsel, stale Evidenz und Executor-Timeout.
- Worker-/Lease-Ausfall, zwei Worker im Claim-Race, Transportabbruch nach POST und mehrdeutige Reconciliation ohne neuen POST.
- Mehrdeutiger Cancel-Transportfall ohne unkontrollierten zweiten DELETE.
- Gesicherte neue MariaDB tatsächlich wiederherstellen und Funktions-/Datenintegrität prüfen.
- Runbooks für Deployment, Upgrade, Tokenwechsel, Incident, Rollback und Deaktivierung an der aktuellen Implementierung überprüfen.
- Produktionsverbindungen und neue getrennte Credentials einrichten, Shadowbetrieb prüfen, Aktivierungs-/Deaktivierungsfenster festlegen.
- Produktive Backupausführung erst nach der gesonderten ausdrücklichen Aktivierung einschalten.

Löschwirksame Retention bleibt eine separate Entscheidung; das Audit oder die bisherige Labfreigabe ersetzt sie nicht.

## 5.6 Sinnvolle Arbeitspakete für die Fortsetzung

**Paket A: Startsicherheit und Evidenz.** F01 und F03 mit dauerhaftem Sperrzustand und zyklusübergreifenden Regressionen. Dieses Paket kommt zuerst.

**Paket B: Benachrichtigungsabschluss.** F02, F04, F05 und F06 gemeinsam, weil Schema, Runtime-Wiring, Rechte und API denselben Vertrag ändern.

**Paket C: Fachlicher Restumfang.** Queue-Zeitreihe implementieren; Storage-Defaults und PBS-Taskdetails entscheiden und gegebenenfalls ergänzen.

**Paket D: Architektur und Dokumentation.** Gate-Regeln zentralisieren, Worker-Wiring klären und Statusquellen konsolidieren. Wenn Paket A/B gemeinsame Gateverträge verändert, diesen Teil gleich dort erledigen.

**Paket E: Kandidat und Abnahme.** Vollständige relevante Gates, Veröffentlichung/Deployment im vorgesehenen Ablauf, Phase-7-Restfälle und Phase-8-Abnahme.

Es ist keine neue Grundarchitektur nötig. Der nächste sinnvolle Schritt ist die Absicherung des vorhandenen Ausführungsmodells und der Abschluss der bereits begonnenen Integration.
