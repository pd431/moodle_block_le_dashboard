@block @block_le_dashboard
Feature: A quiz's due date is its deadline and its close date the cut-off
  In order to know when a quiz is due, as Moodle tells me on the quiz page, the calendar and Timeline
  As a student
  I need the dashboard to use the quiz's due date, with the close date as the last chance to attempt it

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

  Scenario: A quiz past its due date but still open is overdue and can still be attempted
    Given the site runs Moodle 5.3 or later
    And a summative "quiz" "Q1" in "M1" is due "yesterday" and closes "in 5 days"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Overdue" section
    And "Q1" should have a "Submit" button
