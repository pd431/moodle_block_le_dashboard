@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Students an access restriction shuts out drop out of an assessment's numbers
  In order that an assessment's counts cover only the students it is for
  As a module convenor or marker
  I need work by students a restriction now shuts out left out of marking, allocation, release and To mark

  # Pierre, 2026-10-09 (scope remake-scale/scopes/restricted-students.feature, approved). Exeter's formal
  # mitigation moves a student to a copy of the coursework ("<name> - Extension") with a later deadline: the
  # support team adds them to a "<name> extension" group, and the original is restricted to "must belong to
  # <name> AND must not belong to <name> extension". From then on nothing they did on the original counts there.
  # Any restriction checked against a list of students (groups, groupings, profile fields) on any activity type.
  # Before beta20 such a student already left their own dashboard and the Submitted bar, but anything handed in
  # before the move still counted in marking, allocation, release, plagiarism and To mark.
  # As built: the index leaves out the marking units of students who can't access the activity (a group's unit
  # counts while any indexed member can). Nothing in the activity is changed: lifting the restriction brings
  # the work back at the next index. Group changes already queue a re-index of the course.
  # Plagiarism flags live on the same marking units, so they drop out too (coursework only: PHPUnit covers it).

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname  |
      | student1 | Sam       | Student   |
      | student2 | Sue       | Student   |
      | student3 | Sid       | Student   |
      | marker1  | Mo        | Marker    |
      | convenor | Cat       | Convenor  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | student3 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And the following "groups" exist:
      | name         | course | idnumber |
      | NC           | M1     | NC       |
      | NC extension | M1     | NCX      |
    And the following "group members" exist:
      | user     | group |
      | student1 | NC    |
      | student2 | NC    |
      | student3 | NC    |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "NC Individual" in "M1" was due "2 days ago"
    And "NC Individual" is restricted to group "NC" and not group "NC extension"
    And a summative "assign" "Extension copy" in "M1" is due "in 14 days"
    And "Extension copy" is restricted to group "NC extension"
    And the My assessments index catches up

  Scenario: A student moved to the extension sees the copy instead of the original
    Given "student2" is added to group "NC extension"
    And the My assessments index catches up
    When I log in as "student2" and view my dashboard
    Then "NC Individual" should not appear in the "Overdue" section
    And "NC Individual" should not appear in the "Past" section
    And "Extension copy" should appear in the "Upcoming" section
    When I log in as "student1" and view my dashboard
    Then "NC Individual" should appear in the "Overdue" section

  Scenario: Work handed in on the original before the move stops counting
    Given "student1" submitted "NC Individual" "3 days ago"
    And "student2" submitted "NC Individual" "3 days ago"
    And "student3" submitted "NC Individual" "3 days ago"
    And "marker1" marks the submission of "student1" for "NC Individual"
    And the My assessments index catches up
    And I log in as "marker1" and view my dashboard
    And "NC Individual" shows "2" to mark
    When "student2" is added to group "NC extension"
    And the My assessments index catches up
    And I view my dashboard again
    Then "NC Individual" should show "1" to mark
    When I log in as "convenor" and view my dashboard
    Then "NC Individual" should show a "Submitted" progress bar with "2" of "2"
    And "NC Individual" should show a "Marked" progress bar with "1" of "2"

  Scenario: An unmarked student moved out doesn't hold the original in In progress
    Given "student1" submitted "NC Individual" "3 days ago"
    And "student2" submitted "NC Individual" "3 days ago"
    And "student3" submitted "NC Individual" "3 days ago"
    And "marker1" marks the submission of "student1" for "NC Individual"
    And "marker1" marks the submission of "student3" for "NC Individual"
    And the grade of "student1" for "NC Individual" is released
    And the grade of "student3" for "NC Individual" is released
    When "student2" is added to group "NC extension"
    And the My assessments index catches up
    And I log in as "convenor" and view my dashboard
    Then "NC Individual" should appear in the "Past" section
    And "NC Individual" should show the grading status "Released to students"

  Scenario: Taking the student out of the extension group brings their work back
    Given "student2" submitted "NC Individual" "3 days ago"
    And "student2" is added to group "NC extension"
    And the My assessments index catches up
    When "student2" is removed from group "NC extension"
    And the My assessments index catches up
    And I log in as "convenor" and view my dashboard
    Then "NC Individual" should show a "Submitted" progress bar with "1" of "3"
    When I log in as "student2" and view my dashboard
    Then "NC Individual" should appear in the "Past" section

  Scenario: A team counts while any member can still access the assessment
    Given a summative "assign" "Team work" in "M1" with team submission in grouping "Teams" was due "2 days ago"
    And "Team work" is restricted to group "NC" and not group "NC extension"
    And "student1" and "student2" are team "T1" of "Team work"
    And "student1" has submitted "Team work"
    When "student2" is added to group "NC extension"
    And the My assessments index catches up
    And I log in as "marker1" and view my dashboard
    Then "Team work" should show "1" to mark
