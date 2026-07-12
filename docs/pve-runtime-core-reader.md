# Produktiver PVE-Core-Endpoint-Reader

Stand: 11. Juli 2026

## Umfang und Aktivierungsgrenze

Dieser Reader stellt den produktiven `EndpointInstallationReader` für das
PVE-Core- und Storage-Inventar bereit. Er ist ausschließlich lesend und führt
pro ausgewähltem Endpoint die Core-Requests aus:

- `GET /version`;
- `GET /access/permissions`;
- `GET /cluster/status`;
- `GET /cluster/resources`;

danach folgen auf demselben Connector die in
[`pve-storage-inventory-persistence.md`](pve-storage-inventory-persistence.md)
festgelegten Storage-Requests mit Config-Start/Ende und sortiertem Node-Fanout.

Backupjobs, Tasks und jede schreibende Route bleiben außerhalb. PBS wird über
den getrennten Reader aus
[`pbs-runtime-inventory-persistence.md`](pbs-runtime-inventory-persistence.md)
bedient; der strikte Dispatcher erzeugt keine PVE/PBS-Cross-Calls. Dieser
PVE-Reader ist im Symfony-DI-Container ausschließlich mit dem
dedizierten Collector-Runtime-Loop verdrahtet. Jeder Request verwendet den
fenced `ClaimedCycleCheckpoint`; es existiert kein produktiver Noop-Checkpoint
und kein vorgetäuschter Scanlauf.

## Revisions- und Lease-Grenze

`EndpointInstallationReader::read()` verlangt die erwartete
Connection-Revision und einen `ConnectionReadCheckpoint`. Ein sicherheits-
kritischer optionaler beziehungsweise Noop-Pfad existiert nicht.

`PveHttpTransport` checkpointet unmittelbar vor jedem physischen HTTP-Versuch,
also auch vor jedem GET-Retry. Der zweite Checkpoint läuft in `finally`, aber
erst nachdem der `PveTokenAuthenticator`-Callback und damit der gesamte
Klartext-/Authorization-Scope geschlossen ist. Eine Checkpoint-Ausnahme wird
unverändert weitergegeben. Sie wird weder als PVE-Fehler umgedeutet noch durch
einen Retry übergangen.

`ReadConnectionWithFailover` reicht dieselbe erwartete Revision und denselben
Checkpoint an jeden Endpoint-Versuch weiter. Meldet die Konfigurationsquelle
vor dem ersten Remote-Request eine Revisionsabweichung, entsteht der typisierte
Connection-Fehler `connection_changed`; es findet weder ein HTTP-Request noch
Endpoint-Failover statt.

## Atomarer Konfigurations-Read und Secret-Grenze

`DbalPveEndpointReadConfigurationSource` liest in genau einem konsistenten
SELECT:

- die aktivierte PVE-Connection und ihre erwartete Revision;
- den der Connection gehörenden, aktivierten Endpoint;
- genau das Collector-Credential mit `auth_scheme = api_token`.

Die Quelle entschlüsselt nichts. Der Credential-Identifier bleibt als
`BINARY(16)` in MariaDB und wird über den gemeinsamen
`SecretContext::forBinaryCredentialId()`-Vertrag deterministisch zu exakt 32
lowercase Hex-Zeichen. Dadurch verwenden der jetzige Reader und spätere
Credential-Writer dieselbe Additional-Authenticated-Data-Identität.

Das secrettragende `PveEndpointReadConfiguration` bleibt Infrastructure,
redigiert seine Debug-Darstellung vollständig und verweigert Serialisierung.
Das `EncryptedSecret` verlässt diesen Infrastructure-Scope nicht. Erst
`PveTokenAuthenticator::authorize()` ruft `SecretCipher::decrypt()` auf; der
Klartext wird nur im Callback zum Authorization-Header zusammengesetzt.

## TLS- und Fehlervertrag

Die drei Datenbankmodi werden vollständig und exklusiv abgebildet:

- `system_ca`: kein zusätzliches Trust-Material;
- `custom_ca`: validiertes PEM-Bundle, keine Fingerprint-Spalte;
- `sha256_fingerprint`: exakt 32 Binärbytes, keine CA-Spalte.

Der Schema-Constraint begrenzt `custom_ca_pem` zusätzlich auf 262.144 Bytes.
Der Native-Client erzwingt immer Peer- und Hostprüfung und verbietet Redirects.
Ein typisierter Initialisierungs- oder Materialisierungsfehler wird als `tls`
gemeldet. Ein TLS-Handshakefehler des Symfony-Native-Transports bleibt ehrlich
`transport`: Eine vermeintliche TLS-Erkennung anhand veränderlicher Exception-
Texte ist ausdrücklich verboten.

`PveReadFailureCode` wird über eine vollständige `match`-Tabelle auf stabile
Endpoint-Codes abgebildet. Authentifizierung, Berechtigung und nicht verfügbares
Credential sind terminal. Transport, Rate-Limit und Remote-Unavailable erlauben
Endpoint-Failover. Nicht verwertbare Envelopes/Responses werden `root_unusable`,
eine nicht unterstützte Major-Version wird
`unsupported_product_or_version`. Datenbank-/Treiberfehler werden nicht in
Remote-Fehler umgedeutet und propagieren.

## Bewusste Custom-CA-Begrenzung

Der bestehende Custom-CA-Materializer schreibt content-addressed in ein
dediziertes, validiertes Verzeichnis mit Modus `0700`; Dateien erhalten `0600`,
Symlink- und Inhaltskonflikte werden abgewiesen. Die Dateien sind derzeit
bewusst langlebig und nicht per Read-Lifecycle gelöscht.

Ein per-Read-Cleanup wurde in diesem Slice nicht halb implementiert: Eine
vollständige Lösung muss konkurrierende Reads desselben CA-Bundles, offene
Native-HTTP-Streams, Prozessabbruch und symlink-sichere Löschung gemeinsam
beherrschen. Dieser scoped Lifecycle bleibt P2. Bis dahin darf das
Materialisierungsverzeichnis nur nicht geheimes öffentliches CA-Material
enthalten und muss durch die Deployment-Dateirechte geschützt bleiben.

## Testnachweis

Die Unit-/Contract-Tests prüfen:

- PVE 7, 8 und 9 gegen die exakte Composite-GET-Sequenz;
- Checkpoint-Reihenfolge für physische Retries und geschlossenen
  Klartext-Scope;
- identische Checkpoint-Ausnahmen ohne Retry;
- vollständiges typisiertes Failure-Mapping ohne Message-Parsing;
- alle TLS-Modi, Secret-Redaction und verbotene Serialisierung;
- Revisionsdrift vor Remote-I/O und fehlende Produktions-Noops.

Der reale MariaDB-Gate prüft zusätzlich Eigentum/Aktivierung des Endpoints,
Collector-Credential, Revision und den erweiterten CA-Längenconstraint. Live-
TLS-Smokes gegen gepatchte PVE-7/8/9-Systeme bleiben ein Release-Gate.
