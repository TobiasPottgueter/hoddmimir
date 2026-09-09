# Webinterface: UX-Analyse und Anpassungsplan

Stand: 8. September 2026. Geprüfter Quellstand: `196b9d0`.
Status: Frontend und erweiterte Laufhistoriensuche lokal umgesetzt und geprüft am 9. September 2026.
Die untenstehenden Befunde dokumentieren den ursprünglichen Zustand.
Die erweiterte serverseitige Laufhistoriensuche ist einschließlich API-Vertrag,
Client und UI implementiert (siehe ergänzende Abnahme).

## Umsetzungsstand vom 9. September 2026

Quellbasis: `196b9d0`, Änderungen im lokalen Arbeitsbaum, ohne Commit oder
Deployment. Keine PVE-/PBS-Aktion und keine Änderung an Backup- oder
Collector-Verhalten.

| Paket | Umsetzung | Nachweis |
| --- | --- | --- |
| 1 · Bedienfehler | Leere Policy-Auswahl bleibt mit Verwaltungsrecht editierbar; Erklärung bei fehlendem Inventar. Mobile Sidebar mit `inert`, Fokusbegrenzung, Escape, Rückgabe des Fokus und internem Scrollen; Hauptinhalt erhält nach Navigation Fokus. | Component-Regressionen und `e2e/ux-regressions.spec.ts` |
| 2 · Betriebszustände | Eigene Lade-/Fehlerzustände für Dashboard, Queue, Läufe, Meldungen und Detail. Alte Daten bleiben bei Fehler sichtbar und werden gekennzeichnet; Leerzustände nur nach Erfolg. Anzeige-GETs alle 30 Sekunden, ohne überlappende automatische Abrufe; unsichtbare Tabs pausieren. Geöffnete Folgeseiten pausieren das Polling, erneuter Seitenaufruf lädt den aktuellen URL-Kontext. | Store-Tests mit verzögerten Antworten, Fehlern und überholten Abrufen; Composable-Tests mit simulierten Uhren |
| 3 · Formulare | Explizite Feldnamen, feldbezogene Validierung, verlinkte Fehlerzusammenfassung mit Fokus, Bestätigung bei ungespeicherten Policy-/Zieländerungen. Sekundärtexte und Aktionsfarben vereinheitlicht. | Echte PrimeVue-Komponenten, FormErrors-/UnsavedChanges-Tests, Browser-Fehlerfokus |
| 4 · Übersicht | Worker, Probleme, wartende und laufende Backups sowie letzter Erfolg stehen vor Ressourcen und Historie. Unterstützte Zielansichten sind verlinkt. Sichtbares UTC, relative Zeiten und kontextbezogene Nullwerte; Systemhinweis verweist auf Verbindungen bzw. Administration. | Desktop-/Mobilprüfung, Format-/Frischetests |
| 5 · Suche und Konfiguration | Validierte URL-Filter in Inventar, Queue, Läufen/Meldungen, Shadow, Policies und Zielen; Policy-Auswahl teilbar. Filter werden explizit angewendet und lassen sich zurücksetzen. Namensauswahl mit IDs, Gasttyp/VMID, Verbindung und Node; abhängige Ressourcenwahl. Policy-/Zielformular gegliedert, exakte Einheiten für Bytes und Dauer. | URL-/Back-/Reload-Tests, paginierte Auswahl und veraltete Antworten, Einheiten-Roundtrip/Grenzwerte. Erweiterte serverseitige Laufhistoriensuche umgesetzt; ergänzende Abnahme unten. |
| 6 · Konsistenz | Bestehende Shell und PrimeVue weiterverwendet, blaue Primäraktionen, semantische Farbvariablen, schrumpfbare Filter und lokale Tabellenüberläufe. | Viewport-Matrix und Textvergrößerung |

### Lokale Abnahme der ursprünglichen Frontend-Pakete

- Gesamter Frontend-Testbestand: 318 Tests bestanden; danach die ergänzten
  Auswahlprovider und die geänderte Initialaktualisierung gezielt geprüft
  (10 Tests in vier Dateien bestanden). Keine fachlichen Backend-Änderungen.
- Typecheck, ESLint, Formatprüfung und Produktionsbuild bestanden.
- Drei neue reproduzierbare Browser-Regressionen bestanden: mobile
  Fokusführung, geteilte Laufzustandsfilter samt Fehler-/Leerzustand und
  leere Policy-Auswahl samt Formular-Fehlerfokus.
- Chromium 152.0.7977.82, lokal bereitgestellter Linux-Browser. Der isolierte
  Prüflauf verwendet ausschließlich synthetische API-Antworten. Der lokale
  Playwright-Lauf nutzt `/usr/bin/chromium`; die Repository-Konfiguration für
  die reguläre Container-Abnahme wurde nicht geändert.
- Dashboard bei 1440 × 1000: Probleme ab y ≈ 473, Überschrift des letzten
  Erfolgs bis y ≈ 675; alle im Plan geforderten Kerninformationen sichtbar.
  Bei 375 Pixeln beginnen Probleme bei y ≈ 679 statt zuvor ≈ 2098.
- Keine horizontale Seitenüberbreite bei 320, 375, 768, 1024 oder 1440 Pixeln
  für Dashboard, Inventar, Queue, Läufe, Policies einschließlich Formular,
  Ziele und Shadow. Inventar zusätzlich mit 200 % Textgröße geprüft.
  Navigation in 667 × 375 scrollbar; Reduced Motion bleibt wirksam.
- Screenshots und Messwerte des lokalen Prüflaufs liegen temporär unter
  `/tmp/hoddmimir-ux-implementation-20260909/`. Sie werden nicht als dauerhaft
  verfügbare Repository-Artefakte vorausgesetzt.

### Verbleibende Arbeiten und bewusste Grenzen

Die Namenssuche filtert die geladenen Auswahlseiten. Solange weitere Seiten
existieren, steht die Anzahl der geladenen Einträge mit einer expliziten
Nachladeaktion unter dem Feld. Es wird keine vollständige serverseitige
Namenssuche behauptet. Für Leser ohne Verwaltungsrechte stammt die
Verbindungsauswahl aus dem lesbaren Inventar; noch nicht inventarisierte
Verbindungen sind nur in der Verwaltungsansicht auswählbar.

### Erweiterte Laufhistoriensuche: Vertrag und Abnahme

`GET /api/v1/operations/runs` unterstützt neben `state`, `limit` und `cursor`
jetzt `guestId`, `nodeId`, `targetId`, `vmid`, `search`, `startedFrom` und
`startedBefore`. Die Filter werden serverseitig mit AND verknüpft, vor der
stabilen Sortierung nach Startzeit und Lauf-ID absteigend sowie vor der
Pagination angewendet.

- UUID-Filter verwenden kanonische IDs; VMID ist eine positive Ganzzahl bis
  2147483647. Namenssuche: Teilstring gemäß Datenbankkollation, höchstens
  190 UTF-8-Bytes, keine Steuerzeichen; `%`, `_` und `!` werden wörtlich gesucht.
- UTC-Zeitpunkte benötigen `Z`, optional ein bis sechs Nachkommastellen.
  Die Untergrenze gilt einschließlich, die Obergrenze ausschließlich.
  Ungültige Kalenderwerte, leere Filter und nichtpositive Intervalle ergeben
  HTTP 400. Die UI erklärt ungültige Eingaben und unterbindet den Abruf.
- Zeitraum bezeichnet `backup_runs.started_at`, also den Beginn des
  Laufversuchs, nicht den später bestätigten Start des PVE-Tasks.
  Diese Spalte ist verpflichtend; Anforderungen ohne Lauf erscheinen nicht.
- Gast, Node und Ziel werden über die damalige Backup-Anforderung zugeordnet.
  Eine spätere Gastmigration ändert den Node-Filter nicht. Namen und VMID
  kommen aus den weiterhin referenzierten Inventarobjekten, auch wenn diese
  archiviert bzw. Ziele deaktiviert sind. Historische Namens-Snapshots gibt
  es nicht. Löschung referenzierter Objekte verhindern die bestehenden FKs.
- Jeder Cursor bindet alle Filter; andere Filter mit altem Cursor ergeben
  HTTP 400. Äquivalente UTC-Präzision wird normalisiert. Filterwechsel leert
  die bisherige Ergebnisliste, beginnt auf Seite eins und verwirft verspätete
  Antworten des vorherigen Filters. Alte v1-Laufcursor werden abgewiesen;
  erneutes Laden ohne Cursor beginnt die Suche neu.
- Die UI bietet Gastname/VMID, Gast-, Node- und Zielauswahl sowie beide
  Zeitgrenzen; URLs erhalten die angewendeten Filter bei Reload und Zurück.
  Die Gastnamensuche erfasst die gesamte passende Historie. Die Namenssuche
  innerhalb der Auswahlfelder behält die oben beschriebene Seitengrenze.
- Keine Schemaänderung. OpenAPI, generierter TypeScript-Client und der
  interne ReadModel-Vertrag wurden gemeinsam angepasst.

Ergänzende lokale Abnahme für diese Backend-/Frontend-Erweiterung:

- DTO-/API-Tests: 39 Tests, 166 Assertions; `BackupRunQuery` separat mit
  100 % Zeilen- und Zweigabdeckung (33 Tests, 39 Assertions).
- Echte isolierte MariaDB: vier ReadModel-Tests, 179 Assertions; Kombinationen,
  gleiche VMID/Namen in verschiedenen Clustern, wörtliche Suchsonderzeichen,
  Gastmigration, Archivierung, Zeitgrenzen und Cursorwechsel.
- 28 fokussierte Frontendtests in fünf Dateien: API-Parameter, Store,
  überholte Antworten, UTC-Mikrosekunden, Eingabevalidierung und Auswahlfelder.
- Vier Chromium-Regressionen einschließlich erweiterter Historienfilter mit
  Pagination, Reload, Zurück, Zurücksetzen und ungültigen Links. Synthetische
  Browserantworten; die SQL-Semantik wird separat mit MariaDB geprüft.
- Typecheck, Produktionsbuild, ESLint, OpenAPI-Vertragsprüfung und Vergleich
  des generierten Clients bestanden. Neue Filteransicht visuell auf Desktop
  und Mobil geprüft; kein horizontaler Seitenüberlauf bei 320/375/768/1024/1440.

Eine vollständige WCAG-Abnahme, Performance-Messungen großer produktiver
Historien und die reguläre Container-/Live-Abnahme sind nicht Teil dieser
lokalen Abnahme. Commit-, Release- und Deployment-Gates bleiben an ihren
vorgeschriebenen Grenzen erforderlich.

## Ergebnis

Hoddmímir hat eine brauchbare visuelle Grundlage: dunkle Seitenleiste,
helle Inhaltsflächen, konsistente PrimeVue-Komponenten, verständliche
Status-Badges und klar getrennte Fachbereiche. Diese Gestaltung sollte
gezielt weiterentwickelt werden. Der größte Handlungsbedarf liegt bei
bedienbaren Leerzuständen, Tastaturzugänglichkeit, verlässlicher
Statusdarstellung und der Informationsdichte des Dashboards.

Ein funktionaler Einstieg ist derzeit blockiert: Ohne vorhandene
Policy-Auswahlregeln wird der Editor für die erste Regel ausgeblendet.
Dieser Fehler hat Vorrang vor gestalterischen Verbesserungen.

## Prüfgrundlage und Grenzen

- Skill `ui-ux-pro-max`: Web-Checkliste sowie fokussierte Suchen zu
  Formularfehlern, Tastaturfokus und Vue. Native-App-Vorgaben wurden nicht
  pauschal auf die WebApp übertragen.
- Architektur und Funktionsvertrag aus `rewrite-plan.md`; aktueller
  Onboarding-Vertrag aus `proxmox-connection-onboarding-plan.md`.
- Quellprüfung von Shell, Routing, Styling, Ansichten, zentralen Stores,
  Formularen und vorhandenen Component-/Playwright-Tests.
- Browserprüfung des echten lokalen Vue-Frontends mit Playwright und
  Chromium 152.0.7977.82. Alle API-Aufrufe wurden mit synthetischen Daten
  abgefangen. Keine produktiven Systeme, Zugangsdaten oder Backups verwendet.
- Im Browser geprüft: Dashboard mit Beispielbestand und Warnzuständen,
  Navigation, Inventarfilter/Leerzustand, Queue-Leerzustand, Läufe samt
  simuliertem HTTP-503-Fehler und Policy mit leerer Auswahl sowie Editierformular.
- Viewports: 1440 × 1000, 375 × 812 und Navigation bei 667 × 375.
  Reduced Motion wurde geprüft. Dies ist keine vollständige WCAG-Abnahme,
  kein Live-Backend-Test und keine Performance-Messung großer Datenbestände.
- Verbindungen/Onboarding, Ziele, Collector, Shadow und Administration wurden
  auf Quelltextebene betrachtet; ihre vollständigen Abläufe wurden in diesem
  Audit nicht im Browser durchgespielt. Dark Mode ist aktuell kein angebotener
  Modus (`.app-dark` ist konfiguriert, die eigenen Oberflächen sind hell).

Lokale Belege liegen unter `/tmp/hoddmimir-ux-audit-20260908/`:
`audit.cjs`, `results.json`, `dashboard-desktop.png`, `dashboard-mobile.png`,
`navigation-landscape.png`, `inventory-desktop.png`, `queue-mobile.png`,
`runs-desktop.png`, `policy-empty-selection.png` und `policy-form.png`.
Diese temporären Dateien werden nicht als dauerhafte Repository-Artefakte
vorausgesetzt; die wesentlichen Messwerte und Reproduktionen stehen unten.

## Befunde

### UX-01 · Hoch · Erste Policy-Auswahlregel kann nicht angelegt werden

**Browser und Code bestätigt.** Eine Policy ohne Zuweisungen/Overrides öffnen
und „Auswahl anzeigen“ wählen: „Keine Auswahlregeln“ erscheint, aber kein
Button „Auswahlregel speichern“. Der Testbenutzer hatte Verwaltungsrechte.

`PoliciesView.vue` legt den gesamten `PolicySelectionEditor` in den Inhalt
von `AsyncState`. Bei `selectionEmpty` rendert diese Komponente ausschließlich
den Leerzustand. Die API-Leseprojektion liefert tatsächlich vorhandene
Zuweisungen und Overrides; eine leere Auswahl ist ein regulärer Zustand.

**Anpassung:** Liste und Bearbeitungsmöglichkeiten getrennt rendern. Bei
Management-Rechten bleibt der Editor auch ohne vorhandene Regeln erreichbar.
Fehlendes Inventar mit Ursache und nächstem Schritt erklären. Read-only-Nutzer
erhalten weiterhin ausschließlich die leere Liste mit Erklärung.

**Abnahme:** Erste Cluster-, Node- oder Gastregel über die UI anlegen;
leere Auswahl mit und ohne Rechte sowie ohne Inventar separat testen.

### UX-02 · Hoch · Mobile Navigation enthält unsichtbare Tab-Ziele

**Browser und Code bestätigt.** Bei 375 Pixel Breite führt Tab nach dem
Skip-Link auf den unsichtbaren Markenlink der geschlossenen Seitenleiste
(`right: -30px`). Die Seitenleiste wird nur per Transform verschoben.
Escape lässt `aria-expanded="true"` unverändert. Nach Navigation zu „Läufe“
bleibt der Fokus auf dem Navigationslink statt im neuen Inhalt.

Bei 667 × 375 misst die Sidebar 375 Pixel Höhe, ihr Inhalt 653 Pixel;
`overflow-y: visible`. Das Ende des Administrationslinks liegt bei y ≈ 589
und damit außerhalb des sichtbaren Bereichs.

**Anpassung:** Geschlossene mobile Navigation aus Fokusreihenfolge und
Accessibility Tree nehmen. Für das geöffnete Overlay Fokus hineinsetzen,
Hintergrund inaktiv machen, Escape und sichtbares Schließen anbieten und
Fokus zum Auslöser zurückführen. Navigation bei geringer Höhe intern
scrollbar machen. Nach Routenwechsel Fokus gezielt auf den Hauptinhalt
setzen; Scroll-Wiederherstellung für Zurücknavigation berücksichtigen.

**Abnahme:** Tastatur allein, Shift+Tab, Escape, Fokus-Rückgabe, Seitenwechsel,
Scrollbarkeit im Querformat sowie Desktop ohne Overlay-Verhalten prüfen.

### UX-03 · Hoch · Ladefehler und leere Daten werden verwechselt

**Browser und Code bestätigt.** Bei simuliertem HTTP 503 für die Laufabfrage
erscheinen gleichzeitig „Die Daten konnten nicht geladen werden“ und
„Keine Backup-Läufe für diesen Filter“. Ein fehlgeschlagener Abruf darf keine
Aussage über das Nichtvorhandensein von Backups treffen.

`backupOperations.ts` teilt ein `loading` und ein `error` zwischen Dashboard,
Queue, Läufen, Detail und Meldungen. `RunsView.vue` lädt Läufe und Meldungen
parallel. Damit können Abschluss und Fehler eines Bereichs die Anzeige des
anderen beeinflussen; diese Nebenläufigkeit wurde im Code festgestellt,
aber nicht als vollständige Timing-Matrix ausgeführt.

**Anpassung:** Lade-/Fehlerzustände je Abfragebereich führen. Leere Ergebnisse
nur nach erfolgreichem Abruf anzeigen. Vorhandene Daten bei Fehlern sichtbar
lassen und als veraltet markieren. Wiederholen am betroffenen Abschnitt
anbieten; während einer Aktion die zugehörigen Buttons eindeutig sperren.

**Abnahme:** Erfolgreich leer, initialer Fehler, Fehler bei vorhandenen Daten,
langsamer Abruf und gegensätzlich erfolgreiche parallele Abrufe testen.

### UX-04 · Hoch · Betriebsdaten altern ohne klare Aktualisierungsanzeige

**Codebefund.** Dashboard, Queue, Läufe und Laufdetails laden beim Mounten;
ein regelmäßiger Datenabruf oder eine sichtbare Aktualisieren-Aktion fehlt
in diesen Bereichen. Der Queue-Verlauf hat dagegen bereits eine eigene
Aktualisieren-Aktion. `fresh` im Dashboard stammt aus dem letzten Abruf.

**Anpassung:** „Datenstand …“ und „Anzeige aktualisieren“ anbieten.
Für sichtbare Betriebsseiten einen begrenzten, nicht überlappenden GET-Poll
planen, zum Beispiel alle 30 Sekunden; bei unsichtbarem Tab pausieren und
bei Rückkehr aktualisieren. Fehler und Alter explizit darstellen. Die Frische
von Heartbeats auch anhand der vorhandenen Ablaufzeit bewerten.

Dies aktualisiert ausschließlich die Hoddmímir-Anzeige. Der Collector bleibt
im vorgeschriebenen automatischen Raster; kein manueller Scan und keine
zusätzliche Proxmox-Aktion. Das konkrete Poll-Intervall ist vor Umsetzung mit
API-Kosten und bestehendem Collector-Takt abzugleichen.

**Abnahme:** Lange geöffnete Ansicht, alternder Heartbeat, Tabwechsel,
Netzfehler und langsame Antwort mit simulierten Uhren prüfen. Keine
überlappenden GETs und kein POST als Nebenwirkung.

### UX-05 · Mittel · Dashboard priorisiert Fläche vor Betriebsinformation

**Browser bestätigt.** Im Beispielbestand beträgt die Seitenhöhe auf Desktop
3663 Pixel, mobil 6127 Pixel. Einfache Zahlenkarten sind 240 bzw. 192 Pixel
hoch. „Offene Probleme“ beginnt bei y ≈ 1293/2098, die Queue-Überschrift bei
y ≈ 1402/2207. Die Zählkarten haben keine Links zu den betroffenen Objekten.

**Anpassung:** Einleitung verkürzen, Ressourcen als kompakte Kennzahlenzeile
darstellen. Oben Worker-Gesundheit, Handlungsbedarf, laufende/wartende Backups
und letzter Erfolg; darunter Prioritätsverteilung und Detailinformationen.
Zahlen mit passender gefilterter Zielansicht verlinken, sobald diese Filter
unterstützt werden. Keine vollständig neue Farbwelt oder Chart-Bibliothek.

**Abnahme:** Bei 1440 × 1000 sind Workerzustände, Problemzahl, aktive/wartende
Backups und letzter Erfolg ohne Scrollen sichtbar. Mobil stehen Probleme
und aktiver Betrieb vor Ressourcenstatistik und Historie. Textvergrößerung
und lange Namen bleiben lesbar; keine global verkleinerten Formularfelder.

### UX-06 · Hoch/Mittel · Feldnamen und Fehler sind unzureichend zugeordnet

**Browser und Code bestätigt.** Der erste Inventar-Select hat im
Accessibility Snapshot den Namen „VMs und Container“ – seinen aktuellen
Wert, nicht die sichtbare Feldbezeichnung „Ressourcentyp“. Vergleichbare
Selects verwenden oft nur ein umschließendes `label`. Die von PrimeVue
erzeugten Elemente müssen tatsächlich im Accessibility Tree geprüft werden.

`PolicyForm.vue` zeigt einen allgemeinen Validierungsfehler oben, aber keine
feldbezogenen Fehler mit `aria-describedby`/`aria-invalid` und keinen
gezielten Fehlerfokus. Das lange Formular stellt Retention-Einzelfelder,
Sekunden, Bytes und Basiskonfiguration gleichzeitig dar.

**Anpassung:** Stabile Feld-IDs und explizite zugängliche Namen, nötigenfalls
`aria-labelledby`, ergänzen. Feldfehler und verlinkte Fehlerzusammenfassung
mit Fokus nach fehlgeschlagenem Absenden verwenden. Anschließend das
Policy-/Zielformular in Basis, Auslöser und erweiterte Aufbewahrung gliedern.
Für Dauer und Datenmenge verständliche Einheiten anbieten; exakte Werte,
Vererbung und Null-Semantik erhalten. Große Bytewerte nicht über ungenaue
JavaScript-Number-Konvertierungen verändern. Ungespeicherte Änderungen bei
Verlassen/Schließen berücksichtigen; Tokens nicht als Entwurf persistieren.

**Abnahme:** Feldname bleibt bei Wertwechsel erhalten; Pflichtfeldfehler sind
per Tastatur auffindbar. Einheiten-Roundtrip, Grenzwerte, Vererbung und
PBS-Retention-Vertrag mit fokussierten Tests absichern.

### UX-07 · Mittel · Filter erfordern interne IDs und sind nicht teilbar

**Browser/Codebefund.** Inventar fragt Verbindungs- und Parent-UUID ab,
Shadow entsprechend Policy-, Ziel- und Gast-ID. Teilweise laden Selects
sofort, andere Felder benötigen „Filter anwenden“. Filter und ausgewählte
Policy sind nicht in der URL abgebildet. Pinia erhält Teile des Zustands
innerhalb der Sitzung; ein kopierter Link oder Reload rekonstruiert ihn nicht.

**Anpassung:** Suchbare Auswahl mit Namen und Identifikatoren, abhängigem
Cluster-/Node-/Gastkontext und „Filter zurücksetzen“. Interaktionsmodell
vereinheitlichen. Unterstützte Filter und Detailauswahl als validierte
URL-Parameter abbilden. Für Laufhistorie eine Suche nach Gast/VMID, Zeitraum,
Node und Ziel planen; fehlende API-Filter zuerst vertraglich ergänzen.
Sortierung großer Historien immer serverseitig mit stabiler Pagination.

**Abnahme:** Reload, geteilter Link, Zurücknavigation, ungültige Parameter,
leere Treffer sowie viele gleichnamige Gäste über mehrere Cluster testen.

### UX-08 · Mittel · Kontrast, Layout und Komponentenfarben angleichen

**CSS-Berechnung und Sichtprüfung.** Mehrere kleine Sekundärtexte unterschreiten
auf Weiß 4,5:1: `#7b8598` ≈ 3,72:1, `#858fa1` ≈ 3,26:1 und `#727d91` ≈ 4,15:1.
Primärtext `#172033` auf Weiß erreicht dagegen ≈ 16,27:1. Dies sind konkrete
Farbpaarberechnungen, keine vollständige Prüfung aller zusammengesetzten
Hintergründe. Die Inventarfilter zeigen auf Desktop zudem aneinanderstoßende
bzw. überdeckte Select-Inhalte. Die blaue Shell und grünen PrimeVue-
Primäraktionen nutzen bisher getrennte Farbdefinitionen.

**Anpassung:** Semantische Tokens für Text, Flächen, Rahmen, Fokus und Aktion
definieren, bestehendes Blau mit dem PrimeVue-Preset konsistent verbinden.
Sekundärtext abdunkeln. Filterzellen und Komponenten schrumpfbar machen,
rechtzeitig umbrechen lassen und Beschriftungen vollständig zugänglich halten.
Tabellen dürfen bei Bedarf einen eigenen horizontalen Scrollbereich haben.
Ein Dark Mode ist kein notwendiger Bestandteil dieses Plans.

**Abnahme:** Reale gerenderte Text-/Hintergrundpaare, Fokuszustände und
Filterüberlappung prüfen; 320, 375, 768, 1024 und 1440 CSS-Pixel sowie
Textvergrößerung ergänzen. Die bisher bestandenen Prüfungen auf 375 Pixel
zeigten bei Dashboard und Queue keinen horizontalen Seitenüberlauf.

### UX-09 · Mittel · Betriebssprache und Zeitangaben konkretisieren

**Code/Sichtprüfung.** `formatUtc()` formatiert UTC ohne sichtbares UTC-Suffix.
„Keine Messung“ steht auch für nicht geplante Zustellversuche. Texte wie
„serverseitige Operations-Projektion“, „Apply“, „CSRF“ und „revisioniert“
dominieren Teile des Interfaces. `SystemsView.vue` behauptet noch, Verbindungen
würden erst in einer späteren Phase administriert, obwohl der Bereich existiert.

**Anpassung:** UTC ausdrücklich kennzeichnen oder eine einheitliche sichtbare
Anzeigezeitzone einführen. Ergänzend relative Zeiten mit zugänglichem exaktem
Zeitpunkt zeigen. „Nicht geplant“, „Noch kein erfolgreicher Lauf“ und „Keine
Messung“ nach Bedeutung trennen. Betreibertexte auf Zustand, Auswirkung und
nächsten Schritt ausrichten; technische Details aufklappbar halten.
Veralteten Systemhinweis durch passenden Link zu Verbindungen ersetzen.

**Abnahme:** Einheitliche Zeitzone über Dashboard, Queue, Logs und Audit;
Nullwerte und Datumsgrenzen prüfen. Kein Widerspruch zwischen Hilfetext und
tatsächlich angebotenen Funktionen.

## Umsetzung in überprüfbaren Paketen

| Reihenfolge | Paket | Umfang und Abhängigkeiten | Prüfung |
| --- | --- | --- | --- |
| 1 | Bedienfehler beheben | UX-01 und UX-02; Policy-Einstieg und Shell getrennt bearbeitbar | Component-Regressionen, fokussierte Playwright-Flows |
| 2 | Verlässliche Betriebszustände | UX-03, dann UX-04; getrennte Abfragezustände vor Polling | Store-Tests mit verzögerten/fehlerhaften Antworten, Browserprüfung |
| 3 | Barrierearme Formulare | Zugängliche Feldnamen/Fehler aus UX-06 und Kontrast aus UX-08 | Echte PrimeVue-Komponenten, Tastatur, gezielte Accessibility-Prüfung |
| 4 | Betriebsübersicht verdichten | UX-05 und UX-09, auf Zuständen aus Paket 2 | Desktop/Mobil, Fehler-/Leerzustände, lesbare Zeitangaben |
| 5 | Suche und Konfiguration vereinfachen | UX-07 und Formulargliederung/Einheiten aus UX-06 | API-Verträge soweit nötig, Filter-/Vererbungs-/Roundtrip-Tests |
| 6 | Visuelle Konsistenz abschließen | Rest aus UX-08; ggf. Navigation in Betrieb, Konfiguration und Verwaltung gruppieren | Viewport-/Zoom-Matrix, Kontrast- und Fokusprüfung |

Navigation zunächst funktional reparieren. Eine spätere Gruppierung soll
bestehende Ziele und URLs erhalten; keine zusätzliche Navigationsebene ohne
konkreten Orientierungsgewinn. Für jede Änderung zunächst den kleinsten
relevanten Testumfang ausführen; vollständige Gates gelten an den in
`AGENTS.md` definierten Commit-/Merge-/Release-/Deployment-Grenzen.

## Fachliche Leitplanken

- PVE/PBS-Onboarding bleibt bei prüfbaren Einzelbefehlen und Laufzeit-Tokens;
  keine Administrator-Tokens im Wizard.
- Kein „Jetzt scannen“, kein Testbackup als UI-Abkürzung, unveränderte
  Trennung zwischen Anforderung und Backup-Worker-Ausführung.
- Sperrgründe, explizite Ausschlüsse, Revisionen und wirksame Vererbung bleiben
  sichtbar und technisch wirksam. PBS-Pruning und Retention-Freigaben bleiben
  unverändert.
- Bestehende Shell, PrimeVue und Iconfamilie weiterverwenden. Animationen
  sparsam; vorhandene Reduced-Motion-Unterstützung erhalten.

## Referenzen

- [Vue: Accessibility](https://vuejs.org/guide/best-practices/accessibility.html)
  für Skip-Link, Routenfokus und Formularsemantik.
- [W3C: Reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html)
  für die 320-CSS-Pixel-Prüfung und die begrenzte Ausnahme für zweidimensionale
  Inhalte wie Datentabellen.
- [Rewrite-Plan](rewrite-plan.md) und
  [Onboarding-Vertrag](proxmox-connection-onboarding-plan.md).
