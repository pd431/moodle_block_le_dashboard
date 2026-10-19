@block @block_le_dashboard
Feature: Activity icons match the course page
  In order to recognise an activity on the dashboard as I do on the course page
  As a student, marker or convenor
  I need each row to show the module's own icon in its purpose colour

  # Pierre's UAT, 2026-10-19: "Activity icons in the dash don't match the course view." Approved scope
  # beta27-uat.feature: the module's own monologo, which a theme can override, in its purpose colour, as core's
  # course page draws it. The status marks stay as small badges on the icon's corner, and the tooltip and
  # accessible name don't change.

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

  Scenario Outline: A row's activity icon is the one the course page shows
    Given a summative "<activity>" "A1" in "M1" is due "in 10 days"
    And the My assessments index is up to date
    When I log in as "<user>" and view the "<view>"
    Then the activity icon of "A1" should be the "<activity>" module's own icon
    And the activity icon of "A1" should use the "assessment" purpose colour

    Examples:
      | activity | user     | view          |
      | assign   | student1 | Student view  |
      | quiz     | student1 | Student view  |
      | assign   | marker1  | My marking    |
      | quiz     | marker1  | My marking    |

  @mod_coursework
  Scenario: Coursework uses its own module icon
    Given a summative "coursework" "C1" in "M1" is due "in 10 days"
    And the My assessments index is up to date
    When I log in as "student1" and view the "Student view"
    Then the activity icon of "C1" should be the "coursework" module's own icon

  @javascript
  Scenario: Status marks still show on the module's own icon
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the activity icon of "A1" should be the "assign" module's own icon
    And the activity icon of "A1" should have the "overdue" modifier
    And I click on the activity icon of "A1"
    And the activity icon tooltip of "A1" should say "Assignment. Overdue."
