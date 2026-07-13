# Phase 5: Backup Worker und UPID-Monitoring

Status: **lokale Implementierung am 13. Juli 2026 abgeschlossen.** Die reale
PVE-Labmatrix ist auf ausdrücklichen Nutzerauftrag Bestandteil von Phase 7;
Phase 7 wurde noch nicht begonnen.

Dieses Dokument konkretisiert Phase 5 aus dem
[`rewrite-plan.md`](rewrite-plan.md). Die fachlichen Scheduler- und
Freshness-Regeln aus
[`phase-4-policy-shadow-plan.md`](phase-4-policy-shadow-plan.md) bleiben
verbindlich. Der bestehende typisierte PVE-Schreibvertrag ist in
[`pve-backup-write-contract.md`](pve-backup-write-contract.md) beschrieben.

## Lokaler Abschlussstand

Der lokale Phase-5-Umfang ist implementiert:

- atomare Queue, Lease/Fence, Revalidation, Slots und Kapazitätsreservierung;
- Single-attempt-Submission, persistierte Crashgrenze und Reconciliation ohne
  automatischen zweiten `vzdump`-POST;
- UPID-Monitoring, idempotente Logs, kontrollierte Retries und terminale
  Ressourcenfreigabe;
- expliziter At-most-once-Cancelvertrag mit `dispatching`, den belegbaren
  Ergebnissen `requested`, `ambiguous` und `definitive_rejection` sowie dem
  sichtbaren Crashzustand `dispatch_unknown`;
- Matrix-Outbox, Recovery-/Attention-Meldungen und fail-closed Aktivierung;
- versionierte Operations-API, generierter TypeScript-Client und die lokalen
  Operations-Flows.

Die lokale Abnahme umfasst Unit-/Contract-Tests, echte MariaDB-11.4-Tests samt
Up/Down/Up-Migration, Least-Privilege-Prüfungen, OpenAPI-Driftprüfung,
Frontend-/Playwright-Flows und eine integrierte MariaDB-/Application-Suite. Die
integrierte Suite belegt zwei konkurrierende Worker bei genau einem
Submission-Dispatch, eine mehrdeutige Submission mit Lease-Takeover und
Reconciliation ohne zweiten Dispatch sowie den Crash vor dem Remote-Dispatch
ohne POST und ohne automatischen Retry. Der vollständige CI- und Coverage-Lauf
muss für jeden eingefrorenen Releasekandidaten erneut grün ausgeführt werden;
dieses Dokument ersetzt keinen solchen Laufbericht.

Sanitisierte PVE-7/8/9-Fixtures für QEMU und LXC sind lokale Contract-Evidenz,
aber ausdrücklich kein Live-Nachweis. Reale Backupstarts, echte
Transportabbrüche und die PVE-7/8/9-QEMU-/LXC-Labmatrix erfolgen erst in Phase
7. Es wurden dafür in Phase 5 keine Lab-Systeme eingerichtet und keine
Live-Ergebnisse dokumentiert.

## Ziel und Sicherheitsgrenze

Phase 5 liefert den einzigen Prozess, der PVE-Schreibzugriffe ausführen darf:

- Shadow-Entscheidungen idempotent in persistente Backup-Requests überführen;
- Requests atomar claimen und mit monotonem Fence gegen alte Worker schützen;
- Auswahl, Placement, Ziel, Kapazität und Executor-Berechtigung unmittelbar
  vor dem PVE-Schreibaufruf erneut fail-closed prüfen;
- genau einen `vzdump`-POST absetzen, den zurückgegebenen UPID persistieren und
  Status sowie Log bis zu einem nachvollziehbaren Endzustand überwachen;
- nach Prozessabbruch weiter überwachen und mehrdeutige Antworten ohne
  Doppelstart reconciliieren;
- Cancel-Anforderungen persistieren und ausschließlich durch den Backup Worker
  an PVE weiterleiten.

`BACKUP_EXECUTION_ENABLED=false` verhindert neue PVE-Schreibaufrufe. Bereits
akzeptierte oder möglicherweise gestartete Tasks werden unabhängig vom Flag
weiter überwacht beziehungsweise reconciliiert. Der Collector bleibt read-only,
und die WebApp erhält niemals ein PVE-Credential.

Der Backup-Worker erneuert seinen Heartbeat und die gefencete Request-Lease mit
einem frisch gelesenen UTC-Zeitpunkt vor jedem begrenzten Stop-, Log-, Status-
und Reconciliation-Page-I/O. Reconciliation ist zusätzlich durch höchstens 12
Seiten und standardmäßig 1.200 Sekunden Gesamtlaufzeit begrenzt. Das normale
Poll-Intervall bleibt davon unabhängig bei 5 Sekunden.

## Persistenz und Zustände

Die Live-Persistenz besteht mindestens aus:

- `backup_requests` und append-only `backup_request_events`;
- `backup_runs`, append-only `backup_run_events` und geordnete
  `backup_run_log_entries`;
- `backup_node_slots` und `backup_target_slots`;
- `backup_capacity_reservations`;
- frische, gast- und zielbezogene `executor_permission_evidence`.

Ein Request behält seinen ursprünglichen Grund und seine Priorität. Ein Retry
erhält eine neue ID, verweist aber auf denselben Root-Request. Die
Request-Reihenfolge bleibt `priority DESC`, `scheduled_at`, stabile ID.

Claiming, Revalidation, Node-/Zielslot und Kapazitätsreservierung geschehen in
einer expliziten MariaDB-Transaktion mit `SELECT ... FOR UPDATE SKIP LOCKED`.
Der Claim enthält ein opaques Token, ein Ablaufdatum und einen monotonen Fence.
Jede spätere Mutation prüft Token und Fence. Ein abgelaufener Claim kann nur
mit einem höheren Fence übernommen werden.

Terminale Zustände geben Lease, Node-/Zielslot und Kapazitätsreservierung in
derselben Transaktion frei. Append-only Events dokumentieren jeden akzeptierten
Zustandsübergang; ungültige oder stale Mutationen ändern keine Zeile.

## Startpipeline

1. Nächsten zulässigen Request unter Queue-Reihenfolge sperren.
2. Aktuelles Placement sowie Policy- und Zielrevision sperren und vergleichen.
3. Inventar-, Placement-, Kapazitäts- und Executor-Evidenz gegen das gemeinsame
   Freshness-Fenster prüfen.
4. Erwartete Größe als letzte erfolgreiche V2-Größe plus zehn Prozent
   Sicherheitsaufschlag berechnen; ersatzweise die provisionierte Gastgröße
   verwenden. Fehlen beide Werte, bleibt der Request blockiert.
5. Einen Node-Slot, einen Zielslot und die Kapazität atomar reservieren.
6. Claim und aufgelösten, gehashten Policy-Snapshot persistieren.
7. Nach dem Claim unmittelbar vor dem Schreibaufruf erneut revalidieren.
8. Run in `awaiting_submission` persistieren und danach exakt einen physischen
   `POST /nodes/{node}/vzdump` ausführen.
9. Eine gültige, identitätsgleiche Antwort als `accepted` mit UPID speichern;
   eine definitive Ablehnung als `failed`; jede unklare Antwort als
   `reconcile_required` mit Provenienz `ambiguous`.

Zwischen persistiertem `awaiting_submission` und dem Schreibaufruf besteht eine
bewusste Crash-Grenze: Nach einem Neustart wird nicht blind erneut gesendet.
Der Run geht in Reconciliation, solange nicht beweisbar ist, dass kein Request
an PVE übergeben wurde.

## Monitoring, Log und Recovery

- Status- und Log-Abfragen sind read-only und dürfen die bestehende begrenzte
  Retry-Policy verwenden.
- Logzeilen werden nach PVE-Zeilennummer idempotent gespeichert. Wiederholte
  oder überlappende Seiten erzeugen keine Duplikate.
- Erfolg gilt ausschließlich für `status=stopped` und `exitstatus=OK`.
- Ein gestoppter Task mit anderem Exitstatus wird `failed`.
- Kurzzeitig nicht lesbare Taskdaten lassen den Run nicht terminal werden.
- Nach Lease-Ablauf übernimmt ein Worker denselben Run mit höherem Fence und
  setzt Monitoring beziehungsweise Reconciliation fort.
- Eine mehrdeutige Submission wird über beobachtete `vzdump`-Tasks anhand von
  Node, Gast, Benutzer und engem Startzeitfenster abgeglichen. Genau ein
  identitätsgleicher Treffer übernimmt dessen UPID; mehrere oder unvollständige
  Treffer bleiben `unknown`/reconcile-pflichtig statt einen neuen POST
  auszulösen.
- Selbst ein späterer Nachweis `proven_not_started` hebt das Retry-Verbot einer
  mehrdeutigen Submission nicht auf.

## Cancel

Die WebApp setzt ausschließlich eine revisionierte Cancel-Anforderung. Ein
noch nicht gestarteter Request kann serverseitig ohne PVE-Aufruf abgebrochen
werden. Bei einem laufenden Run führt nur der aktuelle gefencete Backup Worker
höchstens einen `DELETE /nodes/{node}/tasks/{upid}` aus. Vor dem I/O wird ein
`dispatching`-Intent persistiert. Bleibt dieser bei einem Folgetick ohne
Ergebnis, wird er sichtbar und append-only auditiert zu `dispatch_unknown`;
ein solcher Dispatch wird ebenso wie eine unklare Stop-Antwort niemals
wiederholt. Der Task wird weiter überwacht, bis PVE einen Endzustand liefert
oder der Run nachvollziehbar `unknown` bleibt.

## Retry-Policy

Mehrdeutige Submissions sind ausnahmslos nicht wiederholbar. Definitiv
fehlgeschlagene Starts oder Läufe beenden die Backup-Pflicht dagegen nicht:

- kontrollierte Retries werden ohne feste maximale Versuchszahl fortgesetzt,
  bis ein Backup erfolgreich ist oder Gast, Policy beziehungsweise Ziel
  bewusst deaktiviert wird;
- die Wartezeiten betragen nach aufeinanderfolgenden definitiven Fehlern
  1, 5, 15, 30 und anschließend 60 Minuten; weitere Versuche bleiben bei
  60 Minuten gedeckelt;
- Grund und Priorität stammen unverändert vom Root-Request. Der Retry ist
  keine neue Prioritätsklasse;
- unmittelbar vor jedem neuen Versuch werden sämtliche Auswahl-, Placement-,
  Freshness-, Executor-, Kapazitäts-, Duplicate- und Concurrency-Gates erneut
  geprüft;
- ein voller oder nicht frisch belegter Storage, fehlende Berechtigung oder
  ein anderes blockierendes Gate erzeugt keinen PVE-Schreibaufruf. Der Request
  bleibt nachvollziehbar blockiert und wird nach neuer Evidenz erneut
  bewertet;
- ein erfolgreicher Lauf beendet die Retry-Kette und setzt den
  Fehler-/Benachrichtigungszustand zurück.

Der attempt-Zähler ist Teil der Historie, nicht die Abbruchbedingung.

## Matrix-Benachrichtigung

Backup-Probleme werden über einen konfigurierbaren Matrix-Webhook gemeldet.
Die Implementierung verwendet einen typisierten Outbound-HTTP-Port über die
fest im Alpine-Image verfügbare PHP-cURL-Erweiterung; die Webhook-URL wird
ausschließlich als Runtime-Secret geladen und weder geloggt noch über die API
ausgegeben. Ein Shell-`curl` wird bewusst nicht verwendet, damit das Secret
nicht in Prozessargumenten erscheint.

- jeder definitive fehlgeschlagene Start oder Lauf erzeugt genau eine
  Problemsmeldung. Folgefehler werden ausdrücklich nicht dedupliziert: Die
  Meldung enthält die fortlaufende Versuchsnummer, Gastname, Gasttyp, VMID,
  Knoten, Backupziel, einen stabilen nicht sensitiven Fehlercode, Fehlerzeit
  und den Zeitpunkt des nächsten geplanten Versuchs;
- ein blockierendes Gate wie fehlende Kapazität oder stale Evidenz verhindert
  den PVE-Schreibaufruf. Solche Blocker sind als eigener Betriebszustand
  sichtbar, zählen aber nicht als fehlgeschlagener `vzdump`-Versuch;
- ein später erfolgreicher Lauf erzeugt genau eine Entwarnung mit Anzahl und
  Dauer der vorangegangenen Fehlversuche;
- eine mehrdeutige Submission oder ein dauerhaft unbekannter Lauf erzeugt eine
  eigene Warnmeldung mit Versuchsnummer und dem ausdrücklichen Hinweis, dass
  kein automatischer `vzdump`-Retry erfolgt;
- ein nach Worker-Verlust nicht mehr beweisbarer Cancel-Dispatch erzeugt eine
  eigene `attention_required`-Meldung und bleibt als `dispatch_unknown`
  sichtbar, ohne einen zweiten `DELETE` auszulösen;
- Webhook-Ausfälle beeinflussen Queue, Backupstatus und Retry-Planung nicht.
  Das persistierte Outbox-Ereignis wird mit 1, 5, 15, 30 und 60 Minuten
  Abstand und danach stündlich unbegrenzt erneut zugestellt; der Rückstand ist
  als Health-Warnung sichtbar;
- Tokens, Credentialdaten, UPID-Rohdaten, Remote-Antworten und Logzeilen werden
  nicht in Matrix-Nachrichten aufgenommen.

Eine vollständige Matrix-Zusammenfassung nach jedem zweiminütigen
Scheduler-Durchlauf ist nicht verpflichtend und standardmäßig deaktiviert.
Fehlversuchs- und Entwarnungsmeldungen sind davon unabhängig und dürfen nicht
unterdrückt werden. PVE-eigene Fehler-E-Mails werden über explizite
Ausführungskonfiguration (`mailnotification=failure` plus konfigurierter
Empfänger) gesteuert; Empfängeradressen werden nicht im Quellcode hinterlegt.

Sobald `BACKUP_EXECUTION_ENABLED=true` gilt, muss die Problemzustellung
gleichzeitig aktiviert und mit einem gültigen HTTPS-Webhook-Secret
konfiguriert sein. Worker-Start und Deployment-Preflight scheitern andernfalls
fail-closed. Bei deaktivierter Backupausführung dürfen lokale Entwicklungs- und
Testumgebungen Outbound-Zustellung deaktiviert lassen.

## API und Phase-6-Anschluss

Phase 5 stellt versionierte, cursor-paginierte Read-Modelle für Queue, Runs,
Events und Logs bereit. Mutationen erzeugen manuelle Requests oder
Cancel-Anforderungen und benötigen `backup_operations.manage`, CSRF,
Idempotency-Key, Revision und Audit-Event. HTTP-Controller führen niemals einen
PVE-Aufruf synchron aus.

## Quality Gates

- 100 Prozent Line- und Branch-Coverage für Domain, Application und eigene
  PVE-Kompatibilitätslogik;
- Unit-Tests für jeden Request-/Run-Zustandsübergang, Gleichheitsgrenzen,
  Feature-Flag und Crashpunkt;
- echte MariaDB-11.4-Tests für zwei parallele Worker, Lease-Ablauf, Fence,
  Slot-/Kapazitätsrace, idempotente Promotion, Logs und Least Privilege;
- Contract-Fixtures für QEMU und LXC auf PVE 7, 8 und 9;
- Tests, dass nach mehrdeutiger Submission und unklarer Stop-Antwort kein
  zweiter Schreibaufruf entsteht;
- OpenAPI-Driftprüfung und generierter TypeScript-Client;
- Phase-6-Playwright-Flows für manuellen Request, Queue, Run, Log, Cancel und
  Worker-Warnzustand;
- die reale Lab-Abnahme bleibt ein externer Nachweis, darf nicht durch Mocks
  ersetzt werden und ist gemäß Nutzerauftrag erst Teil der noch nicht
  begonnenen Phase 7.

## Umsetzungswellen

### 5.1 – Queue und atomare Ressourcen

Status: lokal abgeschlossen.

- Schema, Promotion, Claim/Lease/Fence;
- Revalidation, Node-/Zielslot und Kapazitätsreservierung;
- MariaDB-Concurrency-, Up/Down/Up- und Grant-Tests.

### 5.2 – Submission und Executor-Evidenz

Status: lokal abgeschlossen.

- Backup-Worker-Credential und read-only ACL-Evidenz;
- Payload aus dem unveränderlichen Policy-Snapshot;
- Single-attempt `vzdump`, persistierte Provenienz und Crash-Grenzen.

### 5.3 – UPID-Monitoring, Log, Cancel und Recovery

Status: lokal abgeschlossen.

- Status-/Log-Polling und terminale Zustände;
- Lease-Takeover und Reconciliation;
- persistierte Cancel-Anforderung ohne direkten WebApp-PVE-Zugriff;
- At-most-once-Stop mit explizitem `dispatch_unknown` nach einer nicht mehr
  beweisbaren Dispatchgrenze;
- dauerhafte gate-gesteuerte Retry-Kette und eine Matrix-Meldung für jeden
  definitiven Fehlversuch inklusive Versuchsnummer sowie Entwarnung.

### 5.4 – Operations-API und Abnahme

Status: lokal abgeschlossen; reale Lab-Abnahme nach Phase 7 verschoben.

- Queue-/Run-/Event-/Log-Read-Modelle und revisionierte Commands;
- OpenAPI und generierter Client;
- Race-/Timeout-/Crash-Suite sowie lokale Containerabnahme;
- Contract- und Testplanung für die externe Lab-Matrix; deren reale Ausführung
  für QEMU/LXC und PVE 7/8/9 beginnt erst in Phase 7.
