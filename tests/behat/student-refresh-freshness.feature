@block @block_le_dashboard @block_le_dashboard_crossdb @javascript
Feature: A student's Refresh moves their "Updated" line on
  In order to trust that Refresh did something
  As a student
  I need the "Updated" line to say "just now" after I refresh

  # Pierre's screenshot, 2026-10-09: two days after the last full index a student pressed Refresh and still read
  # "Updated 7 Oct 2026, 12:18". A student's Refresh rewrites only that student's rows (F4), and the line read the
  # oldest assessment index time, which only a full index of the assessment moves.
  # Coordinator, 2026-10-09: the student line shows the later of the oldest assessment index time and this
  # view's last Refresh that finished. A full index of the assessment is not faked: staff lines are unchanged.
  # A Refresh that couldn't update every assessment (one was busy being re-indexed) doesn't count, as before.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | marker1  | M1     | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" is due "in 3 days"
    And the My assessments index is up to date
    And the My assessments index was last updated "2 days ago"

  Scenario: Before a Refresh the line shows the last full update
    When I log in as "student1" and view my dashboard
    Then I should not see "Updated" with the time "now"

  Scenario: After a Refresh the line says just now, and still does on the next visit
    When I log in as "student1" and view my dashboard
    And I press "Refresh" in the "My assessments" block
    Then I should see "Updated" with the time "now"
    And I view my dashboard again
    And I should see "Updated" with the time "now"

  Scenario: A student's Refresh doesn't make a marker's line look fresh
    Given I log in as "student1" and view my dashboard
    And I press "Refresh" in the "My assessments" block
    When I log in as "marker1" and view my dashboard
    Then I should not see "Updated" with the time "now"
