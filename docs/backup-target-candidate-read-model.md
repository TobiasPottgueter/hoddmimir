# Backupziel-Kandidatenprojektion

Status: Backend-Projektionskern und GET-only HTTP-Projektion aus Phase 4.1

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

Es existiert noch kein fachlich festgelegter Freshness-Grenzwert. Deshalb
liefert die Projektion rohe UTC-Beobachtungszeiten und blockiert `canEnable`
mit `freshness_policy_unconfigured`. Ein HTTP-Parameter darf diese
serverseitige Fachentscheidung später nicht ersetzen.

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

Eine spätere aktivierbare PBS-Bindung benötigt eine explizite relationale
Identität und einen abgenommenen TLS-/Connection-Vertrag. Diese Projektion
erfindet beides nicht.

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

Bis Authentifizierung und RBAC vorhanden sind, bleibt auch diese Route auf die
bestehende Host-Loopback-Bindung begrenzt. Der Datenbankbenutzer
`hoddmimir_web` besitzt nur die expliziten Spalten-SELECTs der Projektion und
keine DML-, Secret-, Sync-Run-, TLS- oder vollständigen Endpoint-Rechte.
