# Phase 7: Live- und Parallelbetriebsabnahme

## Vertragsnachtrag vom 7. September 2026

Neue verbindliche Zielverträge: [automatische Wiederfreigabe](adr/0005-automatic-backup-recovery.md) und [Wartungsupgrade mit DB-Restore](adr/0006-maintenance-upgrade-database-restore.md). Beide sind implementiert. Die Wartungs-/Upgrade-/Restore-Fälle wurden am 8. September gegen echte Dev-Systeme [erneut abgenommen](audits/2026-09-07/08-dev-maintenance-acceptance.md); die automatische Wiederfreigabe samt Backupmatrix, Fehler/Retry/Abbruch und fremder Taskbelegung wurde auf der bestehenden DEV [ebenfalls abgenommen](audits/2026-09-07/10-existing-dev-upgrade-and-backup-acceptance.md). Abschließende Veröffentlichungsgates und Registry-Pins stehen noch aus. Die nachstehenden historischen Kandidatennachweise bleiben unverändert; sie belegen die neuen Verträge nicht.


Stand: 19. Juli 2026

Status: **begonnen, nicht abgeschlossen.** Der isolierte Vier-Service-Labstack
ist gesund, und die neue V2-Konfiguration ist inzwischen weitgehend aufgebaut:
acht Laufzeit-Tokens versorgen fünf aktivierte PVE-/PBS-Verbindungen mit elf
gepinnten Endpunkten. Drei PVE-Cluster der Major-Versionen 7, 8 und 9, PBS 3
und PBS 4 sowie sechs Backupziele mit sechs Policies sind angebunden. Die
Backupausführung bleibt deaktiviert.

Der Abschlusskandidat ist Git-SHA `a6b4d4f`. Seine vollständige CI mit 37
grünen Jobs, die ausschließliche AMD64-Veröffentlichung, der digest-gepinnte
Lab-Deploy und die abschließende Drei-Zyklen-Evidenz sind belegt. Offen bleiben
die repräsentativen realen Backup-/Fehlerfälle einschließlich ihrer
Matrix-Problem- und Entwarnungszustellung. Eine benigne Matrix-Zustellprobe
bei weiterhin deaktivierter Backupausführung ist belegt.

Dieser fortschreibbare Evidenzbericht konkretisiert Phase 7 aus dem
[`rewrite-plan.md`](rewrite-plan.md). Die lokale Implementierung und ihre
Quality Gates sind getrennt im
[`phase-6-local-acceptance.md`](phase-6-local-acceptance.md) dokumentiert. Ein
Punkt gilt hier erst als belegt, wenn die genannte reale Evidenz auf dem
unveränderlichen Abschlusskandidaten vorliegt. Automatisierte Tests belegen
Verträge und seltene Fehlerpfade, ersetzen aber nicht die hier ausdrücklich
geforderten repräsentativen Live-Nachweise.

## Verbindlicher Abschlussumfang

Phase 7 bleibt bewusst auf den kleinsten belastbaren Live-Nachweis begrenzt:

1. Kandidat `a6b4d4f` besteht die vollständigen Gates, wird ausschließlich für
   `linux/amd64` veröffentlicht und digest-gepinnt im isolierten Lab ausgerollt.
2. Die positive API-Matrix gegen PVE 7, 8 und 9 sowie PBS 3 und 4 ist mit den
   vorgesehenen TLS-Pins und Laufzeitrechten erfolgreich.
3. Drei aufeinanderfolgende automatische Collector-/Shadow-Zyklen laufen im
   120-Sekunden-Raster stabil, idempotent und ohne Backupstart.
4. Die sechs Ziele und sechs Policies werden aus dem neuen V2-Inventar
   ausgewertet und erzeugen erklärbare, stabile Shadow-Entscheidungen.
5. Je PVE-Major wird mindestens ein QEMU- und ein LXC-Backup erfolgreich
   ausgeführt.
6. Ein repräsentativer definitiver Fehler mit dauerhaftem Retry, ein Cancel
   und die dazugehörige Matrix-Problem-/Statuszustellung werden korreliert
   nachgewiesen.

Die vollständige kombinatorische Security- und Chaosmatrix gehört in Phase 8.
Ihre deterministischen Verträge bleiben bis dahin durch Unit-, Contract-,
MariaDB-, Concurrency- und Fault-Harness-Tests abgesichert.

## Kandidat und Geltungsbereich

- Branch des aktuellen Audits: `codex/policies-shadow-mode`;
- unveränderliche Kandidaten-SHA: `a6b4d4f`;
- vollständige CI, finale AMD64-Artefakte und Registry-/Plattformdigests:
  **belegt**;
- Deployment dieses Kandidaten und erneute read-only Live-Abnahme: **belegt**;
- Backupausführung im aktuell laufenden Labstack: **deaktiviert**;
- produktive Backupausführung und Produktionsfreigabe: ausschließlich Phase 8.

Jede weitere Änderung an Source, Dependencies, Containern, Migrationen oder
Deploymentvertrag erzeugt einen neuen Kandidaten und macht nur die davon
betroffenen Nachweise erneut erforderlich. Unveränderte vollständige Gates
werden nicht allein wegen einer unabhängigen Dokumentationsänderung wiederholt.

## Aktueller Evidenzstand

| Bereich | Verbindliche Evidenz | Aktueller Stand |
| --- | --- | --- |
| Abschlusskandidat | SHA `a6b4d4f`, 37/37 Gates, AMD64-Artefakte und Digests | **belegt** |
| Deployment-Host | Alpine, Docker, Compose und OpenRC | **belegt** |
| Isolierter Labstack | getrennte Grenzen und genau vier gesunde Services | **belegt** |
| Labgrundlage | PVE 7/8/9, PBS 3/4, neun Nodes und 18 Gäste | **belegt** |
| Laufzeitidentitäten | getrennte PVE-Scan-/Backup- sowie PBS-Scan-Tokens | **8 Tokens belegt** |
| V2-Onboarding | fünf Verbindungen mit elf gepinnten Endpunkten | **belegt** |
| Neue Konfiguration | sechs aktivierte Ziele und sechs aktivierte Policies | **belegt** |
| Positive API-Matrix | erfolgreiche PVE-/PBS-Reads auf dem Abschlusskandidaten | **belegt** |
| Collector/Shadow | drei stabile automatische Zyklen auf dem Abschlusskandidaten | **belegt** |
| Matrix-Grundzustellung | echter HTTPS-Webhook und benigne Zustellprobe bei deaktivierter Backupausführung | **belegt** |
| Backup-Labmatrix | QEMU/LXC je PVE-Major sowie Error/Retry/Cancel einschließlich Problem- und Entwarnungszustellung | offen |
| Produktionsaktivierung | explizite Phase-8-Freigabe | nicht Teil von Phase 7 |

## Belegte Labgrundlage

Der für Hoddmímir vorgesehene Alpine-Host wird in getrackter Evidenz nur als
`deployment-host.example.invalid` bezeichnet. Reale Hosts, Endpunkte,
Zertifikatspins, Tokens und Secrets bleiben ausschließlich in ignorierter
lokaler Konfiguration.

Belegt sind:

- Python `3.12.13`, Docker Engine `29.5.2` und Docker Compose `2.40.3`;
- ein vom produktionsnahen Stack durch Projekt, Pfad, Datenbank, Docker-Netz,
  Port, Secrets, Volumes, Staging und Locks getrennter Labstack;
- genau `mariadb`, `data-worker`, `backup-worker` und `webapp` als gesunde
  Services;
- PVE 7 mit drei Nodes, PVE 8 mit drei Nodes und PVE 9 mit drei Nodes;
- je eine QEMU-VM und ein LXC-Container pro Node, insgesamt 18 Wegwerfgäste;
- PBS 3 und PBS 4 mit den vorgesehenen Datastores;
- elf von elf passende, lokal gespeicherte SHA-256-Leaf-Pins;
- die vorgesehenen Identitäten, Rollen, ACLs und versionsabhängigen
  Revoke-Kommandos für alle fünf Installationen.

Der Labstack hat Host-Caddy und ACME-Zustand nicht verändert. Die
Backupausführung ist weiterhin deaktiviert; aus dem gesunden Stack allein
folgt keine Freigabe einer PVE-/PBS-Schreiboperation.

### Sanitisierter Nachweis der Lab-Secret-Rotation

Am 19. Juli 2026 wurden durch eine versehentliche Terminalausgabe
ausschließlich die isolierten Labwerte für Anwendung, Verschlüsselungs-Keyring
und MariaDB offengelegt. Der Labstack wurde daraufhin gestoppt, sein exaktes
Datenbank-Volume sowie seine installierten Laufzeit-Secrets wurden entfernt
und sämtliche betroffenen Werte neu erzeugt; der Lab-Vault wurde mit dem neuen
Satz erneut verschlüsselt. Frischer Deploy, erneutes V2-Onboarding und die
Wiederherstellung der sechs Ziele und Policies wurden erfolgreich verifiziert.
Produktionswerte, PVE-/PBS-Token und die Matrix-Webhook-URL waren nicht Teil der
Ausgabe. Abschlusskandidat und deaktivierte Backupausführung blieben
unverändert.

## Belegte V2-Konfiguration

Das frühere Preflight-Stadium ohne Tokens oder Verbindungen ist überholt. Im
isolierten Lab sind jetzt neu erzeugt und ausschließlich laufzeitgebunden
eingebunden:

- sechs PVE-Tokens: je PVE-Cluster ein Scan- und ein Backup-Token;
- zwei PBS-Scan-Tokens: je PBS-Installation ein Token;
- fünf aktivierte Verbindungen: PVE 7, PVE 8, PVE 9, PBS 3 und PBS 4;
- elf zugeordnete und gepinnte Endpunkte;
- sechs aktivierte Backupziele und sechs aktivierte Policies einschließlich
  ihrer Auswahlregeln.

Die drei Endpunkte eines PVE-Clusters verwenden bewusst gleichwertige
Prioritäten; sie bilden eine gemeinsame Clusterverbindung und kein dreifaches
Inventar. Die Konfigurationsprojektion ist nach wiederholtem Apply ein reiner
No-op. Tokenwerte, Pins, Endpunkte und interne IDs werden nicht in Git oder
diesen Bericht aufgenommen.

Die sechs Lab-Policies verwenden Snapshot-Modus, Zstd und `keep-last=2` als
Konfiguration, führen aber keine eigene PBS-Retention aus. Bei PBS-Zielen sind
die PBS-Prune-Jobs alleinige Retention-Autorität; Hoddmímir sendet dort weder
`maxfiles` noch `prune-backups`.

## Drei gezielte Kandidatenkorrekturen

Die Live-Shadow-Läufe deckten drei konkrete Integrationsfehler auf. Sie sind
gezielt im Abschlusskandidaten `a6b4d4f` behoben und fokussiert getestet:

1. Dem Collector fehlten ausschließlich die Leserechte auf
   `backup_node_slots` und `backup_target_slots`. Eine neue symmetrische
   Migration gewährt genau `SELECT` und nimmt beim Down-Pfad genau diese
   Rechte wieder zurück. Die frische MariaDB-Migrationsprüfung ist grün.
2. Die Root-Namespace-Zuordnung eines PBS-Ziels wurde im Shadow- und
   Submission-Pfad als ungültig behandelt, weil die gespeicherte optionale
   Mapping-Angabe `NULL`, der kanonische Root-Namespace aber `''` ist. Der
   Vergleich normalisiert nun ausschließlich diesen Root-Fall mit
   `COALESCE(..., '')`; Nicht-Root-Namespaces bleiben unverändert fail-closed.
3. PVE 7, 8 und 9 liefern für QEMU und LXC die provisionierte Gastgröße als
   `maxdisk`. Der Reader hatte diese Evidenz verworfen und die vorhandene
   Datenbankspalte nicht befüllt. Der Wert wird nun typisiert bis in
   `provisioned_size_bytes` geführt. Fehlen sowohl dieser Wert als auch eine
   historische Backupgröße, bleibt die Entscheidung mit
   `minimum_free_space/missing` blockiert, ohne den Collector-Zyklus oder
   andere Gastentscheidungen zurückzurollen.

Die Korrekturen erklären, warum frühere Zyklen trotz erfolgreicher Scans noch
keinen stabilen Abschlussnachweis lieferten. Fokussierte Tests, Agentenreviews,
finale CI, Veröffentlichung, Deployment und Live-Wiederholung sind grün.

## Offene Phase-7-Nachweise

### 1. Veröffentlichung und Deployment

- [x] Vollständige CI für exakt `a6b4d4f` mit 37/37 Jobs erfolgreich
      abschließen.
- [x] Worker, Web und eigenes MariaDB-Image ausschließlich als
      `linux/amd64` veröffentlichen und Plattformdigests/SBOMs zuordnen.
- [x] Den Kandidaten digest-gepinnt mit der vorbereiteten
      Deployment-Transaktion im isolierten Lab ausrollen.
- [x] Migrationen, vier gesunde Services, Health, Readiness, Heartbeats und
      root-only Secretrechte erneut prüfen.
- [x] `BACKUP_EXECUTION_ENABLED=false` nach dem Deploy nachweisen.

### 2. Positive PVE-/PBS-Matrix und drei Shadow-Zyklen

- [x] Auf dem Abschlusskandidaten erfolgreiche authentifizierte Reads gegen
      PVE 7, PVE 8, PVE 9, PBS 3 und PBS 4 nachweisen.
- [x] Cluster/Server, Nodes, QEMU, LXC, Storages, Datastores, Namespaces,
      Snapshots, Jobs und Tasks aus den fünf Verbindungen ohne Doppelbestand
      inventarisieren.
- [x] Drei aufeinanderfolgende automatische Zyklen auf dem startzeitbasierten
      120-Sekunden-Raster ohne Überlappung oder Catch-up-Burst beobachten.
- [x] Frische Inventar-, Placement-, Kapazitäts- und Executor-Evidenz mit
      höchstens fünf Minuten Alter sowie erfolgreiche Scope-Ergebnisse
      nachweisen.
- [x] Sechs Ziele/Policies stabil und idempotent auswerten; jede Auswahl-,
      Placement-, Ziel-, Freshness- und Prioritätsentscheidung muss erklärbar
      sein.
- [x] Bei deaktivierter Ausführung weiterhin null Backup-Runs nachweisen.

### 3. Repräsentative Backup-, Fehler- und Matrix-Abnahme

Ein echter geheimer HTTPS-Matrix-Webhook liegt ausschließlich in der
ignorierten Labkonfiguration vor. Eine über den laufenden Backup Worker mit
den produktiven Adapterklassen gesendete benigne Probe wurde bei deaktivierter
Backupausführung im vorgesehenen Kanal empfangen. Das technische
Aktivierungs-Acknowledgement liegt ebenfalls vor und gilt ausschließlich für
den isolierten Labstack. Der produktionsnahe Stack bleibt deaktiviert.

- [x] Benigne Matrix-Grundzustellung bei deaktivierter Backupausführung
      nachweisen, ohne Webhook oder Kanal offenzulegen.
- [ ] Je einen QEMU- und LXC-Gast auf PVE 7, 8 und 9 erfolgreich sichern.
- [ ] Hoddmímir-Run, sanitisierten Remote-Taskbezug, Log, Endzustand,
      Zielartefakt, Audit und Matrix-Outbox korrelieren.
- [ ] Einen definitiven Fehler mit Versuchszähler und dauerhafter
      Retry-Planung nachweisen; ein blockierendes Gate darf keinen Start
      auslösen.
- [ ] Einen kontrollierten Cancel mit genau einem Remote-Abbruch nachweisen.
- [ ] Matrix-Problemmeldung beziehungsweise Status je Versuch und die spätere
      Entwarnung erfolgreich zustellen.
- [ ] Nachweisen, dass die V2-Historie ausschließlich mit diesen neuen
      Labläufen beginnt.

Externe Mutationen dieses Blocks sind auf die ausdrücklich freigegebenen
Wegwerfgäste begrenzt. Löschwirksame Retention bleibt ohne eigene Freigabe
verboten.

## In Phase 8 verschobene Kombinations- und Chaosfälle

Die folgenden seltenen oder kombinatorischen Live-Fälle blockieren Phase 7
nicht. Ihre fachlichen Verträge bleiben automatisiert getestet; praktische
Security-, Betriebs- und Chaosabnahme folgt in Phase 8:

- vollständige System-/Custom-CA-/korrekter-Pin-/falscher-Pin-Matrix und alle
  negativen ACL-Permutationen;
- Endpoint-Failover unter Ausfall, Node-Ausfall und Wiederkehr sowie ein
  kontrollierter Placementwechsel;
- künstlich veraltete Evidenz, wiederholte Executor-Timeouts und getrennte
  Worker-/Lease-Ausfälle;
- Token-Revoke und absichtlich erzeugte TLS- oder Nodefehler;
- Transportabbruch direkt nach dem `vzdump`-POST und die mehrdeutige
  Reconciliation ohne zweiten POST;
- isoliertes Doppelclaim-Race zweier Backup Worker;
- mehrdeutiger Cancel-Transportfall ohne zweiten DELETE.

Auch in Phase 8 gilt unverändert: Ein `vzdump`-POST wird nach mehrdeutiger
Antwort niemals automatisch wiederholt, und TLS-Verifikation bleibt immer
aktiv.

## Noch offene Abnahmearbeiten

Für den verbleibenden Phase-7-Abschluss fehlt kein externer
Konfigurationseingang mehr. Webhook-Grundzustellung und technisches
Aktivierungs-Acknowledgement sind belegt. Offen sind ausschließlich die
repräsentativen QEMU-/LXC-, Error/Retry- und Cancel-Labläufe sowie die damit
gekoppelte Problem- und Entwarnungszustellung.

Tokens, Ziele und Policies sind nicht mehr offen. Produktions-Tokens,
Produktions-Onboarding und jede produktive Backupaktivierung gehören in Phase
8. Secrets, Tokenwerte, Webhook-URLs, private CA-Schlüssel, interne
Inventardetails und vollständige UPIDs dürfen nicht in diesen Bericht oder Git
geschrieben werden.

## Fortschrittsprotokoll

| Datum | Kandidat | Ereignis | Ergebnis | Verbleibende Grenze |
| --- | --- | --- | --- | --- |
| 13.07.2026 | `d88d38a` | Alpine-Host gebootstrapped | Docker, Compose und OpenRC bereit | kein Anwendungsdeploy |
| 15.07.2026 | Zwischenstand | Isolierter Labstack ausgerollt | vier gesunde, getrennte Services | finaler Kandidat und Live-Abnahme offen |
| 15.07.2026 | Zwischenstand | Labgrundlage geprüft | PVE 7/8/9, PBS 3/4, neun Nodes, 18 Gäste und 11 Pins | noch kein V2-Onboarding |
| 19.07.2026 | Vorläufer `9a3f948` | V2-Onboarding und Konfiguration | 8 Tokens, 5 Verbindungen/11 Endpunkte, 6 Ziele/Policies | zwei Live-Integrationsfehler gefunden |
| 19.07.2026 | `7347625` | Least-Privilege- und PBS-Root-Namespace-Hotfix | fokussierte Tests und Review grün | finale CI, Veröffentlichung, Deploy und Live-Wiederholung offen |
| 19.07.2026 | `a6b4d4f` | PVE-Gastgrößenpfad und Missing-Size-Block | 37/37 CI-Jobs, AMD64-Publikation, Deploy und drei stabile Shadow-Zyklen grün | Matrix und repräsentative Backupfälle offen |
| 19.07.2026 | `a6b4d4f` | Benigne Matrix-Zustellprobe bei deaktivierter Backupausführung | Zustellung im vorgesehenen Kanal extern bestätigt | Problem-/Entwarnungszustellung bleibt an die repräsentativen Backupläufe gekoppelt |
| 19.07.2026 | `a6b4d4f` | Isolierte Lab-Secrets nach Terminalausgabe vollständig rotiert | frischer Vault, Deploy, Onboarding und Konfiguration verifiziert; keine Produktions-, Proxmox- oder Matrix-Werte betroffen | Kandidat und deaktivierte Backupausführung unverändert |

## Vorläufiges Urteil

Phase 7 hat den finalen Release-, Deploy-, Onboarding-, Inventar- und
Shadow-Nachweis bestanden. Kandidat `a6b4d4f` läuft mit fünf Verbindungen,
elf Endpunkten, sechs Zielen/Policies und exakt 18 stabilen automatischen
Queue-Einträgen für 18 Gäste; Folgezyklen sind reine Dedup-Projektionen und
Backupausführung bleibt deaktiviert. Der Abschluss wird noch nicht behauptet,
weil die repräsentativen QEMU-/LXC-, Error/Retry- und Cancel-Fälle
einschließlich ihrer Matrix-Problem- und Entwarnungszustellung ausstehen. Die
benigne Matrix-Grundzustellung ist bereits belegt.

Die vollständige negative TLS-/ACL- und seltene Chaosmatrix ist bewusst Phase
8 zugeordnet und hält den Phase-7-Abschluss nicht auf.
