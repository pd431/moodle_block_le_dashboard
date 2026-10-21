@block @block_le_dashboard
Feature: Each view has the sections its users work in
  In order to see what needs me without sorting through what doesn't
  As a student, marker or module convenor
  I need each view's sections to hold one kind of work, by one rule

  # beta28, scope sections.feature (Pierre, 2026-10-21).

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Stu       | Student  |
      | marker1  | Mo        | Marker   |
      | marker2  | Max       | Marker   |
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | marker1  | M1     | teacher        |
      | marker2  | M1     | teacher        |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  # ---------- My marking ----------

  Scenario: Work I've marked while extensions run is in Completed marking, with the extensions hint
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" has submitted "A1"
    And "student2" has an extension on "A1" to "in 2 days"
    And "marker1" marks the submission of "student1" for "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "A1" should appear in the "Completed marking" section
    And "A1" should have an extensions hint

  @javascript
  Scenario: My marking has no Upcoming deadlines section, and keeps Undated
    Given a summative "assign" "A1" in "M1" is due "in 5 days"
    And a summative "assign" "A2" in "M1" with no due date exists
    And a summative "assign" "A3" in "M1" was due "3 days ago"
    And "student1" has submitted "A3"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then the sections should be in the order "Marking in progress, Undated, Completed marking"
    And "A1" should not appear in the "Marking in progress" section
    And "A2" should appear in the "Undated" section
