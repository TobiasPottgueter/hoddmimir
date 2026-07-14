# PBS-3/4-Vertrag für den ersten lesenden Slice

Stand: 11. Juli 2026

## Grenze

Dieser Vertrag definiert den typisierten, positiven Server-, Node-, Datastore-
und Kapazitäts-Snapshot. Namespaces, Backup-Gruppen, Snapshots, Tasks und
Schreiboperationen bleiben außerhalb. Die inzwischen aktivierte Collector-
Verdrahtung, Identitätsbindung, Partial-/Diff-Regeln und Persistenz sind in
[`pbs-runtime-inventory-persistence.md`](pbs-runtime-inventory-persistence.md)
festgelegt. Der produktive Runtime-Scope ist ausschließlich installationsweit;
der explizite Scope bleibt ein getesteter Adaptervertrag, ist aber nicht mit
dem Collector verdrahtet.

Die Reihenfolge ist fest: `/version` ist immer der erste Call, gefolgt von
`/ping`. Erst nach beiden erfolgreichen Produktprüfungen darf der Client
weitere PBS-Daten lesen. Danach folgen `/nodes`, die effektive Berechtigung für
`/system/status`, der Node-Status und ab PBS 4.2 die Instanzidentität. Die
Datastore-Rechte werden relativ zu einem expliziten Scan-Scope geprüft.

Für einen konsistenten Datastore-Snapshot werden anschließend
`/config/datastore`, `/admin/datastore`, je Scope-Datastore
`/admin/datastore/{store}/status?verbose=0` und erneut `/config/datastore`
gelesen. Nur gleicher Top-Level-Digest und gleiche sichtbare ID-Menge bilden
einen stabilen Config-Fence. `/status/datastore-usage` wird bewusst nicht
verwendet.

## Authentifizierung und Transport

PBS-API-Tokens verwenden exakt
`Authorization: PBSAPIToken user@realm!tokenname:secret`. PBS 3 und 4 erzeugen
das Secret als kanonische kleingeschriebene UUID; für dieses Alphabet ist die
Percent-Kodierung des offiziellen Clients eine Nulloperation. Hoddmímir prüft
die UUID innerhalb des begrenzten Plaintext-Callbacks und erweitert das Format
nicht stillschweigend.

Alle Routen sind feste GET-Deskriptoren mit Antwortgrößenlimit. TLS verwendet
System-CA, eine materialisierte eigene CA oder einen endpointbezogenen
SHA-256-Leaf-Pin. Die CA-Modi prüfen Peer und Host; nur im exklusiven Pin-Modus
ersetzt der exakte Digest beide Prüfungen und ein falscher Pin scheitert vor
jedem HTTP-Header. Das ist kein globaler `insecure`-Modus. Redirects sind
deaktiviert. Ein echter TLS-Handshake-Test und Read-only-Smokes gegen die
letzten PBS-3-/4-Patches bleiben Release-Gates. Die
[offizielle PBS-4-Client-Dokumentation](https://pbs.proxmox.com/docs/backup-client.html)
bestätigt den Fingerprint als Serverzertifikatsprüfung, wenn die System-CA das
Zertifikat nicht validieren kann.

## Berechtigungs- und Vollständigkeitssemantik

Ein installationsweiter Scope benötigt propagiertes `Datastore.Audit` auf
`/datastore`. Ein expliziter Scope prüft `Datastore.Audit` auf jedem
konfigurierten `/datastore/{store}` einzeln und behauptet nichts über andere
Datastores. Das Boolean-Ergebnis von `/access/permissions` beschreibt die
Propagation; bereits die Anwesenheit eines Privilegs bedeutet den effektiven
Grant am exakt abgefragten Pfad.

ACL-gefilterte HTTP-200-Antworten sind allein nie ein
Vollständigkeitsnachweis. Fehlende Rechte, IDs, Statusantworten, ein geänderter
Digest oder eine geänderte sichtbare Konfiguration machen den Snapshot
partial und verbieten negative Diffs.

## Versionsunterschiede

- Die PBS-Produktversion steht in `version`; `release` ist das Paket-Release.
- `/nodes` ist im Viewer unzutreffend als `null` beschrieben; die gepinnte
  Serverimplementierung liefert genau eine Zeile mit `node`.
- PBS 3 besitzt nur Filesystem-Datastores in diesem Vertrag.
- PBS 4.2 beschreibt ein S3-Backend in `/config/datastore` als kodierten
  `backend`-Property-String (unter anderem `bucket`, `client` und `type=s3`),
  nicht als gleichnamige Top-Level-Felder.
- PBS 4 liefert `backend-type`; bei `s3` bezeichnen `total`, `used` und `avail`
  nur den lokalen Cache. Diese Werte dürfen kein Remote-Free-Space-Gate
  erfüllen.
- Status-Requests dieses Slices verwenden `verbose=0`; die Fixtures enthalten
  deshalb weder Content-`counts` noch unvollständige `s3-statistics`.
- Ab PBS 4.2 liefert `/nodes/{node}/identity` eine aus `machine-id` abgeleitete
  32-stellige `pbs-instance-id`. Vor 4.2 wird der Endpoint nicht aufgerufen.

## Gepinnte Primärquellen

- [PBS 3 API Viewer](https://pbs.proxmox.com/docs-3/api-viewer/index.html),
  kanonischer `apidoc.js`-SHA-256 beginnend mit `2ba388`, Dokumentation 3.4.4;
- [PBS 4 API Viewer](https://pbs.proxmox.com/docs/api-viewer/index.html),
  kanonischer `apidoc.js`-SHA-256 beginnend mit `c62063`, Dokumentation 4.2.2;
- offizielles `proxmox-backup.git`, PBS-3.4.0-Quelle beginnend mit `36ef1b`
  und PBS-4.2.0-Quelle beginnend mit `035c449`;
- Identitätsänderung beginnend mit `897df9`.

Relevante Quelldateien sind `src/api2/version.rs`, `src/api2/node/mod.rs`,
`src/api2/access/mod.rs`, `src/api2/config/datastore.rs`,
`src/api2/admin/datastore.rs`, `src/auth.rs` und
`pbs-client/src/http_client.rs`.
