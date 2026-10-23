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

  # Pierre, 2026-10-22: in modules I convene, past work with no marker (auto-marked quizzes and such) is mine;
  # work without allocation a colleague marked isn't. @javascript: only a tab switch opens a convenor's My marking.
  @javascript
  Scenario: A convenor's Completed marking leaves out work without allocation that a colleague marked
    Given the following "course enrolments" exist:
      | user     | course | role           |
      | convenor | M1     | editingteacher |
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" has submitted "A1"
    And "marker2" marks the submission of "student1" for "A1"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "My marking"
    Then "A1" should not appear in the "Completed marking" section
    When I log in as "marker2" and view the "My marking"
    Then "A1" should appear in the "Completed marking" section

  @javascript
  Scenario: A convenor's Completed marking holds an auto-marked quiz, and other markers' doesn't
    Given the following "course enrolments" exist:
      | user     | course | role           |
      | convenor | M1     | editingteacher |
    And a summative "quiz" "Q1" in "M1" with no essay question was due "3 days ago"
    And "student1" has finished "Q1"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "My marking"
    Then "Q1" should appear in the "Completed marking" section
    When I log in as "marker1" and view the "My marking"
    Then "Q1" should not appear in the "Completed marking" section

  # Moodle 5.0+ only (the "2 markers" option skips the scenario on 4.5).

  # ---------- Convenor view ----------

  # ---------- Student view ----------

  Scenario: A quiz with every attempt used and none handed in stays in Upcoming until its deadline
    Given a summative "quiz" "Q1" in "M1" with 1 attempt allowed is due "next week"
    And "student1" has abandoned an attempt at "Q1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section
    And "Q1" should not have a "Submit" button
    And "Q1" should have a "Can't submit" button
    And the "Can't resubmit" popover of "Q1" should say "You've used all your attempts, so you can't submit."

  Scenario: My marking with nothing to show says there's no current marking
    Given a summative "assign" "A1" in "M1" is due "in 5 days"
    When I log in as "marker1" and view the "My marking"
    Then I should see "You have no current marking for this academic year." in the "My Assessments" "block"
