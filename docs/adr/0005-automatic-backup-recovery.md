# ADR 0005: Automatische Wiederfreigabe nach ungeklärtem Backupstart

Datum: 7. September 2026
Status: vom Benutzer beschlossen und implementiert; reale DEV-Abnahme am 8. September 2026 bestanden. Abschließende Veröffentlichungsgates offen.

## Entscheidung und Änderung des bisherigen Vertrags

Ein möglicherweise zusätzliches, zeitlich nachfolgendes Backup wird akzeptiert.
Eine dauerhaft notwendige manuelle Freigabe nach einem unbekannten Start ist
nicht das gewünschte Betriebsmodell. Die automatische Wiederfreigabe muss
jedoch durch belastbare Proxmox-Evidenz begründet sein.

Diese Entscheidung ersetzt das bisherige ausnahmslose Verbot eines neuen
automatischen Versuchs nach mehrdeutiger Submission im Rewrite-/Phase-5-Plan.
Unverändert verboten bleibt der blinde HTTP-Retry: Jeder einzelne
Submission-Versuch sendet höchstens einen POST. Ein neuer Versuch benötigt die
unten definierte Anwendungsentscheidung, keine Wiederholung im HTTP-Client.
Cancel/DELETE erhält durch diese Entscheidung keine neue Retry-Erlaubnis.

## Automatischer Ablauf

1. Vor dem POST werden Submission-Identität und Startzeitfenster persistiert.
   Nach verlorener oder unbrauchbarer Antwort wird kein unmittelbarer weiterer
   POST gesendet.
2. Laufende und archivierte Proxmox-Tasks anhand von Node, Gast,
   Benutzer-/Tokenidentität und Zeitfenster vollständig abgleichen.
3. Genau einen eindeutig passenden Task übernehmen. Läuft er noch, weiter
   überwachen; ist er beendet, den tatsächlichen Endzustand übernehmen.
   Erfolg führt zurück zur normalen Policyplanung, definitiver Fehler zur
   bestehenden kontrollierten Retry-Regel.
4. Unvollständige, veraltete, ACL-unzureichende oder fehlgeschlagene Reads sind
   kein Nachweis freier Ressourcen. Bei Nichterreichbarkeit weiter lesend
   klären und keinen Start freigeben.
5. Nach vollständiger, frischer Taskklärung ohne eindeutige Zuordnung einen
   neuen verknüpften Versuch zulassen. Keine zusätzliche pauschale Wartefrist
   und keine vorgeschriebene Folge mehrerer Prüfzyklen. Der neue Versuch
   durchläuft dieselben allgemeinen Startgates wie jeder andere Auftrag.
6. Unmittelbar vor dem neuen POST alle normalen Startbedingungen erneut
   prüfen: Aktivierung, Auswahl, Placement, Inventar-/Kapazitäts-/Executor-
   Frische, Gast-/Node-/Zielkonflikte, Slots und Kapazitätsreservation.
7. Die Freigabe und den neuen Versuch atomar, idempotent und gefencet
   persistieren. Alter Lauf bleibt mit unbekanntem Ergebnis erhalten; der
   neue Versuch referenziert den Vorgänger und die ursprüngliche Backup-
   Pflicht. Grund/Priorität bleiben erhalten. Ein neuer Request darf weder
   Evidenzprüfung noch allgemeine Startgates umgehen.

Mehrere historische Treffer werden nicht willkürlich als Erfolg zugeordnet.
Wenn die Wiederfreigabebedingungen erfüllt sind, darf auch bei weiter
unbekanntem historischen Ergebnis ein neuer Versuch folgen. Zeitablauf allein
genügt nicht. Ein aktiver Task setzt eine anstehende Freigabe außer Kraft.

## Allgemeines Remote-Task-Gate vor jedem Start

Der Backup Worker prüft vor jedem Start direkt auf Proxmox die laufenden
Backup-Tasks. Auch Benutzer und externe Zeitpläne können den Node-Backupslot
belegen. Bei belegtem Slot wartet der Auftrag bis zu dessen nachgewiesener
Freigabe; bei unvollständiger oder unzuverlässiger Sicht erfolgt kein Start.
Diese Regel gilt für automatische, manuelle und erneut freigegebene Aufträge.
Die lokale Queue allein beweist keinen freien Slot.

Der Worker sucht auch den ursprünglichen Task selbst in laufenden und
archivierten Proxmox-Tasks. Er wartet dafür nicht auf den Collector. Dessen
Taskinventar ersetzt die unmittelbare Startprüfung nicht. Bei Placementwechsel
müssen ursprünglicher Submission-Node und aktuelle Platzierung berücksichtigt
werden, damit eine noch laufende alte Ausführung nicht umgangen wird.

## Grenzen und Betriebswirkung

Das Ziel ist die Vermeidung überlappender beziehungsweise unkontrollierter
Starts durch Hoddmímir. Genau ein Backup-Artefakt über alle Versuche hinweg
wird nicht zugesichert. Ein nicht zuordenbarer erfolgreicher Vorgänger kann
ein zusätzliches Backup verursachen; Last, Speicherverbrauch und eine
gegebenenfalls gesondert freigegebene Retention sind dabei zu berücksichtigen.

Externe Scheduler oder Administratoren können zwischen Read und Start selbst
Tasks starten. Eine Taskabfrage allein ist keine verteilte Sperre gegen solche
Akteure. Die Implementierung muss fremde sichtbare Tasks berücksichtigen und
die Grenzen der Koordination ausdrücklich dokumentieren.

Problemmeldungen informieren über die blockierte Klärung; Wiederfreigabe und
späterer Erfolg sind auditiert und mit dem Vorgänger korreliert. Die
automatische Wiederfreigabe ist selbst keine Erfolgsmeldung.

## Implementierungsstand und Abnahme

Die zuvor erwogene Sonderwartefrist und wiederholte Bestätigungsfolge sind
verworfen. Zehn Minuten, drei Prüfungen und ein neuer 120-Sekunden-Klärungstakt
sind keine Anforderungen. Bestehende Pollingintervalle und Backoffregeln für
definitive Fehler bleiben unberührt. Direkte Taskklärung, allgemeines
Remote-Task-Gate und atomare Verknüpfung/Freigabe sind implementiert. Der Worker
prüft vor der Submission die ungefilterten aktiven Backup-Tasks des aktuellen
und gegebenenfalls ursprünglichen Nodes. Bei belegtem oder nicht prüfbarem
Slot gibt er den Claim samt Reservierung frei; ein späterer Claim kann dieselbe
Reservierung wieder aktivieren. Die Wiederfreigabe erzeugt genau einen
verknüpften Folgeauftrag und erhält den unbekannten ursprünglichen Lauf.
Die reale DEV-Abnahme ist im [Bericht 10](../audits/2026-09-07/10-existing-dev-upgrade-and-backup-acceptance.md)
mit ihren Grenzen dokumentiert; die abschließenden Veröffentlichungsgates stehen
noch aus. Die Taskzuordnung prüft zusätzlich unter der Gastsperre, ob ein
Task bereits einem anderen Lauf gehört. Ein solcher Task darf nicht erneut
übernommen werden.

Erforderliche Tests:

- verlorene Antwort, gefundener laufender/erfolgreicher/fehlgeschlagener Task;
- kein Treffer oder mehrere Treffer: Freigabe ohne Sonderwartefrist,
  unter Einhaltung sämtlicher allgemeiner Startgates;
- vollständige Reads versus ACL-Teilansicht, Paginationlimit,
  Timeout, stale Daten und nicht erreichbarer ursprünglicher Node;
- neuer aktiver Task zwischen Prüfungen, Placementwechsel, Workerneustart;
- mehrere Collectorzyklen, manuelle Requests und parallele Worker ohne
  Umgehung der Sperre oder doppelte Wiederfreigabe;
- manuelle/externe Backups belegen Slots für alle Auftragstypen;
- fehlende normale Startvoraussetzungen trotz erfolgreicher Taskklärung;
- Audit-/Notification-Korrelation und echte PVE-Labnachweise.

Der ursprüngliche Auditbefund F01 beschrieb die Umgehung über eine neue
Request-ID. Die implementierten Klärungs- und allgemeinen Startgates schließen
diesen Pfad; die oben abgegrenzte reale DEV-Abnahme ist bestanden. Historische
Audittexte sind kein Statusnachweis des neuen Codes.
