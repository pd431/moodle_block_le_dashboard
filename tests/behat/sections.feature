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

  Scenario: Completed marking holds only work I marked, where nothing is allocated
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" has submitted "A1"
    And "marker2" marks the submission of "student1" for "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "A1" should not appear in the "Completed marking" section
    When I log in as "marker2" and view the "My marking"
    Then "A1" should appear in the "Completed marking" section

  # Moodle 5.0+ only (the "2 markers" option skips the scenario on 4.5).
  Scenario: Completed marking holds only work I marked, with 2 markers and nothing allocated
    Given a summative "assign" "A1" in "M1" with 2 markers was due "3 days ago"
    And "student1" has submitted "A1"
    And "marker2" marks the submission of "student1" for "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "A1" should not appear in the "Completed marking" section
    When I log in as "marker2" and view the "My marking"
    Then "A1" should appear in the "Completed marking" section

  # ---------- Convenor view ----------

  Scenario: An assessment with no submissions goes to Past at its deadline, and back if late work arrives
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "Past" section
    When "student1" has submitted "A1"
    And the My assessments index is up to date
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "In progress" section

  # ---------- Student view ----------

  Scenario: A student's sections hold work by its deadline
    Given a summative "assign" "A1" in "M1" is due "in 5 days"
    And a summative "assign" "A2" in "M1" is due "in 3 days"
    And a summative "assign" "A3" in "M1" was due "2 days ago"
    And a summative "assign" "A4" in "M1" was due "4 days ago"
    And "student1" has submitted "A2"
    And "student1" has submitted "A4"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A2" should appear in the "Upcoming" section
    And "A3" should appear in the "Overdue" section
    And "A4" should appear in the "Past" section

  Scenario: A quiz with every attempt used and none handed in stays in Upcoming until its deadline
    Given a summative "quiz" "Q1" in "M1" with 1 attempt allowed is due "next week"
    And "student1" has abandoned an attempt at "Q1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section
    And "Q1" should not have a "Submit" button
    And "Q1" should have a "Can't submit" button
    And the "Can't resubmit" popover of "Q1" should say "You've used all your attempts, so you can't submit."

  Scenario: A partly released assessment stays In progress for the convenor until the rest is released
    # With marking workflow, so a grade is released only when the step says so.
    Given a summative "assign" "A1" in "M1" with marking workflow was due "5 days ago"
    And "student1" and "student2" have submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And "marker1" marks the submission of "student2" for "A1"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "In progress" section
    When the grade of "student2" for "A1" is released
    And the My assessments index is up to date
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "Past" section
    And "A1" should show the grading status "Released to students"
