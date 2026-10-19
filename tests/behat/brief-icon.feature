@block @block_le_dashboard
Feature: The missing brief flag shows a half-dashed file
  In order to tell at a glance that an assessment has no brief
  As a convenor
  I need the missing brief flag to look like an incomplete document

  # Pierre, 2026-10-17: "Can missing brief be file-half-dashed". Moodle 4.5 ships Font Awesome 6.5.2, which has
  # no half-dashed file, so beta26 kept fa-file-circle-exclamation there. Pierre, 2026-10-19: "Can you ship your
  # own copy of file half dashed for missing brief, UNTIL WE MOVE TO 5.3" (approved scope beta27.feature): 4.5
  # draws the plugin's copy of Font Awesome Free 6.7.2's icon, pix/file-half-dashed.svg.

  Background:
    Given the following "courses" exist:
      | fullname        | shortname |
      | Law and Society | LAW1001   |
    And the following "users" exist:
      | username | firstname | lastname |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course  | role           |
      | convenor | LAW1001 | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "LAW1001" with no description is due "tomorrow"

  Scenario: The missing brief flag shows a half-dashed file on Moodle 5.0 and later
    Given the site runs Moodle 5.0 or later
    When I log in as "convenor" and view the "Convenor view"
    Then the "brief" flag of "A1" should show the "fa-file-half-dashed" icon

  Scenario: Moodle 4.5 shows the plugin's own half-dashed file for a missing brief
    Given the site runs Moodle 4.5
    When I log in as "convenor" and view the "Convenor view"
    Then the "brief" flag of "A1" should show the plugin's own half-dashed file

  @javascript
  Scenario: The plugin's half-dashed file is drawn in the flag's colour
    Given the site runs Moodle 4.5
    When I log in as "convenor" and view the "Convenor view"
    Then the "brief" flag of "A1" should draw the plugin's own half-dashed file in the flag's text colour
