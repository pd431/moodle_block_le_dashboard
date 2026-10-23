@block @block_le_dashboard
Feature: An admin notice above the dashboard
  In order to tell everyone something about the dashboard itself, such as where to send feedback
  As an admin
  I need a rich-text notice above the tabs, empty by default

  # Approved by Pierre 2026-10-17 (scopes/beta26.feature). The notice goes through format_text, so filters and
  # Moodle's HTML cleaning apply. It is shown in every view and is not dismissible.

  Background:
    Given the following "courses" exist:
      | fullname        | shortname |
      | Law and Society | LAW1001   |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tia       | Teacher  |
    And the following "course enrolments" exist:
      | user     | course  | role           |
      | student1 | LAW1001 | student        |
      | teacher1 | LAW1001 | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: A notice set by the admin shows above the tabs, for students and staff
    Given the following config values are set as admin:
      | notice | <div class="alert alert-info">The assessment dashboard is still in live development. Flag any issues on our <a href="https://example.com/form">feedback form</a>.</div> | block_le_dashboard |
    And a summative "assign" "A1" in "LAW1001" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then the My assessments block should show the notice "The assessment dashboard is still in live development."
    And "feedback form" in the notice should link to "https://example.com/form"
    When I log in as "teacher1" and view my dashboard
    Then the My assessments block should show the notice "The assessment dashboard is still in live development."

  Scenario: The notice is cleaned like any other Moodle text
    Given the following config values are set as admin:
      | notice | <p>Hello</p><script>alert(1)</script> | block_le_dashboard |
    When I log in as "student1" and view my dashboard
    Then the My assessments block should show the notice "Hello"
    And the notice should hold no script
