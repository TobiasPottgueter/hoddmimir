# Anschlussarbeiten nach der Maintenance-Abnahme

Arbeitsstand: 8. September 2026. Dieser Bericht ergänzt die historischen
Auditbefunde. Reale DEV-Abnahme, vollständige CI, Kandidatenveröffentlichung
und Deployment der Registry-Digests sind abgeschlossen.

## Queue-Historie (F10)

Der Collector schreibt idempotente Messpunkte auf einem 120-Sekunden-Raster;
Queue-Zustände umfassen wartende Requests, reservierte/laufende Starts und
laufende Taskklärung. Terminale `unknown`-Requests zählen nicht weiter als
aktive Queue. Globale und zielbezogene Reihen enthalten auch Nullwerte.
Messpunkte bleiben 30 Tage erhalten. Die API liefert 24 Stunden unverändert,
7 Tage in 15-Minuten- und 30 Tage in Stundenintervallen. Mittelwerte, Spitzen,
älteste Wartezeit und Zahl vorhandener Stichproben bleiben unterscheidbar;
fehlende Messpunkte werden nicht als Nullwerte erfunden. Die Queue-Seite zeigt
eine paginierte Historie für die drei Zeiträume.

Fokussiert geprüft: 3 echte MariaDB-Tests mit 36 Assertions einschließlich
21.600 Messpunkten, Duplikatschutz, Zielauswahl und Retention-Grenzen;
API-Zugriffsschutz, Komponenten, Typecheck und 100 % Line-/Branch-Coverage
der neuen Application-Dienste.

## Backup-Vorgaben (F08)

Ziele besitzen optionale Vorgaben für Modus, Kompression und Retention.
Explizite Policywerte haben Vorrang; Gast-Overrides bleiben die höchste Ebene.
Retention wird vollständig übernommen. Eine fehlende effektive Konfiguration
blockiert die Aktivierung und Änderungen aktivierter Policies unter Sperren.
Vor Änderungen an Zielvorgaben müssen alle zugehörigen Policies deaktiviert
werden. Requests ohne die neuen optionalen Felder erhalten vorhandene Vorgaben
bei Zieländerungen. Die WebApp zeigt die wirksamen Werte und ihre Herkunft.

Die Zielrevision und der vollständige aufgelöste Snapshot schützen sowohl
manuelle als auch automatische Requests vor unbemerkten Konfigurationswechseln.
Die Vererbung erteilt keine Retention-Ausführungsfreigabe. PBS bleibt ohne
löschwirksame VZDump-Parameter; Legacy-maxfiles bleibt auf PVE 9 gesperrt.

Die neue Migration erhält bestehende Policies unverändert. Ein Rückbau eines
Schemas mit konfigurierten Policies erfolgt über den Maintenance-Restore;
lediglich eine leere Testinstallation lässt sich intern zurückmigrieren.

Fokussiert geprüft: Domain-Vererbung und Übergänge, Speicherung und atomare
Konfigurationssperren, manuelle sowie automatische Policy-Snapshots,
Schema-/Rechteprüfungen, API und UI. Die bisher erweiterten Domain-Klassen
und das Ziel-Readmodel erreichen 100 % Line-/Branch-Coverage.

## PBS-Taskdetails und Logs (F09)

Der laufende Collector ergänzt pro Verbindung und Zyklus bis zu acht sichtbare
Tasks, laufende zuerst und danach die neuesten. Die PBS-3/4-Adapter lesen Status
und maximal 501 Logzeilen zur Erkennung einer Kürzung; gespeichert werden
höchstens 500 bereinigte Zeilen. Begrenzte Antwortgrößen und Zeilenlängen,
UPID-/Identitätsprüfung und getrennte Fehlercodes schützen den Leseweg.
Fehlende ACLs oder ungültige Detailantworten verwerfen keine Tasklisten-Evidenz.

Die Speicherung erfolgt in derselben gefencten Transaktion wie die Taskbeobachtung.
Nicht erneut abgefragte Details behalten ihren Erfassungszeitpunkt. Die
WebApp liest ausschließlich diese Projektion, paginiert 50 Tasks pro Seite und
zeigt fehlende, veraltbare und gekürzte Daten ausdrücklich. Der Web-Datenbankrolle
werden keine Credential-Leserechte hinzugefügt.

Fokussiert: 111 Tests / 421 Assertions mit 100 % Line-/Branch-Coverage der neuen
Application-/Adapterklassen und des erweiterten MonitoringCommit;
12 MariaDB-/Rechtetests / 166 Assertions, geschützte API-Routen, OpenAPI,
Typecheck und fünf Komponenten-/Viewtests. Reale PBS-Abnahme folgt mit dem
neuen Kandidaten.

Vertragsquellen: [PBS 3 API](https://pbs.proxmox.com/docs-3/api-viewer/),
[PBS 4 API](https://pbs.proxmox.com/docs/api-viewer/), jeweils
`/nodes/{node}/tasks/{upid}/status` und `/log`; zusätzlich
[Proxmox Task-Implementierung](https://github.com/proxmox/proxmox-backup/blob/master/src/api2/node/tasks.rs).

## Gemeinsame Startregeln und frische Schreibzähler (F07/F03)

Shadow, manuelle Annahme, Claim und Submission verwenden gemeinsame reine
Regeln für Gast-/Ressourcenfreigaben, PBS-Ziele, Revisionen, Empfänger,
Kapazität und Slots. Jede Stufe behält ihre erforderliche Evidenz und Sperren;
der unmittelbar vor dem POST erforderliche Taskcheck bleibt unverändert.
Kapazitätsrechnung verwendet exakte UInt64-Dezimalarithmetik ohne Überlauf.
Shadow prüft nun ebenfalls die Backup-Capability des Storages.

Ein dabei noch vorhandener F03-Fehler ist behoben: Fehlende, zukünftige oder
veraltete Schreibzähler erzeugen weder einen Byte-Trigger noch einen Reset
der Baseline. Altersbasierte Gründe bleiben unabhängig davon möglich.
Fokussiert: 112 Unit-Tests / 352 Assertions, 52 MariaDB-Tests / 1124 Assertions;
neue gemeinsame Regeln und Shadow erreichen 100 % Line-/Branch-Coverage.

## Worker und Transport (F11)

[ADR 0007](../../adr/0007-worker-runtime-wiring.md) entscheidet ausdrücklich
für den gemeinsamen Symfony-Container. Die Worker starten keinen HTTP-Server;
Framework- und API-Definitionen bleiben im gemeinsamen Build vorhanden.
Die Entscheidung ersetzt die ursprüngliche Forderung nach separatem minimalem
Bootstrap und dokumentiert die Kosten des gemeinsamen Laufzeitumfangs.

Der Transport besitzt nun eine getrennte Fünf-Sekunden-Verbindungsfrist bei
unveränderten 30-/60-Sekunden-Lesebudgets. Die genaue TLS-/Pin-Semantik und
lokalen Socket-Tests stehen im [Read-Vertrag](../../pve-first-read-contract.md).

## Aktuelle DEV-Abnahme

Die Abnahme erfolgt auf der bestehenden DEV-Installation an der unveränderten
HTTPS-Adresse. Der vorhandene Administrator und die Schlüssel blieben erhalten.
Der manuelle Übergang auf Wartungsprotokoll 1 und ein anschließendes reguläres
Upgrade der inzwischen befüllten Datenbank bestanden. Ablauf, Sicherungen und
Nachweise stehen im [DEV-Abnahmebericht](10-existing-dev-upgrade-and-backup-acceptance.md).

PVE 7/8/9 und PBS 3/4 sind über elf TLS-verifizierte Endpunkte eingerichtet.
Die Backup-Token besitzen nach gesicherter ACL-Prüfung zusätzlich `HoddmimirScan`
auf `/nodes`, damit die unmittelbare Taskprüfung auch fremde Backups sieht.
Sechs ausgewählte Gäste sichern nach PBS 3 beziehungsweise PBS 4;
der bestätigte Fehlerempfänger ist `backup@netzkultur.cloud`.

Die echte Abnahme deckte einen cURL-Randfall auf: TLS-Session-Resumption kann
Leaf-Informationen für `CURLINFO_CERTINFO` weglassen. Der deaktivierte
TLS-Session-Cache erzwingt vollständige Zertifikatsevidenz bei jedem Request.
Wiederholte echte PVE-Reads und 48 fokussierte Tests / 321 Assertions bestehen;
der Guard erreicht 47/47 Zeilen und 36/36 Branches. Die anschließend ausgeführten
Gesamtgates enthalten diese Korrektur.

Der Collector projizierte PBS-Taskstatus und bereinigte Logs ohne Lesefehler.
Die sechs regulären Backups, kontrollierter Abbruch, definitiver Fehler mit
Retry sowie Matrix-Fehler und Entwarnung bestanden auf der bestehenden DEV.
Bei verlorenen Startantworten wurden laufende, erfolgreiche und fehlgeschlagene
Tasks nach der im DEV-Bericht dokumentierten Tokenkorrektur richtig zugeordnet.
Ein normaler manueller Auftrag wurde bei nicht erreichbarer direkter Tasksicht
vor dem POST blockiert, ohne Lauf und ohne Startrequest.

299 Frontend-Tests und 19 Browser-Szenarien bestanden für den unveränderten
Frontendstand. Der aktuelle Scan aller drei amd64-Images enthält keine
HIGH-/CRITICAL-Befunde. Die vollständige Veröffentlichungs-CI enthält die
zusätzlichen SQL-Korrekturen der Submission-Tokenidentität und Task-Eigentümerschaft
und ist bestanden; die abschließenden Werte stehen in Bericht 10.

Auch die Recovery-Prüfung ohne Tasktreffer mit fremdem Backup und zwei
parallelen Workern ist bestanden. Ein separater Test belegt den tatsächlichen
Workerneustart anhand geänderter Prozessstartzeiten und fortgeschrittener
Fencing-Werte bei weiterhin unerreichbarer Tasksicht. Der unbekannte
Vorgänger bleibt erhalten; genau ein verknüpfter Folgeversuch wurde erfolgreich.
Die direkten Sperren und die bereinigten Nachweise stehen in Bericht 10.
Die vollständigen Veröffentlichungsgates und das Deployment der endgültigen
Registry-Digests sind abgeschlossen. Der einmalige Übergang älterer
V2-Installationen bleibt ausschließlich manuell.
