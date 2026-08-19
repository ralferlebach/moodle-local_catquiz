# Engineering-Guide – local_catquiz

Dauerhaftes Referenzdokument (sitzungsübergreifend). Während die
`session-00X-changes.md` die Historie einzelner Durchgänge festhalten, bündelt
dieses Dokument die **wiederverwendbaren Lehren** – die Muster, Fallen und
Disziplinen, die sich in der Arbeit an den IRT-Modellen und am Schätzer bewährt
haben. Wer am Rechenkern, an den Ableitungen oder am Schätzer arbeitet, sollte
es vorher lesen.

---

## 1. Numerische Stabilität der IRT-Ableitungen

Die Person- und Item-Ableitungen werden an extremen Fähigkeits-/Parameterwerten
ausgewertet (θ bis ±800 kommt im stabilisierten Newton real vor). Zwei
Fehlerklassen treten dort auf, mit je einem festen Gegenmittel.

### 1.1 Division-Unterlauf → `model_raschmodel::stabilize_denominator()`

An Sättigungspunkten unterlaufen Nenner wie `P·(1−P)`, `P²`, `(1−P)²` auf exakt
`0.0`. PHP 8 wirft dann `DivisionByZeroError` (kein stiller `INF` mehr wie in
PHP 7). Nie ungeprüft durch solche Terme teilen. Stattdessen:

```php
$denom = self::stabilize_denominator($p * (1.0 - $p)); // |denom| >= 1e-12, vorzeichenerhaltend
```

Der Helfer ist an realistischen Punkten inert (gibt den Wert unverändert
zurück) und ersetzt nur den entarteten Nullnenner durch ein vorzeichenrichtiges
`eps`.

### 1.2 Exponential-Überlauf → Max-Shift-Softmax

Politome Modelle summieren `exp()` über Kategorie-Log-Gewichte. Bei großem θ
überläuft `exp()` auf `INF`, und `INF/INF = NAN`. Gegenmittel: vor dem
Exponenzieren das Maximum der Log-Gewichte abziehen (die Normierung ist
shift-invariant):

```php
$max = max($logweights);
$w   = array_map(fn($l) => exp($l - $max), $logweights);
```

Umgesetzt in `pcm_*`/`gpcm_*`-Momenten und den GRM/GGRM-Termen.

### 1.3 Multiplizieren statt Dividieren (das 1PL/2PL-Prinzip)

1PL und 2PL sind von Natur aus sättigungssicher, **weil** ihre Ableitungen mit
`P` bzw. `W = P(1−P)` *multiplizieren* statt zu dividieren. Wo möglich, die
Analytik so umformen, dass der potentiell verschwindende Faktor im Zähler steht.
Beispiel 3PL: statt `(1−c)·W_L/(1−P)` die algebraische Kürzung `= L` verwenden –
kein Nenner, kein Sonderfall.

### 1.4 Sättigungs-Kontrakt (verbindlich)

Jede neue oder geänderte Ableitung muss für θ ∈ {0, ±5, ±40, ±200, ±800} und
alle gültigen Antwortfraktionen **endlich** bleiben – auch für Grenzfälle wie
3PL mit `guessing = 0`. Der Regressionstest
`tests/local/model/derivative_saturation_test.php` deckt genau das ab; neue
Modelle/Ableitungen dort eintragen.

### 1.5 Verhältnis `l/p` statt getrenntem Zähler und Nenner (die Denormal-Falle)

Ein `$p <= 0`-Guard fängt nur den **exakt**-Null-Fall. Zwischen „normal" und
„exakt 0" liegt aber ein Bereich denormaler Werte (z. B. `p ≈ 1e-68`), in dem der
Guard *nicht* greift, `p` aber schon so klein ist, dass `p²` auf `0.0`
**unterläuft**. `stabilize_denominator(p²)` ersetzt die 0 dann durch `eps` – und
ein Term wie `−k·b²·l²·(1−p)²/p²`, der analytisch `−b²` liefern sollte, kollabiert
auf ≈0. In der 3PL-Zweitableitung zerstörte das die Kürzung `terma(+b²) +
termmid(−b²) = 0` und hinterließ ein falsch-positives `+b²` (Session 003).

Gegenmittel: die Terme über das **stabile Verhältnis** `ratio = l/p` ausdrücken
(= L/(c+(1−c)L)), das auch für denormale `l`, `p` wohldefiniert ist (→1 für c=0,
→0 für c>0), statt `p` und `p²` explizit im Nenner zu bilden:

```php
$ratio = $l / $p;                    // stabil, kein p^2-Unterlauf
$terma  = $b2 * $ratio * (1 - 2*$l) * ($k - $p);
$termmid = -$k * $b2 * $ratio ** 2 * (1 - $p) ** 2;
```

Der `$p <= 0`-Guard bleibt für den exakten Nullfall (dort wäre `l/p = 0/0 = NaN`).
Prüfung: 3PL mit `c=0` muss bit-nah der 2PL-Referenz entsprechen (Session-003-Test
`test_c0_reduces_to_2pl_at_saturation`, `max|3PL(c=0) − 2PL| ≈ 5e-15`).

---

## 2. Verifikationsdisziplin

Analytische Ableitungen und Refactorings werden **nie** allein durch bestandene
Fachtests abgenommen, sondern zusätzlich numerisch.

### 2.1 Finite-Differenzen-Harness

`classes/derivative_fd_trait.php` vergleicht analytische Item-Ableitungen gegen
zentrale finite Differenzen (`assert_gradient_close`, `assert_hessian_close`,
Toleranz aus `model_raschmodel::PRECISION`). Jede Item-Ableitung muss den
FD-Abgleich bestehen.

### 2.2 Bitgenaue Äquivalenz bei Refactorings

Wird eine bestehende Berechnung umgeschrieben (z. B. getrennte → kombinierte
Ableitung), muss der neue Pfad **bitgenau** dieselben Werte liefern wie der alte:
Zielwert `max |neu − alt| = 0.0e+0` über alle Modelle × Fraktionen × θ. Ein
CLI-Skript, das beide Pfade gegeneinander stellt, ist der schnellste Nachweis.

### 2.3 Sättigungs-Stresstest

Vor Auslieferung ein Harness über alle 7 Modelle × {Person, Item} × θ bis ±800
laufen lassen; Erwartung: 0 nicht-endliche Werte.

### 2.4 Zahn-Test (teeth test) – nicht verhandelbar

Jeder Fix und jeder neue Schutztest muss **rot werden, wenn man den Fix
zurücknimmt**. Ein Guard-Test, der auch ohne den Guard grün ist, testet nichts.
Konkret in dieser Session: Der reaktivierte Simulationstest wurde geprüft, indem
der Memo-Bug (siehe §3) reinjiziert wurde – der Test fiel (Trefferquote 0,9 %),
mit korrekter Verdrahtung grün.

### 2.5 Toleranz- statt Punktpinning bei sensiblen Trajektorien

Der stabilisierte Newton kippt an Rand-/Degeneriertfällen diskrete Zweige anders
als eine Vor-Refactor-Referenz. Die Abweichung ist dann **bimodal** (fast alle
Schritte quasi exakt, wenige komplett divergent), nicht graduell. Solche Tests
nicht auf jeden Einzelschritt pinnen, sondern **aggregiert** prüfen (z. B. „≥ 90 %
der Schritte treffen die Referenz auf 0.01"). Das fängt grobe Regressionen
zuverlässig und bleibt gegenüber legitimen Zweig-Unterschieden robust.

---

## 3. Fallstudie: PHP-Scoping-Falle im memoisierten Schätzer

**Symptom.** Nach Einführung einer memoisierten kombinierten Ableitung lieferte
der Personen-Schätzer völlig falsche Fähigkeiten (Median-Fehler 5.10 gegen die
Referenz), obwohl die kombinierte Methode bitgenau mit den Einzelmethoden
übereinstimmte.

**Ursache.** `$memo`/`$combined` waren funktions-, nicht blockskopiert. In einer
`foreach`-Schleife per `use (&$memo)` gebundene Closures binden an die
**Variable**, nicht an deren Wert. Jede Iteration wies `$memo = []` neu zu –
dadurch teilten sich *alle* Response-Closures denselben (letzten) Memo, und die
zwischengespeicherte Ableitung einer Response wurde für eine andere Response mit
gleichem Ability-Schlüssel zurückgegeben.

**Lehre.** Pro-Iteration-Zustand nie in einer schleifenlokal reassignten Variable
mit `use (&$…)` halten. Stattdessen in einen **eigenen Funktions-Scope** kapseln:

```php
private static function make_ability_derivative_callable($model, array $ip, float $frac): callable {
    $memo = []; // eigener Scope je Aufruf -> keine Teilung zwischen Responses
    return function ($pp) use ($model, $ip, $frac, &$memo) { /* ... */ };
}
```

**Meta-Lehre.** Der assertionsfreie „läuft ohne Exception"-Test hätte diesen Bug
nie gefangen – der **werteprüfende** Simulationstest schon. Sensible Rechenpfade
brauchen mindestens einen Test, der *Werte* gegen eine unabhängige Referenz
stellt, nicht nur „kein Absturz".

---

## 4. Verifikationsumgebung & Befehle

- **Env:** PHP 8.3, PostgreSQL 16, PHPUnit 9.6, echtes Moodle 4.5.13. Plugin in
  den Moodle-Baum spiegeln, dann PHPUnit initialisieren:
  ```
  rm -rf local/catquiz && cp -a <quelle> local/catquiz
  php -d max_input_vars=5000 admin/tool/phpunit/cli/init.php --no-composer-self-update
  vendor/bin/phpunit local/catquiz/catmodel/<modell>/tests/<modell>_test.php
  ```
- **phpcs (Moodle-Standard):**
  ```
  phpcs --standard=moodle --severity=1 --extensions=php \
        --exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment .
  ```
  Exit 0 = sauber; `phpcbf` zum Auto-Fix.
- **Schwerer Schätzer-Datensatz:** Der 3PL-Personabilitäts-Datensatz ist
  rechenintensiv (kann das Container-Timeout überschreiten). Isoliert per
  `--filter` laufen lassen, nicht im Gesamtlauf.
- **Docblock-Falle bei Einfügungen:** Wird eine neue Methode direkt vor einer
  bestehenden privaten Methode eingefügt, kann deren Docblock verwaisen
  (phpcs: „Missing docblock"). Nach solchen Einfügungen immer phpcs laufen lassen
  und die Docblock-Zuordnung visuell prüfen.

---

## 5. Versionierung & Auslieferung

- `$plugin->release` bleibt fix (aktuell `1.1.4`); nur die interne
  `$plugin->version` wird je Auslieferung erhöht.
- Zwei ZIPs, oberster Ordner `catquiz/`:
  - `…-release.zip`: via `git archive`, respektiert `.gitattributes`
    (`export-ignore` schließt `.github/`, `doc/`, Tooling aus) – das deploybare
    Plugin.
  - `…-<version>.zip`: das volle Arbeitspaket inkl. Tooling und `doc/`.
- Vor jeder Auslieferung die Checkliste in §7 abarbeiten.

**Version-Bump erzwingt Schema-Rebuild (verbindlich).** Moodle wendet Änderungen
an `db/install.xml`/`db/upgrade.php` **nur** an, wenn `$plugin->version` steigt.
Bleibt die Version gleich, sieht Moodle „schon installiert" und lässt das alte
Schema stehen – neue Tabellen fehlen dann (Symptom in Session 003:
`Table "local_catquiz_qhashmap" does not exist`, obwohl in `install.xml`
deklariert). Wer `install.xml`/`upgrade.php` anfasst, **muss** die interne Version
erhöhen und danach die PHPUnit-Umgebung reinitialisieren.

---

## 6. Abhängigkeiten & CI

**adaptivequiz / `attemptfeedbackeditor`.** `adaptivequiz_add_instance()` liest
`$adaptivequiz->attemptfeedbackeditor` unbedingt, der Test-Generator setzt die
Property nie → „Undefined property" bei Instanzerzeugung in Integrationstests.
Verifizierter Befund: Auch der Branch `alise_adaptivequiz` trägt denselben Bug –
ein Branch-Swap behebt es **nicht**. Gegenmittel auf Testseite:
`'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE]` bei
`create_instance` übergeben.

**Diagnosemethode für Dependency-Bugs.** Den fraglichen Branch der Dependency
lokal in den Moodle-Baum klonen und die Tests dagegen laufen lassen, statt der
Vermutung zu vertrauen, ein bestimmter Branch „behebe es schon". Erst der
Klon-Nachweis zeigte, dass alise denselben Bug hat.

**Behat.** Bleibt bewusst non-blocking (`continue-on-error`), solange die
adaptivequiz-Integration am obigen Dependency-Bug hängt und lokal (ohne
Chrome/Selenium) nicht verifizierbar ist. Der Workflow-Kommentar nennt die
genaue Ursache; scharf schalten ist ein Einzeiler, sobald der Fork seinen
Generator fixt.

**Moodle-4.5-Core-API: `external_api` ist namespaced.** In Moodle 4.5 sind die
globalen Klassen `external_api`, `external_function_parameters`,
`external_single_structure`, `external_value` (aus `lib/externallib.php`)
**entfernt**; es existiert nur noch der `core_external\`-Namespace (Laufzeit-Check:
`class_exists('external_api')` = false, `class_exists('core_external\external_api')`
= true). Webservice-Klassen unter `classes/external/` müssen `use core_external\…`
verwenden; das alte `require_once($CFG->libdir.'/externallib.php')` entfällt (die
`core_external`-Klassen sind autogeladen – und das Include löste in
Nicht-Isolated-PHPUnit-Prozessen zusätzlich einen Coding-Error aus). Zwei Fallen
dabei: (a) auf **oberster** Parameterebene verbietet Moodle `VALUE_OPTIONAL` – dort
`VALUE_DEFAULT, <default>` nutzen (in verschachtelten `execute_returns`-Strukturen
ist `VALUE_OPTIONAL` weiterhin korrekt); (b) nach Entfernen des Includes wird der
`defined('MOODLE_INTERNAL') || die();`-Guard in der seiteneffektfreien Klassendatei
von phpcs als überflüssig moniert und ist zu entfernen.

---

## 7. Checkliste vor Auslieferung

1. Alle 7 Modell-Suiten grün (erwartete Test-/Skip-Zahlen unverändert).
2. `derivative_saturation`, `persistence_roundtrip`, `parameter_codec`,
   `model_raschmodel`, `mathcat`, `matrixcat`, `catcalc` grün.
3. FD-Abgleich neuer Item-Ableitungen; Bitgenau-Abgleich bei Refactorings.
4. Sättigungs-Stresstest: 0 nicht-endliche Werte.
5. Neue Fixes/Guards zahn-getestet (Rücknahme → rot).
6. phpcs plugin-weit Exit 0.
7. Interne Version erhöht; CHANGELOG + Session-Doku aktualisiert.
8. Beide ZIPs gebaut, Datei-/Ausschlusszahlen geprüft, präsentiert.
9. Ehrlicher Abschlussbericht inkl. verbleibender Übergangszustände
   (z. B. Behat non-blocking, warum).
