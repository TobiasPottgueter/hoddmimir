# ADR 0006: Wartungsupgrade mit Datenbanksicherung und Restore

Datum: 7. September 2026
Status: beschlossen; Wartungsprotokoll 1 implementiert. Isolierte Tests und [Abnahme der Wartungs-/Upgrade-/Restore-Fälle gegen echte Dev-Systeme](../audits/2026-09-07/08-dev-maintenance-acceptance.md) vorhanden; Veröffentlichung/Freigabe ist davon getrennt.

## Entscheidung

Schema-Upgrades erhalten ein abgeschlossenes Wartungsfenster mit konsistenter
Datenbanksicherung. Bei einem Fehler vor Wiederfreigabe wird der gesicherte
Datenbankstand zusammen mit den passenden alten Anwendungsimages und der
Konfiguration wiederhergestellt.

Dies ersetzt für diesen neuen Upgradepfad die bisherige alleinige
Image-Rückkehr bei vorwärts migriertem Schema (ADR 0001). Es ist weder ein
transaktionales Zurückrollen von MariaDB-DDL noch ein automatischer Aufruf
potenziell datenlöschender down()-Migrationen.

## Verbindlicher Ablauf

1. **Wartung sperren:** Eine über Prozessneustarts und Restore hinweg wirksame
   Wartungssperre verhindert neue automatische/manuelle Backupstarts,
   administrative Schreibzugriffe und konkurrierende Deployments.
2. **Bestehende Arbeit abschließen:** Worker überwachen laufende Backups und
   bereits ausgelöste Abbrüche weiter. Neue Wiederfreigaben nach ADR 0005
   bleiben gesperrt. Ungeklärte Starts müssen ausreichend geklärt sein, um
   laufende Remote-Arbeit auszuschließen.
3. **Remote-Ruhe nachweisen:** Relevante Proxmox-Systeme direkt, frisch und
   vollständig prüfen. Lokale Queue-/DB-Zustände allein reichen nicht.
   Keine Migration bei laufenden Backups, unzureichenden Rechten oder
   Nichterreichbarkeit. Externe Backupzeitpläne/Administratoren müssen für
   das Wartungsfenster koordiniert sein; ein einmaliger Read verhindert
   deren spätere Starts nicht. Keine automatische Zwangsbeendigung laufender
   Backups für das Upgrade.
4. **Schreibende Prozesse anhalten:** Collector, Backup Worker und schreibende
   Webzugriffe stoppen; Hintergrundzustellung externer Benachrichtigungen
   ebenfalls unterbinden. Abgeschlossene Laufzustände vorher persistieren.
5. **Rückkehrstand sichern und prüfen:** Konsistentes Backup der V2-Datenbank
   einschließlich Migrationsstand anlegen. Exakte alte Image-Digests,
   Konfiguration, erforderliche Secret-/Keyringstände und gegebenenfalls
   betroffene DB-Benutzer-/Grantzustände gesichert zuordnen. Vollständigkeit
   und Wiederherstellbarkeit prüfen, bevor DDL ausgeführt wird. Ein bloß
   erfolgreich beendeter Backupbefehl ist kein Restore-Nachweis.
6. **Upgrade ausführen:** Migration und Kandidat ausrollen. Wartungssperre
   bleibt aktiv, Remote-Backupstarts und externe Zustellung bleiben aus.
7. **Funktion prüfen:** Nicht nur Healthcheck, sondern tatsächliche
   Datenbank-Schreib-/Lesepfade, Schema-/Rechteverträge, API/RBAC,
   Worker-Initialisierung und Benachrichtigungs-Outbox prüfen. Testdaten
   isolieren/entfernen; keine echten Backups oder externen Meldungen als
   Teil dieser Prüfung senden.
8. **Bei Fehler zurückkehren:** Kandidat vollständig stoppen. Datenbank aus
   gesichertem Stand sauber wiederherstellen, nicht einfach über ein
   teilweise migriertes Schema importieren. Alte Images, Konfiguration und
   passende benötigte Secrets einsetzen; Restore und alte Anwendung prüfen.
   Wartungssperre erst nach erfolgreicher Prüfung aufheben. Bei Restore-
   oder Prüfungsfehler gesperrt bleiben und den Fehler melden.
9. **Bei Erfolg freigeben:** Erst nach sämtlichen Prüfungen das Upgrade
   abschließen und den vorher vorgesehenen Betriebszustand wiederherstellen.
   Vorher deaktivierte Backupausführung bleibt deaktiviert; keine implizite
   Erstaktivierung. Collector-/Executor-Evidenz muss vor neuen Starts wieder
   frisch sein.

## Rückkehrfenster und Datenerhalt

Die automatische DB-Rückkehr ist nur bis zur Freigabe zulässig. Sobald neue
echte Backups, Konfigurationsänderungen oder externe Benachrichtigungen
zugelassen wurden, würde ein Restore diese neuen lokalen Informationen
verlieren. Remote-Tasks, Backup-Artefakte und versendete Nachrichten werden
dadurch nicht rückgängig gemacht. Spätere Incidents benötigen einen separaten
Recovery-Plan; kein automatischer Restore auf einen veralteten Stand.

Backups und zugehörige Secrets sind geschützt und außerhalb des ersetzten
Datenbestands aufzubewahren; keine Werte in Logs oder Git. Keyring-Rückkehr
muss alle im wiederhergestellten Stand benötigten Schlüssel erhalten.
Die bestehenden Schlüsselregeln aus ADR 0003 gelten für den bisherigen
Image-Rollbackpfad weiter; dieser neue Pfad benötigt eigene Restore-Tests.

Implementierungsdetails, Bedienung und verbleibende Abnahmegrenzen stehen in
[deployment/ansible/README.md](../../deployment/ansible/README.md#maintenance-upgrades-protocol-1).

## Übergang vom bestehenden Deployment

Der neue Ansible-Pfad nutzt Wartungsprotokoll 1. Eine vorhandene Installation
muss bereits dieses Protokoll samt gemeinsamem Kontrollverzeichnis und
Prüfcommands unterstützen. Alte Images erhalten diese Fähigkeiten nicht durch
bloßes Ändern der Compose-Datei. Ein Upgrade einer solchen Installation wird
abgelehnt; die einmalige Herstellung einer kompatiblen Baseline erfolgt
ausschließlich manuell in einem separat geprüften Offline-Verfahren. Dieser
Übergang wird nicht automatisiert. Neuinstallationen legen die Baseline an.

Der verbleibende Image-Recovery-Pfad verweigert ausstehende Migrationen bei
bestehender Installation. F02 ist damit lokal durch einen Restorepfad adressiert,
aber erst nach vollständiger Qualitäts- und Live-Abnahme für Veröffentlichung
geschlossen.

## Abnahme

- Persistente Wartungssperre für Collector, Worker, Webcommands und Deployment;
  kein Start durch Race, Neustart oder Wiederfreigabe.
- Aktive/ungeklärte Remote-Backups und ACL-/Netzausfall verhindern Migration.
- Backupfehler verhindern DDL; Sicherung/Wiederherstellung gegen echte MariaDB.
- Fehler während teilweiser Migration, Kandidatenstart und Funktionsprüfung
  führen zum identischen gesicherten DB-/Anwendungsstand zurück.
- Outbox, Credentials, Schlüssel, Grants und Migrationsversion nach Restore
  funktional prüfen; keine externen Nebeneffekte aus Prüfungen.
- Restorefehler und Prozessabbruch lassen Wartung aktiv und Recovery
  wiederaufnehmbar; keine konkurrierende Deploymenttransaktion.
- Nach Betriebsfreigabe keine automatische Rückkehr auf das Vorupgradebackup.
- Isolierte Inventory-, Compose-, Preflight-, Secret-Permission- und
  Transaction-Rollback-Tests sowie realer Backup-/Restore-Abnahmenachweis.
