@block @block_le_dashboard
Feature: The index never misses or fights over a change
  In order to trust what the dashboard says right after something happens
  As a student, marker or convenor
  I need every change reflected, my own actions at once, and no errors from overlapping updates

  # perf-review/review.md items 1 to 8 (coordinator-approved 2026-10-04). "I view my dashboard"
  # runs no pending index tasks, so it shows what the request itself updated.

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
    And a summative "assign" "A1" in "M1" is due "in 1 hour"
    And the My assessments index is up to date

  # Item 1 (F1)
  Scenario: A submission made while the assessment is being re-indexed is not lost
    Given a re-index of "A1" has started
    When "student2" submits "A1"
    And the running re-index of "A1" finishes
    And the My assessments index catches up
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Submitted" progress bar with "1" of "2"

  # Item 2 (F2)
  Scenario: Two re-indexes of the same assessment at once both succeed
    When "A1" is re-indexed twice at the same time
    Then neither re-index should fail
    And the index for "A1" should match the activity

  Scenario: One failing assessment does not stop the nightly reconcile
    Given a summative "assign" "A2" in "M1" is due "in 2 days"
    And writing the index for "A1" fails once
    When the My assessments nightly reconcile runs
    Then "A2" should be in the index

  # Item 3 (F3)
  Scenario: Showing a hidden grade item releases feedback without waiting for the night
    Given "A1" was due "3 days ago"
    And "student1" has submitted "A1"
    And the grade item of "A1" is hidden
    And "marker1" grades "student1" on "A1" with "65"
    And the My assessments index catches up
    When the grade item of "A1" is shown
    And the My assessments index catches up
    And I log in as "student1" and view my dashboard
    Then "A1" should show the status "Feedback released"

  # Item 4 (F4)
  Scenario: My own submission shows straight away
    When I log in as "student1"
    And I submit "A1"
    And I view my dashboard
    Then "A1" should show the status "Submitted"

  @javascript
  Scenario: A student's Refresh changes only that student's rows
    Given "student2" has submitted "A1" without the index being told
    When I log in as "student1" and view my dashboard
    And I press "Refresh" in the "My assessments" block
    Then the index row of "student2" for "A1" should be unchanged

  Scenario: A team submission updates every member straight away
    Given a summative "assign" "T1" in "M1" with team submission is due "in 1 hour"
    And "student1" and "student2" are in group "G1"
    And the My assessments index is up to date
    When I log in as "student1"
    And I submit "T1" for my group
    And I log in as "student2"
    And I view my dashboard
    Then "T1" should show the status "Submitted"

  # Item 5 (F5)
  Scenario: The nightly reconcile leaves old courses alone
    Given the "Live window for the nightly reconcile" setting is "13" months
    And a course "OLD" with a summative "assign" "Z1" was due "2 years ago"
    And the My assessments index is up to date
    And the deadline of "Z1" is changed directly in the database to "3 years ago"
    When the My assessments nightly reconcile runs
    Then the index should still show "Z1" due "2 years ago"

  Scenario: An event still updates an old course
    Given a course "OLD" with a summative "assign" "Z1" was due "2 years ago"
    And the My assessments index is up to date
    When "Z1" is renamed to "Z1 resit"
    And the My assessments index catches up
    Then the index should show "Z1 resit"

  # Item 8 (F9)
  @javascript
  Scenario: Pressing Refresh again within a minute does not re-index again
    Given I log in as "marker1" and view my dashboard
    And I press "Refresh" in the "My assessments" block
    And 2 minutes pass
    When I press "Refresh" in the "My assessments" block
    Then "A1" should have been re-indexed once
