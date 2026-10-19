@block @block_le_dashboard
Feature: Activities without a marking deadline get the default marking period
  In order to know when marking is due on a quiz or an assignment left without one
  As a marker or convenor
  I need a default marking deadline some days after the deadline (Pierre, 2026-10-19)

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

  Scenario: A quiz gets the default marking period after its deadline
    Given a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" has finished "Q1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "Q1" should show "Marking deadline"
    And "Q1" should have a "defaultmarkingdue" hint
    And I set the "Marking due soon" summary box window to "Within 7 days"
    And the "Marking due soon" summary box should show "0"
    And I set the "Marking due soon" summary box window to "Within 15 days"
    And the "Marking due soon" summary box should show "1"

  Scenario: An assignment with its marking deadline left empty gets the default too
    Given a summative "assign" "A1" in "M1" was due "8 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    And I set the "Marking due soon" summary box window to "Within 5 days"
    Then the "Marking due soon" summary box should show "1"
    And "A1" should have a "defaultmarkingdue" hint

  Scenario: A marking deadline that is set wins over the default
    Given a summative "assign" "A1" in "M1" with a marking deadline "in 20 days" was due "8 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    And I set the "Marking due soon" summary box window to "Within 15 days"
    Then the "Marking due soon" summary box should show "0"
    And "A1" should not have a "defaultmarkingdue" hint

  Scenario: An admin can change the default marking period
    Given the following config values are set as admin:
      | defaultmarkingdays | 20 | block_le_dashboard |
    And a summative "assign" "A1" in "M1" was due "8 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    And I set the "Marking due soon" summary box window to "Within 7 days"
    Then the "Marking due soon" summary box should show "0"

  Scenario: An admin can turn the default marking period off
    Given the following config values are set as admin:
      | defaultmarkingdays | 0 | block_le_dashboard |
    And a summative "assign" "A1" in "M1" was due "8 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    And I set the "Marking due soon" summary box window to "Within 15 days"
    Then the "Marking due soon" summary box should show "0"
    And "A1" should not show "Marking deadline"

  Scenario: The convenor's late marking line uses the default marking period
    Given the following "users" exist:
      | username  | firstname | lastname |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | convenor1 | M1     | moduleconvenor |
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And a summative "assign" "A1" in "M1" was due "20 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should show "1 unmarked after the marking deadline"
    And "A1" should have a "defaultmarkingdue" hint
