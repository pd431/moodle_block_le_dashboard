@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Fixes from the LMS support map audit (F1 to F9)
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
  Scenario: A quiz whose attempts are used up stays in Upcoming, without Submit
    Given a summative "quiz" "Q1" in "M1" with 1 attempt allowed is due "next week"
    And "student1" has finished 1 attempts at "Q1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section
    And "Q1" should show the status "Submitted"
    And "Q1" should not have a "Submit" button
    And "Q1" should have a "Can't resubmit" button

  Scenario: A quiz with attempts left stays in Upcoming
    Given a summative "quiz" "Q1" in "M1" with 2 attempts allowed is due "next week"
    And "student1" has finished 1 attempts at "Q1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section

  Scenario: A quiz override that extends the close keeps the quiz open for that student
    Given a summative "quiz" "Q1" in "M1" was due "2 hours ago"
    And "student1" has a quiz override on "Q1" closing "tomorrow"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section
    And "Q1" should show "Submit"
    When I log in as "student2" and view my dashboard
    Then "Q1" should appear in the "Past" section

  Scenario: An assignment past its cut-off goes to Past
    Given a summative "assign" "A1" in "M1" with a cut-off "1 hour ago" was due "2 hours ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Past" section
    And "A1" should show the status "Not submitted"
    And "A1" should not show "Submit"

  Scenario: An assignment that still accepts late work stays in Overdue
    Given a summative "assign" "A1" in "M1" with a cut-off "tomorrow" was due "2 hours ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Overdue" section
    And "A1" should show "Submit"

  # F3
  Scenario Outline: The convenor's In progress drops assessments with nothing left to mark
    Given a summative "<type>" "<name>" in "M1" <options> was due "5 days ago"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "<name>" should appear in the "Past" section
    And "<name>" should not appear in the "In progress" section

    Examples:
      | type     | name | options                     |
      | workshop | L1   |                             |
      | assign   | A1   | with a cut-off "4 days ago" |
      | quiz     | Q1   |                             |

  Scenario: An assignment that can still receive work stays in the convenor's In progress
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has an extension on "A1" to "next week"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should appear in the "In progress" section

  # F4
  Scenario: Marking early doesn't show New work
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    And "student1" submitted "A1" "2 days ago"
    And "teacher1" marks the submission of "student1" for "A1"
    And "student2" submitted "A1" "1 hour ago"
    And the deadline of "A1" is changed directly in the database to "1 hour ago"
    And the My assessments index is up to date
    When I log in as "teacher1" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section
    And "A1" should not have a "returned" hint
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should not have a "returned" hint

  Scenario: Work arriving after marking was finished still shows New work
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" submitted "A1" "6 days ago"
    And "teacher1" marks the submission of "student1" for "A1"
    And "student2" submitted "A1" "now"
    And the My assessments index is up to date
    When I log in as "teacher1" and view the "My marking"
    Then "A1" should have a "returned" hint

  # F5
  Scenario: Unticking a type removes it from courses outside the live window
    Given a course "OLD" with a summative "quiz" "OldQ" was due "3 years ago"
    And the My assessments index is up to date
    When the "quiz" activity type is unticked on the Activity types page
    And the rebuild task runs
    Then "OldQ" should not be in the index

  Scenario: Removing a tag from the settings removes its activities outside the live window
    Given a course "OLD" with a formative "assign" "OldA" was due "3 years ago"
    And the My assessments index is up to date
    When the formative tag is changed to "practice" in the settings
    And the rebuild task runs
    Then "OldA" should not be in the index

  # F6
  Scenario: Activities whose submissions the block can't see show no Not submitted icon
    Given a summative "workshop" "L1" in "M1" was due "5 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "L1" should appear in the "Past" section
    And "L1" should show the status "Closed"
    And the activity icon of "L1" should have no modifiers

  # F7
  Scenario: The Activity types page describes undated generic activities correctly
    Given a "workshop" activity with grade items exists in "M1"
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Activity types" in site administration
    Then I should not see "Activities without a due date are not listed"
    And I should see "Activities without a due date are listed under Undated"

  # F8
  Scenario: Manually grading a quiz credits the marker
    Given a summative "quiz" "Q1" in "M1" with an essay question was due "2 days ago"
    And "student1" has finished 1 attempts at "Q1"
    And "teacher1" grades the essay of "student1" in "Q1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "Q1" should list marker "Tim Teacher"

  # F9
  @mod_coursework
  Scenario: Publishing coursework grades reaches the dashboard without a Refresh
    Given a summative "coursework" "C1" in "M1" was due "5 days ago"
    And "student1" has submitted "C1"
    And "teacher1" grades "student1" on "C1" with "60"
    And the My assessments index is up to date
    When the agreed grade for "student1" on "C1" is published
    And I log in as "student1" and view my dashboard
    Then "C1" should show the status "Feedback released"

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

  Scenario: A quiz that never releases marks and has no close date leaves Undated once marked
    Given a summative "quiz" "Q1" in "M1" with no due date exists
    And the review options of "Q1" never show marks
    And "student1" has finished 1 attempts at "Q1"
    And the last attempt of "student1" at "Q1" has been graded
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Past" section
    And "Q1" should show the status "Submitted"

  Scenario: A quiz that never releases marks waits in In marking while an attempt needs grading
    Given a summative "quiz" "Q1" in "M1" with marks never shown was due "2 days ago"
    And "student1" has finished 1 attempts at "Q1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should show the status "In marking"

  # F11
  Scenario: Marks shown after close wait for the student's own close
    Given a summative "quiz" "Q1" in "M1" with marks shown after close only was due "2 hours ago"
    And "student1" has a quiz override on "Q1" closing "tomorrow"
    And "student1" and "student2" have finished "Q1"
    And the last attempt of "student1" at "Q1" has been graded
    And the last attempt of "student2" at "Q1" has been graded
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section
    And "Q1" should not show "Feedback released"
    When I log in as "student2" and view my dashboard
    Then "Q1" should show the status "Feedback released"
