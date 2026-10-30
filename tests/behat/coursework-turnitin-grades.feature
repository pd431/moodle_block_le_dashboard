@block @block_le_dashboard @mod_coursework @javascript
Feature: A coursework grade saved by no one in particular is credited to the allocated marker
  In order to see in Completed marking the work I was allocated, whoever's account saved the grade
  As a marker
  I need a coursework grade with no real grader to count as marked by the marker it was allocated to

  # Pierre, 2026-10-29: grades marked in Turnitin and synced into coursework have assessorid -1, so the allocated
  # marker owned none of them; "turnitin grade should count as marked by the marker". Scope:
  # /mnt/project-files/remake/scopes/coursework-system-grader.feature (beta33). The rule is CS6, in
  # adapter/coursework.php grader(); coursework_test::test_grader covers it on every target.
  # Coursework can't be installed here, so these scenarios run only where it is (@mod_coursework).
  # Adjusted while building: written in the existing steps (allocation after submission, "grades ... with" for a
  # real grader, "Marked by me" for the box, "should list marker" for the convenor), with one new step for the
  # grader id.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "coursework" "C1" in "M1" with marker allocation was due "5 days ago"
    And "student1" and "student2" have submitted "C1"
    And the submission of "student1" for "C1" is allocated to "marker1"
    And the submission of "student2" for "C1" is allocated to "marker1"

  Scenario: Grades saved with no grader count as the allocated marker's
    Given the grade of "student1" for "C1" is saved as "68" with grader id "-1"
    And the grade of "student2" for "C1" is saved as "71" with grader id "-1"
    And the My assessments index is up to date
    When I log in as "marker1" and view my dashboard
    Then "C1" should appear in the "Completed marking" section

  Scenario: Turnitin grades count in the allocated marker's recent marking
    Given the grade of "student1" for "C1" is saved as "68" with grader id "-1"
    And the My assessments index is up to date
    When I log in as "marker1" and view my dashboard
    Then the "Marked by me" summary box should show "1"

  Scenario: The convenor sees Turnitin grades under the allocated marker
    Given the grade of "student1" for "C1" is saved as "68" with grader id "-1"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "Convenor view"
    Then "C1" should list marker "Mo Marker"

  Scenario: Grades saved by a real marker still count as theirs, not the allocated one's
    Given "convenor" grades "student1" on "C1" with "68"
    And the My assessments index is up to date
    When I log in as "marker1" and view my dashboard
    Then "C1" should appear in the "Marking in progress" section with "1" to mark

  Scenario: Without allocation, a grade with no grader is the convenor's auto-marked work
    Given a summative "coursework" "C2" in "M1" was due "5 days ago"
    And "student1" has submitted "C2"
    And the grade of "student1" for "C2" is saved as "68" with grader id "-1"
    And the My assessments index is up to date
    When I log in as "convenor" and view the "My marking"
    Then "C2" should appear in the "Completed marking" section
