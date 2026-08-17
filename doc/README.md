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

Übergeordnet: `../CHANGELOG.md` (Auslieferungsnotizen unter Release-Label 1.1.4),
`../README.md` (Plugin-Beschreibung).
