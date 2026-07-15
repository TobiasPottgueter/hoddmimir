# Phase 6: Lokaler Abschlussnachweis

Stand: 13. Juli 2026

Status: **lokal abgenommen.** Die lokale Abschlussprüfung des dokumentierten
Arbeitsstands ist vollständig. Die noch ausstehenden Live-, Deployment- und
Backup-Nachweise bleiben den Phasen 7 und 8 vorbehalten.

Dieser Nachweis konkretisiert den Test- und Abnahmevertrag aus
[`phase-6-webapp-plan.md`](phase-6-webapp-plan.md) sowie die Quality Gates aus
[`rewrite-plan.md`](rewrite-plan.md). Er trennt bereits belegte lokale
Ergebnisse bewusst von den extern noch ausstehenden Prüfungen.

Hinweis zur aktuellen Releasepolitik: Der unten festgehaltene Multiarch-Lauf
ist historische Evidenz des damaligen Arbeitsstands. Seit der Umstellung auf
AMD64-only zählen ausschließlich `linux/amd64`-Builds, -Scans und
-Veröffentlichungen als verpflichtende Produktions- und Release-Evidenz.
Die Dockerfiles bleiben für optionale lokale Builds architekturneutral.

## Arbeitsstandsidentität

- Branch: `codex/policies-shadow-mode`;
- Repository-Basis vor dem lokalen Phase-6-Arbeitsstand: `2da9c94`;
- Arbeitsstandsbezeichnung: `phase6-local-2026-07-13`;
- Ausführungsplattform: Docker Desktop `linux/arm64` auf der lokalen
  Entwicklungsmaschine; die Multiarch-Gates bauen zusätzlich `linux/amd64`;
- unveränderliche Git-Identität des abgenommenen Arbeitsstands:
  `d88d38a7af89565e7d2aa0a5440ea87c3c804fd1`;
- Veröffentlichungsstand: als Commit `d88d38a` auf
  `origin/codex/policies-shadow-mode` veröffentlicht; lokaler Branch und
  Upstream standen bei der Aktualisierung dieses Nachweises bei `0/0`.

Der Nachweis gehört zum vollständigen Implementierungs- und Teststand, der
durch `d88d38a` unveränderlich in Git fixiert ist. Commit und Push erfolgten
nach den dokumentierten lokalen Prüfläufen ohne eine weitere Änderung an
deren Source-, Dependency-, Container-, Migrations- oder Testeingaben. Jede
spätere Änderung an einem solchen Eingang entwertet die jeweils betroffenen
Nachweise und verlangt deren Wiederholung auf einem neuen Kandidaten.

## Geltungsbereich und Grenzen

Der Nachweis gilt ausschließlich für die lokale Phase-6-Implementierung und
ihre reproduzierbaren Tests, Container-Builds und Browserprüfungen. Er ist
kein Beleg für:

- eine Live-Verbindung zu PVE 7, 8 oder 9 beziehungsweise PBS 3 oder 4;
- reale TLS-, ACL-, Inventar- oder Capability-Nachweise gegen Proxmox;
- einen produktionsnahen oder produktiven Ansible-Deploy;
- einen gestarteten `vzdump`, ein reales QEMU-/LXC-Backup oder Restore;
- die Freigabe oder Aktivierung des Backup Workers im produktiven Betrieb.

Zum Zeitpunkt dieser lokalen Abnahme war **Phase 7 noch nicht begonnen**.
Ihr späterer, weiterhin unvollständiger Live-Fortschritt wird getrennt im
[`phase-7-live-acceptance.md`](phase-7-live-acceptance.md) dokumentiert.
Live-PVE-/PBS-Matrix, produktionsnahes Deployment und reale Backup- sowie
Restore-Nachweise werden durch diesen Phase-6-Nachweis weiterhin nicht belegt.

## Ergebnisübersicht

| Prüfbereich                      | Aktueller Nachweis                                                                                                                  | Status für den Abschluss |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ------------------------ |
| PHP Coverage                     | Domain, Application und eigene Proxmox-Kompatibilitätsschicht jeweils 100 % Line und Branch; global 95,56 % Line und 91,87 % Branch | belegt                   |
| Backend-Test                     | 2.277 Tests, 11.088 Assertions; PHPStan 1.081/1.081; keine Composer-/PSR-4-Warnung                                                  | belegt                   |
| MariaDB-Integration              | normaler Integrationslauf 228 Tests/5.230 Assertions; Coverage-Owner-Lauf 395 Tests/5.723 Assertions gegen reale MariaDB            | belegt                   |
| Frontend Unit/Component          | 61 Testdateien, 286 Tests; 97,28 % Statements, 93,49 % Branches, 93,79 % Functions und 97,64 % Lines                                | belegt                   |
| Browser-E2E                      | 19 von 19 Tests auf Desktop- und Mobile-Chromium bestanden                                                                          | belegt                   |
| Proxmox-API-Schema-Drift         | 20 bestandene Tests                                                                                                                 | belegt                   |
| Deployment-Automation            | 65 bestandene Tests                                                                                                                 | belegt                   |
| Historischer Multiarch-Containerlauf | 6 damalige Images: Worker, Web und MariaDB für `linux/amd64` und `linux/arm64`                                                   | historisch belegt        |
| Historische Container-Sicherheit | 0 HIGH/CRITICAL-Funde in allen 6 damaligen Images                                                                                   | historisch belegt        |
| Historische SBOMs                | 6 valide CycloneDX-1.6-SBOMs des damaligen Arbeitsstands                                                                           | historisch belegt        |
| Secret Scan                      | aktuell 10 Commits sowie der damalige Snapshot aller 1.535 vorhandenen, nicht ignorierten Arbeitsbaumdateien ohne Fund              | belegt                   |
| Mutation                         | Critical 90,38 %, Global 80,34 %, jeweils 100 % Mutation-Code-Coverage und 0 Timeouts                                               | belegt                   |
| Finaler Backend-Test             | vollständiger Backend- und Coverage-Gate-Lauf auf dem dokumentierten Arbeitsstand                                                   | belegt                   |
| Vier-Service-Smoke               | 27 Migrationen; MariaDB, Collector, Backup Worker und WebApp gesund; API bereit; Backupausführung deaktiviert                       | belegt                   |
| Visuelle Desktop-/Mobile-Abnahme | korrigierten QA-Build auf 1440 × 900 und 390 × 844 geprüft; keine offenen Findings                                                  | belegt                   |

## Belegte lokale Quality Gates

### Mutation

Der vollständige frische Lauf `make mutation` bestand mit Exitcode 0. Seine
PHPUnit-Path-Coverage-Basis umfasste 2.277 Tests, 11.066 Assertions und vier
übersprungene Tests. Die beiden Infection-Profile meldeten:

- Critical: 4.392 Mutanten, 3.832 durch Tests getötet, 410 escaped,
  21 Syntaxfehler, 129 `skippedCount`, 0 Timeouts und 90,38 % MSI;
- Global: 18.706 Mutanten, 13.326 durch Tests getötet, 3.275 escaped,
  53 Syntaxfehler, 2.052 `skippedCount`, 0 Timeouts und 80,34 % MSI.

Beide Profile erreichten 100 % Mutation-Code-Coverage. Infections
Konsolentext „required more time“ wird im maschinenlesbaren Bericht als
`skippedCount` geführt und ist nicht mit einem Timeout gleichzusetzen;
`timeOutCount` ist in beiden JSON-Berichten exakt 0.

### Backend Coverage und MariaDB

Die PHP-Coverage erfüllt die verbindlichen Schwellen des Rewrite-Plans:

- Domain: 100 % Line und Branch;
- Application: 100 % Line und Branch;
- eigene Proxmox-Kompatibilitätsschicht: 100 % Line und Branch;
- gesamtes PHP-Projekt: 95,56 % Line und 91,87 % Branch.

Der normale Backend-Abschlusslauf `make backend-test` bestand mit Exitcode 0:
2.277 Tests und 11.088 Assertions, PHPStan 1.081/1.081 ohne Fehler, valider
Composer-Vertrag und exakter OpenAPI-Vertrag. Composer meldete weder eine
PSR-4- noch eine andere Warnung.

Der Core-Path-Coverage-Lauf bestand mit 2.277 Tests, 11.066 Assertions und vier
übersprungenen Tests. Seine Rohwerte betragen 11.528/11.776 Lines und
10.288/10.678 Branches. Der disjunkte reale MariaDB-Owner-Lauf bestand mit 395
Tests und 5.723 Assertions. Der zuvor separat ausgeführte normale
Integrationslauf umfasste 228 Tests und 5.230 Assertions. Beide MariaDB-Läufe
verwenden eine reale MariaDB statt SQLite.

Der zusammengesetzte, an ein unverändertes Image und identische Source-Hashes
gebundene Bericht belegt Domain plus Application mit 7.448/7.448 Lines und
6.727/6.727 Branches. Infrastruktur/Proxmox erreicht 2.735/2.735 Lines und
2.093/2.093 Branches; die beiden PBS-Teilbereiche erreichen ebenfalls jeweils
100 % Line und Branch. Das Coverage-Gate bestand mit Exitcode 0.

### Frontend und API-Vertrag

Der Frontend-Lauf umfasst 61 Testdateien und 286 bestandene Unit-/Component-
Tests. Die Gesamt-Coverage beträgt:

- Statements: 97,28 %;
- Branches: 93,49 %;
- Functions: 93,79 %;
- Lines: 97,64 %.

Die Offline-Prüfung des Proxmox-API-Schema-Drift-Vertrags umfasst 20
bestandene Tests. Der finale Playwright-Nachweis umfasst 19 bestandene Tests
gegen die echte PHP-API und eine disposable MariaDB. Er schließt Desktop- und
Mobile-Chromium sowie den nach der Sichtprüfung ergänzten 390-Pixel-
Regressionstest ein.

### Deployment- und Supply-Chain-Gates

Die lokale Deployment-Test-Suite umfasst 65 bestandene Tests. Sie prüft die
vorbereitete Automation, stellt aber keinen tatsächlichen Deploy und keine
Verbindung zur Deployment-VM dar.

Der damalige Multiarch-Gate-Lauf erzeugte ohne Push genau sechs Images. Diese
Angaben bleiben als historische Evidenz erhalten und definieren nicht mehr den
aktuellen Releasevertrag:

- Worker für `linux/amd64` und `linux/arm64`;
- Web für `linux/amd64` und `linux/arm64`;
- MariaDB für `linux/amd64` und `linux/arm64`.

Die SHA-256-Prüfsummen der final geprüften OCI-Archive lauten:

- Worker `linux/amd64`: `741f7097627fbf745da9935feb9085682719760c3f3e77d5bbc7c8ab6e277843`;
- Worker `linux/arm64`: `afb9b2ff963e95a0bb29b0cd86cfe626c7edbf3e51ca7b0d51b18f86452eaf4a`;
- Web `linux/amd64`: `79c403562601bf2a58922a8628ed77b367b7a73fd1bdb8b0d66161fba3f1a984`;
- Web `linux/arm64`: `1ac88d587f0cc709fb455df6b94ab01522de5aaac1b1d0f8d94592659175a374`;
- MariaDB `linux/amd64`: `aca888021fc7ac95edcf34da2daf2ce66cca5bc34bb330bbb0ad822d875940fa`;
- MariaDB `linux/arm64`: `5209c6fe2e31db97aa33b284547c2108ab0041b2cca92f63a229332f203c9940`.

Alle sechs Images bestanden den digest-gepinnten Trivy-Gate-Lauf mit jeweils
0 HIGH- oder CRITICAL-Funden. Für jedes Image wurde ein valides
CycloneDX-1.6-SBOM erzeugt, insgesamt sechs SBOMs. Die Artefakte liegen im von
Git ignorierten `artifacts/`-Verzeichnis und sind kein Release oder Registry-
Push.

Ein nach Commit und Push frisch wiederholter regulärer Secret Scan prüfte den
vollständigen Git-Stand mit zehn Commits und meldete keinen Fund. Vor dem
Commit wurde zusätzlich ein Snapshot aller damaligen 1.535 vorhandenen Dateien
aus `git ls-files --cached --others --exclude-standard` mit dem
digest-gepinnten Gitleaks 8.24.3 im `dir`-Modus geprüft. Dieser frühere Scan
umfasste rund 7,20 MB und meldete ebenfalls keinen Fund. Seine temporäre
Allowlist akzeptierte
nur die exakten synthetischen `AUTH-SENTINEL`-/`NONCE-SENTINEL`-Werte des
Redaction-Tests und die literalen `REPLACE_WITH_*`-Werte des Beispiel-Vaults;
ignorierte `.secrets/`, Dependencies und generierte Artefakte waren nicht Teil
des Arbeitsbaumsnapshots. Nach weiteren Änderungen oder einem späteren Commit
sind die jeweiligen Scans erneut auszuführen.

## Lokale Browserabnahme

Die echte lokale Oberfläche wurde aus einem frischen, isolierten QA-Stack mit
27 Migrationen und 300 Seed-Statements geprüft. Der WebApp-Healthcheck war
grün. Für den manuellen Hostzugriff lief der Stack auf
`http://127.0.0.1:18081`; ein ignorierter lokaler Compose-Override machte nur
für diese Sichtprüfung das ansonsten interne QA-Netz erreichbar. Repository-,
Produktions- und Deployment-Compose blieben unverändert.

Auf dem Desktop-Viewport 1440 × 900 wurden Übersicht, Verbindungen,
Backupziele, Policies, Läufe und Administration visuell geprüft. Übersicht,
Backupziele, Policies, Läufe und Administration belegten jeweils
`scrollWidth === clientWidth === 1440`.

Auf dem mobilen Viewport 390 × 844 wurden Navigation und Onboarding-Dialog
sowie Verbindungen, Policies vor und nach vollständig geladener
Auswahl-/Override-Maske und Laufdetails geprüft. Die Sichtprüfung fand zunächst
drei Min-Content-/Textumbruchfehler. Nach der Korrektur und einem frischen
cache-busted Produktionsbundle belegten alle vier Messpunkte
`scrollWidth === clientWidth === 390`. Der Regressionstest wartet explizit auf
beide vollständig gerenderten Policy-Formulare, bevor er die Breite misst.

Die Browserkonsole enthielt nach der finalen Desktop-/Mobile-Prüfung weder
Warnungen noch Fehler. Die Oberflächen bieten weiterhin keine Aktion „Jetzt
scannen“ und keine direkte PVE-Start-/Stop-Aktion. Die manuelle Prüfung stimmt
damit mit der final grünen 19er-Playwright-Suite überein. Der QA-Stack wurde
anschließend einschließlich seiner disposable Datenbank abgebaut.

## Vier-Service-Smoke

Der Produktions-Compose-Vertrag wurde mit dem isolierten Projektnamen
`hoddmimir-phase6-smoke`, Host-Port `18082` und
`BACKUP_EXECUTION_ENABLED=false` aus einem frischen Datenbank-Volume gebaut
und gestartet. Der one-shot Migrationscontainer führte 27 Migrationen mit 300
SQL-Statements gegen die reale MariaDB aus. Anschließend meldeten MariaDB,
Collector Worker, Backup Worker und WebApp jeweils `healthy`; `/api/health`
lieferte `status: ok` sowie bereite Schema- und Keyring-Prüfungen. Die
Umgebung des Backup Workers bestätigte zusätzlich die deaktivierte
Backupausführung.

Der erste Lauf entdeckte dabei vor der Abnahme einen Runtime-Fehler: Nach dem
bewussten Entfernen von `composer.json` aus den Produktionsimages fiel
Symfonys automatische Projekterkennung auf `/app/src` zurück und umging damit
das beschreibbare `/app/var`-tmpfs. Der abgenommene Arbeitsstand setzt das
Projektverzeichnis im Kernel explizit auf `/app`. Worker- und Web-Build prüfen
diesen Vertrag nach dem Entfernen der Composer-Metadaten; ein Unit-Test hält
die explizite Kernel-Regel fest. Erst der korrigierte Neuaufbau wurde für den
oben beschriebenen erfolgreichen Smoke verwendet.

Nach dem Nachweis wurde ausschließlich der isolierte Stack kontrolliert
abgebaut. Seine Container, sein Netzwerk und sein Datenbank-Volume wurden
entfernt; andere lokale Docker-Projekte und Daten blieben unberührt.

## Abnahmeurteil

Die abgeschlossenen lokalen Gates belegen Coverage, MariaDB-Integration,
Frontend-Qualität, API-Driftkontrolle, Deployment-Automation, den abgesicherten
Produktions-Containerstart und die Multiarch-/Supply-Chain-Eigenschaften des
aktuellen Arbeitsstands. Damit ist Phase 6 lokal abgenommen.

Es wird weder eine Live-Systemabnahme noch ein Deploy- oder Backup-Erfolg
behauptet. Der nach diesem lokalen Abschluss begonnene Phase-7-Fortschritt
steht ausschließlich im getrennten
[`phase-7-live-acceptance.md`](phase-7-live-acceptance.md).
