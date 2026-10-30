@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Staff see only the staff views, everyone else sees the student view
  In order to match how our users work (the rc6 routing)
  As anyone who opens the dashboard
  I need the block to choose my views from the roles I hold, never mixing staff and student views

  # Approved by Pierre 2026-10-03 (rc6 routing). Replaces the "teaches one module and
  # studies another" scenario in student-access.feature (deleted, see the end of this file).
  # "Staff role" and "Module convenor role" are the block's two role settings.
  # A role counts in any course where the user also has an active enrolment, hidden courses
  # and every academic year included, so changing the year never changes the views offered.
  # D6 is unchanged: the Convenor overview needs the Module convenor role.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
      | Module 2 | M2        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | staff1   | Tia       | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "editingteacher" as a staff role
    And the My assessments block treats "moduleconvenor" as a module convenor role

  # beta6 (Pierre, 2026-10-03): what needs action, then what is coming, then what is done.
  # Empty sections are hidden but keep their place, so one upcoming assessment is enough.
  @javascript
  Scenario Outline: Sections are ordered by what needs action first
    Given "staff1" is enrolled in "M1" as "<role>"
    And a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "staff1" and view my dashboard
    Then the "<view>" should be open
    And the sections should be in the order "<order>"

    Examples:
      | role           | view          | order                                                      |
      | student        | Student view  | Overdue, Upcoming, Past                                    |
      | moduleconvenor | Convenor view | In progress, Upcoming, Past                                |

  # My marking has no Upcoming section (Pierre, 2026-10-21), so an undated assessment gives it a row.
  @javascript
  Scenario: My marking's sections are ordered by what needs action first
    Given "staff1" is enrolled in "M1" as "editingteacher"
    And a summative "assign" "A1" in "M1" with no due date exists
    When I log in as "staff1" and view my dashboard
    Then the "My marking" should be open
    And the sections should be in the order "Marking in progress, Undated, Completed marking"
