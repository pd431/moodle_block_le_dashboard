@block @block_le_dashboard @block_le_dashboard_mirror
Feature: Dashboard data is cached, says how fresh it is, and can be refreshed (assign mirror)
  In order to get fast dashboards without being misled by old numbers
  As a student, marker or convenor
  I need to see when my data was last updated and be able to refresh it

  # design-v2 section 4.4 (R4, R9). Steps that say "the index catches up" run pending ad hoc tasks.

  # Mirror of freshness.feature with assign in place of coursework, so the same rules run on sites
  # without mod_coursework. Scenarios that need coursework-only stages are left out.

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
    And a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And both submissions of "A1" are allocated to "marker1"
    And the My assessments index is up to date

  Scenario: The dashboard says when its data was last updated
    When I log in as "marker1" and view my dashboard
    Then I should see "Updated" with the time the index was last updated

  Scenario: Marking is reflected once the index catches up
    Given I log in as "marker1" and view my dashboard
    And "A1" shows "2" to mark
    When "marker1" marks the submission of "student1" for "A1"
    And the My assessments index catches up
    And I view my dashboard again
    Then "A1" should show "1" to mark

  @javascript
  Scenario: Refresh updates a marker's own figures without waiting for the background task
    Given I log in as "marker1" and view my dashboard
    And "marker1" marks the submission of "student1" for "A1"
    When I press "Refresh" in the "My assessments" block
    Then "A1" should show "1" to mark
    And I should see "Updated" with the time "now"

  @javascript
  Scenario: A large convenor refresh is queued and says so
    Given "convenor" convenes 200 modules with tagged assessments
    And I log in as "convenor" and view the "Convenor view"
    When I press "Refresh" in the "My assessments" block
    Then I should see "Refreshing"
    When the My assessments index catches up
    And I view the "Convenor view" again
    Then I should see "Updated" with the time "now"

  Scenario: An assessment becomes overdue at its deadline without re-indexing
    Given a summative "assign" "Q1" in "M1" is due "in 1 minute"
    And the My assessments index is up to date
    When 2 minutes pass
    And I log in as "student2" and view my dashboard
    Then "Q1" should appear in the "Overdue" section

  Scenario: A change no event reported is corrected by the nightly reconcile
    Given the deadline of "A1" is changed directly in the database to "in 7 days"
    When the My assessments nightly reconcile runs
    And I log in as "student2" and view my dashboard
    Then "A1" should show the due date "in 7 days"

  Scenario: An unenrolled student disappears from the convenor's totals
    Given "student2" is unenrolled from "M1"
    When the My assessments index catches up
    And I log in as "convenor" and view the "Convenor view"
    Then "A1" should show a "Submitted" progress bar with "1" of "1"
