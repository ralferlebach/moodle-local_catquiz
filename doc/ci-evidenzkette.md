# CI-Evidenzkette (#176 AK4), Stand 27.11.2026

Was ein Nachweis aus der CI belegt, woher er kommt und was er nicht belegt. Gilt für local_catquiz auf beiden Linien
(`.github/workflows/moodle-plugin-ci-dev.yml`, Push und PR; `…-main.yml` gleich aufgebaut).

## 1. Von Commit zu Beleg

| Glied | Woher | Wo im Lauf |
|---|---|---|
| Commit des Plugins | `GITHUB_SHA` | jede Evidence-Datei, Kopf `Run … @ <sha>` |
| Partnerstände (Host, Adapter) | `.github/ci-partner-versions.txt`; der Job wartet, bis GitHub sie hat (`await_dependencies.sh`) | Evidence-Tabelle „Component \| Commit \| Version“ je Lauf, mit den gebauten Commits aller drei Plugins |
| Moodle, PHP, Datenbank | Matrixzelle | Evidence-Kopf |
| PHPUnit | `--log-junit` der Zelle | `evidence-phpunit-<moodle>-<php>-<db>` (evidence.md/.json):<br>Summen, jede übersprungene oder unvollständige Methode mit Meldung,<br>Pflichttests aus `.github/ci-required-tests.txt` (lief, bestanden, nicht übersprungen). Fehlt einer, schlägt der Job fehl. |
| Behat | Konsolenlog und `behat-executed.jsonl` (Kontext `behat_catquiz_evidence`, je Szenario Datei, Zeile, Ergebnis) | `evidence-behat`:<br>Pflichtfeatures aus `.github/ci-required-features.txt`, jedes Szenario und jede Beispielzeile gelaufen und im letzten Lauf bestanden. Rerun zählt, Skip nicht. |
| Playwright | Lauf der Suite | `catpath-evidence-<run_id>` und Report-Artefakte |
| Selbsttests der Prüfwerkzeuge | `ci_evidence_selftest.py` (19 Fälle), `await_*_selftest.sh` | Job „PHP lint (phplint)“, Logs `ci-logs/*selftest.log` |

## 2. Was zählt, was nicht

- **Grüner Job:** Er sagt nicht, dass jeder Testfall lief. Das sagt der Evidence-Record:
  - Pflichttests einzeln;
  - übersprungene und unvollständige Tests aufgelistet. Sie zählen nicht als bestanden, und das Verdikt nennt sie.
- **Behat ohne Record:** Fehlt `--behat-executed`, ist das nur eine Inventur der Feature-Dateien; der Job schlägt dann
  fehl. Ein leerer Record wird als solcher gemeldet (seit 27.11.2026).
- **Pflichtfeature fehlt im Zweig:** Der Job ist rot („feature file missing“).
- **Gleichnamige Szenarien:** Sie werden über Datei und Zeile getrennt, nicht über den Titel.
- **Behat-Datenbank:** Engine-Behat läuft auf MariaDB. Ein PostgreSQL-Behat-Lauf wird nicht behauptet.
- **Last (k6, JMeter):**
  - Workflows nur auf Anforderung (`workflow_dispatch`); sie gehören nicht zur Pflicht-CI.
  - Lokale k6-Läufe stehen in den Nachweisen mit Prüfstand: 2 CPUs, kein SLO-Urteil.
  - Ein Lastnachweis gilt erst mit einem Lauf auf der Zielumgebung als erbracht.
- **Longrun:** Der Adapter überspringt ihn in der CI-Zelle; er läuft lokal (`CATQUIZ_LONGRUN=1`).

## 3. Abgleich mit einem Lauf (Vorgehen)

1. Run-ID und Commit aus dem Push.
2. In jeder PHPUnit-Zelle `evidence-phpunit-…` öffnen. Prüfen:
   - Commit, Partnerstände und Moodle/PHP/DB;
   - Summen;
   - Pflichttests ✔;
   - übersprungene Methoden mit Grund.
3. `evidence-behat` öffnen:
   - Summenzeilen und Rerun-Zeilen;
   - Pflichtfeatures: „ran n of n, passed n“.
4. Playwright: Ergebnis und Artefakt.
5. Selbsttests im Lint-Job.
6. Lokale Belege (Messungen, Zahntests) sind keine CI-Belege. Sie stehen mit Commit im Nachweis-ZIP der
   Auslieferung.

## 4. Abgleich mit den Läufen vom 27.11.2026 (AK4)

| | 1.3 (`migration-zu-moodle-5.x`) | 1.2 (`ALiSe-v-1.2.0-legacy`) |
|---|---|---|
| Lauf | „Moodle Plugin CI Dev“ 46036851422 (#216), Push | „Moodle Plugin CI Dev“ 46036954976 (#217), Push |
| Commit | 4ffe20a (1.3.2, 2026112703) | b59875f (1.2.2, 2026112702) |
| Ergebnis | Success, 19 Jobs | Success, 11 Jobs |
| PHPUnit | 12 Zellen (5.1/5.2/5.3 × PHP 8.3/8.4 × MariaDB/PG); je Zelle `evidence-phpunit-…` | 4 Zellen (4.5 × PHP 8.2/8.3 × MariaDB/PG); je Zelle `evidence-phpunit-MOODLE_405_STABLE-…` |
| Behat | `evidence-behat` (PHP 8.3, Chrome, MariaDB) | `evidence-behat` (PHP 8.2, Chrome, MariaDB) |
| Playwright | `catpath-evidence-46036851422` | `catpath-evidence-46036954976` |
| Selbsttests | Job „PHP lint (phplint)“ | Job „PHP lint (phplint)“ |

**Inhalt der Evidence-Artefakte** (Auswertung der Joblogs im Audit vom 27.11. abends; ich selbst kann die Artefakte
ohne Anmeldung nicht öffnen):
- in jeder der 16 Zellen:
  - `progress_events_test` 16/16;
  - `progress_integrity_test` 10/10;
  - `context_item_pool_test` 16/16;
  - `standard_error_contract_test` 4/4;
  - ohne Skips in diesen Suiten;
- je Zelle insgesamt zwei übersprungene und zwei unvollständige Tests, außerhalb der Pflichtliste;
- Behat 141/145 (1.3) und 144/145 (1.2): alle Pflichtfeatures bestanden, kein Rerun;
- Playwright 37/37 je Linie;
- Evidence-Selbsttests 19/19 je Linie.

**Annotation „exit code 1“ im Job „Code analysis“ (1.3):** Sie stammt aus einem der beratenden Schritte phpmd oder
phpcpd (`continue-on-error`). phpcs ist dort blockierend. Der Job zählt für „CI complete“ als bestanden, sonst wäre der
Lauf nicht grün.

**Nicht Teil dieser Läufe**, also nicht als Pflicht-CI zu lesen:
- k6 und JMeter;
- Adapter-Longrun;
- PostgreSQL-Behat;
- die lokalen Messungen und Zahntests der Auslieferungen.
