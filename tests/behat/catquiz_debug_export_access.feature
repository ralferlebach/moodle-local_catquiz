@local @local_catquiz
Feature: The debug export of an attempt is guarded before anything is read.
  The PDF with the debug data of an attempt shows nothing to a person without the right to manage
  CATquiz, and nothing for an attempt that has no CAT attempt (issue #173).

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher  | Teacher   | Test     | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | teacher | C1     | editingteacher |

  Scenario: A teacher without the right to manage CATquiz gets no export
    Given I log in as "teacher"
    When I visit "/local/catquiz/export_debuginfo_pdf.php?attemptid=1"
    Then I should see "Sorry, but you do not currently have permissions to do that"
    And I should not see "Debug steps"

  Scenario: An attempt without a CAT attempt gives no export, not an empty one
    Given I log in as "admin"
    When I visit "/local/catquiz/export_debuginfo_pdf.php?attemptid=987654"
    Then I should see "There is no CAT attempt for this attempt."
    And I should not see "Progress"
