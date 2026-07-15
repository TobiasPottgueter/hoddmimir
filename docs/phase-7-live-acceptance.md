# Phase 7: Live- und Parallelbetriebsabnahme

Stand: 15. Juli 2026

Status: **begonnen, nicht abgeschlossen.** Neben dem historischen
Host-Bootstrap ist inzwischen ein vom Produktionsstack isolierter
Vier-Service-Labstack gesund ausgerollt. Die freigegebene Labinfrastruktur
wurde read-only inventarisiert: drei PVE-Cluster der Major-Versionen 7, 8 und 9
mit insgesamt neun Nodes und 18 Wegwerfgästen sowie je ein PBS-3- und
PBS-4-System. Ein ausschließlich lesender Onboarding-Preflight hat die
vorbereiteten Identitäten, Rollen/ACLs, Produkt-Majors und versionsabhängigen
Revoke-Kommandos für alle fünf Installationen sowie elf gepinnte
TLS-Zertifikate bestätigt.

Es wurden weiterhin **keine Laufzeit-Tokens erzeugt oder an Hoddmímir
übergeben, keine Verbindung aktiviert, kein automatischer Hoddmímir-Scan und
kein Backupstart oder anderer Fault-/Mutationsfall ausgeführt**. Ein realer
Matrix-Webhook ist ebenfalls noch nicht abgenommen. Die automatische Promotion
geeigneter Shadow-Entscheidungen in die Queue ist lokal integriert und mit
Unit- sowie MariaDB-Tests belegt, aber noch kein veröffentlichter oder im Lab
ausgerollter Bestandteil eines finalen Kandidaten.

Dieser fortschreibbare Evidenzbericht konkretisiert Phase 7 aus dem
[`rewrite-plan.md`](rewrite-plan.md). Die lokale Implementierung und ihre
Quality Gates sind getrennt im
[`phase-6-local-acceptance.md`](phase-6-local-acceptance.md) dokumentiert.
Ein Punkt gilt hier erst als belegt, wenn die genannte reale Evidenz vorliegt;
Planung, Fixtures, Mocks und lokale Tests ersetzen keinen Live-Nachweis.

## Kandidat und Geltungsbereich

- Branch des aktuellen Audits: `codex/policies-shadow-mode`;
- ein finaler, unveränderlicher Phase-7-Kandidat ist noch nicht festgelegt;
- bereits ausgerollte Zwischenstände belegen Deployment- und
  Isolationsverträge, ersetzen aber weder die Freigabe des finalen Kandidaten
  noch dessen vollständige Quality Gates;
- die lokal integrierte Automatic-Shadow-Promotion bleibt bis zu vollständiger
  CI und erneutem Deployment als Release-/Live-Nachweis ausdrücklich offen;
- die Backupausführung des produktionsnahen Stacks bleibt bis zur ausdrücklich
  getrennten Phase-8-Freigabe deaktiviert.

Jede Änderung an Source, Dependencies, Containern, Migrationen, Tests oder
Deploymentvertrag benötigt vor der weiteren Live-Abnahme einen neuen
unveränderlichen Kandidaten und die Wiederholung der jeweils betroffenen Gates.

## Aktueller Evidenzstand

| Bereich                              | Verbindliche Evidenz                                                             | Aktueller Stand       |
| ------------------------------------ | -------------------------------------------------------------------------------- | --------------------- |
| Finaler Kandidat                     | unveränderliche Git-SHA, Gates und finale AMD64-Artefakte                        | offen                 |
| Lokale Phase-6-Gates                 | Coverage, Mutation, MariaDB, Frontend, Browser, Supply Chain und Container-Smoke | getrennt lokal belegt |
| Deployment-Host-Bootstrap            | Alpine, Python, Docker, Compose und OpenRC auf dem Zielhost                      | belegt                |
| Isolierter Labstack                  | getrennte Laufzeitgrenzen und genau vier gesunde Services                        | **belegt**            |
| Read-only Labgrundlage               | PVE 7/8/9, PBS 3/4, drei Cluster, neun Nodes und 18 Gäste                        | **belegt**            |
| Read-only Onboarding-Preflight       | 5/5 Produkt-/Identitäts-/Revoke-Prüfungen und 11/11 passende TLS-Pins            | **belegt**            |
| Laufzeit-Tokens und V2-Onboarding    | getrennte Scan-/Backup-Tokens und fünf aktivierte Verbindungen                   | offen                 |
| PVE-/PBS-API-Live-Matrix             | Hoddmímir-Reads inklusive TLS, ACL, Pagination, Failover und Fehlerfällen        | offen                 |
| Automatischer Inventar-Sync          | vollständiger autoritativer Scan im regulären Raster                             | offen                 |
| Shadow-Parallelbetrieb und Promotion | Queue nur aus neuem Inventar und neuen Policies, ohne Backupstart                | offen                 |
| Matrix-Zustellung                    | echter HTTPS-Webhook, Problemmeldung und Entwarnung                              | offen                 |
| Isolierte Backup-Labmatrix           | QEMU/LXC je PVE-Major und definierte Fehler-/Race-Fälle                          | offen                 |
| Produktions-Onboarding               | neue Produktionsverbindungen und Shadowbetrieb bei deaktivierter Ausführung      | offen                 |
| Phase-7-Abschlussurteil              | alle Phase-7-Punkte belegt, Phase-8-Grenze intakt                                | offen                 |

## Belegter Bootstrap des Deployment-Hosts

Der für Hoddmímir vorgesehene Alpine-Host wird in getrackter Evidenz nur als
`deployment-host.example.invalid` bezeichnet und wurde am 13. Juli 2026
erfolgreich mit der vorbereiteten Ansible-Automation gebootstrapped. Der reale
Host/FQDN bleibt ausschließlich in der ignorierten lokalen
Produktionskonfiguration.

Belegte Laufzeitstände:

- Python `3.12.13`;
- Docker Engine `29.5.2`;
- Docker Compose `2.40.3`;
- Docker ist als OpenRC-Service gestartet.

Dieser Nachweis belegt ausschließlich das Host-Fundament. Er ist kein
Anwendungsdeploy. Die nachfolgend separat belegte Labinstallation entstand in
einem späteren Schritt. Aus dem Bootstrap allein folgt weder eine
Produktionsfreigabe noch eine Proxmox-Konfiguration.

## Belegter isolierter Labstack

Der Phase-7-Labstack läuft auf demselben freigegebenen Alpine-Host, ist aber
vom produktionsnahen Stack durch eigene Projekt-, Pfad-, Datenbank-,
Docker-Netz-, Loopback-Port-, Secret-, Volume-, Staging- und Lock-Grenzen
getrennt. Genau `mariadb`, `data-worker`, `backup-worker` und `webapp` laufen
gesund. Der Lab-Deploy hat weder Host-Caddy noch ACME-Zustand verändert.

Die Lab-Backupausführung ist deaktiviert. Es ist kein echter Matrix-Webhook
konfiguriert. Der gesunde Stack belegt daher den isolierten Deploymentvertrag,
nicht aber Onboarding, Collector-Inventar, Queuepromotion oder eine
PVE-/PBS-Schreiboperation.

## Belegte read-only Labgrundlage

Die freigegebene Proxmox-Labumgebung wurde außerhalb der Hoddmímir-
Laufzeit-Tokens ausschließlich lesend geprüft:

- PVE 7: drei Nodes, `pve-manager 7.4-20`, `proxmox-ve 7.4-1`;
- PVE 8: drei Nodes, `pve-manager 8.4.19`, `proxmox-ve 8.4.0`;
- PVE 9: drei Nodes, `pve-manager 9.2.4`, `proxmox-ve 9.2.0`;
- PBS 3: `proxmox-backup-server 3.4.8-3`;
- PBS 4: `proxmox-backup-server 4.2.2-1`.

Jede der drei PVE-Major-Linien bildet ein quorates Drei-Node-Cluster. Auf
jedem der neun Nodes existiert genau eine laufende QEMU-VM und ein laufender
LXC-Container als freigegebener Wegwerfgast; damit sind 18 Labgäste vorhanden.
Dieser Topologienachweis ist noch kein erfolgreicher Hoddmímir-Inventarsync.

Ein lokales, ignoriertes Preflight-Werkzeug hat danach ausschließlich lesend
und ohne Secret-Ausgabe bestätigt:

- die vorbereiteten Benutzer, Rollen und propagierten ACLs entsprechen auf
  allen drei PVE-Clustern und beiden PBS-Installationen dem Onboardingvertrag;
- die Produkt-Major-Version stimmt in 5 von 5 Installationen;
- das korrekte versionsabhängige Revoke-Kommando ist in 5 von 5
  Installationen anhand der jeweiligen CLI-Hilfe verfügbar;
- elf von elf vom Deployment-Host beobachtete SHA-256-Leaf-Fingerprints
  stimmen mit den ausschließlich lokal gespeicherten Pins überein.

Die konkreten Endpunkte, Pins und lokalen Evidenzdateien bleiben ungetrackt.
Der Preflight fordert ausdrücklich, dass die kanonischen Laufzeit-Tokens noch
nicht existieren. Er belegt deshalb weder Token-Erzeugung noch eine
Hoddmímir-API-Authentifizierung oder Verbindungspersistenz.

## Noch offene Abnahmeblöcke

### 1. Releasekandidat und Registry

- [ ] Nach allen noch laufenden Repositoryänderungen eine finale
      Kandidaten-SHA festlegen.
- [ ] Alle vom geänderten Arbeitsstand betroffenen lokalen Quality Gates auf
      exakt diesem Kandidaten wiederholen.
- [ ] Worker und Web ausschließlich für `linux/amd64` in die freigegebene
      Registry veröffentlichen.
- [ ] Die tatsächlichen Registry- und Plattformdigests,
      Trivy-Ergebnisse und CycloneDX-SBOMs dem Kandidaten zuordnen.
- [ ] Alle vier Produktionsimage-Referenzen einschließlich MariaDB als
      `image@sha256:...` pinnen; lokale OCI-Archivprüfsummen sind kein Ersatz
      für Registry-Digests.

Externe Mutation: Registry-Push. Dafür werden Registry-Ziel, Authentifizierung
und Veröffentlichungsfreigabe benötigt.

### 2. Produktionsnaher Anwendungsdeploy

- [ ] Das ignorierte, verschlüsselte Ansible Vault mit getrennten
      App-/MariaDB-Secrets und strukturiertem Encryption-Keyring bereitstellen.
      Beim execution-deaktivierten Erstdeploy ist der dokumentierte
      Matrix-Platzhalter zulässig; vor jedem Lab-Backup ist ein echter
      HTTPS-Webhook zwingend.
- [ ] Den digest-gepinnten Kandidaten mit der vorbereiteten
      Deployment-Transaktion ausrollen.
- [ ] Die leere V2-Datenbank und alle erwarteten Migrationen nachweisen; es
      dürfen weder Legacy-Daten noch ein Legacy-Importer beteiligt sein.
- [ ] Genau `mariadb`, `data-worker`, `backup-worker` und `webapp` als laufend
      und gesund nachweisen.
- [ ] `/api/health`, Schema-/Keyring-Readiness, Worker-Heartbeats sowie die
      root-only Datei- und Secretrechte prüfen.
- [ ] `BACKUP_EXECUTION_ENABLED=false` im ausgerollten Stack nachweisen.
- [ ] Den öffentlichen Namen aus der ignorierten Produktionskonfiguration per
      Host-Caddy auf den ausschließlich an `127.0.0.1:8080` gebundenen
      WebApp-Port führen; Caddy bleibt ein OpenRC-Hostdienst und kein fünfter
      Container.
- [ ] Das Let's-Encrypt-Zertifikat per Hetzner-DNS-01 aus dem separaten
      root-/ACME-lesbaren Tokenfile ausstellen, Caddy als non-root verifizieren
      und den öffentlichen `/api/health`-Pfad mit System-CA-Prüfung abnehmen.
- [ ] Den täglichen gesperrten Renewal-Pfad, laufendes OpenRC-`crond`, einen
      idempotenten Nicht-Erneuerungslauf und den getesteten Rollback bei
      Zertifikats-, Reload- oder HTTPS-Health-Fehler live belegen.

Externe Mutation: Dateien, Docker-Images, Container, Volume und leere
V2-Datenbank auf der bereits freigegebenen Deployment-VM. Der Deploy darf
keine PVE-/PBS-Schreiboperation auslösen.

### 3. Vollständige neue Konfiguration

- [ ] V2-Administrator und Rollen neu einrichten und den zugehörigen
      Audit-Nachweis sichern.
- [x] Die versionsabhängigen Revoke-Kommandos anhand der realen CLI-Hilfe auf
      PVE 7/8/9 und PBS 3/4 read-only validieren.
- [x] Die vorbereiteten PVE-Benutzer, Gruppe, Rollen und propagierten ACLs auf
      allen drei Clustern read-only gegen den geschlossenen Vertrag prüfen.
- [x] Die vorbereiteten PBS-Benutzer und propagierten Audit-ACLs auf PBS 3 und
      PBS 4 read-only gegen den geschlossenen Vertrag prüfen.
- [ ] Die getrennten privilegiengetrennten PVE-Scan-/Backup-Tokens und die
      PBS-Scan-Tokens erzeugen. Der erfolgreiche Preflight belegt bewusst ihre
      Abwesenheit und hat keine Token-Mutation ausgeführt.
- [ ] Ausschließlich die Laufzeit-Tokens über das verified-only Onboarding
      eingeben; kein Administrator- oder Bootstrap-Token darf Hoddmímir
      erreichen.
- [ ] Nodes, QEMU-Gäste, LXC-Container, Backuplocations, PBS-Zuordnungen,
      Mindestplatz, Parallelität, explizite Ausschlüsse und Policies vollständig
      neu konfigurieren.

Die Identitäts-/ACL-Grundlage ist vorhanden und read-only verifiziert. Die
noch ausstehende Token-Erzeugung und jedes absichtliche ACL-/Token-Fehlerszenario
sind externe Mutationen; Tokenwerte dürfen weder in Evidenz noch in Git
gelangen.

### 4. Read-only-Live-Matrix und Onboarding

- [x] Die Produkt- und Patchversionen der bereitgestellten PVE-7/8/9- und
      PBS-3/4-Systeme sanitisiert dokumentieren.
- [x] Elf Endpunktzertifikate vom Deployment-Host lesen und die beobachteten
      SHA-256-Leaf-Digests gegen elf lokale Pins vergleichen. Die Digestwerte
      selbst bleiben ungetrackt.
- [ ] System-CA, Custom-CA, korrekten SHA-256-Fingerprint und falschen
      Fingerprint fail-closed prüfen; die Trust-Prüfung bleibt immer aktiv.
      Bei falschem Pin darf kein Authorization-Header übertragen werden.
- [ ] Produkt, Version, Scanner- und Executor-Rechte sowie fehlende,
      zusätzliche oder nicht propagierte Rechte mit den geschlossenen
      Allowlists abgleichen.
- [ ] Version, Cluster/Server, Nodes, QEMU, LXC, Pools, Storages,
      Datastores, Namespaces, Snapshots, Jobs und Tasks einschließlich realer
      Pagination und ACL-Filter lesen.
- [ ] Mehrere PVE-Endpunkte desselben Clusters ohne doppeltes Inventar sowie
      Endpoint-Failover prüfen.
- [ ] Teilfehler, nicht erreichbaren Node und Wiederkehr prüfen, ohne dass
      unvollständige Reads falsche Abwesenheits- oder Archiventscheidungen
      erzeugen.
- [ ] Einen kontrollierten Placementwechsel prüfen; die Queue darf keine
      veraltete Node-Zuordnung verwenden.

Die normalen Remote-Aufrufe dieses Blocks sind read-only. Kontrollierte
Node-/Netzstörungen und Gastmigrationen sind dagegen externe Mutationen und
benötigen ausdrücklich freigegebene Lab-Systeme.

### 5. Automatischer Inventar-Sync und Shadow-Parallelbetrieb

- [x] Den Automatic-Shadow-Promotion-Fix integrieren und lokal mit Unit-, echter
      MariaDB-, Fencing-, Rollback-, Concurrency- und Idempotenznachweisen
      abnehmen.
- [ ] Den integrierten Promotion-Pfad auf dem finalen Kandidaten durch die
      vollständige CI und anschließend im isolierten Lab nachweisen.
- [ ] Nach dem Onboarding den ersten vollständigen automatischen Scan am
      nächsten regulären Rasterpunkt abwarten; kein manueller oder versteckter
      Wizard-Scan ist zulässig.
- [ ] Run-ID, Start/Ende, Autoritativstatus, Scope-Ergebnisse, Objektzahlen,
      Teilfehler, letzter Erfolg und nächsten Lauf dokumentieren.
- [ ] Wiederholte Scans als idempotent nachweisen und das startzeitbasierte
      120-Sekunden-Raster ohne Überlappung oder Catch-up-Burst beobachten.
- [ ] PVE-Storage-/PBS-Kapazität sowie gast- und zielbezogene
      Executor-Evidenz mit höchstens fünf Minuten Alter nachweisen.
- [ ] Mindestens einen vollständigen Shadow-Zyklus bei deaktivierter
      Backupausführung betreiben.
- [ ] Nachweisen, dass die Queue ausschließlich aus dem neuen V2-Inventar und
      den neuen Policies gebildet wird und jede Auswahl-, Placement-, Ziel-,
      Freshness- und Prioritätsentscheidung erklärbar ist.
- [ ] Bei mehreren geeigneten Policies je Gast genau einen stabil ausgewählten
      Gewinner automatisch und idempotent promoten; bei deaktivierter
      Ausführung darf dadurch kein PVE-Schreibaufruf entstehen.
- [ ] Bei absichtlich veralteter Evidenz nachweisen, dass kein Start
      zugelassen wird und frische Evidenz die Neubewertung auslöst.

Dieser Block persistiert ausschließlich neue V2-Inventar-, Queue- und
Auditdaten. Solange die Backupausführung deaktiviert bleibt, mutiert er PVE
oder PBS nicht.

### 6. Isolierte Backup-Labmatrix

Die drei Cluster mit ihren 18 Wegwerfgästen und die beiden PBS-Systeme sind als
Entwicklungsumgebung für Backupstarts, Cancel und kontrollierte
Fehlerinjektionen freigegeben. Diese Freigabe ist noch kein Ausführungsnachweis:
Bis zum Stand dieses Berichts wurde keiner dieser Fälle gestartet. Vor dem
ersten Lauf müssen der finale Kandidat, Laufzeit-Tokens, Hoddmímir-Onboarding,
ein echter HTTPS-Matrix-Webhook und das technische Lab-Acknowledgement
vorliegen. Die Lab-Ausführung bleibt vom produktionsnahen, weiterhin
deaktivierten Stack getrennt.

- [ ] Einen QEMU- und einen LXC-Gast je PVE-Major 7, 8 und 9 erfolgreich auf
      lokalem Storage sichern.
- [ ] Die offiziell zulässigen PVE-/PBS-Kombinationen zuerst aus den
      offiziellen Kompatibilitätsangaben festhalten und anschließend mit
      Snapshot-Nachweis testen.
- [ ] Erfolg, definitiven Fehler, Cancel, Token-Revoke, fehlende Rechte,
      Node-Ausfall und TLS-Fehler praktisch prüfen.
- [ ] Einen Transportabbruch direkt nach dem `vzdump`-POST erzeugen und
      belegen, dass Reconciliation niemals einen zweiten POST auslöst.
- [ ] Zwei Backup Worker in einem isolierten Doppelclaim-Race betreiben und
      exakt einen Remote-Submission-Dispatch nachweisen.
- [ ] At-most-once-Cancel einschließlich des mehrdeutigen
      `dispatch_unknown`-Pfads ohne zweiten DELETE prüfen.
- [ ] Bei definitiven Fehlern Versuchszähler, dauerhafte Retry-Planung,
      Matrix-Problemmeldung und eine spätere Entwarnung prüfen; ein
      blockierendes Gate darf keinen PVE-Schreibaufruf erzeugen.
- [ ] Hoddmímir-Run, sanitisierten UPID-Bezug, Remote-Task, Log,
      Endzustand, Zielartefakt, Audit und Matrix-Outbox korrelieren.
- [ ] Nachweisen, dass die V2-Historie ausschließlich mit diesen neuen
      Lab-Läufen beginnt.

Externe Mutation: `vzdump`-POSTs, Cancel-DELETE, Backupdaten sowie
kontrollierte ACL-, Token-, Node- und Transportfehler. Die Systeme und die
fachliche Schreib-/Fault-Injection-Freigabe liegen vor; noch offen sind die
technischen Voraussetzungen und der reale HTTPS-Matrix-Webhook. Eine
löschwirksame Retention bleibt ohne ihre eigene ausdrückliche Genehmigung
verboten. Selbst mit dieser Genehmigung darf sie nur auf Nicht-PBS-Zielen
wirksam werden. Bei PBS-Zielen werden weder `maxfiles` noch `prune-backups`
gesendet; dort bleiben die PBS-Prune-Jobs alleinige Retention-Autorität.

## Noch offene Inputs und technische Voraussetzungen

Die unterstützten Lab-Systeme, Wegwerfgäste und die fachliche Erlaubnis für
Backupstarts, Cancel und kontrollierte Fehlerinjektionen liegen inzwischen
vor. Für die tatsächliche Ausführung bleiben dennoch offen:

1. ein finaler Kandidat mit grünen Gates und seinen unveränderlichen
   `linux/amd64`-Releaseartefakten;
2. ein gültiger geheimer HTTPS-Matrix-Webhook in der ignorierten
   Labkonfiguration;
3. die Erzeugung und ausschließlich laufzeitgebundene Übergabe der getrennten
   Scan-/Backup-Tokens sowie das verified-only Hoddmímir-Onboarding;
4. die neue Labkonfiguration für Ziele, Auswahl, Namespaces, Grenzwerte und
   Policies;
5. das technische Aktivierungs-Acknowledgement ausschließlich für den
   isolierten Labstack.

Produktions-Tokens und Produktions-Onboarding bleiben unabhängig davon offen.
Eine Empfängerentscheidung für optionale PVE-Fehler-E-Mails und jede
löschwirksame Retention benötigen weiterhin eine getrennte Konfiguration
beziehungsweise Freigabe.

Secrets, Tokenwerte, Webhook-URLs, private CA-Schlüssel, interne
Inventardetails und vollständige UPIDs dürfen nicht in diesen Bericht oder Git
geschrieben werden. Evidenz verwendet technische Aliasnamen, Patchversionen,
Zeitstempel, Hoddmímir-IDs und sanitisierten beziehungsweise gehashten
Remote-Bezug.

## Strikte Abgrenzung zu Phase 8

Phase 7 endet mit dem produktionsnahen Parallelbetrieb bei deaktivierter
Produktionsausführung und einer getrennt freigegebenen isolierten
Backup-Labmatrix. Die folgenden Arbeiten gehören ausdrücklich **nicht** zu
dieser Phase:

- produktive Aktivierung des neuen Backup Workers;
- das vollständige Release- und Security-Gate als Produktionsfreigabe;
- Backup und Restore der neuen MariaDB als Betriebsnachweis;
- vollständige Runbooks für Deployment, Upgrade, Tokenwechsel, Incident und
  Rollback;
- Aktivierungs- und Rollback-Fenster;
- praktische Deaktivierungsprobe von V2;
- Gesamt-Rewrite-Definition-of-Done und Produktionsrelease.

Die Zeichenfolge `ENABLE_PRODUCTION_BACKUPS` ist ein technisches Fail-closed-
Gate, aber keine stellvertretende fachliche Freigabe. Sie darf auf dem
produktionsnahen Phase-7-Stack nicht gesetzt werden. Falls ein isolierter
Lab-Stack denselben technischen Guard für seine bewusst freigegebenen
Backupstarts verwenden muss, ist diese Lab-Aktivierung getrennt zu
dokumentieren und begründet keine Produktionsfreigabe.

## Fortschrittsprotokoll

| Datum      | Kandidat      | Ereignis                                          | Ergebnis                                                                                                                     | Verbleibende Grenze                                              |
| ---------- | ------------- | ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| 13.07.2026 | `d88d38a`     | Alpine-Deployment-Host mit Ansible gebootstrapped | Python 3.12.13, Docker 29.5.2, Compose 2.40.3, Docker/OpenRC gestartet                                                       | kein Anwendungsdeploy, keine Live-Matrix, keine Backupausführung |
| 15.07.2026 | Zwischenstand | Isolierten Labstack ausgerollt und verifiziert    | vier gesunde Services; getrennte Projekt-, Pfad-, DB-, Netz-, Port-, Secret-, Volume- und Lock-Grenzen; Backupausführung aus | finaler Kandidat, Onboarding und Backupstart offen               |
| 15.07.2026 | Zwischenstand | Proxmox-Labgrundlage read-only inventarisiert     | PVE 7/8/9, PBS 3/4, neun Nodes und je QEMU/LXC pro Node                                                                      | noch kein Hoddmímir-Inventarsync                                 |
| 15.07.2026 | Zwischenstand | Onboarding-Preflight read-only ausgeführt         | 5/5 Identitäts-/Versions-/Revoke-Prüfungen und 11/11 lokale TLS-Pins passend                                                 | keine Tokens erzeugt, kein Onboarding aktiviert                  |

## Vorläufiges Urteil

Phase 7 hat jetzt reale, aber klar begrenzte Evidenz: Host-Bootstrap,
isolierter gesunder Labstack, read-only Labtopologie und der sichere
Onboarding-Preflight sind belegt. Sie ist nicht abgeschlossen: finaler
Kandidat und Gates, Laufzeit-Tokens, Hoddmímir-Onboarding, API-Live-Matrix,
automatischer Inventar-Sync, finaler Release-/Live-Nachweis der automatischen
Shadow-Queue-Promotion, Shadow-Parallelbetrieb, Matrix-Zustellung, die komplette isolierte
Backup-/Fehler-Labmatrix und Produktions-Onboarding fehlen noch. Insbesondere
wird weder ein Hoddmímir-Live-Scan noch eine Proxmox-Schreiboperation oder ein
Backup-Erfolg behauptet.
