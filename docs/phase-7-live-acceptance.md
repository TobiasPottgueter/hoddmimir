# Phase 7: Live- und Parallelbetriebsabnahme

Stand: 13. Juli 2026

Status: **begonnen, nicht abgeschlossen.** Der Alpine-Deployment-Host wurde
erfolgreich gebootstrapped. Es wurde noch kein Hoddmímir-Anwendungsstack auf
diesem Host ausgerollt, keine PVE-/PBS-Live-Matrix ausgeführt und kein reales
Backup gestartet. Die fehlenden externen Systeme, Zugangsdaten und
Freigabeentscheidungen sind in diesem Dokument ausdrücklich aufgeführt.

Dieser fortschreibbare Evidenzbericht konkretisiert Phase 7 aus dem
[`rewrite-plan.md`](rewrite-plan.md). Die lokale Implementierung und ihre
Quality Gates sind getrennt im
[`phase-6-local-acceptance.md`](phase-6-local-acceptance.md) dokumentiert.
Ein Punkt gilt hier erst als belegt, wenn die genannte reale Evidenz vorliegt;
Planung, Fixtures, Mocks und lokale Tests ersetzen keinen Live-Nachweis.

## Kandidat und Geltungsbereich

- initialer Phase-7-Kandidat:
  `d88d38a7af89565e7d2aa0a5440ea87c3c804fd1`;
- Branch: `codex/policies-shadow-mode`;
- Commit-Betreff: `feat: complete policies, backup worker, and webapp`;
- Commit-Zeit: 13. Juli 2026, 19:17:15 CEST;
- Veröffentlichungsstand bei Beginn: lokaler Branch und
  `origin/codex/policies-shadow-mode` standen bei `0/0`;
- lokaler Secret-Nachweis nach der Veröffentlichung: Gitleaks 8.24.3 prüfte
  zehn Commits und meldete keinen Fund;
- Backupausführung des produktionsnahen Stacks bleibt bis zur ausdrücklich
  getrennten Phase-8-Freigabe deaktiviert.

Der erfolgreiche Host-Bootstrap verändert den Kandidaten nicht. Änderungen an
Source, Dependencies, Containern, Migrationen, Tests oder Deploymentvertrag
nach `d88d38a` benötigen vor einem Anwendungsdeploy einen neuen unveränderlichen
Kandidaten und die Wiederholung der jeweils betroffenen Gates.

## Aktueller Evidenzstand

| Bereich                     | Verbindliche Evidenz                                                             | Aktueller Stand       |
| --------------------------- | -------------------------------------------------------------------------------- | --------------------- |
| Kandidatenidentität         | unveränderliche Git-SHA und veröffentlichter Branch                              | `d88d38a`, belegt     |
| Lokale Phase-6-Gates        | Coverage, Mutation, MariaDB, Frontend, Browser, Supply Chain und Container-Smoke | getrennt lokal belegt |
| Deployment-Host-Bootstrap   | Alpine, Python, Docker, Compose und OpenRC auf dem Zielhost                      | belegt, Details unten |
| Release-Images              | Worker und Web als `linux/amd64`-Registry-Images mit unveränderlichen Digests    | offen                 |
| Produktionsnaher Deploy     | leere V2-Datenbank, vier gesunde Services und API-Health auf dem Zielhost        | offen                 |
| Öffentliche HTTPS-Grenze    | Host-Caddy, DNS-01-Zertifikat, strikte TLS-Prüfung und öffentlicher Health-Pfad  | lokal vorbereitet, live offen |
| Neue V2-Konfiguration       | Administrator/Rollen, PVE-/PBS-Verbindungen, Ziele, Auswahl und Policies         | offen                 |
| PVE-/PBS-Live-Matrix        | PVE 7/8/9 und PBS 3/4 inklusive TLS, ACL, Pagination und Fehlerfällen            | offen                 |
| Automatischer Inventar-Sync | vollständiger autoritativer Scan im regulären Raster                             | offen                 |
| Shadow-Parallelbetrieb      | Queue nur aus neuem Inventar und neuen Policies, ohne Backupstart                | offen                 |
| Isolierte Backup-Labmatrix  | QEMU/LXC je PVE-Major und definierte Fehler-/Race-Fälle                          | offen                 |
| Phase-7-Abschlussurteil     | alle Phase-7-Punkte belegt, Phase-8-Grenze intakt                                | offen                 |

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
Anwendungsdeploy: Es wurden dadurch weder `/opt/hoddmimir` als laufender
Vier-Service-Stack abgenommen noch eine V2-Datenbank initialisiert oder
Proxmox-Zugangsdaten konfiguriert. Die Backupausführung wurde nicht aktiviert.

## Noch offene Abnahmeblöcke

### 1. Releasekandidat und Registry

- [ ] Nach allen noch laufenden Repositoryänderungen eine neue Kandidaten-SHA
      festlegen, falls `d88d38a` nicht unverändert deployt wird.
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
- [ ] Auf den vorgesehenen PVE-Systemen die angezeigten Befehle gegen die
      letzten gepatchten PVE-7-, PVE-8- und PVE-9-CLI-Schemata validieren.
- [ ] Durch einen Proxmox-Administrator je PVE-Installation den Benutzer
      `hoddmimir@pve`, die Gruppe `Bots`, die Rollen `HoddmimirScan` und
      `HoddmimirBackup` sowie die getrennten privilegiengetrennten Tokens
      `hoddmimir@pve!scan` und `hoddmimir@pve!backup` einrichten.
- [ ] Auf PBS 3 und PBS 4 den Benutzer `hoddmimir@pbs`, den Token
      `hoddmimir@pbs!scan` sowie die propagierten eingebauten Audit-Rollen
      einrichten.
- [ ] Ausschließlich die Laufzeit-Tokens über das verified-only Onboarding
      eingeben; kein Administrator- oder Bootstrap-Token darf Hoddmímir
      erreichen.
- [ ] Nodes, QEMU-Gäste, LXC-Container, Backuplocations, PBS-Zuordnungen,
      Mindestplatz, Parallelität, explizite Ausschlüsse und Policies vollständig
      neu konfigurieren.

Externe Mutation: PVE-/PBS-Benutzer, Rollen, Tokens und ACLs werden bewusst
vom Betreiber außerhalb Hoddmímirs angelegt. Dafür sind benannte Systeme und
eine ausdrückliche administrative Freigabe erforderlich.

### 4. Read-only-Live-Matrix und Onboarding

- [ ] Die exakten Produkt- und Patchversionen der getesteten PVE-7/8/9- und
      PBS-3/4-Systeme dokumentieren.
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
- [ ] Bei absichtlich veralteter Evidenz nachweisen, dass kein Start
      zugelassen wird und frische Evidenz die Neubewertung auslöst.

Dieser Block persistiert ausschließlich neue V2-Inventar-, Queue- und
Auditdaten. Solange die Backupausführung deaktiviert bleibt, mutiert er PVE
oder PBS nicht.

### 6. Isolierte Backup-Labmatrix

Reale Backupstarts dürfen erst beginnen, wenn konkrete Lab-Gäste und
Lab-Ziele benannt, problematische Auswirkungen ausgeschlossen und die
Schreiboperationen ausdrücklich freigegeben sind. Die Lab-Ausführung muss von
der produktionsnahen, weiterhin deaktivierten Instanz getrennt bleiben.

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
kontrollierte ACL-, Token-, Node- und Transportfehler. Benötigt werden eine
ausdrückliche Schreib-/Fault-Injection-Freigabe, sichere Labziele, ein
HTTPS-Matrix-Webhook und das technische Aktivierungs-Acknowledgement. Eine
löschwirksame Retention bleibt ohne ihre eigene ausdrückliche Genehmigung
verboten. Selbst mit dieser Genehmigung darf sie nur auf Nicht-PBS-Zielen
wirksam werden. Bei PBS-Zielen werden weder `maxfiles` noch `prune-backups`
gesendet; dort bleiben die PBS-Prune-Jobs alleinige Retention-Autorität.

## Noch benötigte externe Inputs

Phase 7 kann ohne die folgenden Angaben und Befugnisse nicht wahrheitsgemäß
abgeschlossen werden:

1. freigegebenes Container-Registry-Ziel samt Authentifizierung und Erlaubnis
   zum Veröffentlichen der `linux/amd64`-Images;
2. Ansible-Vault-Passphrase beziehungsweise ein sicherer interaktiver
   Bereitstellungsweg sowie ein gültiger geheimer HTTPS-Matrix-Webhook;
3. benannte, erreichbare und gepatchte PVE-7-, PVE-8-, PVE-9-, PBS-3- und
   PBS-4-Systeme mit TLS-Trustmaterial;
4. ein Betreiber, der die angezeigten PVE-/PBS-Befehle mit administrativen
   Rechten ausführt und die erzeugten Laufzeit-Token sicher übergibt;
5. die fachliche Auswahl der Nodes, Gäste, Ziele, Namespaces, Grenzwerte und
   Policies für die neue V2-Konfiguration;
6. ausdrücklich freigegebene QEMU-/LXC-Labgäste und Backupziele für alle
   drei PVE-Majors;
7. Erlaubnis für Backupstarts, Cancel, Token-Revoke, ACL-Fehler,
   Gastmigration, Node-/Netzstörung und Transportabbruch auf genau diesen
   Lab-Systemen;
8. eine nicht im Quellcode hinterlegte Empfängerentscheidung für optionale
   PVE-Fehler-E-Mails und eine separate Entscheidung, falls löschwirksame
   Retention getestet werden soll.

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

| Datum      | Kandidat  | Ereignis                                          | Ergebnis                                                               | Verbleibende Grenze                                              |
| ---------- | --------- | ------------------------------------------------- | ---------------------------------------------------------------------- | ---------------------------------------------------------------- |
| 13.07.2026 | `d88d38a` | Alpine-Deployment-Host mit Ansible gebootstrapped | Python 3.12.13, Docker 29.5.2, Compose 2.40.3, Docker/OpenRC gestartet | kein Anwendungsdeploy, keine Live-Matrix, keine Backupausführung |

## Vorläufiges Urteil

Phase 7 ist durch den belegten Host-Bootstrap begonnen. Sie ist nicht
abgeschlossen: Release-Images, produktionsnaher Anwendungsdeploy, vollständig
neue Konfiguration, PVE-/PBS-Live-Matrix, automatischer Inventar-Sync,
Shadow-Parallelbetrieb und isolierte reale Backup-Labmatrix fehlen noch.
Insbesondere wird weder ein Deploy-, Live-Scan- noch ein Backup-Erfolg
behauptet.
