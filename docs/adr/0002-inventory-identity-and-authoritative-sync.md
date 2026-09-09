# ADR 0002: Inventaridentität und autoritative Synchronisation

- Status: akzeptiert
- Datum: 10. Juli 2026

## Kontext

Mehrere PVE-Endpunkte können denselben Cluster bereitstellen. Gleichzeitig darf
ein unerreichbarer Endpunkt oder Node nicht dazu führen, dass bekannte Nodes,
Gäste, Placements oder Storages irrtümlich verschwinden. QEMU und LXC müssen als
gleichberechtigte Gäste behandelt werden.

## Entscheidung

- Eine `proxmox_connection` beschreibt genau eine konfigurierte PVE- oder
  PBS-Installation. Alle zugehörigen Endpunkte sind Failover-Wege zu derselben
  Installation und erzeugen kein getrenntes Inventar.
- Der erste Meilenstein modelliert pro PVE-Connection genau einen Cluster oder
  einen logischen Standalone-Cluster.
- Fachliche Identitäten sind:
  - Node: `(cluster_id, node_name)`;
  - Gast: `(cluster_id, guest_type, vmid)`;
  - Storage: `(cluster_id, storage_name)`.
- Der von der API gelieferte Gastname und der Template-Status können unbekannt
  sein und werden dann als `NULL` gespeichert. Es werden keine Ersatznamen oder
  vermeintlichen Standardwerte erfunden. Für PVE 7 wird das Inventar später
  gezielt angereichert; ein UI-Fallback bleibt reine Darstellung und wird nicht
  als Inventardatum persistiert.
- Unvollständige Storage-Metadaten bleiben eine DTO- beziehungsweise
  Teilbeobachtung. Eine neue `pve_storages`-Zeile entsteht erst nach `/storage`
  und der Enrichment-Abfrage je Node mit explizitem `storage_type`,
  `supports_backup` und `shared`. Fehlende Enrichment-Daten machen den Lauf
  `partial`, erzeugen keine neue Storage-Zeile und erlauben keinen negativen
  Diff. Ausschließlich im normalisierten `/storage`-Config-Record haben die
  fehlenden Felder `disable` und `shared` die von PVE definierte
  `false`-Semantik. Die Statusfelder `enabled`, `active` und `shared` aus dem
  Node-Read sind dagegen erforderlich; unbekannte Status-Booleans werden
  niemals als `false` erfunden.
- Der `storage.cfg`-Digest ist eine globale Eigenschaft des gesamten
  Configuration-Sets. Ein autoritativer Enrichment-Lauf vergleicht neben dem
  Start-/End-Digest auch die normalisierte sichtbare Definitionsmenge, weil
  die Storage-Endpunkte ACL-gefiltert sind.
- Der für Kapazität erforderliche
  `GET /nodes/{node}/storage?content=backup` kann serverseitig
  `activate_storage()` und damit beispielsweise einen Mount auslösen. Der
  Backup-Content-Filter ist zwingend und begrenzt diese operative Wirkung auf
  relevante Storages. Der Collector startet dabei keinen Backup-Task, der
  Status-GET ist auf dem Node aber nicht vollständig nebenwirkungsfrei.
- Das aktuelle Gast-Placement ist eine eigene 1:1-Beziehung. Zusammengesetzte
  Fremdschlüssel verhindern Placements und Node-Storage-Zustände über
  Clustergrenzen hinweg.
- Jeder Connection-Scan endet als `succeeded`, `partial`, `failed` oder
  `abandoned`. Nur `succeeded` ist autoritativ.
- Teil- und Fehlläufe dürfen sicher beobachtete Daten anlegen und aktualisieren.
  Sie dürfen niemals aus der Abwesenheit eines Objekts schließen, Placements
  entfernen oder Objekte archivieren.
- Nur ein vollständig erfolgreicher autoritativer Scan führt den negativen Diff
  aus. Seen-Marker, Placementwechsel, Archivierung/Reaktivierung und Abschluss
  des Sync-Laufs erfolgen in einer expliziten Transaktion.
- Wiederkehrende Objekte behalten ihre fachliche ID und werden reaktiviert.
- Strukturierte Teilfehler werden mit Scope und redigiertem Fehlercode am
  Sync-Lauf gespeichert. Credentials oder API-Antworten mit Secrets sind dort
  unzulässig.

## Folgen

- Ein Node-Ausfall erzeugt einen sichtbaren Teilfehler, aber keine destruktive
  Inventaränderung.
- Inaktive, von PVE initialisierte Kapazitätswerte `0/0/0` gelten als
  unbekannt und niemals als gemessene Nullkapazität.
- Idempotente Upserts verwenden die natürlichen Unique Keys und stabilisieren
  interne IDs über beliebig viele Collector-Zyklen.
- Placement-Historie ist ein späteres additives Schema; das Foundation-Schema
  speichert bewusst nur den aktuellen, schedulerrelevanten Zustand.
