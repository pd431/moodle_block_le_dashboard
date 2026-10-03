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

  # 2026-10-03: the dual-role scenario moved to view-routing.feature (rc6 routing, Pierre).

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

  # Added 2026-10-03 (F7, coordinator-approved): a passed date is not news; the In marking pill says the rest.
  Scenario Outline: "Feedback expected" shows only while that date is still to come
    Given a summative "assign" "A1" in "M1" with a marking deadline "<marking>" was due "10 days ago"
    And "student1" has submitted "A1"
    When I log in as "student1" and view my dashboard
    Then "A1" <shows> "Feedback expected"

    Examples:
      | marking    | shows           |
      | in 5 days  | should show     |
      | 2 days ago | should not show |

  # beta6 (Pierre, 2026-10-03): two lines, the module's full name, then the kind of activity.
  Scenario: A student row names the module in full and the kind of activity
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    And a formative "quiz" "Q1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Module 1"
    And "A1" should show "Summative assignment"
    And "A1" should not show "M1"
    And "Q1" should show "Formative quiz"

  # G2 (workflow gaps, 2026-10-03): the gradebook still holds the old attempt's grade.
  Scenario: A student who resubmits sees "In marking", not the previous grade
    Given the following "users" exist:
      | username | firstname | lastname |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | marker1 | M1     | editingteacher |
    And a summative "assign" "A1" in "M1" with marking workflow and reopened attempts was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the submission of "student1" for "A1" is reopened
    And "student1" has submitted "A1"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "In marking"
    And "A1" should not show "Feedback released"
