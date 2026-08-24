@local @local_catquiz @javascript
Feature: The quiz progress summary shows readable question and answer data and
  opens the question in a modal.
  As a student who finished an adaptive quiz attempt, the progress summary shows
  the real question title (not only the technical CAT label), labels the given
  answer clearly, and lets me open each answered question in a modal via the
  magnifier, which must never leave a hanging spinner.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | student1 | Student1  | Test     | toolgenerator1@example.com |
      | teacher  | Teacher   | Test     | toolgenerator3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher  | C1     | editingteacher |
    And the following "local_catquiz > questions" exist:
      | filepath                                                            | filename                               | course |
      | local/catquiz/tests/fixtures/quiz-adaptivetest-Simulation-small.xml | quiz-adaptivetest-Simulation-small.xml | C1     |
    And the following "local_catquiz > importedcatscales" exist:
      | filepath                                          | filename             |
      | local/catquiz/tests/fixtures/simulation_small.csv | simulation_small.csv |
    ## TinyMCE misbehaves in acceptance tests; use a plain editor.
    And the following config values are set as admin:
      | config      | value         |
      | texteditors | atto,textarea |
    And the following "activities" exist:
      | activity     | name             | course | section | idnumber         | intro               |
      | adaptivequiz | My Adaptive Quiz | C1     | 1       | adaptivecatquiz1 | Adaptive Quiz Intro |
    And the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy         | catquiz_selectfirstquestion | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | numberoffeedbackoptions | catquiz_showquestion |
      | C1     | adaptivecatquiz1 | catquiz  | Simulation | Infer lowest skill gap | -2                          | 2                    | 4                    | 0.0                       | 1000.0                    | 2                       | 1                    |
    ## Round-trip the generated CAT settings through the activity form so the
    ## adaptivequiz integration normalises and reserialises them.
    And I am on the "adaptivecatquiz1" Activity page logged in as teacher
    And I follow "Settings"
    And I wait until the page is ready
    And I click on "Save and return to course" "button"
    And I log out

  @javascript
  Scenario: The progress summary labels the answer and opens the question modal
    Given I am on the "adaptivecatquiz1" Activity page logged in as student1
    And I click on "Start attempt" "link"
    And I wait until the page is ready
    And I should see "Question 1"
    And I click on "richtige Antwort" "text" in the "Question 1" "question"
    And I click on "Submit answer" "button"
    And I should see "Question 2"
    And I click on "falsche Antwort 1" "text" in the "Question 2" "question"
    And I click on "Submit answer" "button"
    And I should see "Question 3"
    And I click on "richtige Antwort" "text" in the "Question 3" "question"
    And I click on "Submit answer" "button"
    And I should see "Question 4"
    And I click on "richtige Antwort" "text" in the "Question 4" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    ## Open the quiz progress summary tab that carries the answered-question table.
    When I click on "Quiz progress summary" "link"
    And I wait until the page is ready
    ## Secure the graphical-summary data mapping end to end: the responsive result
    ## table is rendered, the answer is clearly labelled as the given answer, and
    ## the actually chosen answer value is shown in that table.
    Then ".catquiz-graphicalsummary-table" "css_element" should exist
    And I should see "Given answer:"
    And I should see "richtige Antwort" in the ".catquiz-graphicalsummary-table" "css_element"
    ## The magnifier is a real button carrying the "Show question" aria-label.
    When I click on "Show question" "button"
    And I wait until the page is ready
    ## The answered question is shown in a modal dialogue.
    Then "Close" "button" should exist in the ".modal" "css_element"
    And I click on "Close" "button" in the ".modal" "css_element"
    ## Opening a second question must work as well (no stale, empty modal).
    And I click on "Show question" "button"
    And I wait until the page is ready
    Then "Close" "button" should exist in the ".modal" "css_element"
