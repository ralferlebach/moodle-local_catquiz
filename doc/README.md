# doc/ – Übersicht

Zwei Arten von Dokumenten:

## Dauerhaft (sitzungsübergreifend, vor der Arbeit lesen)

- **engineering-guide.md** – Wiederverwendbare Lehren: numerische Stabilität der
  IRT-Ableitungen, Verifikationsdisziplin (FD-Harness, Bitgenau-Abgleich,
  Sättigungs-Stresstest, Zahn-Test, toleranzbasierte Trajektorientests), die
  PHP-Scoping-Falle im memoisierten Schätzer, Verifikationsumgebung/-befehle,
  Versionierung/Auslieferung, adaptivequiz/CI, Auslieferungs-Checkliste.
- **alise-documentation-plan.md** – Empfohlene weitere Dokumente für die
  ALiSe-Arbeitsstränge (Numerische Algorithmen; Diagramme+Feedback+Statistik;
  Abschluss+Ergebnisspeicherung; Privacy/Datenschutz; Performance CAT-Manager &
  Statistik; Performance Testadministration; Migration zu Moodle 5.x; zukünftige
  IRT-Modelle) inkl. Zweck, Inhalt, Priorität und Abhängigkeiten.
- **environment-setup.md** – Reproduzierbarer Aufbau der Test-/Laufzeitumgebung
  (Moodle, PHP, PostgreSQL, PHPUnit, Behat, phpcs/moodle-cs, Gherkin/Mustache-Lint)
  inkl. verifizierter Fixpunkte und typischer Stolpersteine.
- **session-start-prompt.md** – Kopierfertiger Prompt für den Start neuer
  Sitzungen (Projekt, Pfade, Prüf-Ablauf, verbindliche Disziplinen, Auslieferung).

## Historisch (Änderungshistorie je Durchgang)

- **session-001-changes.md** – Absicherung der dichotomen CAT-Modelle.
- **session-002-changes.md** – P/W-Refactor, politome Ableitungen, Codec,
  LORS/LMS, Persistenz-Fixes, Sättigungsfestigkeit, PP-Refactor Stufe 2,
  toleranzbasierter Simulationstest, Code-Hygiene, adaptivequiz-Diagnose.
- **session-003-changes.md** – Branch-Integration (aktueller Stand als Basis),
  Dependency-Branches (alise_adaptivequiz + gebündelte Bridge), Moodle-4.5-
  Migration external_api→core_external, Version-Bump-Schema-Rebuild,
  3PL-ratio-Sättigungsfix, strategy_test-Portabilität, Release 1.1.4.
- **session-004…012-changes.md** – Numerik-/Politom-Arbeitsstränge, M1/M2-
  Schätzer-Vergleich (Newton/BFGS/GA→Newton), politome IP-Recovery-Referenz.
- **session-013-changes.md** – CI-Fixes (Behat/qbank-Kontext, phpcs/MatrixException),
  IP-Edge-Case-Experimente (IP-5/IP-10/IP-9, GA eigenständig) inkl. §D-Fixtures
  und Regressionstest.
- **session-014-changes.md** – Issue #44: Scheduled-Nachberechnung erzeugt keine
  neuen Kontexte mehr (sichere Defaults, persistenter Kontext-Load, In-Place-Update).
- **session-015-changes.md** – Issue #43: zentraler Berechnungsdienst mit zwei Modi
- **session-016-changes.md** – Experiment-Konsequenzen K1 (Newton-Gate/BFGS-Rescue) und K2 (NaN-feste GRM-Startschwellen) umgesetzt.
- **session-017-changes.md** – Experiment-Konsequenzen K3 (reduzierte Struktur), K4 (KKT-Gate), K5 (Identifizierbarkeits-Report) umgesetzt.
- **session-018-changes.md** – CI-Fix: K2 zurückgenommen (K3 ist der Wurzelfix), phpdoc + catscale-float-cast.
  (inkrementell/disruptiv), Lock-API, Lauf-Zusammenfassung und CAT-Management-UI.
- **experiments-ip-edgecases.md** – strukturierte Zusammenstellung der
  IP-Edge-Case-Experimente (Annahmen, Durchführung, Ergebnisse, Befunde,
  Konsequenzen).

Übergeordnet: `../CHANGELOG.md` (Auslieferungsnotizen unter Release-Label 1.1.4),
`../README.md` (Plugin-Beschreibung).
