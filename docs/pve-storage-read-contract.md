# PVE-7/8/9-Vertrag für das Storage-Enrichment

Stand: 10. Juli 2026

## Zweck und Grenze

Dieser Slice ergänzt die erste PVE-Inventarisierung um typisierte,
ausschließlich lesend angeforderte Storage-Konfigurationen und
Node-Beobachtungen. Er umfasst exakt diese zusätzlichen API2-JSON-Aufrufe:

1. `GET /storage` als Startaufnahme;
2. für jeden aus `/cluster/status` bekannten Node genau einmal
   `GET /nodes/{node}/storage?content=backup`;
3. `GET /storage` als Endaufnahme.

`GET /storage/{storage}` wird ausdrücklich **nicht** verwendet. Dieser
Einzelendpunkt verlangt `Datastore.Allocate` und widerspricht dem
Least-Privilege-Vertrag des Collectors.

Der Slice erzeugt reine Application-DTOs, positive Beobachtungen und eine
Autoritativitätsentscheidung. Er enthält noch keine MariaDB-Persistenz, keine
Archivierung beziehungsweise negativen Diffs, keine Aktivierung von
Backup-Zielen, keine direkte PBS-API-Verifikation und keine WebApp-Funktion.
Er ist noch nicht in den Collector oder dessen Dependency Injection
eingebunden.

## Operativer P0-Hinweis: der Status-GET kann Storages aktivieren

`GET /nodes/{node}/storage` ist auf HTTP-Ebene ein GET, aber auf dem PVE-Node
nicht vollständig nebenwirkungsfrei. Die offizielle Implementierung ruft für
jeden aktivierten Treffer `activate_storage()` auf. Abhängig vom Storage-Plugin
kann dies beispielsweise ein Storage mounten oder eine externe Verbindung
aufbauen.

Hoddmímir begrenzt diesen Effekt zwingend mit dem exakten Query-Parameter
`content=backup`. Der Collector startet dadurch keinen Backup-Task, kann aber
relevante Backup-Storages aktivieren. Betreiber müssen diesen Effekt bei
Netzwerk-, Mount- und Berechtigungsplanung berücksichtigen. Ein unfiltrierter
Node-Storage-Read ist in diesem Vertrag nicht zulässig.

## Offiziell belegter Wire-Vertrag

Die API-Viewer beschreiben `GET /storage` nur teilweise. Die offiziellen
`pve-storage`-Implementierungen der Branches `stable-7`, `stable-8` und
`master` liefern jedoch den aus `storage.cfg` normalisierten Config-Record und
kopieren den globalen Config-Digest in jeden für die Identität sichtbaren
Record. Der Vertrag wurde deshalb zusätzlich anhand dieser Primärquellen
fixiert:

- `stable-7` auf Commit `13f8b110bd90ecfddb9a84fb599ff08dbd3b1079`:
  [Config-API](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/API2/Storage/Config.pm;hb=13f8b110bd90ecfddb9a84fb599ff08dbd3b1079)
  und [Status-API](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/API2/Storage/Status.pm;hb=13f8b110bd90ecfddb9a84fb599ff08dbd3b1079);
- dieselben offiziellen Config- und Statusdateien für
  `stable-8` auf Commit `1af4790d7ef4caacc9db10cc3c5f64575f13e966`
  ([Config](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/API2/Storage/Config.pm;hb=1af4790d7ef4caacc9db10cc3c5f64575f13e966),
  [Status](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/API2/Storage/Status.pm;hb=1af4790d7ef4caacc9db10cc3c5f64575f13e966))
  und `master` auf Commit `d666ebd61a5cfcfbc6bec733754f87d56eddf985`
  ([Config](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/API2/Storage/Config.pm;hb=d666ebd61a5cfcfbc6bec733754f87d56eddf985),
  [Status](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/API2/Storage/Status.pm;hb=d666ebd61a5cfcfbc6bec733754f87d56eddf985));
- die PBS-Plugin-Verträge für
  [PVE 7](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/Storage/PBSPlugin.pm;hb=13f8b110bd90ecfddb9a84fb599ff08dbd3b1079),
  [PVE 8](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/Storage/PBSPlugin.pm;hb=1af4790d7ef4caacc9db10cc3c5f64575f13e966)
  und [PVE 9](https://git.proxmox.com/?p=pve-storage.git;a=blob;f=src/PVE/Storage/PBSPlugin.pm;hb=d666ebd61a5cfcfbc6bec733754f87d56eddf985);
- die offiziellen API-Viewer für
  [PVE 7](https://pve.proxmox.com/pve-docs-7/api-viewer/index.html),
  [PVE 8](https://pve.proxmox.com/pve-docs-8/api-viewer/index.html) und
  [PVE 9](https://pve.proxmox.com/pve-docs/api-viewer/index.html).

Alle Antworten werden erst durch den bestehenden JSON-Envelope-Decoder auf
das `data`-Element reduziert. Die Storage-Reader übernehmen anschließend nur
die unten genannten Felder. Unbekannte Felder, einschließlich möglicher
pluginabhängiger oder sensibler Werte, werden weder in DTOs kopiert noch
geloggt.

### `GET /storage`

Pro sichtbarer Definition werden nur diese Felder gelesen:

- `storage`: erforderliche offizielle `pve-storage-id` mit der Grammatik
  `[a-z][a-z0-9._-]*[a-z0-9]` und dem offiziellen `/i`-Modifier; ein
  einzelner Buchstabe oder ein abschließendes `.`, `-` beziehungsweise `_`
  ist ungültig;
- `type`: erforderlicher, syntaktisch sicherer ASCII-Typ, aber nicht hart auf
  bekannte Plugin-Typen enumeriert;
- `content`: erforderliche CSV-Tokenmenge;
- `nodes`: optionale CSV-Allowlist;
- `disable`: optional; fehlend hat gemäß PVE-Konfigurationssemantik den Wert
  `false`;
- `shared`: optional; fehlend hat im normalisierten Config-Record den Wert
  `false`;
- `digest`: erforderlicher, nichtleerer globaler `storage.cfg`-Digest;
- für `type === "pbs"`: `server` und `datastore` erforderlich, `port`
  standardmäßig `8007`, `namespace` nullable.

`maxfiles` wird fachlich ignoriert, falls ein additives oder älteres System es
dennoch liefert. Die gepinnten normalisierten PVE-7/8-Records verwenden
stattdessen `prune-backups`; PVE 9 führt `maxfiles` nicht. PBS-Mappings werden
als vollständiges Tupel aus Server, Port, Datastore und Namespace erhalten;
eine automatische Verbindungskopplung allein über den Hostnamen findet nicht
statt.

Alle übrigen Response-Strings müssen sichtbares ASCII beziehungsweise ihre
konkretere oben beschriebene ID-Grammatik erfüllen. C0-Steuerzeichen, ESC,
DEL, interne Zeilenumbrüche und Nicht-ASCII-Zeichen machen den Record partial;
sie werden nicht durch Trimmen repariert.

Storage-Typen werden bewusst nicht enumeriert. Die offiziellen Mengen ändern
sich zwischen PVE 7, 8 und 9 und PVE unterstützt zusätzliche Plugins.

Die Storage-ID-Grammatik wurde zusätzlich gegen `pve-common` geprüft:

- PVE-7-Linie, Commit `c89e056`,
  [`PVE/JSONSchema.pm`](https://git.proxmox.com/?p=pve-common.git;a=blob;f=src/PVE/JSONSchema.pm;hb=c89e056),
  Definition `pve-storage-id`;
- aktuelle Linie, Commit `74c2506`, dieselbe Grammatik und der
  case-insensitive `/i`-Modifier in
  [`parse_id`](https://git.proxmox.com/?p=pve-common.git;a=blob;f=src/PVE/JSONSchema.pm;hb=74c2506).

Die Application-DTOs und beide Storage-Reader erzwingen denselben Vertrag;
ein alternativer Port-Adapter kann daher keine nichtkanonische Storage-ID in
einen autoritativen Snapshot einschleusen. Die Prüfung ist case-insensitive,
der vom Section-Header gelieferte Wert wird jedoch unverändert erhalten und
nicht in Lowercase umgeschrieben.

### CSV- und Backup-Semantik

`content` und `nodes` werden als ASCII-kommagetrennte Tokenmengen verarbeitet:

- Tokens werden getrimmt, validiert, dedupliziert und stabil sortiert;
- leere oder strukturell ungültige Tokens erzeugen einen Contract-Issue;
- unbekannte syntaktisch gültige Tokens bleiben erhalten;
- Backup-Unterstützung gilt ausschließlich bei exakter, case-sensitiver
  Mitgliedschaft des Tokens `backup`.

Weder `Backup` noch `backup-archive` noch ein Storage-Typ aktivieren die
Backup-Capability.

### `GET /nodes/{node}/storage?content=backup`

Pro sichtbarer Beobachtung werden nur folgende Felder gelesen:

- `storage`, `type` und `content`;
- die drei erforderlichen Status-Booleans `enabled`, `active` und `shared`;
- die Kapazität `total`, `used` und `avail`.

Anders als bei Config-Records erhalten fehlende Status-Booleans keinen
Default. Boolean oder `0|1` werden akzeptiert; alles andere ist ein
Contract-Issue. Da der Endpoint nach Backup-Content filtert und PVE nur auf
dem Node aktivierte Definitionen liefern soll, ist ein zurückgegebenes
`enabled=false` ein Widerspruch.

Eine Kapazität ist nur dann frisch, wenn `enabled=true`, `active=true` und das
gesamte Tripel aus nichtnegativen Integern vorliegt. Zusätzlich gelten
`used <= total` und `avail <= total`. Eine Gleichheit von
`used + avail == total` wird nicht verlangt, weil reservierter oder
anderweitig nicht verfügbarer Platz möglich ist.

Bei `active=false` sind die von PVE initialisierten Werte `0/0/0` keine
gemessene Nullkapazität. Sie werden atomar als nicht verfügbare Kapazität
modelliert. Auffällige Nichtnull- oder Teilwerte in einem nicht aktiven Status
erzeugen ebenfalls keine frische Kapazität und machen den Read partial.
`observed_at` stammt ausschließlich aus der injizierten Clock und wird nach
UTC normalisiert.

## Erwartungs- und Widerspruchsregeln

Eine Config-Definition wird für einen Node genau dann im gefilterten
Status-Read erwartet, wenn:

```text
content enthält exakt "backup"
AND disable == false
AND (nodes fehlt OR nodes enthält den Topologie-Node)
```

Für jeden erfolgreich gelesenen Node müssen erwartete und beobachtete
Storage-IDs identisch sein. Ein fehlender erwarteter oder ein zusätzlicher
Status macht den Read partial. Bei einer passenden ID müssen `type`, die
vollständige normalisierte `content`-Menge und `shared` mit der Config
übereinstimmen.

Syntaktisch sichere positive Beobachtungen bleiben auch bei einem anderen
Nodefehler, einer ACL-Lücke oder einem Widerspruch erhalten. Der Slice leitet
aus einem partial Read aber niemals eine Abwesenheit oder Löschung ab.

## ACL- und Digest-Vollständigkeit

Beide Listenendpunkte filtern nach effektiven Datastore-Rechten. Ein HTTP 200
beweist deshalb keine Vollständigkeit. Für einen autoritativen Storage-Read
muss `/access/permissions` exakt ein propagiertes
`Datastore.Audit = 1` auf `/storage` bestätigen. `Datastore.AllocateSpace`
kann technisch ebenfalls Listenzugriff gewähren, wird dem Collector aber
nicht als Ersatzrecht erteilt. Einzelrechte auf bereits bekannte Storages
können neue unsichtbare Storages nicht ausschließen.

Der `digest` ist global für `storage.cfg` und gehört auf den gesamten
Configuration-Set, nicht auf eine einzelne Definition. Ein Read ist nur
gültig, wenn er nicht leer ist und jeder sichtbare Record denselben
nichtleeren Digest trägt. Für Autoritativität müssen Start und Ende sowohl
denselben Digest als auch dieselbe normalisierte sichtbare Definitionsmenge
haben. Der zweite Vergleich ist zusätzlich nötig, weil gleiche Digests allein
eine zwischenzeitlich geänderte ACL-Sichtbarkeit nicht beweisen.

Der Storage-Slice ist nur autoritativ, wenn außerdem:

- die übergebene Topologie vollständig ist;
- beide Config-Reads gültig sind;
- jeder eindeutige Topologie-Node erfolgreich gelesen wurde;
- keine erwartete oder zusätzliche Status-ID vorliegt;
- kein Typ-, Content-, Shared-, Enabled- oder Kapazitätswiderspruch besteht.

Bei jedem Issue bleibt der Read partial. Es gibt in diesem Slice bewusst keine
Repository- oder Diff-Schnittstelle; insbesondere kann er selbst keinen
negativen Diff ausführen.

## Fixture- und Nachweisgrenze

Die Fixtures unter `backend/tests/Fixtures/Proxmox/Pve/{7,8,9}` sind
sanitisierte, konstruierte Contract-Beispiele. Sie enthalten je Major eine
Config-Aufnahme und zwei kohärente Node-Statusantworten. Unbekannte Felder und
wechselnde Storage-Typen prüfen Forward Compatibility. PVE 7/8 zeigen
`prune-backups`, PVE 8 verwendet für `esxi` den offiziellen Content `import`,
und PVE 9 enthält nur neutrale unbekannte Zusatzfelder ohne `format=1` zu
simulieren. NFS ist in allen drei Major-Fixtures korrekt als shared modelliert.
Das in PVE 7 gezeigte `glusterfs` wird gemäß der gepinnten Plugin-Normalisierung
ebenfalls als shared ausgegeben; PVE 8 `esxi` bleibt ohne explizite
Shared-Konfiguration dagegen `false`.

Die Fixtures sind keine Live-Captures und kein Release-Nachweis. Read-only-
Smoke-Tests gegen die letzten gepatchten PVE-7/8/9-Releases bleiben ein
separates Gate. Wegen der dokumentierten `activate_storage()`-Wirkung müssen
diese Tests in kontrollierten Testumgebungen mit bewusst bereitgestellten
Backup-Storages stattfinden.
