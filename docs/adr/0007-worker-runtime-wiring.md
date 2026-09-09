# ADR 0007: Gemeinsamer Symfony-Kernel für das Runtime-Wiring

Datum: 8. September 2026

Status: umgesetzt; dokumentierte Architekturentscheidung im Anschluss an F11.

## Entscheidung

Web-API, Collector und Backup-Worker verwenden weiterhin denselben kompilierten
Symfony-7.4-DI-Container und `App\Kernel`. Die Worker starten über Symfony Console.
Das ersetzt die frühere Vorgabe eines ausschließlich aus einzelnen Console-/DI-
Komponenten zusammengesetzten Worker-Bootstraps.

Damit enthalten Worker-Images FrameworkBundle und HttpKernel-Klassen sowie die
Service-Definitionen der API. Das ist eine bewusste Abweichung vom ursprünglichen
Minimal-Bootstrap, kein separat implementierter Minimalcontainer. Die Worker
starten keinen HTTP-Server und bearbeiten keine HTTP-Requests. Twig, ORM und
Messenger werden weiterhin nicht eingeführt.

## Begründung

Das gemeinsame Wiring bindet für alle Prozesse dieselben verschlüsselten
Konfigurationen, Proxmox-Adapter, Redaction, Readiness und Maintenance-Sperren ein.
Ein zweiter Bootstrap müsste diese Abhängigkeiten und die Console-Maintenance-
Subscriber separat registrieren. Das würde gerade für Upgrade-/Restore-Proben
und den Schutz vor Starts während Wartung einen zusätzlichen Driftpfad schaffen.

Der Kernel bleibt an der äußeren Prozessgrenze. Domain und Application importieren
keine Symfony-Klassen. Scheduling, Gates und Zustandsentscheidungen bleiben
unabhängig testbar. Die Datenbankrollen und Proxmox-Credentials entscheiden über
den tatsächlichen I/O-Zugriff; die bloße Registrierung einer Web-Service-Definition
gibt einem Worker keine Web- oder Migrationsrechte.

## Nachweis und Grenzen

`WorkerCommandsTest` bootet den gemeinsamen Kernel, konstruiert den produktiven
Worker-Abhängigkeitsgraphen und prüft einmalige Läufe, Readiness und Startverbote.
Die Container- und Maintenance-Abnahmen prüfen denselben Bootstrap mit echten
Rollen und Secrets. Architekturtests und statische Analyse sichern die inneren
Schichten; diese Entscheidung erlaubt keine Fachlogik in Symfony-Controllern.

Ein schlankeres Worker-Image bleibt möglich, benötigt aber einen eigenen
vollständigen Wiring-/Maintenance-/Rollen-Nachweis. Es ist keine ausstehende
Voraussetzung dieses V2-Kandidaten. Der Preis der jetzigen Entscheidung ist der
zusätzliche Framework-Code im Worker-Image und dessen gemeinsame Updatepflicht.
