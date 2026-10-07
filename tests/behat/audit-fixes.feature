@block @block_le_dashboard
Feature: Fixes from the dev architecture audit (A1 to A12)
  In order that the index stays right whatever order things happen in
  As an admin, student, marker or module convenor
  I need rebuilds, settings changes, gradebook grades, slow updates and category roles handled correctly

  # Findings: /mnt/project-files/audience-docs/dev/findings.md (beta16). Approved by the coordinator,
  # 2026-10-06. A2 and A4 are the same as F2 and F3 (support-audit-fixes.feature).
  #
  # As built:
  # - A1, A3 and the coordinator's follow-up: the four backfill writers (install, the settings
  #   callback, Activity types and Rebuild) all go through backfill::queue(), the one helper. That covers install, the tag and role
  #   settings, Activity types and Rebuild. It queues a new backfill unless one is already waiting to
  #   start. A running backfill no longer blocks it, because it has read its data already. Rebuild during
  #   a backfill therefore empties the index and queues a fresh one, and a backfill that finishes while
  #   another waits leaves "Preparing" up. The CLI still refuses while a backfill is queued or running,
  #   since it rebuilds in the same process.
  # - A5: Released counts only units that are ready, so it never exceeds the bar's total, and
  #   outstanding never goes below 0.
  # - A6: a submission's own update, and a student's Refresh, wait at most 2 s for the activity. Past
  #   that the queued task does it. Tasks still wait 60 s.
  # - A7: if marking rows fail, each student is tried alone. Only the units of a student who still fails
  #   keep their last state.
  # - A8: coursework counts a submission only once it is finalised, where the version records that.
  #   Feedback counts only with a grade that isn't blank, and only when it is finalised.
  # - A9: the Refresh throttle is per view, and is set only after the refresh did its work. On a
  #   student's Refresh, an activity that is held is left to its queued task.
  # - A10: not a bug. $transaction->rollback($e) rethrows, so index_rows never returns after a failed
  #   write. No change.
  # - A11: the year form shows the last day, as the list does, and stores the day after it.
  # - A12: a role assigned or unassigned on a category queues a re-index of every indexed course under
  #   it.
  # PHPUnit covers A6, A7 and A9, because Behat can't hold a lock or time a request.

  Background:
    Given the following "categories" exist:
      | name    | category | idnumber |
      | Faculty | 0        | FAC      |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Module 1 | M1        | FAC      |
      | Module 2 | M2        | FAC      |
    And the following "users" exist:
      | username  | firstname | lastname |
      | student1  | Sam       | Student  |
      | student2  | Sue       | Student  |
      | student3  | Sid       | Student  |
      | teacher1  | Tim       | Teacher  |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | student2  | M1     | student        |
      | student3  | M1     | student        |
      | student1  | M2     | student        |
      | teacher1  | M1     | editingteacher |
      | convenor1 | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role

  # A1
  Scenario: Rebuilding while a backfill runs refills every course
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    And a summative "assign" "B1" in "M2" is due "tomorrow"
    And the My assessments index is up to date
    And a backfill has started
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Index" in site administration
    And I press "Rebuild index"
    And I press "Continue"
    And the rebuild task runs
    Then "A1" should be in the index
    And "B1" should be in the index

  Scenario: Saving settings twice during a backfill queues only one follow-up
    Given a backfill has started
    When the formative tag is changed to "practice" in the settings
    And the formative tag is changed to "drill" in the settings
    Then "1" backfill should be waiting to start

  # A3
  Scenario: A tag added while a backfill runs is picked up without waiting for the night
    Given a "practice" "assign" "P1" in "M1" is due "tomorrow"
    And the My assessments index is up to date
    And a backfill has started
    When the formative tag is changed to "practice" in the settings
    And the rebuild task runs
    Then "P1" should be in the index

  # A5
  Scenario: Grades entered in the gradebook never make Released exceed the total
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And "student3" has submitted "A1"
    And "teacher1" grades "student1" on "A1" with "60"
    And the grade of "student1" for "A1" is released
    And the gradebook grade of "student2" for "A1" is set to "55"
    And the gradebook grade of "student3" for "A1" is set to "48"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should show a "Released" progress bar with "1" of "1"

  # A8
  @mod_coursework
  Scenario: A coursework draft isn't counted as submitted
    Given a summative "coursework" "C1" in "M1" is due "tomorrow"
    And "student1" has saved a draft of "C1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "C1" should have no status pill
    And "C1" should appear in the "Upcoming" section

  @mod_coursework
  Scenario: Blank coursework feedback isn't counted as marked
    Given a summative "coursework" "C1" in "M1" was due "3 days ago"
    And "student1" has submitted "C1"
    And "teacher1" has saved blank feedback on the submission of "student1" for "C1"
    And the My assessments index is up to date
    When I log in as "teacher1" and view the "My marking"
    Then "C1" should show "1" to mark

  # A11
  Scenario: The academic year form shows the same last day as the list
    Given the academic year "2026/27" starts on "1 September 2026"
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Academic years" in site administration
    Then I should see "31 August 2027"
    When I click on "Edit" "link" in the "2026/27" "table_row"
    Then the field "timeend[day]" matches value "31"
    And the field "timeend[month]" matches value "August"
    When I press "Save changes"
    Then I should see "31 August 2027"

  # A12
  Scenario: A staff role given on a category updates the courses under it without waiting for the night
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When "teacher1" is given the "editingteacher" role on the category "Faculty"
    Then a re-index of the course "M1" should be queued
    And a re-index of the course "M2" should not be queued
