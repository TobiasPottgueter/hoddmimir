# Proxmox-Verbindungs-Onboarding ohne Administrator-Token

Status: **verbindlicher Vertrag, lokal als Teil von Phase 6 implementiert und
abgenommen.** Automatisierte Backend-, Contract- und Browser-Nachweise sind im
[`phase-6-local-acceptance.md`](phase-6-local-acceptance.md) zusammengefasst.
Die Prüfung der angezeigten Befehle sowie der TLS-, Versions-, ACL- und
Scan-Nachweise gegen reale PVE-/PBS-Systeme bleibt Phase 7.

Dieses Dokument konkretisiert die Verbindungs- und Credential-Verwaltung aus
[`rewrite-plan.md`](rewrite-plan.md) und
[`phase-6-webapp-plan.md`](phase-6-webapp-plan.md). Es beschreibt den
WebApp-Flow für „Neuen PVE hinzufügen“ und „Neuen PBS hinzufügen“.

## Entscheidungen

- Die WebApp erhält niemals einen PVE-/PBS-Administrator-, Bootstrap- oder
  Berechtigungsverwaltungs-Token.
- Die WebApp zeigt prüfbare Einzelbefehle an. Sie erzeugt weder ein
  `curl | shell`-Konstrukt noch ein mit Administratorrechten auszuführendes
  Setup-Skript.
- Der Betreiber führt die Befehle bewusst direkt auf dem PVE- beziehungsweise
  PBS-System aus und trägt anschließend ausschließlich die erzeugten
  Laufzeit-Token in die WebApp ein.
- Hoddmímir prüft die effektiven Rechte jeder eingetragenen Identität. Die
  Existenz bestimmter administrativer Zwischenobjekte allein ist kein
  Betriebsnachweis.
- Der PVE Collector und der PVE Backup Worker erhalten bewusst
  installationsweite, propagierte Rechte auf alle aktuellen und zukünftigen
  Objekte. Proxmox-ACLs bilden nicht die fachliche Auswahl ab.
- Auswahl, explizite Ausschlüsse, Enable-Gates, Policy, Placement,
  Zielzuordnung, Kapazität und Parallelität bleiben allein in Hoddmímir
  maßgeblich.
- Ein kompromittierter PVE Backup Token kann dadurch Backups aller Gäste auf
  allen als Backupziel geeigneten PVE-Storages anstoßen. Er erhält trotzdem
  keine Rechte für VM-Konfiguration, VM-Power, Storage-Konfiguration,
  Löschung, Benutzer oder ACLs. Diese bewusst größere Executor-Reichweite ist
  gegen automatische Abdeckung neuer und verschobener Gäste abgewogen.
- Der Wizard startet keinen Scan und kein Testbackup. Nach der
  Berechtigungsprüfung wartet er auf den nächsten regulären Collector-Tick im
  startzeitbasierten Raster.

## PVE-Zielzustand

Die angezeigten Befehle erzeugen beziehungsweise aktualisieren idempotent den
folgenden Zielzustand:

- Benutzer `hoddmimir@pve`;
- Gruppe `Bots` mit dem Benutzer `hoddmimir@pve`;
- Rolle `HoddmimirScan` mit exakt:
  - `Sys.Audit`;
  - `VM.Audit`;
  - `Pool.Audit`;
  - `Datastore.Audit`;
- Rolle `HoddmimirBackup` mit exakt:
  - `VM.Backup`;
  - `Datastore.AllocateSpace`;
- privilegiengetrennter Token `hoddmimir@pve!scan`;
- privilegiengetrennter Token `hoddmimir@pve!backup`.

Die Gruppe erhält am Root-Pfad `/` beide Rollen mit Propagation. Der
Scan-Token erhält dort ausschließlich `HoddmimirScan`, der Backup-Token
ausschließlich `HoddmimirBackup`, jeweils ebenfalls mit Propagation. Die
Gruppenzuweisung gibt dem Basisbenutzer die Vereinigungsmenge, während die
Token-ACLs die effektiven Laufzeitrechte trennen. Neue Nodes, VMs, CTs, Pools
und Storages sind ohne spätere ACL-Anpassung automatisch abgedeckt.

Die Befehlsdarstellung muss vor ihrer Live-/Produktivabnahme in Phase 7 gegen
die CLI-Schemata der letzten gepatchten PVE-7-, PVE-8- und PVE-9-Version
validiert werden. Sie muss:

- bestehende gleichnamige Rollen vor einer Änderung lesen und bei
  abweichender Definition anhalten;
- bestehende fremde Benutzer-, Gruppen-, Token- oder ACL-Zustände nicht
  stillschweigend überschreiben;
- Token-Secrets nur aus dem unmittelbaren Erzeugungsresultat anzeigen;
- klar kennzeichnen, dass verlorene Secrets nicht erneut gelesen werden
  können und kontrolliert rotiert werden müssen;
- keine Secrets in Dateien, Shell-History-Hilfsbefehle oder Diagnoseausgaben
  schreiben.

## PVE-Wizard

### Eingaben

- Anzeigename der Verbindung;
- DNS-Name oder IP-Adresse;
- Port, standardmäßig `8006`;
- TLS-Modus `system_ca`, `custom_ca` oder `sha256_fingerprint` mit dem
  jeweils exklusiven Trust-Material;
- Scanner-Token-ID und write-only Scanner-Secret;
- Backup-Token-ID und write-only Backup-Secret.

Die Token-IDs müssen verschieden sein und kanonisch
`hoddmimir@pve!scan` beziehungsweise `hoddmimir@pve!backup` entsprechen.

### Remote-Prüfung ohne Mutation

Der PHP-Backend-Flow führt ausschließlich sichere Reads aus:

1. TLS-Handshake unter dem ausgewählten Trust-Modus;
2. `GET /version` getrennt mit beiden Tokens;
3. Produkt- und Major-Prüfung auf PVE 7, 8 oder 9;
4. `GET /access/roles` zur Prüfung der beiden Rollendefinitionen;
5. `GET /access/permissions` ohne fremde `userid`-Abfrage getrennt mit jedem
   Token;
6. Normalisierung und Vergleich der effektiven Pfad-/Privileg-Matrix.

Es werden keine schreibenden Endpunkte als negativer Test aufgerufen. Ein
erwartetes `403` ist kein sicherer Ersatz für die effektive
Berechtigungsmatrix.

### Erwartete Scanner-Rechte

Der Scanner muss die folgenden propagierten Rechte installationsweit besitzen:

| Pfadfamilie | erforderliches Privileg |
|---|---|
| `/` und `/nodes` | `Sys.Audit` |
| `/vms` | `VM.Audit` |
| `/pool` | `Pool.Audit` |
| `/storage` | `Datastore.Audit` |

Fehlende Rechte oder fehlende Propagation sind ein harter Fehler. Zusätzliche
read-only Audit-Rechte sind eine sichtbare Warnung. Jedes zusätzliche
schreibende oder administrative Recht ist ein harter Fehler, insbesondere:

- `VM.Backup`, `VM.Allocate`, `VM.PowerMgmt`, `VM.Config.*`,
  `VM.Migrate`, `VM.Replicate` und Snapshot-Rechte;
- `Datastore.AllocateSpace`, `Datastore.Allocate` und
  `Datastore.AllocateTemplate`;
- `Sys.Modify`, `Sys.PowerMgmt`, `Sys.Console` und `Sys.Incoming`;
- `Permissions.Modify`, Benutzer-, Gruppen-, Realm- oder Pool-Verwaltung.

### Erwartete Backup-Rechte

Der Backup-Token muss installationsweit propagiert besitzen:

| Pfadfamilie | erforderliches Privileg |
|---|---|
| `/vms` und durch Pools enthaltene Gäste | `VM.Backup` |
| `/storage` und durch Pools enthaltene Storages | `Datastore.AllocateSpace` |

Die kombinierte Rolle kann auf Pfaden zusätzlich das jeweils dort nicht
ausgewertete Schwesterprivileg zeigen. Entscheidend ist, dass
`VM.Backup` auf allen Gästen und `Datastore.AllocateSpace` auf allen Storages
wirksam ist und kein drittes Privileg hinzukommt.

Insbesondere sind `Sys.Modify`, `Permissions.Modify`, `VM.PowerMgmt`,
`VM.Allocate`, `VM.Config.*`, `Datastore.Allocate` sowie Benutzer- und
ACL-Verwaltung harte Fehler. Der Worker darf eigene VZDump-Tasks anhand ihrer
UPID ohne diese Zusatzrechte lesen und stoppen. Fremde Tasks, root-only
VZDump-Parameter und Parameter mit `Sys.Modify`-Bedarf bleiben verboten.
Löschwirksame `maxfiles`-/`prune-backups`-Parameter dürfen nur aus der separat
freigegebenen `approvedDeletionRetention` entstehen; ohne die explizite
Retention-Ausführungsgenehmigung bleiben sie im Startrequest verboten. Für ein
PVE-Storage vom Typ `pbs` bleibt diese Genehmigung grundsätzlich gesperrt:
Hoddmímir sendet dort niemals einen Löschparameter, weil die Aufbewahrung durch
die PBS-Prune-Jobs gesteuert wird. Nur Nicht-PBS-Ziele wie lokaler, NFS- oder
CIFS-Storage können die ausdrücklich aktivierte Lösch-Retention verwenden.

Ein versehentlich nicht privilegiengetrennter Token erbt die Vereinigungsmenge
des Basisbenutzers. Die daraus entstehenden zusätzlichen effektiven Rechte
lassen die Prüfung scheitern, auch wenn die Token-Metadaten selbst für die
minimale Identität nicht lesbar sind.

### Aktivierung und Betriebsnachweis

Nur wenn beide Tokens authentifizieren und beide effektiven Matrizen bestehen,
werden Verbindung, Endpoints, TLS-Material und verschlüsselte Credentials in
einer lokalen MariaDB-Transaktion gespeichert und aktiviert. Vorher verbleibt
der Wizard im nicht aktiven Entwurfszustand.

Der Wizard zeigt anschließend getrennte Zustände:

1. `tls_verified`;
2. `product_supported`;
3. `scan_permissions_verified`;
4. `backup_permissions_verified`;
5. `connection_activated`;
6. `first_automatic_scan_pending`;
7. nach dem nächsten Rasterpunkt `inventory_verified` oder den konkreten
   partiellen beziehungsweise fehlgeschlagenen Scope.

Ein Berechtigungs-HTTP-200 allein ist kein Inventarnachweis. Erst der normale
Collector-Zyklus beweist, dass Cluster-, Node-, Gast-, Pool-, Storage-, Job-
und Task-Sichten unter realer Pagination und den bestehenden
Autoritativitätsregeln vollständig lesbar sind. Es gibt weder einen
Wizard-Sonderlauf noch eine Aktion „Jetzt scannen“.

Ein Testbackup wird nie automatisch gestartet. Die erste reale Ausführung
bleibt ein normaler, durch Policy, Queue, Worker-Aktivierung und das explizite
Produktions-Acknowledgement kontrollierter Backup-Request.

## PBS-Zielzustand

PBS unterstützt keine eigenen Rollen `HoddmimirScan` oder
`HoddmimirBackup`. Hoddmímir verwendet dort ausschließlich eingebaute Rollen
und benötigt keinen eigenen PBS-Backup-Worker-Token:

- Benutzer `hoddmimir@pbs`;
- API-Token `hoddmimir@pbs!scan`;
- `Audit` propagiert auf `/system`;
- `DatastoreAudit` propagiert auf `/datastore`;
- `RemoteAudit` propagiert auf `/remote`, damit Sync-Job-Evidenz
  installationsweit vollständig sein kann.

Da PBS Tokenrechte mit den Rechten des Basisbenutzers schneidet, erhalten
sowohl `hoddmimir@pbs` als auch `hoddmimir@pbs!scan` diese ACLs. Der Wizard
prüft mit dem Scanner-Token getrennt:

- TLS, Produkt und PBS 3 beziehungsweise PBS 4;
- `Sys.Audit` auf `/system/status` und `/system/tasks`;
- propagiertes `Datastore.Audit` auf `/datastore`;
- propagiertes `Remote.Audit` auf `/remote`;
- Abwesenheit aller Modify-, Backup-, Read-, Prune-, Verify-, Admin-, Tape-
  und Berechtigungsverwaltungsrechte.

Der PVE-zu-PBS-Storage-Token ist kein Hoddmímir-Backup-Worker-Credential. Er
liegt ausschließlich in der PVE-Storage-Konfiguration und besitzt nur
`DatastoreBackup` auf dem engsten PBS-Namespace. Seine Einrichtung und
Rotation sind ein eigener PVE/PBS-Storage-Administrationsfluss und nicht Teil
von „Neuen PBS hinzufügen“.

Nach erfolgreicher Credential-Prüfung wird die PBS-Verbindung wie PVE
aktiviert und wartet auf den nächsten automatischen Collector-Tick.

## API-, Secret- und Audit-Vertrag

- Der Browser spricht PVE/PBS nie direkt an; alle Prüfungen laufen über die
  versionierte PHP-API und eigene typisierte Proxmox-Adapter.
- Secrets sind write-only, werden nur im begrenzten
  Entschlüsselungs-/Authorization-Callback als Klartext gehalten und niemals
  zurückgegeben.
- Für das Hinzufügen oder Ändern eines Endpoints vergleicht die atomare
  Transaktion die erneut eingegebenen Secrets verbindungs- und
  zweckgebunden mit zufällig gesalzenen Argon2id-Verifikatoren. Die Web-DB-Rolle
  darf ausschließlich diese Verifikatorspalte lesen und aktualisieren, nicht
  `secret_envelope`, `key_id` oder `envelope_version`. Ein deterministischer
  Equality-Digest wird bewusst nicht verwendet, damit gleiche Secrets nicht
  datenbankweit korrelierbar sind. Die Verifikatoren erscheinen weder in API,
  Logs noch Audit-Ereignissen. Bei einem Datenbankabfluss erlauben sie nur das
  kostenbehaftete Prüfen geratener Werte; die Laufzeit-Tokens müssen deshalb
  weiterhin hochentropisch sein und nach einem solchen Vorfall rotiert werden.
- Fehlermeldungen, Audit-Ereignisse, Logs und gespeicherte
  Permission-Evidenz enthalten keine Token-Secrets oder Authorization-Header.
- Die lokale Aktivierung verwendet erwartete Revision und Idempotency-Key.
- Remote-Reads dürfen nach dem bestehenden GET-Vertrag begrenzt wiederholt
  werden. Es gibt in diesem Wizard keine remote schreibenden Requests.
- Credential-Rotation wiederholt denselben Prüfvertrag und ersetzt das aktive
  Credential erst atomar nach vollständigem Erfolg.

## Test- und Abnahmeplan

### Backend

- Unit-Tests für geschlossene PVE-/PBS-Privileg- und Pfad-Allowlisten;
- Branch-Tests für fehlende Propagation, fehlende Pflichtrechte, zusätzliche
  Audit-Rechte, zusätzliche Schreibrechte, nicht separierte Token und
  überschreibendes `NoAccess`;
- Contract-Fixtures für PVE 7/8/9 und PBS 3/4;
- Tests, dass der Wizard ausschließlich die festgelegten GET-Routen besitzt;
- Tests, dass bei irgendeinem Fehler keine aktive Verbindung und kein
  teilweise ersetztes Credential persistiert wird;
- reale MariaDB-Tests für atomare Aktivierung, Revision, Idempotenz,
  Credential-Rotation und Rollback;
- Redaction- und Nicht-Serialisierbarkeits-Tests für alle Secretpfade.

### Frontend

- Komponenten- und Store-Tests für Befehl-, Eingabe-, Prüf-, Warn-, Fehler-,
  Aktivierungs- und `first_automatic_scan_pending`-Zustände;
- Geheimnisfelder werden nach erfolgreicher Übergabe geleert und niemals aus
  API-Antworten rekonstruiert;
- gefährliche Zusatzrechte und fehlende Propagation werden pfadbezogen und
  verständlich erklärt;
- kein Admin-/Bootstrap-Token-Feld, kein Setup-Skript und kein manueller
  Scan-Button.

### End-to-End und reale Systeme

- Playwright für erfolgreichen PVE-/PBS-Flow, falsches Secret, falschen Pin,
  fehlendes Recht, Zusatzrecht, Revision-Konflikt und ausstehenden ersten
  Collector-Zyklus;
- read-only Live-Smokes gegen die letzten gepatchten PVE-7/8/9- und
  PBS-3/4-Versionen;
- Sichtprüfung, dass die angezeigten Befehle exakt den verifizierten
  Laufzeit-Zielzustand erzeugen;
- Abnahme erst nach einem echten vollständigen automatischen Scan;
- Backup-Ausführung bleibt ein getrenntes Release-Gate und wird vom
  Onboarding niemals als Test ausgelöst.

## Nicht Bestandteil

- Übertragung oder Speicherung eines Proxmox-Administrator-Tokens;
- remote Benutzer-, Gruppen-, Rollen-, Token- oder ACL-Mutationen durch
  Hoddmímir;
- manuelle Scans oder versteckte Wizard-Scans;
- automatisch gestartete Testbackups;
- Konfiguration eines PBS-Storage in PVE;
- Verwaltung oder Speicherung des PVE-zu-PBS-`DatastoreBackup`-Secrets in
  Hoddmímir.
