@local @local_catquiz
Feature: The feedback of a CAT test with a single scale (adaptivequizcatmodel_catquiz #2).
  A test on a scale tree of one scale has the fields of that scale and nothing to navigate: no button to open
  another scale, no second panel. What is typed is saved and shown again.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | teacher1 | Teacher1  | Test     | toolgenerator3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "local_catquiz > questions" exist:
      | filepath                                                            | filename                               | course |
      | local/catquiz/tests/fixtures/quiz-adaptivetest-Simulation-small.xml | quiz-adaptivetest-Simulation-small.xml | C1     |
    And the following "local_catquiz > importedcatscales" exist:
      | filepath                                           | filename              |
      | local/catquiz/tests/fixtures/simulation_single.csv | simulation_single.csv |
    And the following "activities" exist:
      | activity     | name             | course | section | idnumber         |
      | adaptivequiz | Adaptive CATquiz | C1     | 1       | adaptivecatquiz1 |
    And the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales | cateststrategy         | catquiz_selectfirstquestion | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | numberoffeedbackoptions |
      | C1     | adaptivecatquiz1 | catquiz  | Single    | Infer lowest skill gap | -2                          | 7                    | 0.4                       | 0.6                       | 2                       |

  @javascript
  Scenario: One scale: its editors, no navigation, and what is typed is kept
    When I am on the "adaptivecatquiz1" Activity page logged in as teacher1
    And I follow "Settings"
    And I wait until the page is ready
    And I click on "Feedback for “Single”" "text"
    Then the settings form has 2 feedback editors
    And "//div[@data-name='feedback_scale_Single_range_1']" "xpath_element" should exist
    And "Edit feedback for “Single”" "button" should not be visible
    And "(//div[@data-region='catquiz-feedback-panel'])[2]" "xpath_element" should not exist
    And "#page-footer" "css_element" should exist
    When I set the field "Feedback" in the "//div[@data-name='feedback_scale_Single_range_1']" "xpath_element" to "Feedback for the single scale"
    And I click on "Save and display" "button"
    And "Save and display" "button" should not exist
    And I follow "Settings"
    And I wait until the page is ready
    And I click on "Feedback for “Single”" "text"
    Then the field "Feedback" in the "//div[@data-name='feedback_scale_Single_range_1']" "xpath_element" matches value "Feedback for the single scale"
    And the settings form has 2 feedback editors
    And "Edit feedback for “Single”" "button" should not be visible

  Scenario: One scale without JavaScript: the fields are there, no button to press
    When I am on the "adaptivecatquiz1" Activity page logged in as teacher1
    And I follow "Settings"
    Then the settings form has 2 feedback editors
    And "//div[@data-name='feedback_scale_Single_range_1']" "xpath_element" should exist
    And "(//div[@data-region='catquiz-feedback-panel'])[2]" "xpath_element" should not exist
