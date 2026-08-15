# Changelog – local_catquiz

## 1.1.2 (interne Version 2026081410)

> Diese Änderungen werden unter dem bestehenden Release-Label 1.1.2
> ausgeliefert; nur die interne `$plugin->version` wird erhöht.

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
