# Issue #177 – Datenmodell, Quellen, Betrieb (Stand 27.11.2026, 1.3.2/1.2.2)

Gilt für beide Linien: 1.3 (`migration-zu-moodle-5.x`, 1.3.2 = 2026112703) und 1.2 (`ALiSe-v-1.2.0-legacy`,
1.2.2 = 2026112702). `progress.php` ist in beiden Linien gleich. Feldinventur: `doc/issue-177-feldinventur.md`.
Entscheidungen: `doc/entscheidungen-2026-11-21.md` (4–8, 12). Messung: `doc/issue-177-nachweise.md`.

## 1. Ziel und Ergebnis in einem Satz

Vorher wurde bei jeder Antwort der ganze Progress neu geschrieben, zweimal je Request: gespielte Fragen mit 38 Feldern,
Antworten mit Texten, Historie. Die Schreibmenge wuchs also mit N, und die Summe über einen Versuch wuchs mit N².
Jetzt schreibt jede Antwort einen Kopf fester Größe (UPDATE) und wenige neue Ereigniszeilen (INSERT). Die Summe wächst
linear.

## 2. Tabellen

### `local_catquiz_progress` (Kopf, eine Zeile je CAT-Versuch)

- `json`: `progressformat = 3` und nur Felder fester Größe:
  - `isfirstquestion`, `breakend`, `forcedbreakend`
  - `lastquestion` (die zuletzt ausgegebene Frage ganz; der nächste Request liest daraus die Ausgabezeit, ein Reload
    gibt sie erneut aus)
  - `activescales`, `droppedscales`, `lockedscales`
  - `abilities`, `preattemptabilities`, `estimates` (je Skala `[θ, SE, n]` wie zuletzt gespeichert)
  - `contextid`, `usageid`, `session`, `starttime`
  - `counts` (Länge jeder Liste, für die Prüfung) und `lastseq`
- Begrenzt durch die Zahl der Skalen des Tests, nicht durch N.
- `lastseq` (neue Spalte): Nummer des letzten gespeicherten Ereignisses. Sie dient als Versionszähler für die
  Konfliktprüfung.
- `quizsettings`: wie bisher, aber nur noch geschrieben, wenn die Einstellungen nicht so gespeichert sind, wie sie sind:
  - beim ersten Speichern und nach einer Änderung;
  - nicht mehr bei jedem Speichern.
  - Sie sind der größte Teil des Kopfs (im Benchmark rund 10 KB) und ändern sich im Versuch nicht.

### `local_catquiz_progress_steps` (Ereignisse, nur eingefügt)

| Spalte | Inhalt |
|---|---|
| `catattemptid`, `seq` | CAT-Versuch (`local_catquiz_attempts.id`) und laufende Nummer. Eindeutig über (`catattemptid`, `seq`). |
| `eventtype` | `shown` (Frage ausgegeben), `answer` (die Antwort, die zählt), `gaveup`, `later` (spätere Antwort, protokolliert, nicht gezählt; seit 1.3.2/1.2.2), `excluded`, `scalestate` (nur Zustand, ohne Frage) |
| `questionusageid`, `slot`, `questionattemptid`, `questionattemptstepid` | Identitäten der QUBA-Antwort (nur `answer`/`gaveup`) |
| `itemid` | Frage-ID. **Kein** Unique-Index: Eine Frage kann mehrfach ausgegeben werden (Reload). |
| `catscaleid`, `ispilot`, `paramid` | Skala, Pilotstatus und Kalibrierungsprovenienz zum Ausgabezeitpunkt (Entscheidung 6, 12) |
| `fraction`, `originalfraction` | Bewertung der Antwort |
| `difficulty`, `information` | Schwierigkeit und Fisher-Information der Wurzelskala zum Ausgabezeitpunkt |
| `estimatesdelta` | JSON, nur geänderte Skalen: `{scaleid: [θ, SE, n]}`; SE `null`, wo sie nicht endlich ist; nie INF/NaN |
| `diagnostics` | JSON, knapp:<br>`sp`: Skalenpfad der Ausgabe<br>`d`/`fi`: Schwierigkeit/Information<br>`r`: Antwort ohne Texte<br>`h`: Historie der Antwort<br>`t`: Trace im Trace-Modus<br>`ss`: Skalenzustände<br>`dbg`: Debug-Zeile, nur mit `store_debug_info` |
| `timecreated`, `formatversion` | Zeitpunkt; Format des Ereignisses (1) |

Indizes:

- eindeutig (`catattemptid`, `seq`): verhindert doppelte Ereignisse bei einem parallelen Schreiber;
- nicht eindeutig (`catattemptid`, `questionattemptstepid`).

## 3. ID-Domänen (AK3)

Drei Zahlenräume, die nie vermischt werden:

| Domäne | Tabelle | im Ereignis |
|---|---|---|
| Host-Versuch | `adaptivequiz_attempt.id` | nicht gespeichert |
| CAT-Versuch | `local_catquiz_attempts.id` | `catattemptid` |
| QUBA | `question_usages` / `question_attempts` / `question_attempt_steps` | `questionusageid`, `questionattemptid`, `questionattemptstepid` |

- `itemid` ist die Frage-ID (`question.id`), keine Kalibrierungs-ID.
- Die Kalibrierung steht in `paramid` (`local_catquiz_itemparams.id`).

## 4. Schreiben und Lesen

### Schreiben

- Schreiben geschieht unter der Sperre der Item-Administration des Hosts
  (`adaptivequiz_itemadministration_<quiz>_<user>`).
- `progress::save()` läuft in einer Transaktion:
  1. Ereignisse seit dem letzten Speichern einfügen.
  2. Den Kopf mit `lastseq` aktualisieren.
- Vor der Transaktion vergleicht `save()` das gespeicherte `lastseq` mit dem eigenen.
  - Weicht es ab, hat ein anderer Request (zweites Fenster, anderes Gerät) inzwischen gespeichert.
  - Dann verwirft `save()` den Cache-Eintrag und wirft `progress_conflict_exception`. Die Meldung bittet um
    Neuladen.
  - Es wird nichts geschrieben, nichts geht verloren, nichts wird doppelt gezählt.
- Ein fehlgeschlagenes Speichern rollt zurück und setzt die Zähler zurück; das nächste Speichern holt alles nach.
- Das erste Speichern eines neu angelegten Kopfs löscht Ereignisse des Versuchs, die ohne Kopf übrig geblieben sind.
  - Mögliche Ursachen: ein Aufräumen, das die Tabelle nicht kannte, oder eine nach einem Restore neu vergebene Nummer.
  - Ohne das Löschen belegten diese Ereignisse die ersten Plätze (Unique-Index), und jedes Speichern scheiterte.
- Was der Teilnehmende im Konfliktfall sieht:
  - beim Verarbeiten der Antwort die Fehlerseite des Hosts mit Link zurück zum Versuch;
  - bei der Auswahl die Moodle-Fehlerseite mit der Meldung `progressconflict`.
  - Der Versuch bleibt offen. Die QUBA-Antwort ist gespeichert und wird beim nächsten Laden nachgetragen
    (Entscheidung 7).
- Ein Request speichert zweimal:
  - Antwort und Schätzung: `answer`-Ereignis mit `estimatesdelta` und Historie;
  - die neue Frage: `shown`-Ereignis.
- Je Antwort entstehen 2 Ereigniszeilen und 2 Kopf-Updates fester Größe.

### Lesen

- Im laufenden Request liest `progress` aus dem Sitzungs-Cache.
- Ist der Cache leer oder veraltet (`lastseq` passt nicht, MUC-Request-Cache `progresscurrent`), dann liest es:
  - den Kopf mit einer Abfrage,
  - alle Ereignisse mit einer Abfrage nach `seq`,
  - die Labels der Fragen gebündelt aus `question_bank_entries.idnumber`.
- Daraus baut es die Listen, die der Code liest (Feldinventur):
  - gespielte Fragen mit den gelesenen Feldern,
  - `playedquestionsbyscale`, Antworten, Historie, Trace, Schätzungen, Debug-Zeilen.
- Kein N+1.

### Texte (D)

In Ereignissen, Kopf, laufendem Feedback und Debug-Zeilen stehen keine Texte. Das gilt für:

- `questionsummary`, `rightanswer`, `responsesummary`;
- Titel, Label, Skalennamen.

Die Anzeige holt sie gebündelt:

- Verlauf: drei Abfragen für die Tabelle;
- Debug: eine Abfrage für die Skalennamen;
- gegebene Antwort: aus der QUBA (`slot_map_of_usage` liest `qa.responsesummary`).

Umbenannte Skalen erscheinen deshalb mit dem neuen Namen; Zahlen, SE, Noten und Referenzgruppen bleiben gespeichert.
Das ist eine fachliche Änderung gegenüber früher, wo der Name zum Spielzeitpunkt im Verlauf eingefroren war. Ältere
Zeilen, die noch Namen tragen, zeigen diese weiter.

### `debug_info`

- Wird nicht mehr je Antwort neu geschrieben.
- Die Zeilen liegen numerisch in den Ereignissen (`dbg`), nur wenn `store_debug_info` an ist.
- `debug_info` wird einmal beim Abschluss geschrieben (`catquiz::save_attempt_to_db`, Scope ≠ Antwort).

## 4a. Identität einer Antwort, Mehrfachslots (Re-Audit 27.11.2026, P0)

**Schlüssel einer Antwort:**
- Eine Antwort wird an ihrem Schritt der Usage erkannt (`question_attempt_steps.id`, im Ereignis
  `questionattemptstepid`), nicht an der Frage.
- Das Ereignis trägt dazu Usage, Slot und Question-Attempt.
- Die Frage-ID (`itemid`) benennt das Item fachlich. Für Duplikate ist sie kein Schlüssel.

**Welche Antwort zählt (Entscheidung 8 vom 21.11.2026):** die erste verarbeitete bewertete Antwort einer Frage.
- Sie steht in `responses` und geht in Schätzung, Zählung, Verlauf und Ergebnis ein, wie vor #177.
- Spätere Antworten werden protokolliert und nicht gezählt:
  - **dieselbe Frage in einem weiteren Slot:** Der Host gibt eine erneut genannte Frage noch einmal aus
    („technisches Duplikat“);
  - **ein späterer Schritt desselben Slots:** etwa eine manuelle Bewertung;
  - **ein Fehlschlag beim erneuten Ausgeben einer schon beantworteten Frage:** `mark_lastquestion_failed()`, Reload.
    Bis 1.3.0/1.2.1 überschrieb dieser Fall die erste Antwort, sie zählte dann als falsch.
- Sie stehen in `laterresponses`, als Ereignis `later`, mit allen QUBA-Identitäten und ohne Texte.

**Idempotenz:** Ein Schritt wird einmal aufgenommen. Wiederholte Aufnahme durch Reload, zweiten Submit oder einen
Request nach einem Absturz vor dem Speichern des CAT-Versuchs ändert nichts. Die bekannten Schritte werden aus Antworten
und späteren Antworten aufgebaut, auch nach dem Lesen aus Kopf und Ereignissen.

**Fehlschläge** (seit 2026112704/2026112703):
- Ein Fehlschlag (aufgegeben; Reload nach Zeitablauf) hat als Identität den Schritt, den er in der Usage hinterlässt:
  den letzten Schritt des letzten Slots der Frage.
- Ohne Schritt gilt der Schlüssel `failed:<Frage>:<letzter Slot>`; es wird kein Schritt erfunden.
- Er wird so einmal aufgenommen wie eine Antwort, auch wenn der reguläre Pfad denselben Aufgabe-Schritt sieht.
- Vertrag aller Fälle: `issue-177-antwortvertrag.md`.

**Leser:**
- Kopfzähler `counts.later`;
- Prüf-CLI (ein älterer Kopf ohne `later` zählt als 0);
- Rückweg ins Format 2 (Schlüssel `laterresponses`);
- `stored_structure()` und damit Privacy-Export, Retention und Historie.
- Ergebnis, Verlauf und Stopp hängen nur an der zählenden Antwort.
- Retention entfernt die späteren Antworten mit dem Arbeitsstand. Der finale Snapshot enthält sie nicht.

**Tests** (`progress_events_test`, echte Usage):
- zwei Slots derselben Frage mit verschiedenen Fractions;
- manueller Bewertungsschritt im selben Slot;
- doppelter Schritt nach Absturz vor dem CAT-Commit;
- Aufgegeben, dann erneuter Fehlschlag einer beantworteten Frage;
- Fehlschlag mit und ohne Schritt, wiederholt und nach Absturz, mit exakten Zeilen;
- unterbrochene Konversion.

Jeweils warm und nach dem Kaltladen, mit Zeilen auf Usage, Slot, Schritt, Item und CAT-Versuch. Zahntests: §5 der
Nachweise vom 27.11.

**Nicht abgedeckt:** ein Integrationslauf Host + Adapter + Engine, in dem die Engine eine beantwortete Frage erneut
nennt. Die Engine tut das im Normalbetrieb nicht (`duplicate_question_reproducer_test` des Adapters, #126). Ein
solcher Lauf bräuchte eine künstliche Fehlerinjektion.

## 5. Abschluss, Historie, Retention (AK6)

- Am Ende schreibt der Abschluss wie bisher den finalen Snapshot in `local_catquiz_attempts.json`.
  - Er enthält gespielte Fragen, Antworten und Verlauf, gebaut aus dem Progress.
  - Die Texte sind daraus entfernt.
- `progress::load_historical()` liest nur den finalen Snapshot und braucht keine Ereignisse.
- Retention (`preserve_for_display`), `history_completion`, Debug-Export, Operator-Prüfungen und Privacy-Export lesen
  den Arbeitsstand über `progress::stored_structure()`.
  - Das gilt für beide Formate.
  - Für Format 3 baut es die frühere Struktur aus Kopf und Ereignissen nach.
- Aufräumen löscht Kopf und Ereignisse gemeinsam:
  - `progress::delete()`, Retention, Privacy (`catattemptid`), Adapter `attempt_cleanup`, Benchmark-CLIs.
- Die Waisen-Inventur des Adapters kennt die Klasse `progress_steps_orphan`.

## 6. Upgrade, Konversion, Rückweg (AK5, AK11)

### Upgrade

- Upgrade-Schritt 2026112701 (1.3) bzw. 2026112700 (1.2): legt nur die Tabelle und die Spalte `lastseq` an.
  - Er ist idempotent; eine Site aus 1.2 hat beides schon.
- Gespeicherte Daten schreibt das Upgrade nicht um.
- Abgeschlossene Versuche bleiben unverändert, alte Formate bleiben lesbar.

### Lazy-Konversion

- Ein laufender Versuch im alten Format wird beim nächsten Speichern unter der Sperre umgestellt:
  1. alte Ereignisse des Versuchs löschen (aus einem abgebrochenen früheren Versuch);
  2. alle Ereignisse einfügen;
  3. den Kopf im Format 3 schreiben.
- Alles geschieht in einer Transaktion. Nach einem Absturz bleibt das alte Format stehen, es gibt kein Teilformat.

### Rückweg

```
php local/catquiz/cli/progress_steps.php --check [--attempt=ID,...] [--ids]
php local/catquiz/cli/progress_steps.php --rollback [--attempt=ID,...] [--execute]
```

- **`--check`** liest nur. Es meldet:
  - Fehler: Zählerabweichung, Lücken in `seq`, `lastseq`-Abweichung, unlesbare Ereignisse, Texte, Ereignisse ohne
    Kopf im Format 3, verwaiste Ereignisse;
  - nur als Hinweis: Kalibrierungen, deren Parameterzeile fehlt.
  - Exit-Code 1 bei Fehlern.
- **`--rollback`** schreibt den Arbeitsstand ins Format 2 zurück und löscht die Ereignisse.
  - Gespielte Fragen ergänzt es aus den Parameterzeilen, Antworttexte aus der QUBA.
  - Debug-Zeilen schreibt es nach `debug_info`, wenn diese Spalte leer ist.
  - Ohne `--execute` ist es ein Trockenlauf.
  - `--execute` geht nur im Wartungsmodus.
- Ein Downgrade installiert Moodle nur mit höherer Versionsnummer oder mit der Datenbank von vorher. Der Rückweg ist
  dafür da.

Reihenfolge für ein Rollout:

1. Backup.
2. Upgrade.
3. `--check` gleich nach dem Upgrade und nach den ersten Versuchen.
4. Im Fehlerfall:
   1. Wartungsmodus;
   2. `--rollback --execute`;
   3. Code zurück und Version höher, oder DB zurück.

## 7. Privacy, Backup

- Privacy-Provider:
  - beschreibt die Ereignistabelle;
  - exportiert den Arbeitsstand in der früheren Struktur;
  - löscht Ereignisse mit dem Versuch (Benutzer, Kontext, Liste).
- Es gibt keine Lecks über Benutzer-, Versuchs- oder Kontextgrenzen: Jede Abfrage geht über `catattemptid` eines
  geprüften Versuchs.
- Backup/Restore: CAT-Nutzerdaten (Versuche, Progress) waren schon vorher nicht Teil der Sicherung. Das bleibt so; die
  Ereignistabelle kommt nicht hinzu. Der Vertrag ist unverändert und wird hier nur festgehalten.

## 8. Grenzen und offene Punkte

- **Sitzungs-Cache:** Der Cache `adaptivequizattempt` (MODE_SESSION) hält den Progress weiter vollständig im Speicher
  der Sitzung, also O(N).
  - Die DB-Schreiblast ist linear; die Größe des Sitzungseintrags wächst mit N.
  - Er wird bei jedem Speichern serialisiert: 1,26 MB bei 625 Antworten, 20 MB bei 10.000.
  - Bei 3.000 Antworten entfallen 179 s von 213 s Speicherzeit auf ihn.
  - In Web-Requests geht er in die Sitzung (DB-, Datei- oder Redis-Session); diese Schreiblast misst der Benchmark nicht.
  - Das war vor #177 genauso (`doc/issue-177-nachweise.md` §3).
  - Ein schlanker Sitzungseintrag wäre ein eigener Schritt.
- **IRT-Neuschätzung:** Sie bleibt O(N) Rechenaufwand je Antwort (#161). Optimiert wurde die Schreiblast, nicht
  zwingend die Latenz.
- **Konfliktfall:** Bei zwei Fenstern bekommt der spätere Request eine Fehlerseite „bitte neu laden“. Er bekommt keine
  stillschweigend verlorene Antwort.
- **Kopf:** `lastquestion` steht vollständig im Kopf, rund 1,4 KB. Das ist fest und wächst nicht.
