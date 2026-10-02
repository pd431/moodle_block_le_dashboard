@block @block_my_assessments @javascript
Feature: Students see only assessments they can actually access
  In order to trust that every assessment on my dashboard is one I can act on
  As a student
  I need the dashboard to apply the same access rules as the course page

  # Classification (overdue, upcoming, past) is covered by refactor doc section 7.
  # Each "view my dashboard" step first runs pending My assessments index tasks.
  # Freshness and refresh are covered in freshness.feature.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | visible |
      | Module 1 | M1        | 1       |
      | Module 2 | M2        | 0       |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
      | student2 | M1     | student |
      | student1 | M2     | student |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | M1     | GA       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario Outline: A visible, tagged, unrestricted <activity> appears
    Given a summative "<activity>" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section

    Examples:
      | activity   |
      | coursework |
      | quiz       |
      | assign     |
      | peerwork   |

  Scenario Outline: A <activity> hidden from students does not appear
    Given a summative "<activity>" "A1" in "M1" is due "tomorrow"
    And "A1" is hidden from students
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

    Examples:
      | activity   |
      | coursework |
      | quiz       |

  Scenario: Assessments in a hidden course do not appear
    Given a summative "quiz" "A1" in "M2" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

  Scenario: An assessment in a section hidden from students does not appear
    Given a summative "coursework" "A1" in "M1" is due "tomorrow"
    And the section containing "A1" is hidden
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

  Scenario: An assessment restricted to a group I am not in does not appear, nor is it counted
    Given a summative "coursework" "A1" in "M1" is due "tomorrow"
    And "A1" is restricted to group "Group A"
    When I log in as "student2" and view my dashboard
    Then I should not see "A1" in the "My assessments" block
    And the "Upcoming" count should be "0"

  Scenario: An assessment restricted to my group appears
    Given a summative "coursework" "A1" in "M1" is due "tomorrow"
    And "A1" is restricted to group "Group A"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section

  Scenario: An assessment restricted by activity completion I have not met does not appear
    Given a summative "quiz" "A1" in "M1" is due "tomorrow"
    And "A1" is only available once "Reading" in "M1" is complete
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

  # R1: any restriction hides the assessment, date restrictions included.
  Scenario: An assessment that only has a date restriction is hidden until it opens
    Given a summative "coursework" "A1" in "M1" is due "in 14 days"
    And "A1" is only available from "in 3 days"
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

  Scenario: An enrolment that has ended hides the module's assessments
    Given a summative "quiz" "A1" in "M1" is due "tomorrow"
    And the enrolment of "student1" in "M1" ended "yesterday"
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

  Scenario: A suspended enrolment hides the module's assessments
    Given a summative "quiz" "A1" in "M1" is due "tomorrow"
    And the enrolment of "student1" in "M1" is suspended
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

  Scenario: A site-configured student role other than "student" is recognised
    Given the role "Distance learner" exists, based on "student"
    And the My assessments block treats "Distance learner" as a student role
    And "student2" is enrolled in "M1" as "Distance learner" only
    And a summative "coursework" "A1" in "M1" is due "tomorrow"
    When I log in as "student2" and view my dashboard
    Then "A1" should appear in the "Upcoming" section

  Scenario: Someone who teaches one module and studies another sees their own assessments as a student
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 3 | M3        |
    And "student1" is enrolled in "M3" as "editingteacher"
    And a summative "coursework" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then I should be able to open the "Student view"
    And "A1" should appear in the "Upcoming" section

  # Added 2026-10-02 with design-v2 section 5a: counts come from data, not from section titles.
  Scenario: Summary counts are unaffected by a customised section title
    Given a summative "quiz" "A1" in "M1" was due "yesterday"
    And the language string for the "Overdue" section title is customised to "Needs attention"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Needs attention" section
    And the "Overdue" summary count should be "1"

  Scenario: Searching filters rows but does not change the summary counts
    Given a summative "quiz" "A1" in "M1" was due "yesterday"
    And a summative "quiz" "A2" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    And I search the "My assessments" block for "A1"
    Then I should not see "A2" in the "My assessments" block
    And the "Overdue" summary count should be "2"

  Scenario: The type filter switches the summary counts to that type
    Given a summative "quiz" "A1" in "M1" was due "yesterday"
    And a formative "quiz" "F1" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    And I press "Formative" in the "My assessments" block
    Then the "Overdue" summary count should be "1"
    And I should not see "A1" in the "My assessments" block
