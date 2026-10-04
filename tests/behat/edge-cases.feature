@block @block_my_assessments @javascript
Feature: Work stays visible when people and assessments move at awkward moments
  In order not to lose track of work that still needs doing
  As a student, marker or module convenor
  I need the dashboard to stay truthful when markers leave, deadlines change, years roll over and students come and go

  # From /mnt/project-files/edge-cases/edge-cases.feature, approved by the coordinator 2026-10-04
  # for EC1, EC3, EC5, EC6, EC7, EC8, EC9 and EC11, and EC3b with Pierre's answer (findings in
  # edge-cases/findings.md).
  # EC2, EC4 and EC10 wait on Pierre and are not here yet.
  # Adjusted while building:
  # - EC1: a marker's marked work still shows the Unallocated bar, at "0 of 1": the bar shows
  #   whenever an allocated activity has submissions, and what matters is that marked work
  #   isn't counted as unallocated. The marker list opens with Show markers first.
  # - EC3b: core refuses an end date without a start date, so the end-date-only course has its
  #   start date removed afterwards (as an import or a database edit leaves it).
  # - EC6: the enrolment-method scenario disables a self enrolment method holding one student,
  #   because disabling manual enrolment would also remove the convenor.

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

  Scenario: A marker who only loses their marking role is treated the same way
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the My assessments index is up to date
    When "marker1" is enrolled in "M1" as "student" only
    And the My assessments index catches up
    And I log in as "convenor" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  # ---------- EC3: year filter hides outstanding work ----------

  Scenario: Marking outstanding on last year's module stays in My marking
    Given the following "courses" exist:
      | fullname | shortname | startdate     |
      | Module 0 | M0        | ##-400 days## |
    And "marker1" is enrolled in "M0" as "teacher"
    And "student1" is enrolled in "M0" as "student"
    And a summative "assign" "Old essay" in "M0" was due "2 days ago"
    And "student1" has submitted "Old essay"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "Old essay" should appear in the "Marking in progress" section with "1" to mark
    And the "To mark" summary count should be "1"

  Scenario: Last year's completed marking stays under its own year
    Given the following "courses" exist:
      | fullname | shortname | startdate     |
      | Module 0 | M0        | ##-400 days## |
    And "marker1" is enrolled in "M0" as "teacher"
    And "student1" is enrolled in "M0" as "student"
    And a summative "assign" "Old essay" in "M0" was due "60 days ago"
    And "student1" has submitted "Old essay"
    And "marker1" marks the submission of "student1" for "Old essay"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "Old essay" should not appear in the "Completed marking" section

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

  Scenario: A student is never Overdue on an assignment with no online submission
    Given a summative "assign" "Presentation" in "M1" with no online submission was due "2 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Presentation" should not appear in the "Overdue" section
    And "Presentation" should appear in the "Past" section
    And "Presentation" should not show "Not submitted"

  Scenario: A released mark on an assignment with no online submission shows as released
    Given a summative "assign" "Presentation" in "M1" with no online submission was due "2 days ago"
    And "marker1" marks the submission of "student1" for "Presentation"
    And the grade of "student1" for "Presentation" is released
    And the My assessments index catches up
    When I log in as "student1" and view my dashboard
    Then "Presentation" should show "Feedback released"

  Scenario: Markers see an assignment with no online submission once its deadline passes
    Given a summative "assign" "Presentation" in "M1" with no online submission was due "2 days ago"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "Presentation" should appear in the "Marking in progress" section with "2" to mark
    When I log in as "convenor" and view the "Convenor view"
    Then "Presentation" should not show a "Submitted" progress bar

  # ---------- EC6: group and enrolment-method changes ----------

  Scenario: Deleting a group updates its members without waiting for the night
    Given a summative "assign" "T1" in "M1" with team submission is due "in 2 days"
    And the following "groups" exist:
      | name | course | idnumber |
      | G1   | M1     | G1       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
      | student2 | G1    |
    And "student1" has submitted "T1" for "G1"
    And the My assessments index is up to date
    When the group "G1" is deleted
    And the My assessments index catches up
    And I log in as "student2" and view my dashboard
    Then "T1" should not show "Submitted"

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

  Scenario: Reallocating a marked script leaves the credit with the marker who marked it
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is allocated to "marker2"
    And "marker1" marks the submission of "student1" for "A1"
    And the submission of "student1" for "A1" is allocated to "marker2"
    And the My assessments index catches up
    When I log in as "marker1" and view the "My marking"
    Then "A1" should appear in the "Completed marking" section
    When I log in as "marker2" and view the "My marking"
    Then "A1" should show a "Marked" progress bar with "0" of "1"

  # ---------- EC9: double marking, a marker replaced (Moodle 5.0+) ----------

  Scenario: Agreement waits for the currently allocated markers
    Given a summative "assign" "A1" in "M1" with marker allocation, 2 markers and manual agreement was due "2 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1" and "marker2"
    And "marker1" marks the submission of "student1" for "A1"
    And the submission of "student1" for "A1" is allocated to "marker2" and "convenor"
    And "marker2" marks the submission of "student1" for "A1"
    And the My assessments index catches up
    # marker2 is now the first allocated marker, who does the agreement (G8). The convenor
    # (the new second marker) hasn't marked, so there is nothing to agree yet.
    When I log in as "marker2" and view the "My marking"
    Then "A1" should not show an "Agreed" progress bar

  # ---------- EC11: revert to draft after release ----------

  Scenario: A released submission reverted to draft reads as open for resubmission
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the submission of "student1" for "A1" is reverted to draft
    And the My assessments index catches up
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Resubmission open"
    And "A1" should not appear in the "Overdue" section

  # ---------- EC3b: which academic years a course belongs to (Pierre, 2026-10-04) ----------
  # A course belongs to every academic year its start-to-end span overlaps.
  # Start date only: its start year. End date only (start date 0): its end year.
  # Neither: only "All years", as in beta9.

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
