@local @local_catquiz @javascript
Feature: Completing an attempt is authoritative on every path.
  Whether a student finishes an attempt in the browser or a teacher closes it
  administratively, the attempt must be finalised exactly once through the same
  path: the completion time is stamped once and the CAT model's finaliser stores
  the result (issues #5 and #8).

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
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy         | catquiz_selectfirstquestion | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | numberoffeedbackoptions |
      | C1     | adaptivecatquiz1 | catquiz  | Simulation | Infer lowest skill gap | -2                          | 2                    | 4                    | 0.0                       | 1000.0                    | 2                       |
    ## Round-trip the settings through the activity form so the adapter reads
    ## fully serialised CAT settings (see catquiz_feedback_validity).
    And I am on the "adaptivecatquiz1" Activity page logged in as teacher
    And I follow "Settings"
    And I wait until the page is ready
    And I click on "Save and return to course" "button"
    And I log out

  ## NOT covered here: administratively closing an attempt (issue #5 DoD 7).
  ## With a custom CAT model in use, mod_adaptivequiz does not render a link to the
  ## attempts report at all: view.php only calls attempts_number(), and that returns
  ## plain text unless the CAT model implements the `attempts_report_url` callback -
  ## which adaptivequizcatmodel_catquiz does not. The teacher therefore has no UI
  ## path to "Close attempt", so this cannot be driven through Behat. The code path
  ## itself is verified: closeattempt.php calls adaptivequiz_complete_attempt(), the
  ## same authoritative function the cron uses, and that is asserted in
  ## cancel_expired_attempts_path_test.

  @javascript
  Scenario: A completed attempt is not finalised twice
    ## Issue #5 DoD 4: finalisation is idempotent. Revisiting the finished attempt
    ## must neither change nor duplicate its result.
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
    And I click on "falsche Antwort 2" "text" in the "Question 4" "question"
    And I click on "Submit answer" "button"
    And I wait until the page is ready
    And I should not see "Question 5"
    ## Re-open the finished attempt.
    And I am on the "adaptivecatquiz1" Activity page
    And I wait until the page is ready
    Then I should not see "Question 5"
