# ADR 0004: Collector-Raster, Lease und Fencing

- Status: akzeptiert
- Datum: 10. Juli 2026

## Kontext

Der Collector läuft kontinuierlich mit einem standardmäßigen 120-Sekunden-Takt.
Ein langer Zyklus darf weder einen zweiten Zyklus überlappen noch verpasste
Rasterpunkte nachholen. Nach Crash oder doppeltem Prozessstart darf außerdem nur
ein Worker den globalen Zyklus finalisieren.

## Entscheidung

- Ein Collector-Prozess eröffnet ein startzeitbasiertes Raster. Die Breite ist
  Deployment-Konfiguration und standardmäßig 120 Sekunden.
- Nach einem Zyklus wird strikt der erste zukünftige Rasterpunkt berechnet. Liegt
  das Ende genau auf einem Tick, ist der folgende Tick der nächste. Alle während
  des Laufs verpassten Ticks werden verworfen.
- Das Raster gilt global für alle aktivierten Connections. Darum existiert genau
  eine Schedule-Zeile `inventory`, nicht ein unabhängiger Zeitplan je Connection.
- Vor Remote-I/O claimt der Worker die Schedule-Zeile mit einem bedingten,
  atomaren Update. Owner-ID, zufälliger Lease-Token, Ablaufzeit und monotoner
  Fencing-Token bilden gemeinsam die Besitzberechtigung.
- Heartbeats dürfen eine noch gültige Lease nur mit exakt passendem Owner,
  Lease-Token und Fencing-Token verlängern.
- Nach Ablauf kann ein anderer Worker claimen und erhöht den Fencing-Token. Ein
  alter Worker darf danach weder Ergebnisse anwenden, den Zyklus finalisieren
  noch die neue Lease freigeben.
- Der Collector verwendet eine eigene Application-Runtime und niemals die
  generische `WorkerLoop`-Readiness-Schleife. Rasterberechnung und Lease-Zustände
  liegen in reinen Application-Klassen; die atomaren SQL-Operationen liegen im
  Infrastructure-Repository.

## Folgen

- Rastergrenzen, Überläufe, Lease-Ablauf und verlorener Besitz sind ohne I/O
  vollständig unit-testbar.
- Repository-Integrationstests müssen zwei unabhängige DB-Verbindungen und einen
  echten parallelen Claim gegen MariaDB verwenden.
- Ein Sync-Snapshot wird erst nach erneutem Fencing-Check in einer kurzen
  Transaktion angewandt.
