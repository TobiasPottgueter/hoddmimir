# Wartungsabnahme gegen echte Dev-Systeme

Stand: 8. September 2026. Fortsetzung von [Bericht 07](07-maintenance-security-and-runtime-acceptance.md).

## Umfang und Befund

Nach Freischaltung des SSH-Zugangs erfolgt die Abnahme auf dem vorhandenen
Dev-Host. Beide bestehenden Hoddmímir-Installationen sind Dev. Für die
absichtlichen DDL-Verluste und Prozessabbrüche wird eine zusätzliche,
zufällig benannte Compose-Installation mit eigener Datenbank, frischen
Geheimnissen und eigenem Netzwerk verwendet. Die bestehende V2 wird nicht
überführt; deren einmaliger Übergang ohne Wartungsprotokoll bleibt manuell.

Die Testdatenbank enthält fünf neu konfigurierte, deaktivierte Verbindungen
mit elf deaktivierten Endpunkten: PVE 7/8/9 und PBS 3/4. Sie verwendet vorhandene
Collector-Tokens, die unter dem frischen Testschlüssel verschlüsselt werden.
Es erfolgt kein Import einer bestehenden Datenbank. Die echten Adapter prüfen
auch die deaktivierten Verbindungen mit gepinnten TLS-Zertifikaten. Eigene
Backupausführung und Benachrichtigungen sind deaktiviert. Die anfängliche
Ruheprüfung aller fünf Systeme war erfolgreich; die PVE-Cluster hatten keine
konfigurierten Backupzeitpläne und keine aktiven Tasks.
Die installierten Versionen wurden nochmals direkt geprüft: PVE `7.4-20`,
`8.4.21` und `9.2.11` sowie PBS `3.4.9-2` und `4.2.5-1`.

Der erste Lauf hat einen zusätzlichen Fehler bei der Sperrfreigabe im Collector aufgedeckt:
`CollectorRuntimeLoop` hielt seinen gemeinsamen Wartungs-Permit während der
Leerlaufpause und nahm ihn unmittelbar danach erneut. Der Wartungsprozess
konnte seine exklusive Sperre deshalb nicht innerhalb von 180 Sekunden
erhalten. Die Sperre wird jetzt vor der Pause bei nicht fälligem Zyklus oder
fehlender Readiness freigegeben. Die abschließende Freigabe berücksichtigt
bereits freigegebene Permits. Ein Regressionstest prüft für beide Pfade die
Freigabe vor dem Warten und genau eine Freigabe je Permit.

Gezielte Prüfung: 42 Tests, 126 Assertions; CollectorRuntimeLoop erreicht
122/122 ausführbare Zeilen und 107/107 Branches. PHPStan ist erfolgreich.
Die Dev-Laufzeitabnahme ist für diesen Stand erfolgreich abgeschlossen.
Der erneute vollständige Coverage-Gate ist bestanden: 2.724 Kerntests mit
12.507 Assertions und 479 MariaDB-Tests mit 8.024 Assertions. Domain/Application
und Proxmox erreichen jeweils 100 Prozent Zeilen- und Branch-Coverage; global
sind es 95,45 Prozent Zeilen und 92,01 Prozent Branches. Auch beide Mutation-Gates
sind bestanden: kritisch MSI 90,30 Prozent bei 4.538 Mutationen, global MSI
80,38 Prozent bei 20.707 Mutationen. Die Schwellen bleiben bei 90 beziehungsweise
80 Prozent.
Die maschinenlesbaren Summaries weisen null Timeouts sowie 94 beziehungsweise
2.066 übersprungene Mutationen aus.

## Operator-Backup

Für den Fremdbackup-Fall wurde als `root@pam` ein Backup des bereits gestoppten
Test-CT 91011 auf PVE 9 gestartet. Das ausschließlich dafür angelegte lokale
Zielverzeichnis war zunächst mit `0700` zu restriktiv: Der unprivilegierte
CT-Prozess konnte es nicht betreten; dieser erste Task endete mit Fehler.
Nach Korrektur auf `0755` wurde ein neuer Task ausdrücklich gestartet und
regulär mit `OK` beendet. Der CT blieb gestoppt. Das eigene Zielverzeichnis
und dessen Sicherungsdateien wurden erst nach Abschluss entfernt.

Der erste Wartungslauf scheiterte zuvor am beschriebenen Collector-Permit.
Nach der Korrektur wurde der Nachweis mit einem weiteren Root-Backup
wiederholt: Während des laufenden Tasks blieb die Migration gesperrt, danach
war die kontrollierte Wiederaufnahme erfolgreich. Auch dieser Task endete
regulär mit `OK`; CT und entferntes eigenes Zielverzeichnis wurden nochmals
unabhängig geprüft. Es wurde kein Backup zur Beschleunigung abgebrochen.

## Ergebnis der erneuten Dev-Abnahme

Alle sechs Szenarien liefen in 382 Sekunden erfolgreich durch, mit den neu
gebauten und erneut gescannten Images einschließlich des Collector-Fixes:

| Szenario | Nachgewiesenes Verhalten |
| --- | --- |
| Tatsächliches Root-Backup auf PVE 9 | Fremder Task erkannt; Journal bleibt `draining`, weder DB-Dump noch Mutation; nach natürlichem Taskende erfolgreiche Recovery |
| Nicht erreichbarer PBS 4 | Nur das separate Testnetz erhält eine zeitweilige, zielgebundene `DOCKER-USER`-Sperre; unbekannte Remote-Ruhe blockiert vor Dump/Mutation; nach Entfernen der Regel erfolgreiche Recovery |
| Reguläres Upgrade | Tatsächliche Sicherung und Restore-Probe in zweiter MariaDB, funktionale Rollen-/Worker-Prüfung und anschließende Freigabe |
| Fehler nach teilweiser DDL | Verlorene Spalte und zusätzlicher DB-Zustand werden rückgängig gemacht; ursprünglicher Binärinhalt und Anwendung wiederhergestellt |
| SIGKILL vor Freigabe | Neuer Prozess übernimmt den dauerhaften Transaktionszustand und stellt DB und Anwendung wieder her |
| SIGKILL nach dauerhaftem Freigabemarker | Kein DB-Rollback; die nach diesem Marker angelegte Tabelle bleibt erhalten |

Die fünf echten Verbindungen und verschlüsselten Collector-Credentials bleiben
während aller Upgrade-/Restore-Fälle in der Testdatenbank. Funktionsprüfungen
entschlüsseln sie mit dem gesicherten Schlüssel; die ursprünglichen
Secret-Dateien bleiben bytegleich. Abschließend sind Wartungssperre und
aktives Transaktionsjournal aufgehoben.

Die unabhängige Nachprüfung bestätigt acht unveränderte, gesunde bestehende
Dev-Container anhand von Container-ID, Image-ID, Startzeit und Health-Status.
Alle 18 Testgäste haben unveränderte Namen, Nodes und gestoppten Zustand.
Die Testcontainer, Volumes, Netzwerke, PBS-Störregel, temporäre Registry,
übertragenen Image-Archive und zusätzliche private Credential-Kopie sind
entfernt. Die beiden vorhandenen Dev-Installationen wurden nicht aktualisiert.

Die übertragenen Archive wurden auf dem Dev-Host vor dem Laden gegen die
lokalen SHA-256-Prüfsummen verifiziert und ausschließlich über eine temporäre
Loopback-Registry per Digest eingesetzt. Das Sicherheits-Gate meldet erneut
null HIGH/CRITICAL für alle drei `linux/amd64`-Images bei unveränderter
Trivy-Einstufung. Es gab keine externe Veröffentlichung.

Nachweise: `artifacts/maintenance-validation/2026-09-08/live/`, insbesondere
`runtime.log`, `foreign-backup-summary.json`, `dev-post-check.json`,
`image-archive-sha256.json`, `runtime-images.json`, `proxmox-versions.json`,
`supply-chain.log` und
die tatsächlich verwendeten Harness-/Seed-Dateien ohne Credentials.

Diese Abnahme schließt die beschriebenen Wartungs-/Upgrade-/Restore-Fälle von
ADR 0006 gegen die echten Dev-Systeme. Sie beansprucht keine erneute vollständige
QEMU/LXC-Backupmatrix, Matrix-Zustellung oder Abnahme sämtlicher automatischer
Wiederfreigabefälle aus ADR 0005. Die umfassenderen historischen Phase-7-Nachweise
sind davon getrennt zu bewerten. Der einmalige Übergang älterer V2-Installationen
ohne Wartungsprotokoll bleibt ausschließlich manuell.

## Zuordnung der Prüfstände

Die endgültigen Coverage- und Mutation-Images wurden einschließlich des
Collector-Fixes neu gebaut. Alle 1.173 PHP-Quell- und Testdateien wurden gegen
den Arbeitsbaum verglichen. Die vollständig archivierten Gate-Protokolle,
Clover-Berichte, Machine-Summaries und Image-/Quell-Prüfsummen liegen im oben
angegebenen Verzeichnis. Die unveränderten Frontend-, Browser-, Ansible- und
Deployment-Verträge aus Bericht 07 bleiben gültig; die tatsächliche Dev-Probe
wurde mit dem korrigierten Laufzeitstand vollständig wiederholt.
