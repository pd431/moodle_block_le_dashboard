@block @block_le_dashboard
Feature: My marking's Marking due soon box counts marking by its deadline
  In order to see what marking is due first
  As a marker
  I need the box to count submissions still to mark by their marking deadline (UAT, beta27)

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
      | marker1  | M1     | teacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: The Marking due soon box counts submissions by their marking deadline
    Given the following "users" exist:
      | username | firstname | lastname |
      | student2 | Sue       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | M1     | student |
    And a summative "assign" "A1" in "M1" with a marking deadline "in 5 days" was due "3 days ago"
    And a summative "assign" "A2" in "M1" with a marking deadline "in 10 days" was due "3 days ago"
    And a summative "assign" "A3" in "M1" is due "in 2 days"
    And "student1" and "student2" have submitted "A1"
    And "student1" has submitted "A2"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    And I set the "Marking due soon" summary box window to "Within 7 days"
    Then the "Marking due soon" summary box should show "2"
    And I set the "Marking due soon" summary box window to "Within 15 days"
    And the "Marking due soon" summary box should show "3"
    And I should not see "Student deadlines" in the "My assessments" block
