@block @block_le_dashboard
Feature: Work stays visible when people and assessments move at awkward moments
  In order not to lose track of work that still needs doing
  As a student, marker or module convenor
  I need the dashboard to stay truthful when markers leave, deadlines change, years roll over and students come and go

  # From /mnt/project-files/edge-cases/edge-cases.feature, approved by the coordinator 2026-10-04
  # for EC1, EC3, EC5, EC6, EC7, EC8, EC9 and EC11, and EC3b with Pierre's answer (findings in
  # edge-cases/findings.md). EC2, EC4 and EC10 follow Pierre's decisions, approved for beta11.
  # Adjusted while building:
  # - EC1: a marker's marked work still shows the Unallocated bar, at "0 of 1": the bar shows
  #   whenever an allocated activity has submissions, and what matters is that marked work
  #   isn't counted as unallocated. The marker list opens with Show markers first.
  # - EC3b: core refuses an end date without a start date, so the end-date-only course has its
  #   start date removed afterwards (as an import or a database edit leaves it).
  # - EC6: the enrolment-method scenario disables a self enrolment method holding one student,
  #   because disabling manual enrolment would also remove the convenor.
  # - EC10: enrolments already in a course when an assessment is created are dated before its
  #   deadline, so only students enrolled later in a scenario are late joiners. The "Not submitted"
  #   icon is checked as the row's modifier, not its activity icon.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
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
    And "convenor" is enrolled in "M1" as "teacher"
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  # ---------- EC1: work allocated to a marker who left ----------

  @javascript
  Scenario: Unmarked work of a marker who left the module falls to the convenor
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is allocated to "marker1"
    And "marker1" marks the submission of "student1" for "A1"
    And the My assessments index is up to date
    When "marker1" is unenrolled from "M1"
    And the My assessments index catches up
    And I log in as "convenor" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And "A1" should have an unallocated hint
    When I view the "Convenor view" again
    Then "A1" should show an "Unallocated" progress bar with "1" of "2"
    And I press "Show markers" in the "My assessments" block
    And I should see "Mo Marker (no longer on this module)" in the "My assessments" block

  @javascript
  Scenario: Work already marked by a marker who left stays credited to them
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And "marker1" marks the submission of "student1" for "A1"
    And the My assessments index is up to date
    When "marker1" is unenrolled from "M1"
    And the My assessments index catches up
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should show an "Unallocated" progress bar with "0" of "1"
    And "A1" should show a "Marked" progress bar with "1" of "1"

  Scenario: Work of a deleted marker stays with the convenor after the next re-index
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the My assessments index is up to date
    When the user "marker1" is deleted
    And the My assessments index catches up
    And the My assessments nightly reconcile runs
    And I log in as "convenor" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  # ---------- EC3: year filter hides outstanding work ----------

  @javascript
  Scenario: The convenor sees last year's unfinished marking in In progress
    Given the following "courses" exist:
      | fullname | shortname | startdate     |
      | Module 0 | M0        | ##-400 days## |
    And "convenor" is enrolled in "M0" as "moduleconvenor"
    And "student1" is enrolled in "M0" as "student"
    And a summative "assign" "Old essay" in "M0" was due "2 days ago"
    And "student1" has submitted "Old essay"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view"
    Then "Old essay" should appear in the "In progress" section

  # ---------- EC5: assignments with no online submission ----------

  @javascript
  Scenario: A student is never Overdue on an assignment with no online submission
    Given a summative "assign" "Presentation" in "M1" with no online submission was due "2 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Presentation" should not appear in the "Overdue" section
    And "Presentation" should appear in the "Past" section
    And "Presentation" should not show "Not submitted"

  @javascript
  Scenario: Markers see an assignment with no online submission once its deadline passes
    Given a summative "assign" "Presentation" in "M1" with no online submission was due "2 days ago"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "Presentation" should appear in the "Marking in progress" section with "2" to mark
    When I log in as "convenor" and view the "Convenor view"
    Then "Presentation" should not show a "Submitted" progress bar

  # ---------- EC6: group and enrolment-method changes ----------

  @javascript
  Scenario: Disabling an enrolment method updates the counts without waiting for the night
    Given the "self" enrolment method of "M1" is enabled
    And the following "users" exist:
      | username | firstname | lastname |
      | student3 | Sid       | Self     |
    And the following "course enrolments" exist:
      | user     | course | role    | enrol |
      | student3 | M1     | student | self  |
    And a summative "assign" "A1" in "M1" is due "in 2 days"
    And the My assessments index is up to date
    When the "self" enrolment method of "M1" is disabled
    And the My assessments index catches up
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Submitted" progress bar with "0" of "2"

  # ---------- EC7: activity being deleted ----------

  @javascript
  Scenario: An activity being deleted leaves My marking at once
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When "A1" is deleted from the course page
    And I log in as "marker1" and view the "My marking"
    Then I should not see "A1" in the "My assessments" block
    When I log in as "convenor" and view the "Convenor view"
    Then I should not see "A1" in the "My assessments" block

  # ---------- EC8: reallocating marked work ----------

  # ---------- EC9: double marking, a marker replaced (Moodle 5.0+) ----------

  # ---------- EC11: revert to draft after release ----------

  # ---------- EC3b: which academic years a course belongs to (Pierre, 2026-10-04) ----------
  # A course belongs to every academic year its start-to-end span overlaps.
  # Start date only: its start year. End date only (start date 0): its end year.
  # Neither: only "All years", as in beta9.

  @javascript
  Scenario: A January to December course appears under both academic years it spans
    Given the academic year "2025/26" starts on "##2025-09-01##"
    And the academic year "2026/27" starts on "##2026-09-01##"
    And the following "courses" exist:
      | fullname | shortname | startdate       | enddate         |
      | Apprenticeship | AP1 | ##2026-01-05## | ##2026-12-18## |
    And "convenor" is enrolled in "AP1" as "moduleconvenor"
    And "student1" is enrolled in "AP1" as "student"
    And a summative "assign" "Portfolio" in "AP1" is due "in 30 days"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view" for academic year "2025/26"
    Then "Portfolio" should appear in the "Upcoming" section
    When I log in as "convenor" and view the "Convenor view" for academic year "2026/27"
    Then "Portfolio" should appear in the "Upcoming" section

  @javascript
  Scenario: A course with only a start date appears under its start year only
    Given the academic year "2025/26" starts on "##2025-09-01##"
    And the academic year "2026/27" starts on "##2026-09-01##"
    And the following "courses" exist:
      | fullname | shortname | startdate      | enddate |
      | Module 2 | M2        | ##2026-09-28## | 0       |
    And "convenor" is enrolled in "M2" as "moduleconvenor"
    And a summative "assign" "Essay 2" in "M2" is due "in 30 days"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view" for academic year "2026/27"
    Then "Essay 2" should appear in the "Upcoming" section
    When I log in as "convenor" and view the "Convenor view" for academic year "2025/26"
    Then I should not see "Essay 2" in the "My assessments" block

  @javascript
  Scenario: A course with only an end date appears under its end year only
    Given the academic year "2025/26" starts on "##2025-09-01##"
    And the academic year "2026/27" starts on "##2026-09-01##"
    And the following "courses" exist:
      | fullname | shortname | startdate      | enddate        |
      | Module 3 | M3        | ##2026-09-28## | ##2026-12-18## |
    And the start date of "M3" is removed
    And "convenor" is enrolled in "M3" as "moduleconvenor"
    And a summative "assign" "Essay 3" in "M3" is due "in 30 days"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view" for academic year "2026/27"
    Then "Essay 3" should appear in the "Upcoming" section
    When I log in as "convenor" and view the "Convenor view" for academic year "2025/26"
    Then I should not see "Essay 3" in the "My assessments" block

  # ---------- EC2: assessments without a deadline get an "Undated" section (Pierre, 2026-10-04) ----------
  # An "Undated" section appears in all three views only when it has rows: after Upcoming, before Past or Completed.
  # It holds tagged assessments with no deadline (never set, or switched off) that have nothing in action:
  # unsubmitted work for students, nothing to mark for markers, nothing outstanding for the convenor.
  # Work that needs action stays in its action section. Tagging is explicit, so tagged activities without a
  # date are now listed (this replaces R8's "not listed" for tagged activities).
  # Staff get a "Check settings" flag on the row (it replaced the "No deadline" hint on 2026-10-07), wherever it
  # sits, pointing to the activity's settings.

  @javascript
  Scenario: An assignment whose due date is switched off before anyone submits moves to Undated
    Given a summative "assign" "A1" in "M1" is due "in 3 days"
    And the My assessments index is up to date
    When the due date of "A1" is switched off
    And the My assessments index catches up
    And I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Undated" section
    And "A1" should show "Submit"
    When I log in as "marker1" and view the "My marking"
    Then "A1" should appear in the "Undated" section
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "Undated" section
    And "A1" should have a "setup" hint

  @javascript
  Scenario: Switching off the due date mid-marking keeps the marking where it was
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When the due date of "A1" is switched off
    And the My assessments index catches up
    And I log in as "marker1" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback released"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "In progress" section
    And "A1" should have a "setup" hint

  @javascript
  Scenario: The Check settings flag tells the convenor where to add a deadline
    Given a summative "assign" "A2" in "M1" with no due date exists
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view"
    And I click on the "setup" flag of "A2"
    Then I should see "Fix this in the activity's settings" in the "My assessments" block
    And I should see "settings" linking to the settings page of "A2"

  # 2026-10-07: markers get the Check settings flag too (setup-flags.feature); students never do.

  @javascript
  Scenario: Untagged activities without a deadline are still not listed
    Given an "assign" activity with grade items exists in "M1"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view"
    Then I should not see "Undated" in the "My assessments" block

  # ---------- EC4: students who leave (Pierre, 2026-10-04: all submitted work gets marked) ----------
  # Suspended or expired students' submitted work stays in marking and release. Pierre's site moves suspended
  # students to a "student_inactive" role, configured as a second student role, so that path needs no code;
  # these scenarios cover Moodle's own suspension and end dates.

  @javascript
  Scenario: Work of a student whose enrolment ended still counts after the nightly run
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When the enrolment of "student1" in "M1" ended "1 hour ago"
    And the My assessments nightly reconcile runs
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should not appear in the "In progress" section
    And "A1" should show a "Released" progress bar with "1" of "1"

  @javascript
  Scenario: An assessment whose students have all left goes to Past, not back to In progress
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When "student1" is unenrolled from "M1"
    And "student2" is unenrolled from "M1"
    And the My assessments index catches up
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "Past" section

  # ---------- EC10: late joiners (Pierre, 2026-10-04: unsubmitted once past the grace period) ----------
  # A student enrolled after the deadline is treated as unsubmitted once the deadline plus the grace period
  # has passed: Past, "Not submitted", no overdue icon. Inside the grace period they can still submit, so the
  # row is Overdue as for anyone else. Late submissions Moodle still accepts after that are ignored on this row.
