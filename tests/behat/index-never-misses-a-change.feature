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

  # Item 2 (F2)

  # Item 3 (F3)

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

  # Item 8 (F9)
  @javascript
  Scenario: Pressing Refresh again within a minute does not re-index again
    Given I log in as "marker1" and view my dashboard
    And I press "Refresh" in the "My assessments" block
    And 2 minutes pass
    When I press "Refresh" in the "My assessments" block
    Then "A1" should have been re-indexed once
