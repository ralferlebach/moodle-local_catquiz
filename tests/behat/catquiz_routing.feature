@local @local_catquiz @javascript
Feature: An attempt routes its participant into courses and groups (issues #129, #130).
  At the end of an attempt the participant is enrolled into the course and added to the group that
  the range of each routing scale names - the scales the strategy reports, each once. Checked on the
  memberships Moodle actually holds, not on the message.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | student1 | Student1  | Test     | toolgenerator1@example.com |
      | teacher  | Teacher   | Test     | toolgenerator3@example.com |
    And the following "courses" exist:
      | fullname      | shortname |
      | Course 1      | C1        |
      | Target course | TC        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher  | C1     | editingteacher |
    And the following "groups" exist:
      | name              | course | idnumber |
      | Routed Simulation | C1     | RS       |
      | Routed SimA       | C1     | RA       |
      | Routed SimA01     | C1     | RA01     |
      | Routed SimA02     | C1     | RA02     |
      | Routed SimB       | C1     | RB       |
    And the following "local_catquiz > questions" exist:
      | filepath                                                            | filename                               | course |
      | local/catquiz/tests/fixtures/quiz-adaptivetest-Simulation-small.xml | quiz-adaptivetest-Simulation-small.xml | C1     |
    And the following "local_catquiz > importedcatscales" exist:
      | filepath                                          | filename             |
      | local/catquiz/tests/fixtures/simulation_small.csv | simulation_small.csv |
    And the following config values are set as admin:
      | config      | value         |
      | texteditors | atto,textarea |
    And the following "activities" exist:
      | activity     | name             | course | section | idnumber         | intro               |
      | adaptivequiz | My Adaptive Quiz | C1     | 1       | adaptivecatquiz1 | Adaptive Quiz Intro |

  @javascript
  Scenario: CAT routes the root - into the course and into its group
    Given the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy | catquiz_selectfirstquestion | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | routingcourse | routinggroupprefix |
      | C1     | adaptivecatquiz1 | catquiz  | Simulation | CAT            | -2                          | 4                    | 4                    | 0.0                       | 1000.0                    | TC            | Routed             |
    And I am on the "adaptivecatquiz1" Activity page logged in as teacher
    And I follow "Settings"
    And I wait until the page is ready
    And I click on "Save and return to course" "button"
    And I log out
    When I am on the "adaptivecatquiz1" Activity page logged in as student1
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
    And I click on "falsche Antwort 1" "text" in the "Question 4" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I log out
    And I log in as "admin"
    And I am on the "Target course" "enrolled users" page
    Then I should see "Student1 Test"
    And I am on the "Course 1" "enrolled users" page
    And I should see "Routed Simulation" in the "Student1 Test" "table_row"
    And I should not see "Routed SimA" in the "Student1 Test" "table_row"

  @javascript
  Scenario: Classical test routes every measured valid scale, each into its own group once
    ## The fixed form asks the four SimA01 items, then the four SimA02 items; right and wrong in turn,
    ## so each of them, SimA and the root is measured with a fraction of one half. SimB is not measured.
    Given the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy  | catquiz_minquestions | catquiz_maxquestions | catquiz_minquestionspersubscale | catquiz_standarderror_min | catquiz_standarderror_max | routinggroupprefix |
      | C1     | adaptivecatquiz1 | catquiz  | Simulation | Classical test  | 8                    | 8                    | 2                               | 0.0                       | 1000.0                    | Routed             |
    And I am on the "adaptivecatquiz1" Activity page logged in as teacher
    And I follow "Settings"
    And I wait until the page is ready
    And I click on "Save and return to course" "button"
    And I log out
    When I am on the "adaptivecatquiz1" Activity page logged in as student1
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
    And I click on "falsche Antwort 1" "text" in the "Question 4" "question"
    And I click on "Submit answer" "button"
    And I should see "Question 5"
    And I click on "richtige Antwort" "text" in the "Question 5" "question"
    And I click on "Submit answer" "button"
    And I should see "Question 6"
    And I click on "falsche Antwort 1" "text" in the "Question 6" "question"
    And I click on "Submit answer" "button"
    And I should see "Question 7"
    And I click on "richtige Antwort" "text" in the "Question 7" "question"
    And I click on "Submit answer" "button"
    And I should see "Question 8"
    And I click on "falsche Antwort 1" "text" in the "Question 8" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I log out
    And I log in as "admin"
    And I am on the "Course 1" "enrolled users" page
    Then I should see "Routed Simulation" in the "Student1 Test" "table_row"
    And I should see "Routed SimA01" in the "Student1 Test" "table_row"
    And I should see "Routed SimA02" in the "Student1 Test" "table_row"
    And I should not see "Routed SimB" in the "Student1 Test" "table_row"
