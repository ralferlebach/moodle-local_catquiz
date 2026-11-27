# #177 – Antwortvertrag: welche Antwort zählt (Stand 27.11.2026)

Gilt für beide Linien, 1.3 und 1.2. Ergänzt `issue-177-datenmodell.md` §4a. Code:
- `progress::update_cached_responses()`;
- `progress::mark_lastquestion_failed()`, `take_in_failure()`;
- `catquiz::get_last_finished_step_of_question()`.

## 1. Grundlage: Entscheidung 8

Wortlaut aus `doc/entscheidungen-2026-11-21.md`, Projektverantwortlicher, 21.11.2026:

> 8. **Kanonische Antwort:** Es zählt die erste verarbeitete bewertete Antwort, wie heute. Spätere Änderungen werden
>    protokolliert, nicht gezählt.

**Was im Vertrag ausgelegt ist und bestätigt werden muss:**
- Die Einheit ist die **Frage (das Item) im CAT-Versuch**, nicht der Slot.
- Ein CAT-Versuch stellt eine Frage absichtlich nie zweimal. `removeplayedquestions` schließt aus:
  - die gespielten Fragen des Progress;
  - alle Fragen der Usage (#126).

  Ein zweiter Slot derselben Frage ist deshalb nie eine geplante Wiederholung.

Gäbe es künftig eine Strategie mit geplanter Wiederholung, wäre dieser Vertrag neu zu entscheiden. Die Engine kann eine
geplante Wiederholung heute nicht von einer technischen unterscheiden und behandelt beide gleich.

## 2. Die Fälle

| Fall | zählt | wird protokolliert | Identität |
|---|---|---|---|
| Erste bewertete Antwort einer Frage | ja | als `answer` | QUBA-Schritt (`question_attempt_steps.id`) mit Usage, Slot, Question-Attempt |
| Dieselbe Frage in einem weiteren Slot (technisches Duplikat des Hosts) | nein | als `later` | Schritt des weiteren Slots |
| Späterer Schritt desselben Slots: Korrektur, manuelle Bewertung, Neubewertung | nein | als `later` | der neue Schritt |
| Derselbe Schritt noch einmal: Doppelklick, zweiter Submit, Reload, Retry, Absturz vor dem CAT-Commit | – | nein, einmal aufgenommen | Schritt bekannt |
| Fehlschlag (aufgegeben; Reload nach Zeitablauf), Frage ohne gezählte Antwort | ja, mit Fraction 0 | als `answer` (`gaveup`, wenn aufgegeben) | Schritt, den der Fehlschlag in der Usage hinterlässt: Aufgabe-Schritt oder der Schritt aus `catquiz::mark_last_question_failed()` |
| Fehlschlag einer Frage, die schon eine gezählte Antwort hat | nein | als `later` | dessen Schritt |
| Fehlschlag ohne Schritt in der Usage (keine Usage, Frage ohne Slot) | ja, wenn erster | als `answer` bzw. `later` | Schlüssel `failed:<Frage>:<letzter Slot der Frage, sonst 0>`; Schrittspalten leer, kein erfundener Schritt |
| Derselbe Fehlschlag noch einmal (Wiederholung, Absturz, Reload) | – | nein | gleicher Schritt bzw. gleicher Schlüssel |
| Zwei Tabs mit veraltetem Arbeitsstand | – | Speichern abgelehnt | Konfliktprüfung des Kopfs (`test_a_stale_working_state_is_not_written_over_a_newer_one`) |

**Folgerungen:**
- Ability, SE, N je Skala (Root und Teilskalen), Stopp, Note, Abschluss und Verlauf rechnen nur mit der zählenden
  Antwort.
- Jeder tatsächlich verarbeitete Schritt bleibt als Ereignis nachvollziehbar, mit allen QUBA-Identitäten und ohne
  Texte.
- Altresultate werden nicht umgeschrieben.

## 3. Was belegt ist und was nicht

| | Test (`progress_events_test`, echte Usage, warm und nach Kaltladen) |
|---|---|
| zwei Slots, abweichende Fractions | `test_the_same_question_in_two_slots_keeps_both_answers_apart` |
| Korrektur im Slot, doppelter Schritt, Absturz vor CAT-Commit | `test_a_changed_step_of_a_slot_is_logged_and_a_step_is_taken_in_once` |
| Fehlschlag mit Schritt, Wiederholung, Absturz, regulärer Pfad sieht denselben Schritt, Fehlschlag ohne Schritt | `test_a_failure_is_known_by_its_step_and_taken_in_once` (exakte Zeilen) |
| Aufgegeben; Fehlschlag einer beantworteten Frage | `test_a_given_up_question_and_a_later_failure_of_an_answered_one` |
| zwei Tabs | `test_a_stale_working_state_is_not_written_over_a_newer_one` |

**Nicht belegt:** ein Lauf Host → Adapter → Engine mit Fehlerinjektion. Er müsste zeigen:
- zwei Slots derselben Frage, manuelle Korrektur, Doppelklick, zwei Tabs, Retry, Absturz vor und nach dem CAT-Commit;
- Prüfung von N, Ability, SE, Stopp, Note, Abschluss, Finalisierung, Verlauf und Rechten.

Offen im Issue (#177 AK3/DoD2).

## 4. Offen beim Betreiber

- [ ] Bestätigen oder korrigieren: Einheit ist die Frage im Versuch; ein zweiter Slot ist immer technisch; spätere
  Korrekturen und Neubewertungen zählen nicht.
