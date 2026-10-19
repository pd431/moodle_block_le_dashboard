@block @block_le_dashboard
Feature: The control bar buttons take the theme's corner radius
  In order for the dashboard to look like the rest of my theme
  As a student or member of staff
  I need the type filter and Show grades to have the same corners as the search box (Pierre, 2026-10-19)

  @javascript
  Scenario: The type filter and Show grades have the same corners as the search box
    Given the theme sets its border radius to "0.5rem"
    And the following "courses" exist:
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
    Then the outer corners of the type filter should have the same radius as the search box
    And the inner corners of the type filter should be square
