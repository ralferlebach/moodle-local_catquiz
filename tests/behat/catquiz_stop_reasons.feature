@local @local_catquiz @javascript
Feature: An attempt ends for a reason the participant is told, and the result follows it (issues #141, #134).
  The pool runs out before the minimum: no valid result. The pool runs out after the minimum: a
  result. The time runs out: the attempt ends with what was answered in time.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | student1 | Student1  | Test     | toolgenerator1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    ## Two questions: the pool is exhausted after the second.
    And the following "local_catquiz > questions" exist:
      | filepath                                              | filename                 | course |
      | local/catquiz/tests/fixtures/catquiz_resources_mc.xml | catquiz_resources_mc.xml | C1     |
    And the following "local_catquiz > importedcatscales" exist:
      | filepath                                              | filename                 |
      | local/catquiz/tests/fixtures/catquiz_resources_mc.csv | catquiz_resources_mc.csv |
    And the following config values are set as admin:
      | config      | value         |
      | texteditors | atto,textarea |
    And the following "activities" exist:
      | activity     | name             | course | section | idnumber         | intro               |
      | adaptivequiz | My Adaptive Quiz | C1     | 1       | adaptivecatquiz1 | Adaptive Quiz Intro |

  @javascript
  Scenario: The pool runs out before the minimum: the participant is told, and there is no valid result
    Given the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | scalefeedbacktexts |
      | C1     | adaptivecatquiz1 | catquiz  | Ressourcen | CAT            | 3                    | 5                    | 0.0                       | 1000.0                    | 1                  |
    When I am on the "adaptivecatquiz1" Activity page logged in as student1
    And I click on "Start attempt" "button"
    And I wait until the page is ready
    And I click on "richtige Antwort" "text" in the "Question 1" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I click on "falsche Antwort 1" "text" in the "Question 2" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    Then I should see "There are no further suitable questions for you at the moment" in the "Your test has ended" "dialogue"
    And I click on "OK" "button" in the "Your test has ended" "dialogue"
    And I should see "Test result can not be calculated because minimum number of questions was not reached"
    And I should not see "Question 3"
    And "Submit answer" "button" should not exist

  @javascript
  Scenario: The pool runs out after the minimum: the participant is told, and the result stands
    Given the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | scalefeedbacktexts |
      | C1     | adaptivecatquiz1 | catquiz  | Ressourcen | CAT            | 1                    | 5                    | 0.0                       | 1000.0                    | 1                  |
    When I am on the "adaptivecatquiz1" Activity page logged in as student1
    And I click on "Start attempt" "button"
    And I wait until the page is ready
    And I click on "richtige Antwort" "text" in the "Question 1" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I click on "falsche Antwort 1" "text" in the "Question 2" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    Then I should see "There are no further suitable questions for you at the moment" in the "Your test has ended" "dialogue"
    And I click on "OK" "button" in the "Your test has ended" "dialogue"
    And I should not see "minimum number of questions was not reached"
    And I should not see "No valid test result could be determined for this attempt."
    And I should see "Written feedback for Ressourcen"

  @javascript
  Scenario: The time runs out: the attempt ends with what was answered in time
    Given the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | scalefeedbacktexts |
      | C1     | adaptivecatquiz1 | catquiz  | Ressourcen | Classical test | 1                    | 2                    | 0.0                       | 1000.0                    | 1                  |
    And the CAT settings of "adaptivecatquiz1" limit an attempt to 5 minutes
    When I am on the "adaptivecatquiz1" Activity page logged in as student1
    And I click on "Start attempt" "button"
    And I wait until the page is ready
    And I click on "richtige Antwort" "text" in the "Question 1" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I should see "Text of resource question two."
    And the running CAT attempt of "student1" started 10 minutes ago
    And I click on "richtige Antwort" "text" in the "Question 2" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    Then I should see "The time available for this test has run out" in the "Your test has ended" "dialogue"
    And I click on "OK" "button" in the "Your test has ended" "dialogue"
    And "Submit answer" "button" should not exist
