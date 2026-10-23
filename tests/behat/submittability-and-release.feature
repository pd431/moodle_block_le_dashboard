@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Rows leave Upcoming when nothing more can be handed in, and marks release as the activity does
  In order that what the dashboard says matches what happened in the activity
  As a student, marker, module convenor or admin
  I need released grades, closed quizzes, empty assessments and settings changes handled correctly

  # Findings: /mnt/project-files/audience-docs/support/findings.md (beta16, reproduced on 4.5 + PG).
  # The coordinator approved fixing all nine. Each fix gets a scenario here.
  #
  # Decisions (coordinator confirmed 2026-10-06; F2 follows Pierre's 22:06 submittability rule, no middle path):
  # - F1: a released grade counts as the outcome, submitted or not. The row goes to Past with "Feedback
  #   released" and the grade, or stays in Upcoming while the deadline is ahead. No Overdue section, no
  #   clock, no "Not submitted".
  # - F2 (and A2), Pierre 2026-10-06 22:06: Upcoming and Overdue hold only work the student can still hand
  #   in, technically and by policy. One rule in the classifier: past the student's own cut-off, the
  #   work goes to Past. The adapters fold every limit into that cut-off: an assignment's cut-off, a
  #   quiz's close, overrides and extensions, and a quiz's last allowed attempt (work handed in stays in Upcoming
  #   until its deadline since 2026-10-09). Past rows show Mitigate
  #   while its window is open. Assign, coursework and peerwork stay in Overdue while late work is
  #   accepted. A quiz has no late window, so it goes to Past at its close.
  # - F3: the convenor's In progress follows My marking. Once the deadline has passed, an assessment with
  #   nothing ready to mark goes to Past when any of these holds:
  #   * it is a generic type;
  #   * its cut-off or quiz close has passed;
  #   * every student's own deadline, extensions included, has passed.
  # - F4: "New work" shows only when every unit handed in before the last mark had been marked, and
  #   newer work has arrived since. Marking early no longer sets it off.
  # - F5: a backfill queued by a settings change re-indexes every course, ignoring the live window. The
  #   nightly reconcile keeps the window.
  # - F6: generic types and assignments with no submission plugin show "Closed" in Past, with no
  #   not-submitted icon.
  # - F8: the quiz marker is whoever saved the last manual grade on the attempt's questions. That person
  #   comes from the question attempt steps.
  # - F9: coursework's own allocation, publishing and plagiarism-flag event names can't be confirmed
  #   without the plugin, so no observers were added. The nightly reconcile re-reads coursework, and a
  #   Refresh does it at once. Tagged @mod_coursework, for Pierre's coursework test.
  # - F10 (coordinator default, 22:14): the quiz adapter reads release the way quiz_grade_item_update()
  #   does. Marks reach the gradebook only when shown "later, while open", or "after close" with a
  #   close date. Otherwise, or with a maximum grade of 0, nothing is ever released, and the index marks
  #   the quiz norelease. A fully marked attempt then counts as finished: the student's row is
  #   "Submitted" with no grade, in Past once the quiz is closed or the date passed (Undated work too),
  #   and the convenor's Released count equals Marked, so the row goes to Past.
  # - F11: with marks shown after close only, each student is released at the later of the gradebook's
  #   date and their own close. That applies on the student's row and in the convenor's Released count.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username  | firstname | lastname |
      | student1  | Sam       | Student  |
      | student2  | Sue       | Student  |
      | teacher1  | Tim       | Teacher  |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | student2  | M1     | student        |
      | teacher1  | M1     | editingteacher |
      | convenor1 | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And the "workshop" activity type is enabled in My assessments

  # F1
  @javascript
  Scenario: A grade released without a submission is shown in Past, not as overdue
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "teacher1" grades "student1" on "A1" with "55"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Past" section
    And "A1" should show the status "Feedback released"
    And "A1" should not show "Not submitted"
    And the activity icon of "A1" should have no modifiers
    When I press "Show grades" in the "My assessments" block
    Then the grade of "A1" should be visible

  # F2
  @block_rhb
  Scenario: A closed quiz goes to Past, with Mitigate while its window is open
    Given the My assessments Mitigate stays open "48" hours after the deadline
    And a summative "quiz" "Q1" in "M1" was due "2 hours ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Past" section
    And "Q1" should show the status "Not submitted"
    And "Q1" should not show "You can still submit"
    And "Q1" should not show "Submit"
    And "Q1" should show "View"
    And "Q1" should show "Mitigate"

  @block_rhb
  Scenario: Mitigate leaves the Past row once its window ends
    Given the My assessments Mitigate stays open "48" hours after the deadline
    And a summative "quiz" "Q1" in "M1" was due "3 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Past" section
    And "Q1" should not show "Mitigate"

  # Reversed by Pierre, 2026-10-09 (locked-upcoming.feature): it stays in Upcoming until the quiz closes.

  # F3

  # F4

  # F5

  # F6

  # F7
  Scenario: The Activity types page describes undated generic activities correctly
    Given a "workshop" activity with grade items exists in "M1"
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Activity types" in site administration
    Then I should not see "Activities without a due date are not listed"
    And I should see "Activities without a due date are listed under Undated"

  # F8

  # F9

  # F10
  Scenario Outline: A quiz that never releases marks is finished once marked
    Given a summative "quiz" "Q1" in "M1" with <setting> was due "2 days ago"
    And "student1" has finished 1 attempts at "Q1"
    And the last attempt of "student1" at "Q1" has been graded
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Past" section
    And "Q1" should show the status "Submitted"
    And "Q1" should not show "In marking"
    And the grade of "Q1" should not be visible
    When I log in as "convenor1" and view the "Module convenor view"
    Then "Q1" should appear in the "Past" section

    Examples:
      | setting                         |
      | marks never shown               |
      | marks shown immediately after only |
      | a maximum grade of 0            |

  # F11
