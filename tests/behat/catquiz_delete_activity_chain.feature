@local @local_catquiz @javascript
Feature: Deleting an activity on the course page removes its CAT data and nothing else (adaptivequizcatmodel_catquiz #11).
  The whole chain as a teacher starts it: the course page, the recycle bin, the deletion task, the host and
  the CAT model. The ids are crossed on purpose: the activity attempt of the person whose activity is deleted
  has the number of the CAT attempt of someone in another activity. That other attempt keeps its saved
  state and can be continued.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | student1 | Student1  | Test     | toolgenerator1@example.com |
      | student2 | Student2  | Test     | toolgenerator2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
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
      | activity     | name     | course | section | idnumber         | intro               |
      | adaptivequiz | Quiz One | C1     | 1       | adaptivecatquiz1 | Adaptive Quiz Intro |
      | adaptivequiz | Quiz Two | C1     | 1       | adaptivecatquiz2 | Adaptive Quiz Intro |
    And the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | scalefeedbacktexts |
      | C1     | adaptivecatquiz1 | catquiz  | Ressourcen | Classical test | 1                    | 2                    | 0.0                       | 1000.0                    | 1                  |
      | C1     | adaptivecatquiz2 | catquiz  | Ressourcen | Classical test | 1                    | 2                    | 0.0                       | 1000.0                    | 1                  |

  Scenario: The activity of one person is deleted; the crossed attempt of another person is untouched
    ## student1 runs an attempt in "Quiz Two"; its CAT id is still to come for the activity attempts.
    Given the CAT attempt numbering is 5 ahead of the activity attempts
    And I am on the "adaptivecatquiz2" Activity page logged in as student1
    And I click on "Start attempt" "link"
    And I wait until the page is ready
    And I click on "richtige Antwort" "text" in the "Question 1" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I should see "Text of resource question two."
    And I log out
    ## student2's activity attempt in "Quiz One" gets the number of student1's CAT attempt.
    And the next activity attempt id equals the CAT attempt id of "student1"
    And I am on the "adaptivecatquiz1" Activity page logged in as student2
    And I click on "Start attempt" "link"
    And I wait until the page is ready
    And I click on "falsche Antwort 1" "text" in the "Question 1" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I click on "richtige Antwort" "text" in the "Question 2" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And "[data-region='adaptivequiz-attempt-feedback']" "css_element" should be visible
    And the CAT attempt of "student2" is filed under its own activity attempt with a different id
    And I log out
    ## The teacher deletes "Quiz One" on the course page; the deletion runs as the task the recycle bin starts.
    When I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I delete "Quiz One" activity
    And I run all adhoc tasks
    Then no attempt data of "student2" is left
    And the saved progress of the CAT attempt of "student1" still belongs to "student1"
    And the orphan inventory of the CAT model finds nothing
    And I log out
    ## The other person goes on where they stopped.
    When I am on the "adaptivecatquiz2" Activity page logged in as student1
    And I click on "Continue attempt" "button"
    And I wait until the page is ready
    And I should see "Text of resource question two."
    And I click on "richtige Antwort" "text" in the "Question 2" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    Then "[data-region='adaptivequiz-attempt-feedback']" "css_element" should be visible
    And the CAT attempt of "student1" is filed under its own activity attempt with a different id
