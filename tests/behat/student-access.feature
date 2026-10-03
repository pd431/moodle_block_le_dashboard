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

  # G12 (Pierre, 2026-10-03, Q2), replacing F7: the feedback date stays, with a hint once it passes.
  Scenario: A passed feedback date stays, with a hint saying who to contact
    Given the My assessments feedback contact is "your module convenor" linking to "https://example.com/contact"
    And a summative "assign" "A1" in "M1" with a marking deadline "yesterday" was due "10 days ago"
    And "student1" submitted "A1" "11 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected"
    And "A1" should have a feedback contact hint
    When I click on the feedback contact hint of "A1"
    Then I should see "Feedback should arrive soon. If it doesn't, contact your module convenor." in the "My assessments" block
    And I should see "your module convenor" linking to "https://example.com/contact"

  Scenario: A feedback date still to come has no hint
    Given a summative "assign" "A1" in "M1" with a marking deadline "in 5 days" was due "3 days ago"
    And "student1" submitted "A1" "4 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected"
    And "A1" should not have a feedback contact hint

  # G4 (Pierre, 2026-10-03, Q1). Mitigate needs block_rhb's links: without block_rhb, a stand-in gives one.
  @block_rhb
  Scenario Outline: Mitigate is offered from 3 months before the deadline to 24 hours after it
    Given a summative "assign" "A1" in "M1" <is> due "<due>"
    And <step>
    When I log in as "student1" and view my dashboard
    Then "A1" should <show> "Mitigate"

    Examples:
      | is  | due          | step                          | show     |
      | is  | in 100 days  | nothing happens               | not show |
      | is  | in 80 days   | nothing happens               | show     |
      | is  | in 3 days    | "student1" has submitted "A1" | show     |
      | was | 20 hours ago | nothing happens               | show     |
      | was | 20 hours ago | "student1" has submitted "A1" | show     |
      | was | 2 days ago   | nothing happens               | not show |

  @block_rhb
  Scenario: The Mitigate window follows the settings and the student's own deadline
    Given the My assessments Mitigate window opens "30" days before and closes "48" hours after the deadline
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And a summative "assign" "A2" in "M1" is due "in 40 days"
    And a summative "assign" "A3" in "M1" was due "30 hours ago"
    And "student1" has an extension on "A1" to "tomorrow"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Mitigate"
    And "A2" should not show "Mitigate"
    And "A3" should show "Mitigate"

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

  # ---------- Workflow gaps, beta7 (coordinator-approved scope, 2026-10-03) ----------

  # G13: a reopened attempt is open again, whatever the deadline, and nothing was missed.
  Scenario: A reopened attempt can be resubmitted even after the overdue threshold
    Given the following "users" exist:
      | username | firstname | lastname |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | marker1 | M1     | editingteacher |
    And a summative "assign" "A1" in "M1" with reopened attempts was due "30 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the submission of "student1" for "A1" is reopened
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show "Resubmission open"
    And "A1" should show "Submit"
    And I click on the activity icon of "A1"
    And the activity icon tooltip of "A1" should say "Assignment."

  # G5: lateness to the precision its size needs, rounded down, against the effective deadline.
  Scenario Outline: Lateness is shown to the precision its size needs
    Given a summative "assign" "A1" in "M1" was due "<due>"
    And "student1" submitted "A1" "<submitted>"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "<text>"

    Examples:
      | due            | submitted      | text                              |
      | 2 minutes ago  | 90 seconds ago | Submitted less than a minute late |
      | 1 hour ago     | 48 minutes ago | Submitted 12 min late             |
      | 8 hours ago    | 2 hours ago    | Submitted 6 h late                |
      | 3 days ago     | 20 hours ago   | Submitted 2 days late             |

  Scenario: On-time work says nothing about earliness or the time it was submitted
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And "student1" submitted "A1" "3 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "In marking"
    And "A1" should not show "Submitted"
    And "A1" should not show "early"
    And "A1" should not show "late"

  Scenario: Late summative work carries the late note, late formative work does not
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And a formative "assign" "F1" in "M1" was due "2 days ago"
    And "student1" submitted "A1" "1 day ago"
    And "student1" submitted "F1" "1 day ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Late submissions may have their mark capped."
    And "F1" should not show "capped"

  Scenario: Work submitted before an extended deadline is not late
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" has an extension on "A1" to "tomorrow"
    And "student1" submitted "A1" "1 day ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should not show "late"
    And "A1" should not show "capped"

  Scenario: An overdue assessment with a cut-off says how long is left to submit
    Given a summative "assign" "A1" in "M1" with a cut-off "in 21 hours" was due "3 hours ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Overdue" section
    And "A1" should show "You can still submit for 20 h"

  Scenario Outline: Upcoming deadlines within a week show how soon
    Given a summative "assign" "A1" in "M1" is due "<due>"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "<text>"

    Examples:
      | due           | text          |
      | in 40 minutes | Due in 39 min |
      | in 5 hours    | Due in 4 h    |
      | in 3 days     | Due in 2 days |

  # G14: the pill already says "Feedback released".
  Scenario: A released row doesn't repeat "released"
    Given the following "users" exist:
      | username | firstname | lastname |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | marker1 | M1     | editingteacher |
    And a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback released"
    And "A1" should not show "Released"
