@local @local_catquiz @javascript
Feature: Statistics and feedback over several tests and courses: comma-separated IDs in the shortcodes (issue #175).
  testid takes course module ids - one or several, separated by commas; courseid takes course ids. The statistics of
  two tests in two courses count the attempts of both, once each; the charts loaded with a tab, the export and a second
  shortcode on the same page use the same set. A teacher of one of the courses sees nothing of a set that reaches into
  the other. The feedback lists the attempts of every course of the list that the viewer may see.

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname | email                      |
      | student1  | Student1  | Test     | toolgenerator1@example.com |
      | student2  | Student2  | Test     | toolgenerator2@example.com |
      | student3  | Student3  | Test     | toolgenerator3@example.com |
      | teachera  | TeacherA  | Test     | toolgenerator4@example.com |
      | teacherab | TeacherAB | Test     | toolgenerator5@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
      | Course 2 | C2        |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | C1     | student        |
      | student2  | C1     | student        |
      | student3  | C1     | student        |
      | student1  | C2     | student        |
      | student2  | C2     | student        |
      | student3  | C2     | student        |
      | teachera  | C1     | editingteacher |
      | teacherab | C1     | editingteacher |
      | teacherab | C2     | editingteacher |
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
      | activity     | name          | course | section | idnumber         | intro      |
      | adaptivequiz | CAT of C1     | C1     | 1       | adaptivecatquiz1 | Quiz Intro |
      | adaptivequiz | CAT of C2     | C2     | 1       | adaptivecatquiz2 | Quiz Intro |
    And the following "activities" exist:
      | activity | name                | course | idnumber | content |
      | page     | Two tests           | C1     | page1    | -       |
      | page     | One test            | C1     | page2    | -       |
      | page     | Feedback of courses | C1     | page3    | -       |
    And the following "local_catquiz > testsettings" exist:
      | course | adaptivecatquiz  | catmodel | catscales  | cateststrategy | catquiz_minquestions | catquiz_maxquestions | catquiz_standarderror_min | catquiz_standarderror_max | scalefeedbacktexts |
      | C1     | adaptivecatquiz1 | catquiz  | Ressourcen | Classical test | 2                    | 2                    | 0.0                       | 1000.0                    | 1                  |
      | C2     | adaptivecatquiz2 | catquiz  | Ressourcen | Classical test | 2                    | 2                    | 0.0                       | 1000.0                    | 1                  |
    And the "shortcodes" filter is "on"
    ## student1 in C1, student2 in C2, student3 in both: four attempts, three people.
    And the following CAT results of "adaptivecatquiz1" exist:
      | user     | days ago | ability | valid |
      | student1 | 3        | 1.0     | yes   |
      | student3 | 2        | 0.5     | yes   |
    And the following CAT results of "adaptivecatquiz2" exist:
      | user     | days ago | ability | valid |
      | student2 | 2        | 1.5     | yes   |
      | student3 | 1        | 2.0     | yes   |
    ## The same set twice on one page, in another order; the course module ids, not the instance ids.
    And the page "page1" shows "<p>[catquizstatistics testid=#cm:adaptivecatquiz1#,#cm:adaptivecatquiz2#]</p><p>[catquizstatistics testid=#cm:adaptivecatquiz2#,#cm:adaptivecatquiz1#]</p>"
    And the page "page2" shows "<p>[catquizstatistics testid=#cm:adaptivecatquiz1#]</p>"
    And the page "page3" shows "<p>[catquizfeedback courseid=#course:C1#,#course:C2# numberofattempts=10]</p>"

  Scenario: Two tests in two courses: every attempt once, in every block on the page and after a reload
    Given I am on the "page1" "page activity" page logged in as teacherab
    Then I should see "Statistics of 2 tests on scale Ressourcen"
    When I click on "Testusage" "link" in the "(//ul[@role='tablist'][.//a[contains(@id, 'local-catquiz-statistics-')]])[1]" "xpath_element"
    And I click on "Show chart data" "link" in the "(//div[contains(@id, '-testusage')])[1]//div[@data-chartname='attemptsperperson']" "xpath_element"
    ## One attempt: student1 and student2; two attempts: student3 - with a result each.
    Then "(//div[@data-chartname='attemptsperperson'])[1]//tr[th[normalize-space(.)='1']]/td[2][normalize-space(.)='2']" "xpath_element" should exist
    And "(//div[@data-chartname='attemptsperperson'])[1]//tr[th[normalize-space(.)='2']]/td[2][normalize-space(.)='1']" "xpath_element" should exist
    When I click on "Testusage" "link" in the "(//ul[@role='tablist'][.//a[contains(@id, 'local-catquiz-statistics-')]])[2]" "xpath_element"
    And I click on "Show chart data" "link" in the "(//div[contains(@id, '-testusage')])[2]//div[@data-chartname='attemptsperperson']" "xpath_element"
    Then "(//div[@data-chartname='attemptsperperson'])[2]//tr[th[normalize-space(.)='1']]/td[2][normalize-space(.)='2']" "xpath_element" should exist
    And "(//div[@data-chartname='attemptsperperson'])[2]//tr[th[normalize-space(.)='2']]/td[2][normalize-space(.)='1']" "xpath_element" should exist
    And the page has no duplicate ids
    When I reload the page
    And I click on "Testusage" "link" in the "(//ul[@role='tablist'][.//a[contains(@id, 'local-catquiz-statistics-')]])[1]" "xpath_element"
    And I click on "Show chart data" "link" in the "(//div[contains(@id, '-testusage')])[1]//div[@data-chartname='attemptsperperson']" "xpath_element"
    Then "(//div[@data-chartname='attemptsperperson'])[1]//tr[th[normalize-space(.)='2']]/td[2][normalize-space(.)='1']" "xpath_element" should exist
    ## The export of a block: the attempts of its charts.
    And the CSV export of the statistics number 1 lists the attempts of "student1, student2, student3, student3"
    And the CSV export of the statistics number 2 lists the attempts of "student1, student2, student3, student3"

  Scenario: One test: the statistics of that test only, as before
    Given I am on the "page2" "page activity" page logged in as teachera
    When I click on "Testusage" "link" in the "#region-main" "css_element"
    And I click on "Show chart data" "link" in the "//div[contains(@id, '-testusage')]//div[@data-chartname='attemptsperperson']" "xpath_element"
    ## student1 and student3 with one attempt each in the test of C1.
    Then "//div[@data-chartname='attemptsperperson']//tr[th[normalize-space(.)='1']]/td[2][normalize-space(.)='2']" "xpath_element" should exist
    And the CSV export of the statistics number 1 lists the attempts of "student1, student3"

  Scenario: A teacher of one course sees nothing of a set that reaches into another course
    Given I am on the "page1" "page activity" page logged in as teachera
    Then I should not see "Statistics of 2 tests"
    And "//ul[@role='tablist'][.//a[contains(@id, 'local-catquiz-statistics-')]]" "xpath_element" should not exist
    And I should not see "Student2"

  Scenario: The feedback of two courses: the own attempts in both, others' where the viewer may see them
    Given I am on the "page3" "page activity" page logged in as student3
    ## Two attempts of their own, one in each course.
    Then "(//*[contains(normalize-space(text()), 'My Attempt from')])[2]" "xpath_element" should exist
    And "(//*[contains(normalize-space(text()), 'My Attempt from')])[3]" "xpath_element" should not exist
    And I should not see "Student1 Test"
    When I am on the "page3" "page activity" page logged in as teachera
    Then I should see "Student1 Test"
    And I should see "Student3 Test"
    And I should not see "Student2 Test"
    When I am on the "page3" "page activity" page logged in as teacherab
    Then I should see "Student1 Test"
    And I should see "Student2 Test"
    And I should see "Student3 Test"
