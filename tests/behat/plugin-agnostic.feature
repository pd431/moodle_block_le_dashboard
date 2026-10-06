@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: The dashboard works with whichever assessment plugins are installed
  In order to use My assessments without depending on mod_coursework, and with new activity types
  As a site administrator
  I need to choose which activity types appear, and see what the block can know about each

  # design-v2 sections 4.1 and 4.3. Each "view my dashboard" step first runs pending index tasks.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: The block installs and works on a site without mod_coursework
    Given "mod_coursework" is not installed
    And a summative "quiz" "Q1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section
    And I should not see any error

  Scenario: The admin page lists every activity type with gradebook entries and what the block can show for it
    Given a "workshop" activity with grade items exists in "M1"
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Activity types" in site administration
    Then I should see the following activity types:
      | Activity            | Support                         | Enabled |
      | Assignment          | Full                            | yes     |
      | Quiz                | Full                            | yes     |
      | Workshop            | Due date and grade only         | no      |

  Scenario: An activity type left unticked is ignored even when tagged
    Given a summative "workshop" "W1" in "M1" is due "tomorrow"
    And the "Workshop" activity type is not enabled in My assessments
    When I log in as "student1" and view my dashboard
    Then I should not see "W1" in the "My assessments" block

  Scenario: A ticked generic activity type shows its due date before the deadline
    Given the "Workshop" activity type is enabled in My assessments
    And a summative "workshop" "W1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then "W1" should appear in the "Upcoming" section
    And "W1" should show "Due" with the date "tomorrow"

  Scenario: A generic activity moves to Past at its deadline and is never called overdue
    Given the "Workshop" activity type is enabled in My assessments
    And a summative "workshop" "W1" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    Then "W1" should appear in the "Past" section
    And "W1" should not appear in the "Overdue" section

  # R8: LTI is out of scope for now.
  Scenario: External tools are not offered as an activity type
    Given an "lti" activity with grade items exists in "M1"
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Activity types" in site administration
    Then I should not see "External tool (LTI)"

  # EC2 (Pierre, 2026-10-04): tagging is explicit, so a tagged activity without a date is listed as Undated.
  Scenario: A tagged generic activity with no due date is listed as Undated
    Given the "Workshop" activity type is enabled in My assessments
    And a summative "workshop" "W2" in "M1" with no due date exists
    When I log in as "student1" and view my dashboard
    Then "W2" should appear in the "Undated" section

  Scenario: An activity type with its own adapter uses it in preference to the generic one
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Overdue" section

  Scenario: An error indexing one activity type does not break the others
    Given indexing of "quiz" activities fails with an error
    And a summative "quiz" "Q1" in "M1" is due "tomorrow"
    And a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And I should not see any error
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Activity types" in site administration
    Then the "Quiz" activity type should show its last indexing error
