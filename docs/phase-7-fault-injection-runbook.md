# Phase-7: deterministische PVE-Fehlerinjektion

Stand: 15. Juli 2026

Status: **Harness und lokaler Contract sind vorbereitet; noch kein Live-Fall
ausgeführt.** Die freigegebene Labtopologie und ihre Wegwerfgäste sind
read-only bestätigt, aber Laufzeit-Tokens, Hoddmímir-Onboarding, echter
Matrix-Webhook und finaler Kandidat fehlen noch. Weder der mehrdeutige
`vzdump`-Start noch der Cancel-Fall oder eine andere Mutation darf deshalb in
der Live-Acceptance als belegt markiert werden.

## Zweck und Grenze

Der Harness unter [`lab/fault-proxy`](../lab/fault-proxy/README.md) ist
ausschließlich für die isolierten Phase-7-Labtests bestimmt. Er erzeugt den
kritischen Fall, in dem PVE einen schreibenden Request erfolgreich vollständig
beantwortet hat, Hoddmímir aber kein Byte dieser Antwort erhält. Damit lassen
sich Reconciliation und das Verbot eines blinden `vzdump`-Retries real prüfen.

HAProxy wird dafür bewusst nicht verwendet. Seine `http-response`-Regeln und
`silent-drop` arbeiten bereits nach den Response-Headern; sie garantieren nicht,
dass der vollständige Response-Body vom Upstream gelesen wurde. Der kleine
stdlib-Proxy puffert dagegen die vollständige Upstream-Antwort und injiziert
erst danach den Verbindungsabbruch.

Der Harness ist keine Production-Runtime-Abhängigkeit: Er liegt in keinem
Application-Image, wird von keiner Production-Ansible-Rolle installiert und
wird nicht dauerhaft als Dienst eingerichtet.

## Sicherheitsvertrag

- Der Client-Endpunkt ist TLS-terminiert; Zertifikat und Private Key werden als
  lokale Dateien übergeben.
- Der PVE-Upstream muss eine credential-freie `https://`-Authority sein.
  System-CA oder das explizite `--upstream-ca` prüfen Zertifikatskette und
  Hostname. Es gibt keinen Insecure-Schalter.
- Aktivierung erfordert mindestens eine exakte Route, eine positive begrenzte
  Fehleranzahl und den Wortlaut
  `INJECT_AFTER_VERIFIED_UPSTREAM_RESPONSE`. Ohne diese drei Angaben startet
  der Prozess nicht.
- Der optionale Response-Hold ist davon getrennt und benötigt zusätzlich eine
  exakte Hold-Route, exakt `--hold-count 1`, eine Laufzeitgrenze und den zweiten
  ACK-Wortlaut `HOLD_AFTER_VERIFIED_UPSTREAM_RESPONSE`. Der Fault-ACK allein
  aktiviert niemals den Hold-Latch. Ohne Hold-Route bleibt das bisherige
  Proxyverhalten unverändert. Ein Hold-fähiger Harness-Prozess ist bewusst
  one-shot; für einen weiteren Hold ist ein neuer Prozess mit neuer leerer
  Control-Ablage zu starten. `--fault-count` bleibt davon unabhängig.
- Der Hold beginnt ausschließlich nach vollständig gelesener, verifizierter
  2xx-Upstream-Antwort und vor dem ersten Response-Byte an Hoddmímir. Er endet
  durch eine exakt validierte Release-Datei, Client-Abbruch, SIGTERM oder die
  maximal 120 Sekunden lange Hold-Grenze; Timeout und Kontrollfehler brechen
  fail-closed ab.
- Das Hold-Control-Verzeichnis ist eine leere, root-owned Ablage mit mode
  `0700`. Der Harness bindet Verzeichnis und `state.json` an ihre Inodes und
  validiert `release` über den bereits geöffneten Deskriptor. Beide Dateien sind
  reguläre, nicht verlinkte root-owned Dateien mit mode `0600`. Ein unsicherer
  Inode- oder Verzeichnistausch bricht fail-closed ab; der Harness löscht keine
  extern austauschbaren Control-Einträge. Die Zustände `upstream_complete`,
  `hold_entered`, `client_gone`, `released` und `fault` enthalten nur das
  sanitierte Routenlabel und Zähler, niemals Header, Bodies, Tokens, Nodewerte,
  Taskwerte oder UPIDs.
- Der Listener akzeptiert ausschließlich eine explizite Loopback-, RFC1918-
  oder IPv6-ULA-Adresse; Hostnamen, Wildcard- und öffentliche Adressen werden
  bereits beim Start abgelehnt.
- Fehler werden nur nach vollständigen erfolgreichen 2xx-Upstream-Antworten
  injiziert. TLS-, Transport- und Nicht-2xx-Fehler werden nicht künstlich
  mehrdeutig gemacht.
- Header und Bodies werden weitergereicht, aber weder geloggt noch persistiert.
  Der JSON-Nachweis enthält ausschließlich die Labels
  `POST /nodes/{node}/vzdump`,
  `DELETE /nodes/{node}/tasks/{task}/stop` und `OTHER /other` sowie Zähler.
- Der Private Key muss eine reguläre, nicht verlinkte Datei ohne Gruppen- oder
  Weltrechte sein. Die atomische Metrikdatei ist mode `0600`; Symlinks werden
  abgelehnt und ihr Elternverzeichnis muss dem Prozessbenutzer gehören, ohne
  Gruppen- oder Welt-Schreibrechte.
- Responses oberhalb des auch im Backend geltenden 8-MiB-Limits werden nicht
  injiziert und mit einem lokalen 502 abgewiesen; der Response wird nie auf
  Platte gepuffert.
- Der Harness verwaltet weder Firewall noch DNS noch CA-/Token-Dateien. Er ist
  im Vordergrund auf einer dedizierten Labmaschine oder Lab-IP zu starten und
  nach jedem Test zu beenden.

## Endpunkte

Der aktuelle Hoddmímir-/PVE-Vertrag verwendet:

- Start: `POST /api2/json/nodes/{node}/vzdump`;
- Stop: `DELETE /api2/json/nodes/{node}/tasks/{upid}`.

Der Stop-Selektor akzeptiert zusätzlich die explizite Lab-Form mit
abschließendem `/stop`. Status-, Log- und Listenpfade sowie benachbarte Methoden
werden nie injiziert. Query-Varianten zählen ebenfalls als `OTHER /other` und
werden nur transparent weitergeleitet.

## Vorbereitung

1. Auf der Labmaschine Python 3.11 oder neuer und eine private Arbeitsablage
   bereitstellen. Keine Dateien unter `/etc/hoddmimir` oder im Production-
   Deployment verwenden.
2. Ein nur für diesen Proxy bestimmtes Serverzertifikat mit passendem SAN und
   Private Key bereitstellen. Den ausstellenden Test-CA-Schlüssel nicht auf die
   Hoddmímir-Maschine kopieren.
3. Die CA-Kette des PVE-Labzertifikats als reine Trust-Datei bereitstellen oder
   eine vom System bereits vertraute CA verwenden. Der Hostname in
   `--upstream` muss exakt zum Zertifikat passen.
4. Das Proxy-Serverzertifikat beziehungsweise seine Test-CA in Hoddmímir als
   Custom-CA-Vertrauen für den temporären Labendpunkt konfigurieren. Der
   Backup-Token bleibt ausschließlich in Hoddmímir; er ist kein Proxy-Argument.
5. Vor jedem Fall eine neue Metrikdatei und einen eigenen Prozess verwenden.

Beispiel mit ausschließlich reservierten Dokumentationsnamen:

```sh
install -d -m 0700 /var/lib/hoddmimir-fault-lab
python3 lab/fault-proxy/hoddmimir_fault_proxy.py \
  --listen-host 127.0.0.1 \
  --listen-port 18443 \
  --server-certificate /var/lib/hoddmimir-fault-lab/proxy.crt \
  --server-private-key /var/lib/hoddmimir-fault-lab/proxy.key \
  --upstream https://pve-lab.example.invalid:8006 \
  --upstream-ca /var/lib/hoddmimir-fault-lab/pve-ca.crt \
  --metrics-file /var/lib/hoddmimir-fault-lab/vzdump-counters.json \
  --fault-route post-vzdump \
  --fault-count 1 \
  --activation-ack INJECT_AFTER_VERIFIED_UPSTREAM_RESPONSE
```

`127.0.0.1` ist nur passend, wenn der Hoddmímir-Client denselben Network-
Namespace verwendet oder ein kontrollierter SSH-Tunnel davorliegt. Bei einer
separaten Labmaschine ist stattdessen ausschließlich deren private Labadresse
zu binden; niemals eine öffentliche oder Production-Adresse.

## VZDump-Abnahmelauf

1. Harness mit `--fault-route post-vzdump --fault-count 1` starten.
2. Genau einen freigegebenen Wegwerfgast über eine eigene Labpolicy anfordern.
3. Nachweisen, dass der Client einen Transportabbruch erhält und der Hoddmímir-
   Lauf in die mehrdeutige Reconciliation wechselt.
4. Direkt auf PVE nachweisen, dass genau ein `vzdump`-Task existiert.
5. Hoddmímir bis zur UPID-Zuordnung und zum realen Task-Endzustand beobachten.
6. Die Metrikdatei sichern. Erwartet sind für den Startpfad genau
   `received=1`, `upstreamResponses=1`, `faultsInjected=1` und zunächst
   `responsesForwarded=0`. Insbesondere darf `received` nicht durch einen
   zweiten POST steigen.

## Cancel-Abnahmelauf

Dieser Ablauf ist speziell für das persistierte Cancel-`dispatching`-Fenster.
Er darf ausschließlich gegen den freigegebenen Labworker und einen
Wegwerfbackup-Lauf ausgeführt werden.

1. Eine neue, leere Control-Ablage anlegen und vor dem Start prüfen:

   ```sh
   install -d -o root -g root -m 0700 /var/lib/hoddmimir-fault-lab/cancel-hold
   ```

2. Einen ausreichend langen Wegwerfbackup-Lauf ohne Startfehler beginnen und
   einen neuen Harness mit genau einem DELETE-Fault und genau einem DELETE-Hold
   starten:

   ```sh
   python3 lab/fault-proxy/hoddmimir_fault_proxy.py \
     --listen-host 127.0.0.1 \
     --listen-port 18443 \
     --server-certificate /var/lib/hoddmimir-fault-lab/proxy.crt \
     --server-private-key /var/lib/hoddmimir-fault-lab/proxy.key \
     --upstream https://pve-lab.example.invalid:8006 \
     --upstream-ca /var/lib/hoddmimir-fault-lab/pve-ca.crt \
     --metrics-file /var/lib/hoddmimir-fault-lab/cancel-counters.json \
     --fault-route delete-task-stop \
     --fault-count 1 \
     --activation-ack INJECT_AFTER_VERIFIED_UPSTREAM_RESPONSE \
     --hold-route delete-task-stop \
     --hold-count 1 \
     --hold-max-seconds 60 \
     --hold-control-directory /var/lib/hoddmimir-fault-lab/cancel-hold \
     --hold-activation-ack HOLD_AFTER_VERIFIED_UPSTREAM_RESPONSE
   ```

3. Genau einmal Cancel auslösen. Erst fortfahren, wenn `state.json` den Zustand
   `hold_entered` und die Metrik für die sanitierte DELETE-Route exakt
   `upstream_complete=1` sowie `hold_entered=1` zeigt. Zu diesem Zeitpunkt ist
   die erfolgreiche PVE-Antwort vollständig verifiziert, aber Hoddmímir hat
   garantiert noch kein Response-Byte erhalten. Zusätzlich read-only prüfen,
   dass der Cancel-Versuch weiterhin persistent `dispatching` ist.
4. Jetzt ausschließlich den freigegebenen Lab-Backupworker mit SIGKILL beenden.
   Der Harness muss anschließend `client_gone=1` melden. Kein anderer Container
   und kein PVE-/PBS-Prozess darf beendet werden.
5. Den Latch durch eine atomar erzeugte, root-owned mode-`0600` Datei mit exakt
   `RELEASE\n` lösen:

   ```sh
   umask 077
   release_tmp="$(mktemp /var/lib/hoddmimir-fault-lab/cancel-hold/.release.XXXXXX)"
   printf 'RELEASE\n' >"${release_tmp}"
   chmod 0600 "${release_tmp}"
   mv "${release_tmp}" /var/lib/hoddmimir-fault-lab/cancel-hold/release
   ```

6. Den Lab-Backupworker neu starten. Der persistierte Versuch muss in
   `dispatch_unknown` wechseln; der Worker darf keinen zweiten DELETE senden.
   Reconciliation anschließend bis zum tatsächlichen Task-Endzustand abwarten.
7. Der finale Nachweis enthält exakt `received=1`, `upstreamResponses=1`,
   `upstream_complete=1`, `hold_entered=1`, `client_gone=1`, `released=1` und
   `responsesForwarded=0` für die DELETE-Route. Ein zweiter DELETE, eine andere
   Route oder ein fehlendes `dispatch_unknown` ist ein Abnahmefehler.

Erreicht der Hold seine Grenze oder wird die Control-Datei unsicher, entsteht
`fault=1` und kein Response wird weitergeleitet. Dieser Lauf ist nicht als
erfolgreicher Crashfenster-Nachweis zu werten und muss mit frischem Prozess,
frischer Metrik- und frischer Control-Ablage wiederholt werden.

## Lokaler Contract-Nachweis

```sh
python3 -m unittest discover -s lab/fault-proxy/tests -p 'test_*.py' -v
```

Die Tests erzeugen kurzlebige lokale Testzertifikate, prüfen beide exakten
Routen und nahe False Positives, einen einzigen Fehler bei mehreren Requests,
vollständigen Upstream-Response vor Abbruch, die Ablehnung eines vorzeitig
abgebrochenen `Content-Length`-Responses ohne Verbrauch des Fault-Kontingents,
echte TLS-Hostname-/CA-Prüfung, fail-closed Aktivierung, Symlink-Ablehnung,
Dateimodus, getrennten Hold-ACK, Response-Byte-Timing, den festen One-shot-
Hold-Count, Timeout,
Client-Abbruch, Release, SIGTERM-Abbruch mit erhaltener sanitierter Evidenz,
Inode-/Verzeichnistausch, lesbare partielle TLS-Records unter der Hold-Deadline
und das Ausbleiben von Header-, Token-, Body-, Node-, Task- und UPID-Werten in
Output und Metrik.

## Abschluss und Bereinigung

- Temporären Hoddmímir-Labendpunkt deaktivieren und die normale direkte
  PVE-Verbindung wieder verifizieren.
- Proxyprozess beenden und bestätigen, dass kein Prozess mehr auf dem
  Labport lauscht.
- Bei einem Hold-Lauf `state.json`, die optionale `release`-Datei und die Metrik
  nach Prozessende als sanitisierten Nachweis sichern. Erst nachdem bestätigt
  ist, dass der Proxyprozess beendet ist, die ausschließlich diesem Lauf
  zugeordnete Control-Ablage entfernen. Der Proxy löscht diese extern
  austauschbaren Einträge absichtlich nicht selbst.
- Nur den sanitisierten JSON-Nachweis aufbewahren. Temporäre Zertifikate,
  Private Keys und lokale Trust-Dateien sicher entfernen.
- Keine Harness-Konfiguration, Tokens, realen Hosts, CAs oder Schlüssel in Git
  übernehmen.
