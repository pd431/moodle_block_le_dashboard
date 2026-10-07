@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Finished marking shows where the assessment stands, with the marking detail on request
  In order to see at a glance what happened to work I have finished with
  As a marker or a module convenor
  I need each Completed marking or Past row to say whether students have their feedback, with the bars behind a button

  # Pierre, 2026-10-07 16:01: "For staff (marker and modcon) I'd like the past assessments to show a grading
  # status instead (released to students, some withheld, colleagues still grading, that sort of thing) and then
  # a possible dropdown for showing what the marking was like, but at a glance, past should be status only.
  # Maybe for the withheld, include the number. Released to students. 5 withheld"
  #
  # One status per row, worked out over the whole assessment, not only my share. In order:
  #   1. "Colleagues still grading": some submitted work is still unmarked at a stage (markers only: the
  #      convenor's row would still be In progress).
  #   2. "Marked, not yet released": everything is marked and nothing is released.
  #   3. "Released to students. 5 withheld": some is released; the number is the work that isn't.
  #   4. "Released to students": everything is released.
  #   "Marking finished" replaces 2-4 where nothing is ever released (a quiz whose review never shows marks, F10).
  # The progress bars (and, for convenors, the markers) move into a "Show marking" panel on these rows.
  # Coordinator default, for Pierre to confirm: a convenor's assessment whose marking is finished and partly
  # released goes to Past with the withheld count, instead of staying In progress until the last release.
  # With nothing released it stays In progress, as before.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | student3 | Sid       | Student  |
      | marker1  | Mo        | Marker   |
      | marker2  | Max       | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | student3 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | marker2  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" with marker allocation was due "5 days ago"
    And "student1" has submitted "A1"
    And "student2" has submitted "A1"
    And "student3" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is allocated to "marker1"
    And the submission of "student3" for "A1" is allocated to "marker2"

  Scenario: My share is done while a colleague is still grading
    Given "marker1" marks the submission of "student1" for "A1"
    And "marker1" marks the submission of "student2" for "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Completed marking" section
    And "A1" should show the grading status "Colleagues still grading"
    And "A1" should not show any progress bars

  Scenario: Everything marked, nothing released
    Given "marker1" marks the submission of "student1" for "A1"
    And "marker1" marks the submission of "student2" for "A1"
    And "marker2" marks the submission of "student3" for "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should show the grading status "Marked, not yet released"

  Scenario: Some work released, the rest withheld, with the number withheld
    Given "marker1" marks the submission of "student1" for "A1"
    And "marker1" marks the submission of "student2" for "A1"
    And "marker2" marks the submission of "student3" for "A1"
    And the grade of "student1" for "A1" is released
    When I log in as "marker1" and view my dashboard
    Then "A1" should show the grading status "Released to students. 2 withheld"
    When I log in as "convenor" and view my dashboard
    Then "A1" should appear in the "Past" section
    And "A1" should show the grading status "Released to students. 2 withheld"

  Scenario: Everything released
    Given "marker1" marks the submission of "student1" for "A1"
    And "marker1" marks the submission of "student2" for "A1"
    And "marker2" marks the submission of "student3" for "A1"
    And the grade of "student1" for "A1" is released
    And the grade of "student2" for "A1" is released
    And the grade of "student3" for "A1" is released
    When I log in as "marker2" and view my dashboard
    Then "A1" should show the grading status "Released to students"
    When I log in as "convenor" and view my dashboard
    Then "A1" should appear in the "Past" section
    And "A1" should show the grading status "Released to students"

  Scenario: A convenor's assessment with nothing released stays In progress, with its bars
    Given "marker1" marks the submission of "student1" for "A1"
    And "marker1" marks the submission of "student2" for "A1"
    And "marker2" marks the submission of "student3" for "A1"
    When I log in as "convenor" and view my dashboard
    Then "A1" should appear in the "In progress" section
    And "A1" should not show a grading status
    And "A1" should show progress bars "Submitted, Unallocated, Marked, Released"

  Scenario: Rows still being marked keep their bars and have no status
    Given "marker1" marks the submission of "student1" for "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And "A1" should not show a grading status
    And "A1" should show progress bars "Marked"

  @javascript
  Scenario: The marking detail opens from the row
    Given "marker1" marks the submission of "student1" for "A1"
    And "marker1" marks the submission of "student2" for "A1"
    And "marker2" marks the submission of "student3" for "A1"
    And the grade of "student1" for "A1" is released
    When I log in as "convenor" and view my dashboard
    And I press "Show marking" in the "A1" row
    Then "A1" should show a "Marked" progress bar with "3" of "3"
    And "A1" should show a "Released" progress bar with "1" of "3"
    And "A1" should list markers "Mo Marker" and "Max Marker"
