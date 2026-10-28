@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Convenors oversee all marking in their modules, with bars only where the activity supports them
  In order to see where marking is stuck
  As a module convenor
  I need every assessment in my modules with progress for each marking stage the activity actually has

  # Bars come from what each activity type supports.
  # Each "view my dashboard" step first runs pending My assessments index tasks.
  # Freshness and refresh are covered in freshness.feature.

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

  @javascript
  # A2 is on last year's module and was due last year; one due this year would show by its date (EC3).
  Scenario: Only assessments of the selected academic year are shown
    Given a summative "coursework" "A1" in "M1" was due "3 days ago"
    And a summative "coursework" "A2" in "M2" was due "2025-10-20 12:00"
    When I log in as "convenor" and view the "Convenor view" for academic year "2026/27"
    Then I should see "A1" in the "My assessments" block
    And I should not see "A2" in the "My assessments" block

  @javascript
  Scenario: Convenor sees marking by every marker, including their own
    Given a summative "coursework" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is allocated to "convenor"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Marked" progress bar with "0" of "2"
    And "A1" should list markers "Mo Marker" and "Cat Convenor"

  @javascript
  Scenario: Unallocated bar appears only for activity types with allocation
    Given a summative "coursework" "A1" in "M1" with marker allocation was due "3 days ago"
    And a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is not allocated
    And "student1" has finished "Q1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show an "Unallocated" progress bar with "1"
    And "Q1" should not show an "Unallocated" progress bar

  @javascript
  Scenario: Moderation and agreement bars appear only where the activity has those stages
    Given a summative "coursework" "A1" in "M1" with 2 markers and moderation was due "3 days ago"
    And a summative "assign" "A2" in "M1" was due "3 days ago"
    # Marking bars appear once something is submitted (beta1 review M7).
    And "student1" has submitted "A1"
    And "student1" has submitted "A2"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show progress bars "Submitted, Marker 1, Marker 2, Agreed, Moderated, Released"
    And "A2" should show progress bars "Submitted, Marked, Released"

  # R4: computed when indexed; freshness.feature covers refresh.
  @javascript
  Scenario: Submission totals count only students who can access the assessment
    Given a summative "coursework" "A1" in "M1" was due "3 days ago"
    And "A1" is restricted to group "Group A"
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Submitted" progress bar with "1" of "2"

  @javascript
  Scenario: An assessment hidden from students is still overseen, and flagged
    Given a summative "coursework" "A1" in "M1" was due "3 days ago"
    And "A1" is hidden from students
    When I log in as "convenor" and view the "Convenor view"
    Then I should see "A1" in the "My assessments" block
    And "A1" should have a "hidden" hint

  # D4: released means the agreed or final grade has been published to the student.
  @javascript
  Scenario: A double-marked submission counts as released only once its final grade is published
    Given a summative "coursework" "A1" in "M1" with 2 markers was due "10 days ago"
    And "student1" has submitted "A1"
    And both markers have given feedback on the submission of "student1" for "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Released" progress bar with "0" of "1"
    When the agreed grade for "student1" on "A1" is published
    And the My assessments index catches up
    And I view the "Convenor view" again
    Then "A1" should show a "Released" progress bar with "1" of "1"

  # G15 (workflow gaps, 2026-10-03): peer work marks groups while Submitted counts students.
