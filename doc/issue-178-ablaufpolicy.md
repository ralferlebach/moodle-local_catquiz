# #178 – Effektive Ablaufpolicy offener Versuche (Stand 27.11.2026)

Gilt für beide Linien. Code:
- `classes/local/attempt/expiry_policy.php`;
- `classes/task/cancel_expired_attempts.php` (alle 5 Minuten, `db/tasks.php`);
- `catquiz_handler::time_limits()`.

## 1. Welche Grenze gilt

Die erste zutreffende Regel gilt:

| # | Einstellung | Grenze ab Versuchsstart | Quelle im Log |
|---|---|---|---|
| 1 | Policy des Tests `catquiz_expirypolicy` = `"none"` (vom Lab gesetzt, kein Formularfeld) | keine; der Task schließt nie | `test-policy-none` |
| 1 | Policy des Tests = Sekunden > 0 | genau diese Sekunden, ohne Puffer | `test-policy-seconds` |
| 2 | Zeitlimit des Tests: „Zeitlimit“ an, Wert > 0, Einheit `h`, `min` oder `sec` | Limit × 1,5 (Puffer für die letzte Antwort) | `test-timelimit` |
| 3 | sonst: keine Einstellungen, Zeitlimit aus, Wert leer oder 0, unbekannte Einheit | Standard der Site `maximum_attempt_duration_hours` in Stunden | `site-default` |
| 3 | Standard der Site = 0 (oder negativ) | keine | `site-default-none` |

**Eindeutigkeit (AK01):**
- **Explizites 0 im Formular** ist „kein eigenes Limit“ → Standard der Site, wie bisher. „Nie schließen“ ist nur die
  Policy `none`.
- **Fehlender Wert, leerer Wert, Zeitlimit aus:** Standard der Site.
- **Standard der Site nicht gesetzt** (`false`, leer): 24 Stunden. Vorher wurde daraus 0, also gar keine Grenze
  (`intval(false)`).
- **Unbekannte Einheit:** kein eigenes Limit. Vorher wurde der Wert als Sekunden gelesen.
- **Policy-Wert, der weder `none` noch eine ganze Zahl > 0 ist:** wird ignoriert und nicht umgedeutet. Das Log nennt
  es („invalid catquiz_expirypolicy ignored“).
- **Komponente:** Gelesen werden die Einstellungen aus `local_catquiz_tests` der Aktivität, in beiden Schreibweisen
  (`mod_adaptivequiz`, `adaptivequiz`). Die neueste Zeile gilt.

**Zeitbasis:**
- Gezählt wird ab `timecreated` des Versuchs (absolute Laufzeit).
- Eine Pause zählt mit; eine Antwort verlängert nichts.
- Das Log nennt zusätzlich die Zeit seit der letzten Änderung (`timemodified`).

## 2. Für das Lab (AK02)

```php
use local_catquiz\local\attempt\expiry_policy;
expiry_policy::set_for_activity($adaptivequizid, expiry_policy::NONE); // Langlauf: nie durch den Task schließen
expiry_policy::set_for_activity($adaptivequizid, 172800);              // genau 48 h
expiry_policy::set_for_activity($adaptivequizid, null);                // wieder die Regeln 2 und 3
expiry_policy::for_activity($adaptivequizid)->describe();              // ['seconds' => ?int, 'source' => …, 'note' => …]
```

- Reguläre Tests ändern sich nicht. Ohne gesetzte Policy gelten die Regeln 2 und 3 wie bisher, bis auf die beiden
  Korrekturen oben (Standard nicht gesetzt, unbekannte Einheit).
- Run 103 (CLARA, 16./17.11.2026): Das Lab schaltete das Zeitlimit aus (`test_provisioner.php`). Damit galt der
  Standard der Site, 24 h. Nach etwa 24 h wurden 50 Langläufe mit 1.362 bis 2.459 Antworten geschlossen.
- Mit der Policy `none` bleiben sie offen (Test `test_the_policy_of_the_lab_keeps_a_long_run_open`). Das Lab muss
  dafür die Policy setzen; das liegt beim Lab (#178 der catquizlab).

## 3. Abschluss durch den Task (AK03, AK04, AK05)

**Ablauf je offenem Versuch:**
- Policy bestimmen; nicht abgelaufen → bleibt.
- Abgelaufen → Sperre der Itemadministration des Hosts nehmen, ohne Warten:
  - Ressource `adaptivequiz_itemadministration_<Aktivität>_<Nutzer>`;
  - dieselbe Sperre, die die Frage-Requests und `cat_session::complete_attempt()` halten.
- Hält ein Request sie (letzte Antwort, Resume), bleibt der Versuch für den nächsten Lauf (`locked`).
- Unter der Sperre wird der Versuch neu gelesen:
  - Ist er inzwischen abgeschlossen oder gelöscht (Reset, Löschung), wird nichts geschrieben (`ended`).
  - Sonst schließt ihn `adaptivequiz_complete_attempt_by_id()` mit Stoppcode `timelimit`, über denselben Abschluss wie
    jedes andere Ende: Finalisierung, Note, Abschluss, Ereignis.

**Status und Gültigkeit:**
- Den Engine-Status „durch Zeitlimit geschlossen“ bekommt nur ein Versuch, den dieser Lauf geschlossen hat.
- Ein Versuch, den sein eigener Request beendet hat, behält sein Ende. Vorher wurde sein Status überschrieben.
- Technischer Zustand und fachliche Gültigkeit bleiben getrennt:
  - technisch: Stoppcode `timelimit`, Status `attemptclosedbytimelimit`;
  - fachlich: `resultvalid`, `resultstatus` aus dem Validator.
- Ob ein CLASSIC-Lauf mit Zeitablauf als „erfolgreich“ zählt, entscheidet der Leser aus Stoppcode und Status, nicht
  aus `attemptstate = complete` (Lab #179).

**Log** (je Entscheidung außer „bleibt“, ohne Nutzer und Antworten):

```
  attempt 123 (activity 45): closed - time over; limit 86400 s (site-default, no time limit of the test), run time 90012 s, last change 33 s ago
Processed 52 open attempts in 1 seconds and marked 1 as completed (closed 1, kept 1, unlimited 50)
```

## 4. Tests (Pflichtliste der CI) und was offen ist

| Test | prüft |
|---|---|
| `cancel_expired_attempts_test::test_the_effective_expiry_policy` (20 Fälle) | 0, leer, aus, `none`, Sekunden, ungültig, Einheiten, 24-h-Grenze, Puffer, Langlauf, Standard gesetzt/0/nicht gesetzt |
| `…::test_the_run_time_counts_from_the_start` | Pause zählt, Antwort verlängert nicht |
| `cancel_expired_attempts_policy_test` | Policy des Labs (Run-103-Fall); beide Schreibweisen; Standard nicht gesetzt; Sperre eines Requests; inzwischen abgeschlossen; inzwischen gelöscht; Log ohne Nutzer |
| `cancel_expired_attempts_run_test`, `…_path_test` | Abschluss über die Aktivität, Stoppcode, Finalisierung |

Zahntests: 7 Schranken, je einzeln zurückgenommen, alle rot, beide Linien.

**Offen (DoD02):**
- Integration mit echter Engine-Antwort im selben Augenblick, also mit einem zweiten Prozess. Der Test simuliert den
  Request über die gehaltene Sperre.
- Der Ergebnis- und Stoppvertrag mit Adapter, Host und Lab (Adapter #13, Host #27, Lab #179).

---

## English summary

The cleanup task closes an open CAT attempt when its effective expiry policy says so.

**Order of the rules:**
1. Test policy `catquiz_expirypolicy`:
   - `"none"`: never closed;
   - seconds > 0: exactly that limit, no buffer.

   The lab sets it with `expiry_policy::set_for_activity()`; there is no form field.
2. The test's time limit (h/min/sec) × 1.5.
3. The site default `maximum_attempt_duration_hours`: 0 = none, not set = 24.

**Further rules:**
- Time counts from the start of the attempt.
- An expired attempt is closed under the item administration lock of mod_adaptivequiz. If a request holds the lock,
  the attempt waits for the next run.
- The attempt is re-read under the lock. Only an attempt this run closed gets the time-limit status.
- Every decision except "kept" is logged with the attempt id, activity id, limit and its source. Users and answers are
  never logged.
