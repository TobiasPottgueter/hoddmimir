# Backupziel-Kandidatenprojektion

Status: lokal implementierte, authentifizierte GET-Projektion aus Phase 4/6;
reale PVE-/PBS-Abnahme bleibt Phase 7

Ein Kandidat ist genau ein beobachtetes PVE-Storage innerhalb seines
PVE-Clusters. Die Projektion ist read-only, cursor-paginiert und auf höchstens
100 Kandidaten pro Seite begrenzt. Kapazitätswerte werden als vorzeichenlose
kanonische UInt64-Dezimalstrings von `0` bis `18446744073709551615` geliefert,
damit JavaScript oder JSON keine großen `BIGINT`-Werte runden. Führende Nullen,
Vorzeichen und Werte außerhalb dieses Bereichs werden fail-closed abgelehnt.

Für jeden aktiven Cluster-Node wird serverseitig eine eigene Evidenz erzeugt.
Fehlender Node-Storage-State bleibt sichtbar und wird nicht als deaktiviert,
null Byte oder anderweitiger Default gedeutet. Die geschlossenen Blocker
unterscheiden unter anderem fehlende Evidenz, disabled/inactive und
unavailable/invalid Capacity.

Der gemeinsame Freshness-Grenzwert beträgt standardmäßig 300 Sekunden und ist
als Deployment-Konfiguration änderbar. Die Grenze ist inklusiv; die erste
Mikrosekunde danach ist stale. Inventar-, Node-/Kapazitäts-, PBS-Mapping- und
Executor-Nachweise werden einzeln und fail-closed geprüft. Ein HTTP-Parameter
darf diese serverseitige Fachentscheidung nicht ersetzen.

Die Projektion unterscheidet für Storage-Inventar, Node-State, Kapazität,
PBS-Mapping und PBS-Kapazität jeweils `missing`, `stale` und `future` über
eigene geschlossene Blockercodes. Ein Beobachtungszeitpunkt exakt 300 Sekunden
vor `now` ist frisch; die erste Mikrosekunde davor ist stale. Ein
Beobachtungszeitpunkt nach `now` ist niemals frisch. Die Uhr wird einmal pro
Projektionsseite gelesen, sodass alle Evidenzen einer Antwort dieselbe Grenze
verwenden.

Für konfigurierte Ziele projiziert der Readmodel-Adapter die erwarteten und
beobachteten Node-Evidenzen aus `executor_permission_evidence`. Nur eine
vollständige, frische Evidenz mit `VM.Backup` und
`Datastore.AllocateSpace` gilt als autorisiert; fehlende, partielle, veraltete,
zukünftige oder negative Evidenz bleibt über geschlossene Blockercodes
fail-closed sichtbar. Ein noch nicht konfigurierter Storage-Kandidat trägt den
Status `requires_target_configuration` und erfindet keine Executor-Evidenz.

## PBS-Grenze

`mapping.server` und `mapping.port` sind freie, von PVE beobachtete
Konfigurationswerte und keine relationale Hoddmímir-Identität. Ein exakter
Host-/Port-Vergleich gegen konfigurierte PBS-Endpunkte erzeugt daher nur die
typisierte Evidenz `matched`, `unresolved` oder `ambiguous`:

- nur genau ein aktiver Endpoint-Match darf weitere PBS-Evidenz zuordnen;
- PBS Connection, Server, Datastore und exakter Namespace müssen aktiv und
  eindeutig sichtbar sein;
- ein Match beweist weder TLS-Identität noch Zertifikatskontinuität;
- S3-`local_cache` ist kein Remote-Kapazitätsnachweis und blockiert mit
  `pbs_remote_capacity_unproven`.

Ein konfiguriertes PBS-Ziel persistiert eine explizite relationale Bindung an
Connection, Datastore und optionalen Namespace. Der beobachtete Host-/Port-
Match der Kandidatenprojektion bleibt davon getrennte Evidenz und erfindet
weder eine relationale Identität noch einen TLS-Nachweis.

## HTTP-Vertrag

`GET /api/v1/backup-target-candidates` veröffentlicht exakt diese Projektion.
Die optionalen Filter sind `connectionId` und `clusterId`; `limit` ist auf
`1..100` begrenzt und standardmäßig `50`. Die Fortsetzung verwendet einen
opaken Cursor, der an beide Filter gebunden ist. Es gibt weder Offset-, Node-,
`canEnable`- noch Freshness-Queryparameter. Unbekannte, Array-, malformed oder
cross-filter Querywerte liefern ausschließlich `400 invalid_query`.

Ein Ausfall oder eine inkonsistente Zeile der Read-Projektion wird ohne SQL-,
Endpoint-, TLS- oder Secretdetails als `503 read_model_unavailable`
veröffentlicht. Der Endpoint bleibt GET-only; insbesondere existiert hier kein
Command zum Aktivieren eines Ziels.

Die gemeinsame Phase-6-API schützt auch diese Route durch Session-
Authentifizierung und `inventory.read`. Die WebApp-Bindung bleibt bis zur
expliziten Reverse-Proxy-/TLS-Abnahme in Phase 7 auf Host-Loopback begrenzt.
Der Datenbankbenutzer `hoddmimir_web` besitzt nur die expliziten Spalten-
SELECTs der Projektion und keine DML-, Secret-, Sync-Run-, TLS- oder
vollständigen Endpoint-Rechte.
