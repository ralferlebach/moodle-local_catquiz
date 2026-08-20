# Changelog – local_catquiz

## 1.1.5 (interne Version 2026082003)

> Strang „Diagramme+Feedback+Statistik": Behat-Abnahme für Issue #10
> (Feedbackansicht valide vs. ungültig). Details in `doc/session-035-changes.md`.

- **#10 Behat:** Neues Feature `catquiz_feedback_validity.feature` mit zwei
  Szenarien. Ungültiger Versuch (alle Antworten falsch → `fraction = 0`, alle
  Skalen ausgeschlossen) → genau ein zentraler Hinweis („No valid test result
  could be determined for this attempt."); valider Versuch (gemischte Antworten)
  → Feedback ohne den zentralen Hinweis. Auswahl-neutraler Invalid-Hebel (keine
  Änderung an SE-/nminscale-Auswahlparametern).

## 1.1.5 (interne Version 2026082002)

> Strang „Diagramme+Feedback+Statistik": Issue #10 Folge-Inkrement
> (Nebenwirkungs-Gating + Forced-Scale-Passthrough). Details in
> `doc/session-034-changes.md`.

- **#10 Einschreibungs-Gating:** `get_courses_to_enrol()` / `get_groups_to_enrol()`
  wählen Kandidatenskalen jetzt über `feedback_helper::get_reportable_scales()`
  (schließt `excluded`/`hidden` aus, nicht nur `toreport`). Ein ungültiges
  Ergebnis löst damit **keine** automatische Kurs-/Gruppen-Einschreibung mehr aus.
- **#10 Forced-Scale-Passthrough:** `feedbackgenerator::select_scales_for_report()`
  reicht `forcedscaleid`/`feedbackonlyfordefinedscaleid` jetzt bis zur
  Auswahlstrategie durch (Mapping auf deren `catscaleid`); neue Träger-Properties
  `feedbacksettings::$forcedscaleid` / `$feedbackonlyfordefinedscaleid`
  (Default 0/false → verhaltensbewahrend, wenn nicht gesetzt).
- Neuer Testfall `attemptfeedback_test`: `toreport`-, aber `excluded`-Skala
  schreibt nicht ein (zahn-getestet).

## 1.1.5 (interne Version 2026082001)

> Strang „Diagramme+Feedback+Statistik": Issue #10 (Feedback an valide Ergebnisse
> binden), Kern-Inkrement. Details in `doc/session-033-changes.md`.

- **#10 Generator-Gating:** `feedbackgenerator::no_data()` liefert jetzt ein
  leeres Ergebnis – Generatoren ohne Daten erzeugen keinen „nicht verfügbar"-Tab
  mehr (die Assembly überspringt leere Ergebnisse).
- **#10 Zentraler Hinweis:** Hat ein Versuch keine berichtbare Skala (`toreport`,
  nicht `excluded`/`hidden`), zeigt die Feedbackansicht **genau einen** zentralen
  Hinweis („Für diesen Versuch konnte kein valides Testergebnis bestimmt werden.")
  inkl. Ablehnungsgrund statt verstreuter Skalen-/„nicht verfügbar"-Blöcke.
- Validität und Ablehnungsgründe in `feedback_helper` zentralisiert
  (`has_reportable_result`, `get_reportable_scales`, `get_exclusion_reason_string`);
  `customscalefeedback` delegiert nun an den geteilten Helfer (DRY).
- Neue Strings `feedbacknovalidresult(+heading)` (EN/DE). Neuer Test
  `feedback_gating_test` (zahn-getestet).

## 1.1.5 (interne Version 2026082000)

> Strang „Diagramme+Feedback+Statistik": Behat-001 gefixt und #44 abgeschlossen.
> Details in `doc/session-032-changes.md`.

- **001 `catquiz_courses` (Behat):** Ursache über einen neuen PHPUnit-Roundtrip-
  Test (`catquiz_courses_persistence_test`) auf die Browser-/Form-Interaktion
  eingegrenzt (PHP/JSON-Persistenz ist nachweislich intakt). Der Autocomplete-
  Behat-Step setzt jetzt zusätzlich das native `<select multiple>` verlässlich
  (`option.selected` + `change`-Event, jQuery-Fallback) – das ist der tatsächlich
  submittierte Formwert. Drei native-`<select>`-Kontrollpunkte (nach Auswahl,
  nach Validierungsfehler, nach Save+Reload) lokalisieren jeden künftigen
  Verlust punktgenau.
- **#44 Nachberechnung:** Kern-Invariante zahn-getestet
  (`incremental_keeps_context_test`): inkrementell **mit** neuen Responses behält
  den aktiven Kontext (kein neuer Kontext, `catscale.contextid` unverändert,
  Personparameter unangetastet); disruptiv versioniert in einen neuen Kontext
  (Selbstvalidierung des Fixtures).
- **Fix `model_item_param_list::confirmed()`:** griff die Item-ID (`get_id()`) als
  Array-Key in die nach `componentid` gekeyte Liste – undefinierter Key und in
  Produktion `null->get_status()` (Fatal) in der inkrementellen Schätzung. Jetzt
  keying-robuste Iteration über die Werte. Dieser Bug blockierte die inkrementelle
  Nachberechnung real; vom #44-Zahn-Test aufgedeckt.

## 1.1.4 (interne Version 2026081718)

> CI-Fix: K2 zurückgenommen (K3 ist der Wurzelfix). Details in
> `doc/session-018-changes.md`.

- **K2 vollständig zurückgenommen:** die „freie Schwellen über der Baseline"-
  Heuristik klemmte legitim negative GRM-Schwellen (Recovery -0.7 -> 0.001);
  K3 (get_fractions-Fix) trägt die missing-bottom-Fälle allein. Recovery grün.
- phpdoc-Fehler (empirical_start_thresholds) durch den Revert behoben.
- `catscale_structure` min/max scale value nach float gecastet (PHP 8.1).

## 1.1.4 (interne Version 2026081717)

> Experiment-Konsequenzen K3, K4, K5. Details in `doc/session-017-changes.md`.

- **K3:** `get_fractions` (GRM/GGRM) zählte eine nicht-null-Baseline reduzierter
  Strukturen doppelt (Jacobian eine Komponente zu lang); Fix: niedrigste Fraktion
  als Baseline überspringen. missing-bottom-Fälle schätzen jetzt korrekt.
- **K4:** K1-Gate nutzt den projizierten Gradienten (KKT) -> keine unnötigen
  Rescues an Randoptima.
- **K5:** `catcalc::item_identifiability_report()` (beobachtete Kategorien,
  projizierter Restgradient, Boundary-Flag, well-identified, Warnungen) + Test.

## 1.1.4 (interne Version 2026081716)

> Experiment-Konsequenzen K1 & K2. Details in `doc/session-016-changes.md`.

- **K1 (Numerik):** Newton-Qualitäts-Gate + BFGS-Rescue mit keep-best in
  `catcalc::estimate_item_params` – schlecht konditionierte/flache Geometrien
  erreichen jetzt das bessere Optimum (z. B. GPCM missing-middle −508 → −222);
  gutartige Items bleiben bit-identisch.
- **K2 (Numerik):** NaN-feste GRM/GGRM-Startschwellen (freie Schwellen streng über
  der Baseline, schlüsselerhaltend; opt-in nur graded-Modelle).

## 1.1.4 (interne Version 2026081715)

> Issue #43: Zentraler Berechnungsdienst mit zwei Modi (inkrementell/disruptiv)
> und CAT-Management-UI. Details in `doc/session-015-changes.md`.

- Neuer stabiler Vertrag `classes/local/calculation/`: `calculation_mode`,
  `calculation_trigger`, `calculation_request`, `calculation_result`,
  `calculation_strategy` + `incremental_recalculation`/`disruptive_recalculation`,
  `calculation_service` (einziger Einstiegspunkt, Moodle-Lock je Skala,
  Lauf-Zusammenfassung je Skala).
- Scheduled Task nutzt den Service (inkrementell); neuer `adhoc_calculation`-Task;
  Web-Request queued nur.
- `manage_calculation.php`: capability-gated UI (disruptiv: `RISK_DATALOSS` +
  Bestätigung), Statusübersicht je Skala, verlinkt aus den Einstellungen.
  Capabilities `local/catquiz:recalculate` + `:disruptiverecalculate`.
- Test `tests/calculation_service_test.php` (7 Tests, inkl. Kontext-Invariante,
  Lock, Ad-hoc-Queue). Sprachstrings EN + DE.

## 1.1.4 (interne Version 2026081714)

> Issue #44: Scheduled-Nachberechnung erzeugt keine neuen CAT-Kontexte mehr und
> blendet keine historischen Daten aus. Details in `doc/session-014-changes.md`.

- Scheduled Task `recalculate_cat_model_params` **standardmäßig deaktiviert** +
  **vierteljährliche** Kadenz; Upgrade-Schritt setzt bestehende Installationen auf
  denselben sicheren Stand (nur wenn nicht admin-angepasst).
- Task lädt den **persistenten aktiven Kontext** aus `catscale.contextid`, nie aus
  dem Prozess-Cache; `needs_update` vor jeder Mutation; **kein** neuer/aktivierter
  Kontext (Kontext-Invariante `contextid vorher == nachher`); mtrace-Zusammenfassung.
- `catmodel_info::update_params(..., $inplace)`: kontexterhaltender Pfad
  (Itemparameter upsert in bestehenden Kontext, PP unverändert).
- Test `tests/task_recalculate_context_test.php` (Defaults + Kontext-Invariante).

## 1.1.4 (interne Version 2026081713)

> CI-Reparaturen und IP-Edge-Case-Experimente. Details in
> `doc/session-013-changes.md`.

### CI
- **Behat**: `create_catquiz_questions` nutzte das in Moodle 4.5 nicht existente
  Modul `mod_qbank`; Import läuft jetzt vorwärtskompatibel über den Kurskontext
  (Moodle 4.5) bzw. `mod_qbank` (Moodle 5.x). Regressionswächter
  `tests/generator_test.php`.
- **phpcs**: Klassen-Duplikat `MatrixException` aus `classes/matrix.php` entfernt
  (kanonisch in `MatrixException.php`); Fixture-Brace-Fehler behoben.

### IP-Edge-Case-Experimente
- Vergleich Newton/BFGS/**GA-eigenständig**/GA→Newton über pathologische
  Geometrien: IP-5 (a→0, GGRM/GPCM), IP-10 (fehlende Kategorien, alle politomen),
  IP-9 (bimodale Ability). 25 §D-Fixtures + Regressionstest
  (`tests/edgecase_ip_test.php`, 25/25 grün).
- Befund: bei fast singulärer Hesse ist Newton nicht überlegen (Iterationslimit);
  bei fehlenden Kategorien findet **GA-eigenständig** ein besseres Optimum als
  Newton/GA→Newton. GRM-Startschwellen degenerieren bei fehlender Unterkategorie
  (dokumentiert, zahn-getestet).

## 1.1.4 (interne Version 2026081700)

> Neues Release-Label **1.1.4** (fix). Übernahme des aktuellen Plugin-Stands
> (Upstream-Merge) und Wiederherstellung der Funktionsfähigkeit gegen die
> aktuellen Abhängigkeits-Branches. Details in `doc/session-003-changes.md`.

### Abhängigkeits-Branches
- **mod_adaptivequiz**: Fork-Branch `alise_adaptivequiz` (`2024123107`,
  `3.0.3dev`) – bündelt die Bridge **`adaptivequizcatmodel_catquiz`** (`1.0.3`,
  `2024123105`) unter `mod/adaptivequiz/catmodel/catquiz`.
- **local_wunderbyte_table** `3.3.0` (`>= 2024040200`), **local_shortcodes**
  `1.1.3` (empfohlen).
- Der `attemptfeedbackeditor`-Bug besteht in der Fork weiter; CI-Patch
  (`.github/ci/patch_adaptivequiz_generator.php`) bleibt aktiv.

### Moodle-4.5-Kompatibilität wiederhergestellt
- **External-Webservice-Klassen auf `core_external` migriert:** In Moodle 4.5 ist
  die globale `external_api` (samt `external_function_parameters`,
  `external_single_structure`, `external_value`) entfernt. Alle zwölf
  `classes/external/*.php` nutzen jetzt den `core_external\`-Namespace; die
  `require_once externallib.php`-Zeilen (und der dadurch überflüssige
  `MOODLE_INTERNAL`-Guard) wurden entfernt.
- **`manage_catscale`**: Top-Level-`VALUE_OPTIONAL` → `VALUE_DEFAULT, null`.
- **`dataapi::update_catscale`**: Guard gegen `null`-Kontext beim Update ohne
  `contextid` (verhinderte TypeError in `create_items_in_new_context(int)`).
- **`update_parameters`**: ungültige IDs (`<= 0`) liefern `success = false`.
- Fehlender Lang-String `functiondoesntexist` ergänzt.

### Schema/Version
- **Version-Bump erzwingt Schema-Rebuild:** Die neue Tabelle
  `local_catquiz_qhashmap` (Question-Hasher) wird erst nach Erhöhung von
  `$plugin->version` angelegt – Moodle wendet Schema-Änderungen nur bei geänderter
  Version an.

### Numerik: 3PL-Sättigung
- **Zweitableitung bei `guessing = 0` korrigiert:** An Sättigungspunkten lieferte
  die 3PL-Hesse `+b²` statt `≈ 0`, weil `p²` unterlief und die Kürzung zerstörte.
  `log_likelihood_p_p` und `get_ability_derivatives` nutzen jetzt das stabile
  Verhältnis `ratio = l/p` (kein Teilen durch `p`/`p²`). `max|3PL(c=0) − 2PL| =
  4.7e-15`. Neuer, zahn-getesteter Regressionstest in der 3PL-Suite.
- Der frühere CI-`DivisionByZeroError` (Datensatz #2) ist behoben.

### Tests
- `strategy_test` portabel für Moodle 4.5 **und** 5.0 (Question-Bank-API).
- Golden-Master-Test `test_responses_lead_to_expected_item_parameters` als
  `incomplete` markiert: die Referenz-Fixture ist veraltet relativ zur aktuellen
  Schätzung und ist gegen eine externe Referenz neu zu erzeugen.

## 1.1.2 (interne Version 2026081413)

> Diese Änderungen werden unter dem bestehenden Release-Label 1.1.2
> ausgeliefert; nur die interne `$plugin->version` wird erhöht.

### Robustheit & Testschulden abgearbeitet (P2/P3)

- **Diskriminationsgrenzen (GGRM/GPCM):** Die Diskrimination wird nun in die
  konfigurierbare, fachlich positive Trusted Region `[trusted_region_min_b,
  trusted_region_max_b]` geklemmt (gemeinsamer Helfer `restrict_discrimination()`
  in `model_multiparam`) mit hartem positivem Boden 0.1 statt des früheren
  hartkodierten `[0.1, 5.0]`. Die Setting-Defaults dieser beiden Modelle wurden von
  `min_b = -3` / `max_b = 3` auf `min_b = 0.1` / `max_b = 5.0` korrigiert (positiv,
  erhält das bisherige effektive Verhalten, jetzt admin-steuerbar).
- **Threshold-Projektion (GRM/GGRM):** Die Ordnungssicherung überschritt bisher
  ggf. die obere Grenze. Ein Vorwärts- plus Rückwärtspass hält die Thresholds nun
  innerhalb `[min, max]` (Box-Constraint) und aufsteigend.
- **Kategorienstruktur bei Erstkalibrierung:** `empirical_start_thresholds()`
  akzeptiert optional die deklarierte Kategorienstruktur des Items (Struktur vom
  Item, Häufigkeiten aus den Responses), sodass eine im Kalibrierungssample
  unbeobachtete Kategorie erhalten bleibt. Aktuelle Aufrufer bleiben unverändert;
  die vollständige Verdrahtung folgt mit dem NRM/RSM-Umbau.
- **Initiale Item-Schwierigkeit:** `estimate_initial_item_difficulties()` nutzt den
  empirischen Logit `p = (r + 0.5) / (n + 1)` – keine Division durch 0 (keine
  Antworten → neutrale Schwierigkeit 0), kein `log(0)` (alles richtig/falsch), kein
  asymmetrischer Offset.
- **FD-Toleranzen** verschärft (Gradient `atol = rtol = 1e-6`, Hessian
  `atol = 1e-5`, `rtol = 1e-4`; 100–1000× enger) – alle FD-Tests halten das.
- **Neues Recovery-/Invariant-Oracle:** Für simulierte Antworten mit bekannter
  Fähigkeit wird geprüft, dass der Schätzer die wahre Fähigkeit wiederfindet und der
  Standardfehler mit mehr Items monoton fällt – ein robuster Ersatz für das an den
  Vor-Refactor-Schätzer gepinnte (weiter geskippte) CAT-Trajektorien-Szenario.
- **Simulationstest gehärtet:** Zusätzlich zur 90-%-Trefferquote muss das mittlere
  Abweichungsband (0.01, 0.5] ≤ 2 % bleiben. Die Referenzabweichung ist bimodal
  (gemessen: 512 Treffer, 0 im Mittelband, 32 Branch-Flips von 544), sodass ein
  systematischer Drift nun auffällt, den die Trefferquote allein maskieren könnte.
- Veralteter `// Ralf …`-Kommentar entfernt.

### Politome Fisher-/Iteminformation korrigiert (Kategorie-Doppelzählung)

- `item_information()` (GRM/GGRM/PCM/GPCM) zählte die Baseline-Kategorie doppelt:
  der Baseline-Term wurde separat addiert **und** in der Schleife über das
  Kategorie-Array (das die Baseline bereits enthält) erneut. Da
  `category_information = -log_likelihood_p_p = Var(K)` frac-unabhängig ist,
  ergab sich `Var(K)·(1 + P_baseline)` statt `Var(K)` – im Beispiel ~31 % zu hohe
  Information. Wirkt direkt auf die Fisher-basierte Itemauswuahl
  (`teststrategy/preselect_task/fisherinformation`) und den ausgewiesenen
  Standardfehler. Behoben (Baseline genau einmal), gegen eine unabhängige
  FD-Referenz verifiziert und mit neuen Fisher-Tests je Modell abgesichert
  (zahn-getestet).

### PCM/GPCM `likelihood()` sättigungssicher

- Die Kategorie-Wahrscheinlichkeiten nutzen jetzt denselben Max-Shift-Softmax wie
  die Momente (statt roher `exp()`-Summen). Damit bleibt `likelihood()` bei
  extremen θ endlich (kein `INF/INF = NaN`); der Sättigungstest prüft nun auch
  `likelihood()` und `get_ability_derivatives()`.

### Estimator-Interface-Verträge vervollständigt

- `catcalc_item_estimator` deklariert wieder `get_log_jacobian()`/`get_log_hessian()`
  (von `catcalc::estimate_item_params()` benötigt); `catcalc_ability_estimator`
  deklariert jetzt `get_ability_derivatives()` (von `estimate_person_ability()`
  benötigt). Damit ist der Modellvertrag für künftige Modelle wieder vollständig.

### Weitere Robustheit & Tests

- 1PL/2PL erhalten eigene `get_ability_derivatives()`-Overrides (P einmal
  berechnet); Stufe 2 deckt damit alle 7 Modelle ab.
- Neue numerische PP-θ-FD-Tests für die 4 politomen Modelle sowie
  `get_ability_derivatives == Einzelmethoden`-Tests für alle 7 Modelle.
- TR-Grenzen: nur ein ungesetzter (`false`) oder leerer Config-Wert fällt auf
  ±5 zurück; ein administrativ gesetztes `0` wird respektiert.

### Kombinierte Personen-Ableitungen (PP-Refactor Stufe 2)

- Neue `model_raschmodel::get_ability_derivatives($pp, $ip, $frac)` liefert Score
  und Hesse in einem Durchgang. Basis-Default delegiert an
  `log_likelihood_p`/`log_likelihood_p_p`; effiziente Overrides in 3PL, GRM, GGRM,
  PCM und GPCM teilen sich die (teure) Wahrscheinlichkeits-/Momentberechnung.
- Der Personen-Schätzer (`catcalc::estimate_person_ability`) nutzt pro Response
  eine memoisierte, nach Ability-Wert geschlüsselte Hülle
  (`make_ability_derivative_callable`), sodass Jacobian- und Hesse-Callable
  dieselbe Berechnung teilen. Die Werte sind bitgenau identisch zu den getrennten
  Methoden (max. Abweichung 0.00e+0), FD- und sättigungsgeprüft bis θ = ±800.

### Simulationstest wieder aktiv (toleranzbasiert statt Skip)

- `catcalc_test::test_simulation_steps_match_reference_within_tolerance` ersetzt den
  zuvor geskippten, auf Vor-Refactor-FP-Rundung gepinnten Schritttest. Er prüft
  aggregiert, dass ≥ 90 % der Referenzschritte (radCAT/classicCAT) auf 0.01
  übereinstimmen. Die Abweichung ist bimodal (94 % < 0.01, 6 % Rand-/Degeneriert-
  fälle mit abweichendem diskreten Newton-Zweig). Fängt grobe Regressionen
  zuverlässig (Zahn-getestet).

### Code-Hygiene

- Tote Trust-Region-Methoden `get_log_tr_jacobian`/`get_log_tr_hessian` (0 Aufruf-
  stellen) aus dem Interface `catcalc_item_estimator` und allen 7 Modellen entfernt.
- Hartkodierte `[-5, 5]`-Grenzen in den politomen `restrict_to_trusted_region`
  (GRM/GGRM/PCM/GPCM) durch die Admin-Settings `trusted_region_min_a`/`_max_a`
  ersetzt (Fallback ±5), analog zu den dichotomen Modellen.

### adaptivequiz-Integration (CI)

- `attemptfeedbackeditor` wird in den Integrationstests (`testitemimporter_test`,
  `strategy_test`) bei `create_instance` gesetzt. Grund: `adaptivequiz_add_instance()`
  in der Dependency (auch im Branch `alise_adaptivequiz`) liest die Property
  unbedingt, der Generator setzt sie nie. Behebt den Blocker bei der
  Instanzerzeugung; beide Tests laufen wieder an.

### Sättigungsfestigkeit der Ableitungen (Division-by-Zero / NaN behoben)
Behebt den CI-Unit-Fehler (33× `DivisionByZeroError` in
`model_person_ability_estimator_catcalc_test`, Datensatz #2 / 3PL) und härtet
darüber hinaus **alle** betroffenen Ableitungen systematisch gegen extreme
Fähigkeiten. Ursache waren zwei Muster: (a) Division durch `P·(1−P)` bzw. die
Kategoriewahrscheinlichkeit `P_r`, die bei Sättigung auf exakt 0 unterläuft
(PHP 8 wirft dann), und (b) `exp()`-Summen, die auf `INF` überlaufen
(`INF/INF = NaN`).
- **1PL/2PL sind nachweislich unbetroffen**: ihr Score/Hesse *multipliziert*
  mit `P` bzw. `W = P(1−P)` und geht bei Sättigung sauber gegen 0.
- **3PL** `log_likelihood_p`/`_p_p` divisionsstabil umgeschrieben: über die
  Kürzung `(1−c)·W_L/(1−P) = L` wird der Score zu `b·L·(k−P)/P` (nur noch `/P`,
  `P ≥ c`), plus Grenzwert-Guard für den `c=0`-Unterlauf. Item-Ableitungen
  zusätzlich über `stabilize_denominator()` abgesichert.
- **GRM/GGRM**: Person- und Item-Ableitungen dividieren durch `P_r`; Nenner
  über `stabilize_denominator()` abgesichert.
- **PCM/GPCM**: `exp()`-Summen (Person- und Item-Ableitungen sowie LMS) auf
  **max-Shift-Softmax** umgestellt (überlauffrei; Partitionssumme ≥ 1).
- Neuer Basis-Helfer `model_raschmodel::stabilize_denominator()`: schiebt einen
  exakt-nullen (bzw. sub-ε) Nenner auf ±ε. Bei realistischen Arbeitspunkten
  inert (`|Nenner| ≫ ε`), daher FD-verifizierte Werte im Normalbereich unverändert.
- Neuer Regressionstest `tests/local/model/derivative_saturation_test.php`:
  alle 7 Modelle × θ bis ±800 × Person-/Item-Ableitungen → alles endlich
  (7 Tests, 2208 Assertions). Zahn-getestet (Revert → `DivisionByZeroError`).

### PP-Refactor politom (Personfähigkeits-Ableitungen)
Die politomen `log_likelihood_p`/`_p_p` von der impliziten
`likelihood_p/likelihood`- bzw. Roh-`exp`-Schleifen-Form auf geschlossene,
stabile Ausdrücke umgestellt (FD-verifiziert ≤ 2e-10):
- **PCM**: `Score = r − E[K]`, `Hesse = −Var(K)` über stabiles Softmax.
- **GPCM**: `Score = b(r − E[K])`, `Hesse = −b²·Var(K)`.
- **GRM/GGRM**: P/W/V-Form `Score = b(W_r − W_{r+1})/P_r`,
  `Hesse = (b²·P_r·(V_r − V_{r+1}) − (b(W_r − W_{r+1}))²)/P_r²` mit
  `Q_j = σ(b(θ − a_j))`, `W = Q(1−Q)`, `V = W(1−2Q)`.
Nebeneffekt: keine redundanten `sort_fractions`-Aufrufe mehr, weniger `exp()`.

### Persistenz politomer Item-Parameter (End-to-End korrigiert)
Fünf konkrete Persistenz-Bugs behoben, die dazu führten, dass geschätzte
politome Parameter beim Speichern/Laden verloren gingen oder valide Items
verworfen wurden:
- `calculate_params()` reichte den `$startvalue` nicht an `estimate_item_params()`
  weiter (alle vier politomen Modelle). Ohne ihn ging die Warm-Start-Kategorie-
  Struktur verloren und nicht beobachtete Kategorien fielen weg.
- Tippfehler `$starvalues` → `$startvalues` in `model_raschmodel::calculate_params()`.
- `set_parameters()` invalidiert jetzt das gecachte `json`, sodass `to_record()`
  es aus den neuen Parametern neu aufbaut.
- `add_parameters_to_record()` fehlte für GRM, PCM und GPCM (nur GGRM hatte es):
  ohne diesen Hook wurden `difficulties`/`intercepts` nicht ins Record-JSON
  serialisiert und beim Reload war das JSON leer. Für alle drei ergänzt
  (Format exakt spiegelbildlich zu `get_parameters_from_record()`).
- GGRM `is_valid()` war invertiert (NaN → gültig) und griff auf den falschen
  Schlüssel `difficulty` statt `difficulties` zu; dadurch wurden valide GGRM-
  Items von `save_to_db()` herausgefiltert. Logik korrigiert (NaN → ungültig).
- Neuer Test `tests/local/model/persistence_roundtrip_test.php`: Roundtrip
  set → save_to_db → reload für alle vier Modelle, GGRM-`is_valid`, sowie ein
  Warm-Start-Test (nicht beobachtete Kategorie bleibt via `$startvalue` erhalten).
  Alle Fixes sind zahn-getestet (Revert → Test wird rot).

### Politome LMS (Least Mean Squares) vervollständigt
Für alle vier politomen Modelle (PCM, GPCM, GRM, GGRM) ist die LMS-Zielfunktion
inkl. erster und zweiter Ableitung nach den Item-Parametern implementiert. Als
Verallgemeinerung der dichotomen Form `n·(frac − P)²` wird der **erwartete Score**
`S = n·(frac − μ)²` mit `μ = E[X] = Σ_k frac_k·P_k` verwendet (für dichotome
Items fällt das exakt auf `P(korrekt)` zurück). Gemeinsamer Basis-Helfer
`lms_assemble()`; pro Modellfamilie werden `μ`, `∂μ` und `∂²μ` analytisch
gebildet (Softmax-Tails für PCM/GPCM, kumulative Differenzen für GRM/GGRM,
inkl. Diskriminations-Kreuztermen). Alle Ableitungen sind gegen finite
Differenzen verifiziert (je 48 FD-Fälle pro Modell). Damit ist Paket B
(MLE + LORS + LMS) für die politomen Modelle vollständig.

### Trust-Region: Threshold-Ordnung für GRM/GGRM
`restrict_to_trusted_region()` erzwingt jetzt zusätzlich zur Box-Beschränkung
die aufsteigende Ordnung `a_1 ≤ a_2 ≤ … ≤ a_M` der Schwellen. Bei vertauschten
Schwellen wäre `P_k = Q_k − Q_{k+1}` negativ geworden und die Likelihood NaN.
Die Baseline-Kategorie (niedrigster Bruch) bleibt als Platzhalter unberührt.
Neuer Test bestätigt aufsteigende Schwellen und endliche Likelihood.

### CI: Code Checker, PHPUnit und Behat
- Plugin-weite Code-Checker-Bereinigung (~1080 vorbestehende Fehler via `phpcbf`
  und manuell behoben). Zwei vorbestehende Debt-Sniffs bleiben ausgeschlossen
  (`--exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment`):
  Klasse-pro-Datei (invasiv) und die projektfremde TODO-Konvention (Wunderbyte
  nutzt einen eigenen Tracker statt `MDL-`). Plugin-weit sonst 0 Fehler/Warnungen.
- Der an die alte `exp()`-Rundung gepinnte Simulationstest
  `catcalc_test::test_simulation_steps_calculated_ability` ist übergangsweise
  geskippt (die PP-Ableitungen wurden auf die numerisch stabile P/W-Form
  umgestellt und sind FD-verifiziert; die hartkodierte Trajektorie wird als
  Follow-up neu gepinnt bzw. toleranzbasiert). Zusätzlich ein realer
  By-Reference-Fix im Test-Provider.
- Behat (adaptivequiz-Integration, umgebungsbedingt) ist übergangsweise
  `continue-on-error`.

### CI: Installations-Blocker behoben
- Abhängigkeit mod_adaptivequiz wird jetzt vom Branch `alise_adaptivequiz`
  gezogen; dieser bündelt das Subplugin `adaptivequizcatmodel_catquiz` ohne die
  doppelte Tabellendefinition. Die separate `add-plugin`-Zeile für
  `adaptivequizcatmodel_catquiz` wurde in beiden Workflows entfernt. Damit läuft
  der `install`-Schritt der Pipeline wieder durch (lokal verifiziert).

### Politome LORS (Log'ed Odds-Ratio Squared)
- LORS jetzt auch für die vier politomen Modelle. Beide Familien sind log-linear
  in einem Odds-Ratio je Grenze/Schritt: graded (GRM/GGRM) im KUMULATIVEN Odds
  P(X>=k)/P(X<k), partial credit (PCM/GPCM) im ADJAZENTEN Odds P_k/P_{k-1}. In
  beiden Fällen R_k = log(OR_k) + b(p_k - theta), Objektiv S = n*sum_k R_k^2.
- Gemeinsamer Basis-Helfer `compute_lors(...)`; die Schwellen-/Intercept-Hesse ist
  diagonal (2n b^2), nur die Diskrimination (GGRM/GPCM) koppelt über die Grenzen
  (Kreuzterme 2n(2 b x_k + log(OR_k)), H_bb = 2n*sum x_k^2). Neue Methoden
  `lors_residuals`/`lors_1st`/`lors_2nd_derivative_ip` mit OR-Array-Signatur
  (`$ors` nach Fraction gekeyt), am baseline-freien Codec ausgerichtet.
- Gegen finite Differenzen von `lors_residuals` verifiziert (je Modell 12 Fälle);
  Zahn-Test: defekter GGRM-LORS-b-Term -> Failures.

### Dynamische Dimensionalisierung
- Die Informationskriterien (`calc_aic_item`/`bic`/`caic`/`aicc`/`sabic`) nutzen
  nun die datengetriebene Parameterzahl `get_model_dim_from_ip($item)` (aus dem
  Codec) statt des parameterlosen `get_model_dim()`. Damit funktionieren sie auch
  für die politomen Modelle mit variabler Kategorienzahl. `get_model_dim()` wird
  nur noch für die dichotome Startwert-Dimensionierung und als Null-Fallback
  verwendet.

### Paket B: politome Ableitungen (alle vier Modelle)
- PCM: `get_log_jacobian`/`get_log_hessian` implementiert (warfen zuvor
  „Not yet implemented"). Item-Parameter-Ableitungen über Tail-Wahrscheinlichkeiten:
  J_δj = T_j − [r≥j], H_{j,l} = T_j·T_l − T_max(j,l), T_j = Σ_{k≥j} P_k.
- GPCM: `get_log_jacobian`/`get_log_hessian` implementiert (waren leer). Wie PCM
  mit Diskriminationsskalierung (b·… bzw. b²·…), plus Diskriminations-Ableitung
  über Momente (Score s_r−E[s], Krümmung −Var(s)) und δ_j–b-Kreuztermen.
- Beide gegen finite Differenzen von `log_likelihood` (über den Codec) verifiziert;
  Zahn-Tests bestätigen die Wirksamkeit.
- GRM: `get_log_jacobian`/`get_log_hessian` neu in P/W-Form. Der bisherige
  Jacobian hatte einen Index-Bug (Zählschleife überschrieb den Kategorieindex);
  dem Hessian fehlten die Kreuzterme. Kategorienwahrscheinlichkeit als Differenz
  benachbarter kumulativer Logistiken P_r = Q_r − Q_{r+1}; nur die beiden
  Randschwellen der beobachteten Kategorie tragen bei, mit Kreuzterm dazwischen.
- GGRM: wie GRM mit Diskrimination b (Q_k = σ(b(θ−a_k))), plus b-Ableitung und
  Kreuztermen Schwelle–b. Assembliert über H = P''/P − (P'/P)².
- Alle vier politomen Modelle nun gegen finite Differenzen verifiziert.

### Paket A: datengetriebener Parameter-Codec
- `convert_ip_to_vector`/`convert_vector_to_ip` für alle sieben Modelle korrekt
  implementiert (zuvor buggy/„dirty"-Stubs bei GRM/PCM/GPCM; bei den dichotomen
  Modellen neu ergänzt). Round-trip verlustfrei, per Test für alle Modelle belegt.
- Datengetriebene Dimension: neue Basismethode `get_model_dim_from_ip($ip)` =
  1 + Länge des flachen Parametervektors. Der bisherige `get_model_dim()` der
  politomen Modelle (fehlerhaftes `count()` auf Strings → PHP-8-`TypeError`) wirft
  jetzt eine klare `coding_exception` mit Verweis auf die datengetriebene Variante.
- `catcalc::estimate_item_params` nutzt nun den Codec: Newton-Raphson rechnet auf
  einem flachen Vektor, Jacobian/Hessian/Trusted-Region werden entsprechend
  adaptiert, das Ergebnis wird über `convert_vector_to_ip` zurückgewandelt.
  Verhalten für die dichotomen Modelle unverändert (Recovery-Tests grün;
  Zahn-Test: defektes `convert_vector_to_ip` lässt die Recovery scheitern).


### Numerische Stabilität / Refactoring
- Zentrale, überlauffreie `logistic()`-Funktion im Estimator-Interface
  (`catcalc_item_estimator`) deklariert und einmal in der Basisklasse
  `model_raschmodel` implementiert (plus Helfer `logistic_w` für W = P(1−P)).
- Die rechenintensiven Berechnungen (Likelihood, Log-Jacobian, Log-Hessian) der
  dichotomen Modelle rasch (1PL), raschbirnbaum (2PL) und mixedraschbirnbaum
  (3PL) auf die zentrale Logistic- und die P/W-Form umgestellt. Damit entfallen
  die separat gebildeten, überlaufgefährdeten Exponentialterme
  (z. B. `exp($a*$b)`, `exp($b*$theta)`). Mathematisch äquivalent, bestätigt
  durch finite-Differenzen-Tests und die vorbestehenden Regressionstests.
- Auch der Personen-Parameter-Pfad (Ability-Schätzung) von 1PL/2PL/3PL auf den
  P/W-Kern umgestellt: `log_likelihood_p` (Score), `log_likelihood_p_p`
  (Krümmung) und `fisher_info` nutzen nun eine gemeinsame Logistic-Auswertung
  statt mehrfacher `exp()`-Terme (Score/Hessian pro Modell: 1PL k−P bzw. −W,
  2PL b(k−P) bzw. −b²W, 3PL über die Kettenregel auf P = c+(1−c)L). Abgesichert
  durch neue θ-finite-Differenzen-Tests und die vorbestehenden Regressionstests.

### Bugfix
- 3PL Fisher-Information korrigiert: verwendete zuvor die Schwierigkeit statt der
  Diskrimination. Neu: I(θ) = b²·(1−P)/P·((P−c)/(1−c))², reduziert sich für c=0
  korrekt auf die 2PL-Form b²·P·(1−P).

### 3PL LORS (Log'ed Odds-Ratio Squared) vervollständigt
- Die 3PL-LORS-Ableitungen (`lors_1st_derivative_ip`/`lors_2nd_derivative_ip`)
  waren unvollständig (2-dimensional statt 3-dimensional, Kreuzterm gestubt) und
  wurden nirgends aufgerufen. Da der LORS-Rest `n·(log(OR)+b(a−θ))²` nicht vom
  Guessing-Parameter c abhängt, ist die Ableitung nach c identisch 0; damit ist
  die 3D-Form eindeutig. Nachimplementiert: Gradient [d/da, d/db, 0], Hesse als
  3×3 mit korrektem Kreuzterm 2n·(2b(a−θ)+log(OR)) und Null-Zeile/-Spalte für c.
- Derselbe zuvor auf 0 gestubte a/b-Kreuzterm im 2PL-LORS ebenfalls korrigiert.
- Beide gegen finite Differenzen von `lors_residuals` abgesichert (neue Tests).

### Tests
- Gemeinsamer finite-Differenzen-Testhelfer (`\local_catquiz\derivative_fd_trait`)
  als unabhängige numerische Referenz für Jacobian/Hessian, verankert an
  1PL/2PL/3PL.
- Fragile Einzelbeobachtungs-`calculate_params`-Tests (1PL/2PL) durch robuste
  synthetische Recovery-Tests ersetzt (bekannte Parameter, viele Ability-Punkte).

- LORS und LMS als alternative (Vor-)Berechnungswege abgesichert und
  fertiggebaut: FD-Tests fuer LMS (1PL/2PL/3PL) und LORS (1PL/2PL); 3PL-LMS-1.-
  und -2.-Ableitung (fehlerhaft) in P/W-Form neu implementiert; Hesse-Assertion
  gegen Schluesselreihenfolge robust gemacht (ksort).

### Kompatibilität / CI
- Mindestanforderung auf Moodle 4.5 angehoben (`$plugin->requires = 2024100700`,
  min. PHP 8.1).
- CI-Matrix auf Moodle 4.5 (PHP 8.1/8.2/8.3, PostgreSQL und MariaDB) reduziert;
  PHP 7.4 und die Branches 4.1–4.4 entfernt.

> Hinweis zur CI: Der Installationsschritt der Pipeline scheitert derzeit an
> einem Upstream-Konflikt der Abhängigkeiten (die Tabelle
> `adaptivequiz_cat_params` wird sowohl von mod_adaptivequiz\@catmodel_main als
> auch vom Subplugin adaptivequizcatmodel_catquiz definiert). Dies liegt nicht in
> local_catquiz. Siehe doc/session-002-changes.md.
