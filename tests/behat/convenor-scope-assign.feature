@block @block_my_assessments @block_my_assessments_mirror @javascript
Feature: Convenors oversee all marking in their modules, with bars only where the activity supports them (assign mirror)
  In order to see where marking is stuck
  As a module convenor
  I need every assessment in my modules with progress for each marking stage the activity actually has

  # Bars come from what each activity type supports.
  # Each "view my dashboard" step first runs pending My assessments index tasks.
  # Freshness and refresh are covered in freshness.feature.

  # Mirror of convenor-scope.feature with assign in place of coursework, so the same rules run on sites
  # without mod_coursework. Scenarios that need coursework-only stages are left out.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | startdate      |
      | Module 1 | M1        | ##2026-09-28## |
      | Module 2 | M2        | ##2025-09-29## |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | student3 | Sid       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | student3 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
      | convenor | M2     | moduleconvenor |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | M1     | GA       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student2 | GA    |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the academic year "2026/27" starts on "2026-09-01"

  Scenario: Only modules in the selected academic year are shown
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And a summative "assign" "A2" in "M2" was due "3 days ago"
    When I log in as "convenor" and view the "Convenor view" for academic year "2026/27"
    Then I should see "A1" in the "My assessments" block
    And I should not see "A2" in the "My assessments" block

  Scenario: Convenor sees marking by every marker, including their own
    Given a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is allocated to "convenor"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Marked" progress bar with "0" of "2"
    And "A1" should list markers "Mo Marker" and "Cat Convenor"

  Scenario: Unallocated bar appears only for activity types with allocation
    Given a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"
    And a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is not allocated
    And "student1" has finished "Q1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show an "Unallocated" progress bar with "1"
    And "Q1" should not show an "Unallocated" progress bar

  # R4: computed when indexed; freshness.feature covers refresh.
  Scenario: Submission totals count only students who can access the assessment
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "A1" is restricted to group "Group A"
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Submitted" progress bar with "1" of "2"

  Scenario: An assessment hidden from students is still overseen, and flagged
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "A1" is hidden from students
    When I log in as "convenor" and view the "Convenor view"
    Then I should see "A1" in the "My assessments" block
    And "A1" should show "Hidden from students"

  Scenario: A marker who is not a convenor cannot open the convenor view
    When I log in as "marker1" and view my dashboard
    Then I should not be able to open the "Convenor view"

  # D4: released means the agreed or final grade has been published to the student.

  # beta1 review M7: no "Marked 0 of 0" before anything is submitted.
  Scenario: Marking bars appear only once something is submitted
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And a summative "assign" "A2" in "M1" was due "3 days ago"
    And "student1" has submitted "A2"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show progress bars "Submitted"
    And "A2" should show progress bars "Submitted, Marked, Released"

  # beta6 (Pierre, 2026-10-03): marking doesn't start before the deadline.
  Scenario: Before the deadline the convenor sees submissions only
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show progress bars "Submitted"

  Scenario: Before the deadline an allocated assessment also shows what is unallocated
    Given a summative "assign" "A1" in "M1" with marker allocation is due "tomorrow"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show progress bars "Submitted, Unallocated"
    And "A1" should show an "Unallocated" progress bar with "1" of "2"
    And I should not see "Show markers" in the "My assessments" block

  Scenario: Once the deadline passes the marking bars appear
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "In progress" section
    And "A1" should show progress bars "Submitted, Marked, Released"

  # G1, G2 (workflow gaps, 2026-10-03).
  Scenario: A resubmission under marking workflow comes back to the convenor, not released
    Given a summative "assign" "A1" in "M1" with marking workflow and reopened attempts was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the submission of "student1" for "A1" is reopened
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "In progress" section
    And "A1" should show a "Released" progress bar with "0" of "1"

  # ---------- Workflow gaps, beta7 (coordinator-approved scope, 2026-10-03) ----------

  # G6
  Scenario: Returned work moves the assessment back to In progress with a hint for the convenor
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student2" has an extension on "A1" to "in 3 days"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And "student2" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "In progress" section
    And "A1" should have a returned work hint

  # G10
  Scenario: Unmarked work past the marking deadline is called out for the convenor
    Given a summative "assign" "A1" in "M1" with a marking deadline "yesterday" was due "10 days ago"
    And "student1" and "student2" have submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show "1 unmarked after the marking deadline"

  Scenario: Before the marking deadline there is no call-out
    Given a summative "assign" "A1" in "M1" with a marking deadline "in 5 days" was due "3 days ago"
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should not show "unmarked after the marking deadline"

  # G11
  Scenario: Staff rows name which deadline a date is
    Given a summative "assign" "A1" in "M1" with a marking deadline "in 10 days" was due "3 days ago"
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show "Submission deadline"
    And "A1" should show "Marking deadline"
    And "A1" should not show "Due"
