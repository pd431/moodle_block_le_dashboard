@block @block_my_assessments @javascript
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

  Scenario Outline: The views offered depend only on staff and convenor roles
    Given "staff1" is enrolled in "M1" as "<M1 role>"
    And "staff1" is enrolled in "M2" as "<M2 role>"
    When I log in as "staff1" and view my dashboard
    Then I <marking> be able to open the "My marking"
    And I <convenor> be able to open the "Convenor view"
    And I <student> be able to open the "Student view"
    And the "<opens on>" should be open

    Examples:
      | M1 role        | M2 role        | marking    | convenor   | student    | opens on          |
      | editingteacher | student        | should     | should not | should not | My marking        |
      | moduleconvenor | student        | should not | should     | should not | Convenor view     |
      | editingteacher | moduleconvenor | should     | should     | should not | My marking        |
      | student        | student        | should not | should not | should     | Student view      |

  Scenario: Someone who teaches one module and studies another sees only the staff view
    Given "staff1" is enrolled in "M1" as "student"
    And "staff1" is enrolled in "M2" as "editingteacher"
    And a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "staff1" and view my dashboard
    Then I should not be able to open the "Student view"
    And I should not see "A1" in the "My assessments" block

  Scenario: Someone with no recognised role gets the student view with nothing to show
    Given "staff1" is enrolled in "M1" as "guest"
    When I log in as "staff1" and view my dashboard
    Then I should see "You have no assessments to show." in the "My assessments" block

  Scenario: A staff role in a course where the enrolment has ended does not count
    Given "staff1" is enrolled in "M1" as "student"
    And "staff1" is enrolled in "M2" as "editingteacher"
    And the enrolment of "staff1" in "M2" ended "yesterday"
    When I log in as "staff1" and view my dashboard
    Then I should be able to open the "Student view"
    And I should not be able to open the "My marking"

  Scenario: A saved choice of the student view is ignored once someone holds a staff role
    Given "staff1" is enrolled in "M1" as "student"
    And "staff1" last opened the "Student view"
    And "staff1" is enrolled in "M2" as "editingteacher"
    When I log in as "staff1" and view my dashboard
    Then the "My marking" should be open
