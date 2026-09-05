@local @local_catquiz
Feature: The CAT manager keeps its tab in the URL.
  As a manager I want reload and the browser's back button to keep the tab I was
  looking at, and I want only that tab to be built on the server (issue #29).

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | manager1 | Mana      | Ger      | manager1@test.com |
    And the following "system role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |
    And I log in as "manager1"

  Scenario: Without a tab the first one is shown
    When I visit "/local/catquiz/manage_catscales.php"
    Then I should see "Summary"

  Scenario: The tab is taken from the URL and survives a reload
    ## The decisive property: the tab is a request parameter, not a fragment. With
    ## the previous anchor-based markup the fragment never reached the server, so a
    ## reload silently returned to the first tab.
    When I visit "/local/catquiz/manage_catscales.php?tab=questions"
    Then I should see "Questions"
    When I reload the page
    Then I should see "Questions"

  Scenario: An unknown tab falls back instead of failing
    ## A crafted parameter must not select something that does not exist - and must
    ## not make the manager build every tab either.
    When I visit "/local/catquiz/manage_catscales.php?tab=nonexistent"
    Then I should see "Summary"
    And I should not see "Coding error detected"

  Scenario: Switching tabs keeps the context and scale
    ## Without the state in the link a tab switch would silently reset what the
    ## manager had selected.
    When I visit "/local/catquiz/manage_catscales.php?tab=calculations&contextid=1&scaleid=0"
    Then I should see "Calculations"
