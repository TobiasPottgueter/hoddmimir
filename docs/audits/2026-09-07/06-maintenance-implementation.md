# Nachtrag: Wartungsupgrade und Restore, 8. September 2026

Der Wartungsablauf aus ADR 0006 ist im Arbeitsstand implementiert. Er ist noch
nicht für Produktion freigegeben. Der ursprüngliche Audit bleibt als historische
Momentaufnahme erhalten.

## Implementierter Umfang

- Gemeinsame persistente Dateisperre mit `open`, `draining`, `frozen`; Worker,
  Collector, API, CLI und Benachrichtigungen beachten sie. Operationen halten
  gemeinsame Locks; der Phasenwechsel wartet exklusiv auf laufende Arbeit.
- Direkte vollständige Ruheprüfung auf PVE/PBS einschließlich deaktivierter
  Verbindungen. Laufende Tasks, fehlende Sicht und ungeklärte lokale Arbeit
  verhindern Migration. Externe Starts erfordern organisatorische Sperrung.
- Rootgeschützte, dauerhaft protokollierte Sicherung von Datenbank, Grants,
  Migrationsstand, Images, Konfiguration und Secrets. Restoreprobe auf separater
  MariaDB mit anschließendem Dumpvergleich vor DDL.
- Funktionale Rollen-/Outbox-/Credential-/Worker- und HTTP-Prüfung vor Freigabe;
  Prüfschreibzugriffe werden zurückgerollt.
- Restore nach teilweiser Migration oder Kandidatenfehler; Wiederaufnahme nach
  Prozessabbruch, gesperrter Zustand bei Recoveryfehler und dauerhaftes Verbot
  automatischer DB-Rückkehr nach Freigabe.

Bedienung und Parameter: [Ansible README](../../../deployment/ansible/README.md#maintenance-upgrades-protocol-1).

Aktuelle Fortsetzung und Korrekturen: [Bericht 07](07-maintenance-security-and-runtime-acceptance.md).

## Bereits erfolgreiche Prüfungen

| Prüfung | Ergebnis |
| --- | --- |
| Backend-Container: Composer, OpenAPI, PHPStan, Unit/Contract | 2.722 Tests, 12.484 Assertions |
| Erneutes PHPStan nach Testkorrekturen | ohne Fehler |
| Frontend: API-Drift, ESLint, TypeScript, Coverage-Tests | 290 Tests in 61 Dateien; Formatkorrektur separat erfolgreich geprüft |
| Browser | 19 Playwright-Tests, Desktop und Mobile |
| Deployment | 123 Tests, davon der separat ausgeführte Real-DB-Test standardmäßig übersprungen |
| Echter MariaDB-Dump/Teil-DDL/Restore | erfolgreich, einschließlich Binärdaten, Views und Grants |
| Wartung und Queue gegen echte MariaDB | 51 Tests, 1.027 Assertions |
| Vollständige MariaDB-Suite | 304 Tests, zunächst eine veraltete Rechteerwartung; korrigierte Rechtegruppe mit 4 Tests und 126 Assertions erfolgreich nachgeprüft |
| Ansible Lint, isoliertes Inventory und Syntax | erfolgreich |
| Produktions-/Lab-Secret- und Lab-Inventory-Skripte | erfolgreich |
| CLI-Protokoll und Validierung | Protokoll 1; ohne eingefrorene Sperre abgewiesen, mit Sperre validiert |
| Supply-Chain-Verträge | 46 Tests erfolgreich mit lokalem Container-PHP |
| Gitleaks | 54 Commits, keine Funde |
| Produktionsimages | alle drei für linux/amd64 gebaut |
| Mutation kritisch | 90,27 Prozent MSI; 4.535 Varianten; keine Timeouts |
| Mutation global | 80,40 Prozent MSI; 20.705 Varianten; keine Timeouts |

Der Mutation-Gate wurde mit 16 parallelen Prozessen und unverändertem
Anwendungscode erfolgreich abgeschlossen. Seine bereits erfolgreich erzeugte
Coverage wurde wiederverwendet. Alle Quellhashes im Mutation-Coverage-XML stimmen
mit den aktuellen Backend-Quelldateien überein. Zusammenfassungen liegen unter
`artifacts/maintenance-validation/mutation-critical.json` und
`artifacts/maintenance-validation/mutation-global.json`.

## Coverage

Die instrumentierte Core-Suite lief mit 2.722 Tests erfolgreich durch.
`Application/Maintenance` erreicht 100 Prozent Zeilen- und Branch-Abdeckung
(13 ausführbare Zeilen, 25 Branches). Auch `CheckBackupNodeTasks` erreicht
100 Prozent (15 Zeilen, 18 Branches).

Der Gesamt-Gate ist nicht grün: Das vorab eingefrorene Coverage-Testimage
enthielt noch die veraltete Rechteerwartung. Seine MariaDB-Phase lief mit
479 Tests und genau diesem Fehler durch; die aktuelle korrigierte Testgruppe
ist separat erfolgreich geprüft. Der Coverage-Lauf wurde nicht als erfolgreiche
Gesamtabnahme umdeklariert. Im Core-Bericht fehlen außerdem noch:

- `PrePostProblemClassifier`: eine Zeile und ein Branch;
- `RecoveryOutcome`: ein Branch;
- `PveHttpBackupClient`: zwei Zeilen und vier Branches.

Diese Stellen gehören zu den vor der Wartungsimplementierung vorhandenen
Backupänderungen im Arbeitsbaum. Für eine Freigabe müssen die Lücken geschlossen
und die betroffenen Gates mit dem finalen Teststand erfolgreich abgeschlossen
werden. Der fehlgeschlagene Wrapper hat seine temporären Coverage-Berichte bereinigt.
Die zuvor ausgelesenen Teilwerte sind als abgeleitetes Protokoll in
`artifacts/maintenance-validation/coverage-observed.json` festgehalten; daneben
liegen der Gate-Log und die Wartungs-XML-Dateien der Mutation-Coverage. Der unten
genannte Manifest-Hash beschreibt den Stand nach den Testkorrekturen.

## Offene Freigabegrenzen

Korrektur vom 8. September: Die zunächst zugeordneten 15 HIGH/CRITICAL-Befunde
stammen aus einem alten MariaDB-Bericht vom 13. Juli, nicht aus einer aktuellen
Prüfung dieser Images. Daraus lässt sich kein aktueller Sicherheitsbefund ableiten.
Ein frischer vollständiger Build und Scan ist für die Freigabe erforderlich.
Keine Versionspins oder Schwellenwerte wurden für diese Wartungsimplementierung
aufgeweicht.

Eine vorhandene Installation muss Wartungsprotokoll 1 bereits unterstützen.
Die einmalige Überführung älterer Images ist ausschließlich ein manuelles Offline-Verfahren;
eine bloße Compose-Änderung reicht nicht. Die Live-Probe mit PVE/PBS und eine
vollständige Upgrade-/Recovery-Abnahme auf einer kompatiblen Installation stehen
aus. Es wurde weder veröffentlicht noch auf Produktion ausgerollt.

## Geprüfter Arbeitsstand

Basis-Commit: `bd6a915cc50b8b8b29aec86c7d57953427ef1d04`, mit den bestehenden
uncommitteten Änderungen. Das lokale Manifest
`artifacts/maintenance-validation/source-files.json` erfasst die Quell- und
Testdateien nach den Korrekturen; SHA-256:
`082cf1d86d37f19f4cebf9b0bfe673568f1bd5f417f50cac2c5ea5c502520db9`.
Nach den breiten Läufen wurden ausschließlich betroffene Checks für geänderte
Testdateien wiederholt; reine Dokumentationsänderungen lösen keinen erneuten
vollständigen Lauf aus.
