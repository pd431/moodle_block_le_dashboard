@block @block_le_dashboard
Feature: A deadline moved by an extension says it is the extended one
  In order to know which deadline applies to me
  As a student with an extension
  I need my row to label it "Extended deadline", not "Due"

  # Pierre, 2026-10-28 14:26: "extension can be treated as it is now, but should trigger 'extended deadline' instead
  # of due, if that's a paradigm that exists already" (coursework-flags-and-mitigations.feature; DECISIONS CS5).
  # The label is date_extendeddeadline, on any activity type whose extension the index records. Staff rows are
  # unchanged.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
      | student2 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" is due "in 1 day"
    And "student1" has an extension on "A1" to "in 4 days"

  @javascript
  Scenario: The extended deadline reads the same in the browser
    When I log in as "student1" and view my dashboard
    Then the dates of "A1" should be "Extended deadline" in that order
