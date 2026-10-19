@block @block_le_dashboard @javascript
Feature: Admins can turn off the convenor's two tagging lines
  In order to keep the Module convenor view quiet where tagging is managed centrally
  As an admin
  I need a switch for the untagged activities line and one for the tagged activities to check line

  # The year picker needs JavaScript, so every scenario runs in the browser.
  # Approved by Pierre 2026-10-19 (scopes/beta27.feature, "Whole line" on the card): both on by default. Off
  # means the line isn't rendered and its queries don't run. The second switch hides the whole settings check
  # line, including tagged activities whose type isn't enabled; each row's Check settings flag stays.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username  | firstname | lastname |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | convenor1 | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And the following "activities" exist:
      | activity | name  | course | grade |
      | assign   | Essay | M1     | 100   |
    And a summative "assign" "A1" in "M1" with no due date exists
    And a summative "workshop" "W1" in "M1" is due "tomorrow"
    And the My assessments index is up to date

  Scenario: Both lines show by default
    When I log in as "convenor1" and view the "Module convenor view" for academic year "All years"
    Then I should see "You have 1 compatible but untagged activities across your courses." in the "My assessments" block
    And I should see "2 tagged activities need their settings checked across your courses." in the "My assessments" block

  Scenario: An admin can turn off the untagged activities line
    Given the following config values are set as admin:
      | showuntagged | 0 | block_le_dashboard |
    When I log in as "convenor1" and view the "Module convenor view" for academic year "All years"
    Then I should not see "compatible but untagged" in the "My assessments" block
    And I should see "2 tagged activities need their settings checked across your courses." in the "My assessments" block

  Scenario: An admin can turn off the tagged activities to check line
    Given the following config values are set as admin:
      | showsetupchecks | 0 | block_le_dashboard |
    When I log in as "convenor1" and view the "Module convenor view" for academic year "All years"
    Then I should not see "need their settings checked" in the "My assessments" block
    And I should see "You have 1 compatible but untagged activities across your courses." in the "My assessments" block
    # The row flag is separate and stays.
    And "A1" should have a "setup" hint
