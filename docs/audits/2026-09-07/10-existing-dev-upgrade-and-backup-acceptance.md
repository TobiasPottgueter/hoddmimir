# Bestehende DEV-Installation: Übergang und Backup-Abnahme

Stand: 8. September 2026. Der einmalige Übergang der bestehenden DEV-Installation
und die reale Backup-/Recovery-Abnahme sind abgeschlossen. Kandidat
`2.0.0-rc.20260908.1` ist nach vollständiger CI veröffentlicht und auf derselben
Installation mit geprüften Registry-Digests ausgerollt.

## Verbindliches Ziel

Ziel ist die vorhandene Installation `/opt/hoddmimir`, Compose-Projekt
`hoddmimir`, hinter `https://hoddmimir.netzkultur.cloud`. Es gibt keinen
produktiven Nutzbetrieb. Der technische Ansible-Profilname ändert daran nichts.
Die zwischenzeitlich zusätzlich aufgebaute Instanz `hoddmimir-dev-v2` war eine
unnötige Abweichung vom Auftrag. Nach Prüfung auf fehlende Aufträge, Runs und
aktive Policies wurden Datenbank und Installation geschützt auf zwei Hosts
gesichert und ausschließlich ihre vier Container, ihr Netzwerk und ihr Volume
entfernt. Die vorhandene separate Lab-Installation blieb unberührt.

Die vorhandene Datenbank enthält vor dem Übergang einen Benutzer, aber keine
Verbindungen, Ziele, Policies, Gäste, Requests, Runs oder Benachrichtigungen.
Der bestehende Administratorzugang wurde über die API bestätigt. Dateien,
Schlüssel, Secretstände und exakte alte Image-Referenzen sind geschützt gesichert.
Der Caddy-VHost zeigt unverändert auf den bestehenden Loopback-Port 8080.

Der zu übernehmende Fehlerempfänger ist `backup@netzkultur.cloud`. Die
ausschließlich lesende Prüfung des vom Benutzer benannten Alt-Repositories
bestätigte `mailnotification=failure` und diesen Empfänger in
`executeBackup.php:1161–1162`, Commit `b0bbb7e441e96d410b1be6209542dd7deb6a407b`.
Es werden weder alter Quellcode noch alte Credentials oder Datenbanken importiert.

## Einmaliger manueller Offline-Übergang

Der installierte Stand besitzt noch kein Wartungsprotokoll 1. Deshalb wird
der reguläre automatische Upgradepfad nicht dafür benutzt. Der Übergang wird
als einzelne Operator-Schritte durchgeführt:

1. Ziel, deaktivierte Ausführung, leere Arbeitszustände und Remote-Ruhe prüfen.
2. Konkurrierende Deployments sperren und die drei schreibenden
   Anwendungskomponenten stoppen; die vorhandene MariaDB bleibt erhalten.
3. Einen neuen konsistenten Datenbank-/Grantdump samt Dateien, Schlüsseln und
   Image-Identitäten sichern. Die Sicherung geschützt auch auf einem zweiten
   Host ablegen. Den exakten Dump in einer temporären MariaDB wiederherstellen
   und durch identischen erneuten Dump prüfen, bevor DDL erfolgt.
4. Die aus den aktuellen Ansible-Templates vorbereitete Konfiguration mit
   identischen Installations-, Secret-, Netzwerk-, Datenbank- und HTTPS-Zielen
   installieren. Den persistenten Wartungszustand geschlossen initialisieren.
5. Migration und funktionale Prüfung unter Wartung ausführen: vier Datenbankrollen,
   beide Worker, Schema, Benutzerbestand, Health- und HTTP-Wartungsgate.
6. Erst nach erfolgreicher Prüfung freigeben. Backupausführung und Matrix bleiben
   zunächst deaktiviert; die PVE-/PBS-Konfiguration und begrenzte Testauswahl
   erfolgen danach über die Web-API.

Vor Freigabe erlaubt ein Fehler die geprüfte Rückkehr zu Dump, Dateien und alten
Images. Nach Freigabe wird dieser Vorzustand nicht automatisch wiederhergestellt.
Dies ergänzt keinen automatisierten Baseline-Importer oder Legacy-Upgradepfad.

## Geprüfte Offline-Probe

Die Probe verwendet eine temporäre MariaDB-Kopie in einem internen Docker-Netz.
Sie ersetzt keine vorhandene Anwendung und erhält keinen veröffentlichten Port.
Ein identischer Dump nach Restore bestätigt Schema, Daten und Grants. Anschließend
liefen die acht ausstehenden Migrationen bis `Version20260908000300`, die
funktionale Prüfung aller vier Datenbankrollen, beide Worker-Readiness-Prüfungen,
die Ruheprüfung und das tatsächliche HTTP-Wartungsgate erfolgreich.
Nach der Migration wurde der Originaldump erneut hergestellt und bytegleich
geprüft. Die temporären Container, das Volume und das Netzwerk wurden entfernt.
Die Probe wurde mit dem abschließend benannten PBS-JSON-Constraint wiederholt.

Nachweise liegen unter
`artifacts/followup-validation/2026-09-08/existing-dev/`, insbesondere
`rehearsal-result.json`, `rehearsal.log`, `runtime-images.json` und
`image-archive-sha256.json`. Private Dumps und Schlüssel liegen ausschließlich
in geschützten, nicht versionierten Ablagen.

## Qualität und verbleibende Abnahme

Der tatsächliche Offline-Übergang bestand am selben Tag: Drei Anwendungskomponenten
wurden gestoppt, der frische Dump wurde geschützt auf einen zweiten Host kopiert
und in einer separaten MariaDB bytegleich wiederhergestellt. Acht Migrationen
führten auf 40 Schema-Versionen. Vier Rollenprüfungen, beide Worker-Readiness-
Prüfungen sowie Health 200 und Wartungsgate 503 bestanden vor der Freigabe.
Benutzerbestand, Schlüssel und bestehende Secretstände blieben erhalten.
Nach Freigabe bestanden der vorhandene Administratorlogin, die API und HTTPS
über IPv4 an der unveränderten Adresse. Ein IPv6-DNS-Eintrag ist dort derzeit
nicht auflösbar. Backup-Ausführung und Matrix blieben für das Onboarding deaktiviert.
Ab der protokollierten Freigabe darf der alte Offline-Dump nicht automatisch
zurückgespielt werden.

Die komplette erste Kernausführung bestand mit 2.793 Tests / 12.919 Assertions.
Die vollständige MariaDB-Ausführung fand veraltete Schema-Erwartungen, einen
impliziten JSON-Constraintnamen und fehlende Isolation gegenüber Messpunkten
anderer Collector-Tests. Die Korrekturen erweitern den benannten JSON-Check
und isolieren den historischen Testzeitraum transaktional. Die übrigen Tests
und Fachregeln werden dadurch nicht abgeschwächt.

Mutation: kritisch 90,58 % bei 4.724 Mutationen, global 80,60 % bei 21.299
Mutationen. Beide Berichte weisen null Timeouts aus. Die Anwendungsklassen und
ausgeführten Nicht-Datenbanktests wurden anhand von 1.301 Dateihashes mit dem
Arbeitsbaum verglichen; spätere Änderungen betreffen nur die benannte Migration
und Integrationsfixtures. Der Scan der aktualisierten amd64-Images fand keine
HIGH-/CRITICAL-Befunde. Der um einen VZDump-Drop vor der Upstream-Verbindung
erweiterte Test-Proxy besteht 24 Tests.

Die erneute vollständige MariaDB-Suite besteht mit 499 Tests und 8.138 Assertions.
Der dabei verwendete Integrationsfixture-Override ist samt Hash dokumentiert;
Anwendungscode und Schema entsprechen dem unveränderten Coverage-Image.

Auch der abschließende Coverage-Gate besteht: Domain/Application 8.177/8.177
Zeilen und 7.505/7.505 Branches, Proxmox-Infrastruktur 3.157/3.157 Zeilen und
2.501/2.501 Branches. Global werden 95,55 % Zeilen und 92,01 % Branches erreicht.
Die Deployment-Suite besteht mit 125 Tests und zwei optionalen Übersprüngen;
deren reale Container-/Datenbanknachweise sind separat dokumentiert.

Die nachfolgenden Recovery-Korrekturen wurden anschließend in einer frischen
vollständigen Veröffentlichungs-CI geprüft. Die finale Veröffentlichung und das
Deployment sind am Ende dieses Berichts dokumentiert. Die reale DEV-Abnahme ist
einschließlich fremder Tasks bestanden.

## Reguläre Backups und zusätzliche Betriebsbefunde

Die sechs regulären Backups bestanden auf PVE 7/8/9: je QEMU nach PBS 3 und
LXC nach PBS 4, genau sechs Start-POSTs und kein DELETE. Für jeden Lauf wurden
der erfolgreiche Proxmox-Endzustand, genau ein nichtleeres Archiv aus dem
Tasklog und die zugehörigen Anwendungsevents unabhängig geprüft.
Der QEMU-Abbruch bestand mit einem POST, einem DELETE und ohne Retry.
Ein gezielt gesperrter LXC erzeugte einen definitiven Fehler und nach 60 Sekunden
genau einen erfolgreichen Retry; Grund und Priorität blieben gleich. Matrix
bestätigte Fehler und Entwarnung jeweils nach einem Zustellversuch.

Dabei wurden zwei Konfigurationslücken geschlossen: Der Matrix-Platzhalter der
bestehenden DEV-Installation wurde durch den bereits konfigurierten DEV/Lab-
Webhook ersetzt, einschließlich des geschützten Inventory-Vaults. Außerdem
fehlte ein aktiver NTP-Dienst. Eine reine NTP-Messung bestätigte rund 154 Sekunden
Uhr-Rückstand. Bei gestoppter Anwendung wurde synchronisiert; anschließend
lagen DEV, PVE und PBS innerhalb von 0,2 Sekunden zur Vergleichsuhr.
Der Bootstrap aktiviert nun den vorhandenen BusyBox-NTP-Client dauerhaft.
Die sechs vorher erzeugten Archive wurden über ihre expliziten Tasklog-
Archivnamen zugeordnet, nicht über die damals versetzten Anwendungstimestamps.

## Tokenidentität bei verlorener Antwort

Die erste echte Recovery-Prüfung fand einen weiteren Implementierungsfehler:
Die persistierte Submission enthielt nur den Benutzernamen, während der
Proxmox-UPID zusätzlich `!token_name` enthält. Die exakte Zuordnung scheiterte
deshalb; nach Ende des ersten Tasks entstand ein zusätzlicher Folgeversuch.
Der Worker wurde für die Korrektur gestoppt. Die historischen Ergebnisse bleiben
unverändert erhalten.

Die Submission speichert nun die vollständige Tokenidentität. Der ergänzte
Integrationstest gibt die entfernte Identität unabhängig vom Snapshot vor und
reproduzierte den Fehler vor der Korrektur. Danach bestanden 46 Queue-/Submission-
Tests mit 998 Assertions, die komplette reguläre MariaDB-Suite mit 311 Tests /
7.566 Assertions und PHPStan. Alle drei Images wurden erneut gebaut und ohne
HIGH-/CRITICAL-Befunde gescannt. Das reguläre Wartungsprotokoll aktualisierte
die bestehende, inzwischen befüllte DEV erfolgreich; die installierte Datei
wurde unabhängig mit dem lokalen Quellhash verglichen. Die oben genannten
Gesamtgates beziehen sich auf den Stand vor dieser zusätzlichen SQL-Korrektur.

Die anschließenden echten Recovery-Fälle bestanden:

- Laufender Task: während der Anwendungserkennung unabhängig auf PVE noch
  laufend gesehen, danach erfolgreich; genau ein POST und kein Folgeauftrag.
- Bereits erfolgreicher Task: Antwort bis zum unabhängig bestätigten Taskende
  zurückgehalten und anschließend verworfen; erfolgreicher Endzustand übernommen,
  genau ein POST und kein Folgeauftrag.
- Fehlgeschlagener Task: verlorene Startantwort und gezielte Gastsperre;
  tatsächlicher Fehler übernommen, nach 60 Sekunden genau ein erfolgreicher Retry.
  Grund und Priorität blieben erhalten; die Gastsperre wurde entfernt.
  Klärung, Fehler und Entwarnung wurden jeweils einmal zugestellt. Der
  Problemzähler erfasst hier zwei Ereignisse: Startunklarheit und Taskfehler.
- Allgemeines Startgate: bei gezielt unterbrochener direkter Tasksicht entstand
  `remote_tasks_unavailable`, kein Lauf und kein POST. Der wartende Auftrag
  wurde vor Wiederherstellung des Zugangs storniert.

Die bereinigten Einzelnachweise liegen als `matched-running-fixed.json`,
`matched-terminal.json`, `matched-failed.json` und
`ordinary-prestart-unreachable.json` im oben genannten Artefaktverzeichnis.

## Bereits zugeordnete Tasks in überlappenden Suchfenstern

Die zeitlich eng aufeinanderfolgenden Fault-Tests reproduzierten einen weiteren
Randfall: Ein älterer Task lag noch im Suchfenster und gehörte bereits zu einem
anderen Anwendungslauf. Der UNIQUE-Constraint verhinderte die doppelte Zuordnung,
der ungefangene Konflikt beendete jedoch den Worker. Die gefencete
Zuordnungstransaktion prüft deshalb zusätzlich den bestehenden Taskbesitzer.
Ein bereits zugeordneter Task ist kein Treffer für den aktuellen Lauf; nach
vollständiger Taskklärung greift die normale Wiederfreigabe mit unbekanntem
Vorgänger. Der allgemeine direkte Taskcheck bleibt vor jedem Folge-POST wirksam.

Der neue echte MariaDB-Regressionstest reproduziert den UNIQUE-Konflikt vor der
Korrektur und besteht danach mit 22 Assertions. Er prüft den erhaltenen alten
Taskbesitzer, den unbekannten aktuellen Lauf, die einmalige Verknüpfung und
freigegebene lokale Slots. Die vollständige MariaDB-Suite besteht nun mit
312 Tests / 7.592 Assertions; PHPStan und der erneute Scan aller drei Images
bestehen ebenfalls.

Der betroffene DEV-Auftrag wurde nach Stoppen der Anwendung und privater
Datenbanksicherung durch genau einen Aufruf des korrigierten Workers geklärt.
Neue Backupstarts und Matrix-Zustellung waren in diesem Reparaturprozess
explizit deaktiviert. Queue-Zeilen wurden nicht manuell umgeschrieben.
Die anschließende Prüfung mit der vorgesehenen Maintenance-Datenbankrolle
bestätigte lokale und entfernte Ruhe. Der Datenbankdump wurde geschützt auf
den zweiten Host kopiert und per SHA-256 verglichen. Das anschließende Upgrade
verwendet wieder das reguläre Wartungsprotokoll.


## Wiederfreigabe ohne Treffer, Neustart und fremde Belegung

Der Test-Proxy verwarf genau einen Startrequest vor jeder Upstream-Verbindung.
Anschließend wurde ausschließlich der Proxyzugang der Anwendung vorübergehend
unterbrochen. Der Auftrag blieb in Klärung; zwei neue manuelle Request-IDs
wurden mit `active_request_exists` abgelehnt. Nach Skalierung auf zwei Worker
wurde weitere 130 Sekunden gewartet. Es entstand weder ein zusätzlicher
Proxy-POST noch ein Folgeauftrag auf Grundlage der nicht erreichbaren Tasksicht.
Die zusätzliche Fencing-Prüfung zeigte, dass Docker bei dieser Skalierung den
bisherigen Prozess weiterlaufen ließ; der tatsächliche Neustart wurde deshalb
anschließend gesondert abgenommen, wie unten beschrieben.

Ein anschließend durch den Administrator gestartetes QEMU-Backup belegte auf
demselben Node den Slot. Nach Wiederherstellung des Zugangs konnte die vollständige
Taskklärung genau einen Folgeauftrag erzeugen. Zunächst blockierte die normale
Inventarfrische; danach wurde das direkte `remote_backup_running`-Gate als
`pre_submit_blocked` auditiert. Während der fremde Task lief, blieb der
Proxyzähler unverändert. Der fremde Task wurde weder abgebrochen noch beschleunigt.
Nach seinem natürlichen erfolgreichen Ende lief der Folgeauftrag erfolgreich.
Der Proxy empfing insgesamt zwei Anwendungs-POSTs und leitete genau einen an
PVE weiter. Der ursprüngliche Lauf bleibt `unknown`; Grund und Priorität wurden
beibehalten. Nachweis: `no-match-foreign.json`.

Die zeitliche Verschiebung eines bereits freigegebenen Folgeauftrags ist hier
die bestehende allgemeine Deferral-Regel für Frische und belegte Ressourcen.
Die Wiederfreigabe selbst trägt keine zusätzliche Recovery-Wartefrist.
ACL-Teilansicht, Paginationgrenzen, widersprüchliche Taskdaten und
Placementwechsel sind durch die automatisierten Fach-/Adaptertests abgedeckt;
diese Punkte werden nicht als zusätzliche reale Fault-Szenarien ausgegeben.

Die Fault-Endpunkte wurden danach auf alle drei ursprünglichen, aktivierten
PVE-7-Endpunkte zurückgesetzt und erneut verifiziert. Der Proxy und seine
eng begrenzte Firewallregel sind entfernt beziehungsweise gestoppt; das eigene
fremde Testarchiv wurde erst nach bestätigtem Taskende gelöscht. Es läuft wieder
ein Backup-Worker mit fünf Sekunden Pollintervall. Backupstarts bleiben bis zu
einer weiteren bewussten Aktivierung deaktiviert; Matrix ist konfiguriert.

## Weitere Funktionsnachweise und bereinigtes Deploymentziel

Auf der bestehenden DEV lieferten beide PBS-Verbindungen Taskdetails mit
insgesamt 70 bereinigten Logzeilen ohne Status-/Logfehler. Die Queue-Historie
lieferte Messpunkte für 24 Stunden, sieben und 30 Tage mit den vorgesehenen
Intervallbreiten. Nachweis: `pbs-details-queue-history.json`.

Die versehentlich zusätzlich angelegte Instanz `hoddmimir-dev-v2` enthielt keine
Requests, Läufe, Benachrichtigungen oder aktivierten Policies. Datenbank und
Installationsdateien wurden geschützt auf zwei Hosts gesichert und per Hash
verglichen. Ausschließlich ihre vier Container, ihr eigenes Netzwerk und ihr
eigenes Datenvolume wurden entfernt. Der ältere Labstack blieb unberührt.
Das dauerhafte Deploymentziel ist die bestehende Installation an der
unveränderten HTTPS-Adresse.

## Frontend-Abhängigkeiten vor Veröffentlichung

Der erste Veröffentlichungslauf für `74d4ef8` bestand Frontendtests, Typecheck,
Formatierung und Build, wurde aber durch `npm audit` blockiert. Die betroffenen
transitiven Versionen von brace-expansion, nanoid, PostCSS und undici wurden
innerhalb der vorhandenen kompatiblen Bereiche aktualisiert.
Der Schema-Parser bindet js-yaml weiterhin exakt an eine verwundbare Version;
ein auf dieses Paket begrenztes npm-Override verwendet deshalb 4.3.1,
die [korrigierte 4.x-Version](https://github.com/advisories/GHSA-5p4m-2wfm-xmqj).
Der OpenAPI-Generator selbst bleibt unverändert. Der aktualisierte Lockfile
meldet keine npm-Auditbefunde; die 299 Frontendtests einschließlich Coverage,
API-Client-Abgleich, Lint und Formatprüfung bestehen erneut.
Die Veröffentlichung erfolgte nach einem neuen erfolgreichen vollständigen
CI-Lauf des korrigierten Commits. Es wurden keine Images aus dem blockierten Lauf freigegeben.

## Gesonderter Nachweis eines tatsächlichen Worker-Neustarts

Ein weiterer begrenzter Drop vor der Upstream-Verbindung erzeugte einen
ungeklärten Start. Während der Proxyzugang gesperrt blieb, wurden beide
Backup-Worker ausdrücklich neu gestartet. Unabhängig abgefragte Container-
Startzeitpunkte änderten sich bei beiden Prozessen. Nach Ablauf der alten Lease
stieg die Claim-Fence von 1 auf 2; Request und Lauf trugen nachweislich dieselbe
neue Fence. Bis dahin blieb der Auftrag ungeklärt und der POST-Zähler unverändert.
Ein zusätzlicher manueller Request wurde weiterhin mit `active_request_exists`
abgelehnt. Nach Wiederherstellung des Zugangs entstand genau ein erfolgreicher
Folgeversuch; der erste Lauf bleibt unbekannt. Der gesonderte Nachweis steht in
`actual-restart-fenced.json` und ergänzt die bereits bestandene Parallel- und
Fremdbelegungsprüfung. Anschließend wurden erneut alle ursprünglichen Endpunkte,
ein Worker, das Fünf-Sekunden-Intervall und deaktivierte Backupstarts hergestellt.

## Abschließende CI und Kandidatenveröffentlichung

Der vollständige [Veröffentlichungslauf 34249005285](https://github.com/TobiasPottgueter/hoddmimir/actions/runs/34249005285)
für `a5b8ad94e88a36e4aa32fcbfc1a3cbd98457ff26` ist erfolgreich. Er veröffentlicht
`2.0.0-rc.20260908.1` für Worker, Web und MariaDB ausschließlich als
`linux/amd64`. Die drei Image-Nachweise wurden anschließend ohne Registry-
Zugangsdaten erneut abgerufen und exakt gegen `published-images.json` geprüft.

Die frischen Gates enthalten alle beschriebenen Recovery-Korrekturen:

- Backend-Container: 2.793 Tests / 12.919 Assertions; MariaDB-Coverage-Suite:
  500 Tests / 8.307 Assertions.
- Domain/Application: 8.177/8.177 Zeilen und 7.505/7.505 Branches;
  Proxmox-Infrastruktur: 3.157/3.157 Zeilen und 2.501/2.501 Branches.
  Global: 95,44 % Zeilen und 91,86 % Branches.
- Kritischer Mutation Score: 90,61 % (4.253/4.694); global: 80,87 %
  (16.095/19.902).
- 299 Frontendtests, 19 Playwright-Szenarien, Ansible- und Restoreprüfungen,
  Secret- und Supply-Chain-Gates sowie der vollständige Containerstart bestanden.

Zwei Artefaktübertragungen scheiterten zunächst mit HTTP 403 vom Intermediär:
der Upload eines bestandenen Mutationsshards und der Download der Container-
SBOMs. Die betroffenen Jobs und abhängigen Gates wurden gezielt wiederholt;
Anwendungscode und Anforderungen wurden dafür nicht geändert. Die erfolgreichen
Ergebnisse sind unter `existing-dev/ci-final/` und `existing-dev/publication/`
archiviert. Die vier im Coverage-Kernlauf aufgrund fehlender `/run/secrets`
übersprungenen CLI-Tests wurden im vollständigen Backend-Containerlauf ausgeführt.

## Separater Wechsel des Datenbank-Images

Der reguläre Wartungspfad lehnte den Wechsel vom bisherigen offiziellen
MariaDB-Image zum veröffentlichten Hoddmímir-MariaDB-Image vor Änderungen ab.
Seine Grenze für Datenbank-Imagewechsel wurde beibehalten. Ein separates
Operatorverfahren führte den Wechsel mit eigener Deploymentsperre durch:

1. Beide Images verwenden MariaDB 11.4.12; die Serverdatei wurde zusätzlich
   anhand ihres SHA-256 als bytegleich bestätigt.
2. Nach frischer Remote-Ruheprüfung wurden die schreibenden Anwendungen
   angehalten und die Wartung gesperrt. Der neue Datenbank-/Grantdump sowie
   Konfiguration und Schlüssel wurden geschützt auf zwei Hosts gesichert.
3. Der Dump wurde mit dem alten und dem neuen Image in getrennten temporären
   MariaDB-Instanzen wiederhergestellt. Beide erneuten Dumps waren bytegleich;
   vier Datenbankrollen und beide Worker bestanden die funktionale Prüfung.
4. Ausschließlich die MariaDB-Image-Referenz wurde geändert. Compose-Projekt,
   Datenvolume, Konfiguration und Secrets blieben erhalten. Auch der Dump der
   installierten Datenbank nach dem Wechsel stimmte exakt mit der Sicherung
   überein. Funktionsprüfungen und HTTP-Wartungsgate bestanden vor Freigabe.

Die bereinigten Nachweise stehen unter
`existing-dev/publication/database-image-maintenance/`. Private Sicherungen
bleiben außerhalb von Git. Die lokale Ansible-Umgebung wurde für den vorhandenen
Alpine-Host um `community.general` 13.4.0 ergänzt; fehlende Voraussetzungen hatten
die Vorprüfungen zuvor ohne Anwendungsänderung beendet.

## Endgültiges Deployment und unabhängige Nachprüfung

Das reguläre Protokoll-1-Upgrade installierte anschließend die veröffentlichten
Worker- und Webimages auf `/opt/hoddmimir`. Die Transaktion
`659ac4066b78460184c4326160993a35` ist abgeschlossen; ihr geprüfter Rückkehrstand
liegt zusätzlich geschützt auf dem zweiten Host. Alle drei laufenden Images
tragen die OCI-Revision `a5b8ad94e88a36e4aa32fcbfc1a3cbd98457ff26`.

| Image | Installierter AMD64-Manifest-Digest |
| --- | --- |
| Worker | `sha256:0d0e6e66c2c66758456153fb5ec0e9f30f1017ee13857fcbf49294327f8f46d9` |
| Web | `sha256:c5046c59396931b6ac050ef4754431d113a90704cf85ad48ecb9ab3dfffd05a2` |
| MariaDB | `sha256:d6bb5c1c0755899fd77cc04b232e4d49ea58b46a48e5e9eb8998dff24f1699df` |

Die separate Laufzeitprüfung bestätigt die Digests, Architektur und Git-Revision,
vier gesunde Container, HTTPS über IPv4, offene Wartung ohne aktive Transaktion
und den laufenden NTP-Dienst. Anschließend wurden alle vier Container tatsächlich
neu gestartet; ihre geänderten Startzeiten sind aufgezeichnet. Auch danach
bestanden Image-/Healthprüfung und Datenerhaltungsprüfung: fünf Verbindungen,
sechs Ziele, sechs Policies, 43 Aufträge, 23 Läufe, 24 Benachrichtigungen und der
vorhandene Benutzer sind erhalten. Die IDs des Ausgangsbestands sowie alle
Secret- und Keyringbytes stimmen mit der geschützten Vergleichsbasis überein.

Der zweite reguläre Ansible-Deploymentlauf blieb vollständig unverändert:
`ok=42 changed=0 failed=0`. Nach Abschluss wurden die eigene temporäre Registry
und die lokalen Test-/Buildcontainer entfernt. Die frühere zusätzliche DEV
ist bereits gesichert entfernt; die vorhandene ältere Lab-Installation wurde
nicht verändert.

Endzustand: gleiche HTTPS-Adresse, ein Backupworker mit fünf Sekunden
Pollintervall, **Backupausführung deaktiviert**, Matrix aktiviert. Die drei
ursprünglichen PVE-7-Endpunkte sind wiederhergestellt; Fault-Proxy und zugehörige
Firewallregel sind entfernt beziehungsweise gestoppt. Die Veröffentlichungs-
und Deploymentnachweise schließen die beauftragten Anschlussarbeiten ab; sie
ersetzen keine weitergehende Produktionsfreigabe oder einen Legacy-Importer.
