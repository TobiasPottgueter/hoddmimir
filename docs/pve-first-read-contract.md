# PVE-7/8/9-Vertrag für den ersten Read-Slice

Stand: 10. Juli 2026

## Zweck und Nachweisgrenze

Dieses Dokument fixiert den ersten ausschließlich lesenden PVE-Vertrag für
Hoddmímir. Der Slice umfasst genau diese API2-JSON-Aufrufe:

- `GET /version`;
- `GET /access/permissions` für die eigene technische Identität;
- `GET /cluster/status`;
- `GET /cluster/resources` ohne Typfilter.

Die zugehörigen Fixtures unter
`backend/tests/Fixtures/Proxmox/Pve/{7,8,9}` sind aus den offiziellen
API-Viewer-Schemas konstruiert. Sie enthalten keine von einem echten System
aufgezeichneten Antworten. Damit können wir Envelope-, Pflichtfeld- und
Forward-Compatibility-Verhalten beginnen zu implementieren. Sie sind **kein
Live- oder Release-Nachweis** für PVE 7, 8 oder 9. Dafür bleiben die in
`rewrite-plan.md` geforderten Read-only-Smoke-Tests gegen die letzten
gepatchten Releases, einschließlich ACL-Filter- und TLS-Fehlerfällen,
verpflichtend.

Alle Pfade in diesem Dokument sind relativ zu
`https://<endpoint>:8006/api2/json`.

## Aktivierungs- und Testgrenze dieses Slice

Dieser Read-Slice ist über den produktiven `EndpointInstallationReader` und den
Collector-Runtime-Loop im Dependency-Injection-Container aktiviert. Die
endpointgebundene sichere Factory, der revisionsgebundene
MariaDB-Konfigurations-Read und der produktive GET-only-Core-Reader sind in
[`pve-runtime-core-reader.md`](pve-runtime-core-reader.md) dokumentiert. Die
lokalen Unit-, Contract- und MariaDB-Tests ersetzen weiterhin keinen echten
TLS-Handshake.

Lokale Integrations- und Live-Tests für System-CA, Custom-CA sowie korrekten
und falschen SHA-256-Zertifikatsfingerprint bleiben deshalb ein Phase-2- und
Release-Gate. `NativeHttpClient` ist aktuell mit 30 Sekunden Inaktivitätslimit
und 30 Sekunden Gesamtdauer konfiguriert. Ein separater Connect-Richtwert von
5 Sekunden bleibt offen, weil der Native-Transport dafür derzeit keine
portable, getrennte Option bereitstellt. Diese Punkte gelten ausdrücklich
nicht als durch die Optionstests erledigt.

## Offizielle Quellen und gepinnter Schema-Stand

Abrufdatum aller Webquellen und Schema-Assets: **10. Juli 2026**.

| Major | offizieller Dokumentationsstand | API Viewer | SHA-256 des vollständigen `apidoc.js` |
|---|---:|---|---|
| PVE 7 | 7.4, 22. März 2023 | [Viewer](https://pve.proxmox.com/pve-docs-7/api-viewer/index.html) · [Schema-Asset](https://pve.proxmox.com/pve-docs-7/api-viewer/apidoc.js) | `125f0af24951e901800e49559593678edd95af66da27c88311faecda708ebaf1` |
| PVE 8 | 8.4.0, 9. April 2025 | [Viewer](https://pve.proxmox.com/pve-docs-8/api-viewer/index.html) · [Schema-Asset](https://pve.proxmox.com/pve-docs-8/api-viewer/apidoc.js) | `bbe03a42c55b3f9ae77a5b5216c1a8554f4fffd0f4b266848f4af26be295946e` |
| PVE 9 | 9.2.3, 3. Juli 2026 | [Viewer](https://pve.proxmox.com/pve-docs/api-viewer/index.html) · [Schema-Asset](https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js) | `f2b77b57c71f3781a0993cc5062940ef31e0843fd9a6bcfdb4de4dd2001d6d9e` |

Ergänzende Primärquellen:

- [PVE-API: JSON-Envelope, Authentifizierung und Stabilitätsregeln](https://pve.proxmox.com/wiki/Proxmox_VE_API);
- [PVE-7-Implementierung der Cluster-API](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Cluster.pm;hb=stable-7);
- [PVE-8-Implementierung der Cluster-API](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Cluster.pm;hb=stable-8);
- [aktuelle PVE-9-Implementierung der Cluster-API](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Cluster.pm;hb=master);
- [PVE-Zertifikatsverwaltung](https://pve.proxmox.com/pve-docs/chapter-sysadmin.html#sysadmin_certificate_management);
- [PVE-Berechtigungen und eingeschränkte API-Tokens](https://pve.proxmox.com/pve-docs/chapter-pveum.html);
- offizielle `pve-manager`-Paketindizes für [PVE 7](http://download.proxmox.com/debian/pve/dists/bullseye/pve-no-subscription/binary-amd64/Packages.gz), [PVE 8](http://download.proxmox.com/debian/pve/dists/bookworm/pve-no-subscription/binary-amd64/Packages.gz) und [PVE 9](http://download.proxmox.com/debian/pve/dists/trixie/pve-no-subscription/binary-amd64/Packages.gz) zur Prüfung der `version`-Grammatik.

Die vollständigen Asset- und kanonischen Endpoint-Hashes sowie der exakte
Hash-Algorithmus sind zusätzlich in der
[`Fixture-Provenienz`](../backend/tests/Fixtures/Proxmox/Pve/README.md)
festgehalten. Der aktuelle PVE-9-Viewer ist ein bewegliches Ziel; erst der
Hash macht den hier ausgewerteten Abruf reproduzierbar erkennbar.

## Endpoint- und Pflichtfeldmatrix

Alle erfolgreichen Antworten werden als API2-JSON-Envelope mit einem
Top-Level-Feld `data` erwartet. Die Tabelle nennt unter „offiziell
verpflichtend“ ausschließlich Felder, die das jeweilige Viewer-Schema nicht
als optional markiert. Bedingte Felder bleiben auch dann offiziell optional,
wenn Hoddmímir sie für eine konkrete Inventarart benötigt.

| Endpoint | Query | PVE 7: offiziell verpflichtend | PVE 8: offiziell verpflichtend | PVE 9: offiziell verpflichtend | Rechte und Bedeutung |
|---|---|---|---|---|---|
| `GET /version` | keine | Objekt: `release`, `repoid`, `version` | wie PVE 7; `repoid` zusätzlich 8–64 Hex-Zeichen | wie PVE 8 | für jeden angemeldeten Benutzer; erster Call zur Major-Erkennung |
| `GET /access/permissions` | `path` und `userid` optional; für den Collector ohne `userid` | dynamisches Objekt, keine benannten Pflichtfelder | wie PVE 7 | wie PVE 7 | die eigene Identität darf ihre effektiven Rechte lesen; fremde Identitäten benötigen `Sys.Audit` auf `/access` |
| `GET /cluster/status` | keine | Array; je Eintrag `id`, `name`, `type` | wie PVE 7 | wie PVE 7 | benötigt `Sys.Audit` auf `/`; liefert Cluster- und Node-Zustand oder nur den lokalen Standalone-Node |
| `GET /cluster/resources` | `type` optional (`vm`, `storage`, `node`, `sdn`); Hoddmímir lässt ihn für den Vollscan weg | Array; je Eintrag `id`, `type` | wie PVE 7 | wie PVE 7 | formal für jeden angemeldeten Benutzer, aber serverseitig ACL-gefiltert |

Zusätzliche Hoddmímir-Validierung nach dem offiziellen Baseline-Check:

- Ein `cluster`-Statusobjekt muss für die Identitätsauswertung `name` sowie
  die Zustandswerte `nodes`, `version` und `quorate` liefern.
- Ein `node`-Statusobjekt braucht `name`; bei einem Standalone-System wird
  genau der lokale Eintrag mit `nodeid = 0` und ohne `cluster`-Eintrag
  erwartet.
- Ressourcen vom Typ `qemu` oder `lxc` benötigen für Inventar und Placement
  `node` und `vmid`; ein Storage benötigt mindestens `storage`, `node` und
  `content`. Fehlt ein solches bedingtes Inventarfeld, wird die Ressource nicht
  stillschweigend erfunden oder als entfernt gewertet.
- Storage-Beobachtungen aus `/cluster/resources` bleiben in diesem Slice
  ausschließlich DTOs und führen noch zu keinem `pve_storages`-Upsert. Eine
  neue Storage-Zeile ist erst nach `/storage` und dem Enrichment je Node mit
  explizitem `storage_type`, `supports_backup` und `shared` zulässig. Fehlende
  Enrichment-Daten machen den Lauf `partial`, erzeugen keine neue Zeile und
  erlauben keinen negativen Diff; unbekannte boolesche Werte erhalten niemals
  einen stillen `0`-Default.
- Unbekannte Objektfelder werden ignoriert. Ein unbekannter Ressourcentyp wird
  bewusst ignoriert; er wird niemals als Gast oder Storage uminterpretiert und
  aktiviert ohne explizite versionierte Entscheidung keine Capability.

## Unterschiede im Ressourcenindex

| Änderung | PVE 7 | PVE 8 | PVE 9 |
|---|---|---|---|
| Gast-Template-Markierung | `template` fehlt im offiziellen Ressourcen-Schema; der erste Slice darf Template-Status nicht erfinden | optionales `template` | optionales `template` |
| Gast-I/O und Netzwerk | nicht im Schema | `diskread`, `diskwrite`, `netin`, `netout` additiv | weiterhin optional |
| weitere Gastfelder | keine `tags`/`lock` im Indexschema | `tags`, `lock` additiv | zusätzlich `memhost` für QEMU |
| Node-/Storage-Felder | Baseline | keine hier benötigte neue Baseline | `host-arch` und `shared` additiv |
| neue Ressourcen | `node`, `storage`, `pool`, `qemu`, `lxc`, `openvz`, `sdn` | wie PVE 7 | zusätzlich Typ `network` sowie `network`, `network-type`, `protocol`, `sdn`, `zone-type` |

Die PVE-9-Fixture enthält deshalb sowohl offizielle additive Felder als auch
das absichtlich unbekannte Feld `future-observation`. Das erzwingt die
vorgesehene Regel „benötigte Felder streng, Zusatzfelder tolerant“, ohne eine
neue Capability allein wegen eines Zusatzfelds freizuschalten.

## ACL-Filterrisiko und Vollständigkeit

`GET /cluster/resources` trägt im Viewer zwar `user: all`, ist aber **keine
ungefilterte Clusteraufnahme**. Die offizielle Implementierung prüft während
des Aufbaus unter anderem:

- `VM.Audit` je `/vms/{vmid}`;
- `Pool.Audit` je `/pool/{pool}`;
- `Sys.Audit` je `/nodes/{node}` (ohne das Recht können Node-Details reduziert
  sein);
- `Datastore.Audit` je `/storage/{storage}`;
- `SDN.Audit` je SDN-Pfad.

Ein HTTP-200 kann deshalb eine syntaktisch korrekte, aber unvollständige Liste
enthalten. Bei separierten API-Tokens sind die effektiven Tokenrechte zudem
immer eine Teilmenge der Rechte des zugehörigen Benutzers.

Folgerungen für den Collector:

1. Die effektiven Rechte werden vor der Inventarisierung mit
   `/access/permissions` bewertet.
2. Fehlende Mindestpfade oder ein fehlendes `/cluster/status`-Recht machen
   den Lauf unvollständig und sichtbar fehlerhaft.
3. Das Fehlen eines Objekts in einer ACL-gefilterten oder sonst teilweisen
   Antwort ist niemals ein autoritativer Entfernungsnachweis.
4. Erst ein vollständig erfolgreicher Lauf mit bestätigter Rechteabdeckung
   darf den sicheren Inventar-Diff als vollständig markieren.

## TLS-Fingerprint-Semantik

Produktionsverbindungen verwenden entweder die normale CA- und
Hostname-Verifikation oder ein ausdrücklich konfiguriertes
SHA-256-Zertifikat-Pinning. Für das Pinning gilt im eigenen Transport:

- Der Fingerprint ist der SHA-256-Digest des DER-kodierten, vom PVE-API-Port
  präsentierten **Leaf-Zertifikats**.
- Eingaben dürfen als 32 Doppelhex-Oktette mit Doppelpunkten oder als 64
  Hex-Zeichen normalisiert werden; intern werden exakt 32 Bytes
  verglichen. Groß-/Kleinschreibung und Doppelpunkte sind nur Darstellung.
- Der Vergleich erfolgt vor Authentifizierungsdaten und ohne stillen Fallback,
  TOFU oder deaktivierte TLS-Prüfung. Eine Abweichung ist ein harter
  Verbindungsfehler.
- Ein Pin ist endpointbezogen und muss bei einem legitimen Zertifikatswechsel
  kontrolliert ersetzt werden.

PVE erzeugt standardmäßig je Node ein eigenes API-Zertifikat, das von der
clusterweiten PVE-CA signiert ist; ein Node kann außerdem ein eigenes
externes/ACME-Zertifikat für `pveproxy` verwenden. Der Leaf-Fingerprint kann
daher zwischen Nodes desselben Clusters verschieden sein und ist **keine
Cluster-ID**.

## Clusteridentität

`GET /cluster/status` enthält für einen echten Cluster einen Eintrag mit
`type = "cluster"`, dem konstanten `id = "cluster"` und dem konfigurierten
Clusternamen in `name`. Weder `id` noch `name` ist eine global eindeutige,
unveränderliche UUID. `version` ist nur die veränderliche Corosync-
Konfigurationsversion. Bei einem Standalone-PVE liefert die offizielle
Implementierung stattdessen genau einen künstlichen lokalen Node-Eintrag mit
`nodeid = 0` und keinen Cluster-Eintrag.

Damit kann dieser Slice:

- Clusterbetrieb von Standalone-Betrieb unterscheiden;
- Clusternamen und beobachtete Node-Mitgliedschaft vergleichen;
- widersprüchliche Endpoints sicher ablehnen.

Er kann aber nicht allein kryptografisch beweisen, dass zwei Endpoints zum
selben Cluster gehören. Hoddmímir erzeugt deshalb eine eigene dauerhafte
interne Cluster-ID. Mehrere konfigurierte Endpoints dürfen ihr erst nach
konsistenter, explizit zugeordneter Verbindungskonfiguration und erfolgreicher
Mitgliedschaftsprüfung zugeordnet werden. Clusternamen, sortierte Node-Listen
und Leaf-Fingerprints werden weder einzeln noch zusammen als globale
Primärschlüssel verwendet. Ein späterer zusätzlicher Nachweis (zum Beispiel
über eine bewusst abgerufene gemeinsame Cluster-CA) ist eine eigene,
versionierte Capability und nicht Bestandteil dieses ersten GET-Slice.
