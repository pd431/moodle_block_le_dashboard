@block @block_le_dashboard
Feature: The block is titled "My Assessments"
  In order to see the dashboard under the name the university uses
  As anyone with the block on their Dashboard
  I need its title to read "My Assessments"

  # beta28, scope block-title.feature (Pierre, 2026-10-21).

  Scenario: The block's title reads "My Assessments"
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    When I log in as "student1" and view my dashboard
    Then "My Assessments" "block" should exist
