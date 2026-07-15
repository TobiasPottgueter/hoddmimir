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

1. Einen ausreichend langen Wegwerfbackup-Lauf ohne Startfehler beginnen.
2. Einen neuen Harness mit
   `--fault-route delete-task-stop --fault-count 1` starten beziehungsweise
   den temporären Backupendpunkt kontrolliert auf ihn umstellen.
3. Einmal Cancel auslösen. PVE muss den Task stoppen, obwohl Hoddmímir keine
   DELETE-Antwort erhält.
4. Reconciliation bis zum tatsächlichen Task-Endzustand abwarten.
5. In der Metrikdatei genau einen empfangenen DELETE, eine vollständige
   Upstream-Antwort und eine Injektion nachweisen. Ein zweiter DELETE ist ein
   Abnahmefehler.

## Lokaler Contract-Nachweis

```sh
python3 -m unittest discover -s lab/fault-proxy/tests -p 'test_*.py' -v
```

Die Tests erzeugen kurzlebige lokale Testzertifikate, prüfen beide exakten
Routen und nahe False Positives, einen einzigen Fehler bei mehreren Requests,
vollständigen Upstream-Response vor Abbruch, die Ablehnung eines vorzeitig
abgebrochenen `Content-Length`-Responses ohne Verbrauch des Fault-Kontingents,
echte TLS-Hostname-/CA-Prüfung, fail-closed Aktivierung, Symlink-Ablehnung,
Dateimodus und das Ausbleiben von Header-, Token-, Body-, Node- und Taskwerten
in Output und Metrik.

## Abschluss und Bereinigung

- Temporären Hoddmímir-Labendpunkt deaktivieren und die normale direkte
  PVE-Verbindung wieder verifizieren.
- Proxyprozess beenden und bestätigen, dass kein Prozess mehr auf dem
  Labport lauscht.
- Nur den sanitisierten JSON-Nachweis aufbewahren. Temporäre Zertifikate,
  Private Keys und lokale Trust-Dateien sicher entfernen.
- Keine Harness-Konfiguration, Tokens, realen Hosts, CAs oder Schlüssel in Git
  übernehmen.
