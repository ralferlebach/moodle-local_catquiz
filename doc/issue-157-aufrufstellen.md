# #157 – Aufrufstellen-Audit: Itempool und Parameter nach Kontext (Stand 27.11.2026, 1.3.2/1.2.2)

Nur lesend erstellt, aus dem Code beider Linien. Seit dem Fix von #157 unverändert; diese Auslieferung ändert keine der
Stellen.

**Vertrag:**
- Ein Item gehört dem Kontext, nach dem gefragt wird (`lci.contextid`).
- Sein aktiver Parameter muss auflösen: `lci.activeparamid = lcip.id`, gleiche Item-ID, gleicher Kontext, gleiche
  Frage oder keine.
- Ein Item mit Verweis, der nicht auflöst, ist weder Pilot noch wird es ausgegeben. Es wird gemeldet.
- Piloten sind Items ohne aktiven Parameter.

## 1. Laufzeitpool und alles, was ihn liest

| Stelle | Filter | Verbraucher |
|---|---|---|
| `catquiz::return_sql_for_catscalequestions()` | `lci.contextid = :itemcontextid`; Parameter über `lci.activeparamid = lcip.id AND lcip.contextid = lci.contextid AND (lcip.componentid = lci.componentid OR 0)`; `invalidparamref` kennzeichnet nicht auflösende Verweise | `catscale::load_pool()` (Laufzeitpool); Fragenliste, Datenkarte und Detailansicht des Managers; `statistics_diagnosis` (Pool); `measure_runtime_pool`, `compare_versions`, `explain_hotqueries` (CLI) |
| `catquiz::return_sql_for_catscalequestions_count()` | derselbe Join | Zähler der Skalenverwaltung |
| `catscale::get_testitems()` → `load_pool()` | über die erste Zeile; Cache `runtimepool` je Kontext und Pool-Revision (#146) | `strategy::get_testitems` (Auswahl); `questions_loader` (Kontext `questions`); `personabilities` und `feedback_helper` (Feedback); `settings_validator` (Prüfung der Testeinstellungen); `measure_pool_invalidation`, `measure_screens` (CLI) |

## 2. Weitere Leser von Itemparametern

| Stelle | Filter | Zweck, Einordnung |
|---|---|---|
| `catquiz::get_unusable_item_counts_per_scale()` | optional `lci.contextid`; Parameter über `activeparamid` | Zähler unbrauchbarer Items, Verwaltung |
| `catquiz::get_sql_for_item_params()`, `get_sql_for_max_status_for_item()` | `componentid` und `contextid` | Detailansicht eines Items in einem Kontext |
| `catscale::duplicate_testitemparams_for_scale_with_new_contextid()` | `lci.contextid = lcip.contextid = alter Kontext` | Verwaltungsaktion „Kontext kopieren“, schreibt in den neuen Kontext |
| `statistics_diagnosis` (Pool, Zeile ~311) | Item- und Parameterkontext | Diagnose der Statistik |
| `statistics_diagnosis` (Zeile ~454, „oldrows“) | absichtlich alle Kontexte | zeigt Items früherer Kontexte zur Erklärung; nur lesend, nicht im Pool |
| `history_completion::items()` | `i.contextid`; Parameter über `activeparamid` **ohne** Zeitbezug | QUBA-Rückfall des Verlaufs. **Bekannte Grenze (#159, Entscheidung 12):** heutige statt damals aktiver Parameter. |
| `item_param_integrity` (CLI `check_item_integrity.php`) | Item-Kontext; Parameter absichtlich ungefiltert | findet nicht auflösende Verweise (fehlend, anderes Item, anderer Kontext, andere Frage) |
| `attempt_pool_audit` (CLI `audit_context_pool_attempts.php`) | `activeparamid` und Item-ID | nur lesende Prüfung abgeschlossener Versuche, aggregiert |
| `category_fraction_check`, `operator_checks` (BP-04) | je nach Prüfung | nur lesend |

## 3. Tests (`tests/local/pool/context_item_pool_test.php`, Pflichtliste der CI)

| Test | prüft |
|---|---|
| `test_the_item_of_the_context_with_its_parameters` (älteres zuerst, jüngeres zuerst) | dieselbe Frage als Item in zwei Kontexten: das Item des gefragten Kontexts mit seinen Parametern, unabhängig von der ID-Reihenfolge |
| `test_each_context_keeps_its_own_calibration` | Kalibrierung je Kontext, kein Rückgriff auf den anderen |
| `test_the_standard_error_reads_the_context_asked_for`, `…_the_active_parameters_only` | SE aus dem gefragten Kontext und nur aus aktiven Parametern |
| `test_a_broken_reference_is_reported_not_piloted` (Fälle) | nicht auflösender Verweis: gemeldet, kein Pilot |
| `test_a_question_in_two_sibling_scales` | Dublette in zwei Geschwisterskalen |
| `test_pilots_and_exclusions_without_stand_ins` | echte Piloten bleiben, keine Ersatz-Items |
| `test_two_contexts_have_disjoint_pools`, `test_other_contexts_do_not_change_the_pool` | Pools je Kontext getrennt; fremde Kontexte ändern nichts |
| `test_the_size_of_the_production_context` | synthetisch in Produktionsgröße: 766 Zeilen, 528 aktiv und auflösend, 238 Piloten |

**Offen, nicht im Code zu erbringen:**
- Vor/Nach-Zählung auf Staging bzw. einer Produktionskopie (Root 22, Kontext 1605):
  - `check_item_integrity.php --contextid=1605 --scaleid=22 --json`;
  - `audit_context_pool_attempts.php --contextid=1605 --scaleid=22 --json`;
  - SQL-01 bis SQL-04 aus `doc/issues-2026-11-17.md`.
- Die selektive Invalidierung beim Deployment.
- Die Entscheidung zu abgeschlossenen Versuchen.
- Die End-to-End-Abnahme.
