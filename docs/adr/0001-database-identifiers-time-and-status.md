# ADR 0001: Datenbank-IDs, Zeitwerte und Statusrepräsentation

- Status: akzeptiert
- Datum: 10. Juli 2026

## Kontext

Hoddmímir beginnt mit einer leeren V2-Datenbank. WebApp, Collector und Backup
Worker greifen parallel auf dieselben fachlichen Datensätze zu. IDs müssen ohne
zentrale Sequenz erzeugbar sein, Zeitvergleiche müssen unabhängig von Host- und
Datenbankzeitzone funktionieren und Zustände müssen explizit versionierbar
bleiben.

## Entscheidung

- Fachliche IDs sind UUIDv7 und werden in MariaDB als `BINARY(16)` gespeichert.
  Die Domain behandelt sie als eigene Werte; die binäre Kodierung bleibt ein
  Infrastrukturdetail.
- Alle fachlichen Zeitwerte werden als `DATETIME(6)` in UTC gespeichert. Jede
  Doctrine-DBAL-Verbindung setzt ihre Session-Zeitzone explizit auf `+00:00`.
  `TIMESTAMP` wird wegen impliziter Zeitzonenumrechnung und engerem Wertebereich
  nicht verwendet.
- Die Anwendung erzeugt Zeitwerte über den injizierten `Clock`-Vertrag. Es gibt
  keine fachlichen Datenbanktrigger und keine Abhängigkeit von lokaler
  Serverzeit.
- Zustände sind ASCII-`VARCHAR`-Spalten mit benannten `CHECK`-Constraints.
  MariaDB-`ENUM` wird nicht verwendet, damit neue Zustände über nachvollziehbare
  Migrationen eingeführt werden können.
- Tabellen verwenden InnoDB und `utf8mb4`; technische Namen und Hash-/Tokenwerte
  verwenden binäre beziehungsweise `ascii_bin`-Vergleiche.
- Persistenz verwendet Doctrine DBAL und Doctrine Migrations, aber kein ORM.
  Domänenobjekte enthalten keine Doctrine-Attribute.
- MariaDB-DDL ist nicht zuverlässig transaktional. Migrationen sind deshalb
  additive Forward-Migrationen und behaupten keine atomare DDL-Rückabwicklung.
- Jede Schemaänderung folgt verpflichtend Expand/Contract: Der Expand-Schritt
  muss mit dem aktuell laufenden und dem neuen Image kompatibel sein. Entfernen
  oder Verengen erfolgt erst in einem späteren Release, nachdem kein altes Image
  mehr zurückgerollt werden kann.
- Readiness verlangt, dass alle vom jeweiligen Image erwarteten Migrationen
  ausgeführt wurden. Zusätzliche neuere Migrationen bleiben bereit, damit nach
  einem fehlgeschlagenen App-Rollout das vorherige Image weiterlaufen kann.

## Folgen

- UUID-Konvertierung und UTC-Normalisierung werden zentral in der Infrastruktur
  implementiert und gegen eine echte MariaDB getestet.
- Häufige Sortierung erfolgt nicht implizit anhand der UUID, sondern über
  explizite Zeit- und stabile ID-Spalten.
- Jeder neue Status erfordert Migration, Anwendungscode und Grenztests im selben
  Change.
- Ein Deployment-Rollback stellt Anwendungscode und Konfiguration wieder her,
  niemals MariaDB-DDL. Runbooks und Fehlermeldungen weisen auf möglicherweise
  bereits vollständig oder teilweise angewandte Forward-Migrationen hin.
