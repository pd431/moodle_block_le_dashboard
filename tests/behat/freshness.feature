@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Dashboard data is cached, says how fresh it is, and can be refreshed
  In order to get fast dashboards without being misled by old numbers
  As a student, marker or convenor
  I need to see when my data was last updated and be able to refresh it

  # design-v2 section 4.4 (R4, R9). Steps that say "the index catches up" run pending ad hoc tasks.

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
    And a summative "coursework" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And both submissions of "A1" are allocated to "marker1"
    And the My assessments index is up to date

  Scenario: Refresh updates a marker's own figures without waiting for the background task
    Given I log in as "marker1" and view my dashboard
    And "marker1" marks the submission of "student1" for "A1"
    When I press "Refresh" in the "My assessments" block
    Then "A1" should show "1" to mark
    And I should see "Updated" with the time "now"

  Scenario: A large convenor refresh is queued and says so
    Given "convenor" convenes 200 modules with tagged assessments
    And I log in as "convenor" and view the "Convenor view"
    When I press "Refresh" in the "My assessments" block
    Then I should see "Refreshing"
    When the My assessments index catches up
    And I view the "Convenor view" again
    Then I should see "Updated" with the time "now"
