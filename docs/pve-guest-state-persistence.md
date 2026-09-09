# PVE Guest Placement und roher Write-State

Status: verbindlicher Persistenzvertrag für Phase 4.1

## Placement-Revision

`guest_placements.placement_revision` beginnt bei der ersten bekannten
Platzierung mit `1`. Eine erneute Beobachtung desselben Nodes aktualisiert nur
`observed_at` und `sync_run_id`. Nur wenn sich die beobachtete `node_id`
tatsächlich ändert, wird die Revision innerhalb derselben gefenceten
Inventory-Transaktion um genau eins erhöht.

Beim Archivieren eines Gasts bleibt seine letzte Platzierungszeile erhalten.
Sie ist durch Gaststatus und Beobachtungszeit ausdrücklich keine aktuelle
Eligibility-Aussage. Dadurch bleibt die Revision auch über eine vorübergehende
Abwesenheit monoton: Eine Rückkehr auf denselben Node ändert sie nicht, eine
Rückkehr auf einen anderen Node erhöht sie. Scheduler-Entscheidungen dürfen
archivierte oder veraltete Platzierungen nicht verwenden.

## Roher PVE-Counter

`guest_write_states` ist ausschließlich die letzte autoritativ beobachtete
Projektion des von PVE gelieferten Guest-Felds `diskwrite`:

- `diskwrite_bytes` speichert den unveränderten, nicht negativen Integer;
- `observed_at` ist der UTC-Zeitpunkt der Installation-Beobachtung;
- `authoritative_sync_run_id` verweist auf den Sync-Lauf, dessen Guest-Scope
  vollständig war.

Ein fehlendes oder ungültiges Feld überschreibt einen älteren Stand nicht.
Ein Teilread aktualisiert den Write-State ebenfalls nicht. Ein kleinerer
späterer Counter wird dagegen unverändert gespeichert: Diese Schicht deutet
weder Counter-Reset noch Wraparound, Baseline, Cooldown oder Scheduler-Grund.
Diese Semantik wird erst in einer gesonderten, fachlich entschiedenen Schicht
aufgebaut.

Archivierte Gäste behalten den zuletzt beobachteten Write-State. Gaststatus
und `observed_at` machen ihn für Eligibility ungeeignet, bewahren aber die rohe
Beobachtung ohne eine erfundene Reset-Aussage.

## Berechtigungen

Nur der Collector erhält `SELECT`, `INSERT` und `UPDATE` auf
`guest_write_states`. Er erhält kein `DELETE` auf diese Tabelle; die nicht mehr
benötigte `DELETE`-Berechtigung auf `guest_placements` wird ebenfalls entzogen.
WebApp und Backup-Worker erhalten über diese Migration keine neue
Leser- oder Schreibberechtigung.
