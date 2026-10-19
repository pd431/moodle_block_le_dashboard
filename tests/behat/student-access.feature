@block @block_le_dashboard
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
    And the My assessments block keeps its hints and guidance from before beta27

  @javascript
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

  @javascript
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

  @javascript
  Scenario: An assessment in a section hidden from students does not appear
    Given a summative "coursework" "A1" in "M1" is due "tomorrow"
    And the section containing "A1" is hidden
    When I log in as "student1" and view my dashboard
    Then I should not see "A1" in the "My assessments" block

  @javascript
  Scenario: An assessment restricted to a group I am not in does not appear, nor is it counted
    Given a summative "coursework" "A1" in "M1" is due "tomorrow"
    And "A1" is restricted to group "Group A"
    When I log in as "student2" and view my dashboard
    Then I should not see "A1" in the "My assessments" block
    And the "Upcoming" count should be "0"

  @javascript
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
  @javascript
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

  @javascript
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
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And the language string for the "Overdue" section title is customised to "Needs attention"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Needs attention" section
    And the "Overdue" summary count should be "1"

  @javascript
  Scenario: Searching filters rows but does not change the summary counts
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And a summative "assign" "A2" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    And I search the "My assessments" block for "A1"
    Then I should not see "A2" in the "My assessments" block
    And the "Overdue" summary count should be "2"

  @javascript
  Scenario: The type filter switches the summary counts to that type
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And a formative "assign" "F1" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    And I press "Formative" in the "My assessments" block
    Then the "Overdue" summary count should be "1"
    And I should not see "A1" in the "My assessments" block

  # G12 (Pierre, 2026-10-03, Q2), replacing F7: the feedback date stays, with a hint once it passes.
  @javascript
  Scenario: A passed feedback date stays, with a hint saying who to contact
    Given the My assessments feedback contact is "your module convenor" linking to "https://example.com/contact"
    And a summative "assign" "A1" in "M1" with a marking deadline "yesterday" was due "10 days ago"
    And "student1" submitted "A1" "11 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected"
    And "A1" should have a feedback contact hint
    When I click on the feedback contact hint of "A1"
    Then I should see "Feedback should arrive soon." in the "My assessments" block
    And I should see "If it doesn't, contact your module convenor." in the "My assessments" block
    And I should see "your module convenor" linking to "https://example.com/contact"

  # D9: the contact defaults to "your module convenor". Since beta20 (guidance-settings.feature) an admin who
  # empties the box keeps the hint's factual line, with no guidance under it.
  @javascript
  Scenario: The feedback contact defaults to the module convenor
    Given a summative "assign" "A1" in "M1" with a marking deadline "yesterday" was due "10 days ago"
    And "student1" submitted "A1" "11 days ago"
    When I log in as "student1" and view my dashboard
    And I click on the feedback contact hint of "A1"
    Then I should see "If it doesn't, contact your module convenor." in the "My assessments" block

  Scenario: With the feedback guidance emptied, the hint keeps its factual line only
    Given the "guidance_feedbacklate" setting is empty
    And a summative "assign" "A1" in "M1" with a marking deadline "yesterday" was due "10 days ago"
    And "student1" submitted "A1" "11 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected"
    And the "feedbackcontact" popover of "A1" should contain only its factual line

  Scenario: A feedback date still to come has no hint
    Given a summative "assign" "A1" in "M1" with a marking deadline "in 5 days" was due "3 days ago"
    And "student1" submitted "A1" "4 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected"
    And "A1" should not have a feedback contact hint

  # G4 (Pierre, 2026-10-03), as rc6 again from beta18 (mitigate-rc6.feature): Past work the student didn't hand
  # in keeps Mitigate for the hours set after their own deadline, extensions included.
  @block_rhb
  Scenario: Past work keeps Mitigate for the hours set after the student's own deadline
    Given the My assessments Mitigate stays open "48" hours after the deadline
    And a summative "assign" "A1" in "M1" with a cut-off "3 days ago" was due "3 days ago"
    And a summative "assign" "A3" in "M1" with a cut-off "30 hours ago" was due "30 hours ago"
    And "student1" has an extension on "A1" to "1 hour ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Past" section
    And "A1" should show "Mitigate"
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
  @javascript
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
    And "A1" should have no status pill
    And "A1" should show "Submit"
    And I click on the activity icon of "A1"
    And the activity icon tooltip of "A1" should say "Assignment."

  # G5: lateness to the precision its size needs, rounded down, against the effective deadline.
  Scenario Outline: Lateness is shown to the precision its size needs
    Given a summative "assign" "A1" in "M1" was due "<due>"
    And "student1" submitted "A1" "<submitted>"
    When I log in as "student1" and view my dashboard
    Then the "late" icon of "A1" should be labelled "<text>. Late penalties may apply."

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

  # Pierre, 2026-10-04: the late note is now the late penalties popover (student-polish.feature).
  Scenario: Late summative work says late penalties may apply, late formative work only says it was late
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And a formative "assign" "F1" in "M1" was due "2 days ago"
    And "student1" submitted "A1" "1 day ago"
    And "student1" submitted "F1" "1 day ago"
    When I log in as "student1" and view my dashboard
    Then the "late" icon of "A1" should be labelled "Submitted 1 day late. Late penalties may apply."
    And the "late" icon of "F1" should be labelled "Submitted 1 day late."

  Scenario: Work submitted before an extended deadline is not late
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" has an extension on "A1" to "tomorrow"
    And "student1" submitted "A1" "1 day ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should not show "late"
    And "A1" should not have a late penalty hint

  # beta23 CI, 4.5 MariaDB at 22:30 UTC: failed three times with relative times. Fixed times and a fixed "now"
  # make it the same at any hour.
  Scenario: An overdue assessment with a cut-off says how long is left to submit
    Given a summative "assign" "A1" in "M1" with a cut-off "Tue 13 October 2026 08:00 Europe/London" was due "Mon 12 October 2026 09:00 Europe/London"
    And it is now "Mon 12 October 2026 12:00" in "Europe/London"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Overdue" section
    And "A1" should show "You can still submit for 20 h"

  # B9 (support map, beta22 re-run): without a cut-off Moodle keeps taking the work, so a countdown to the
  # deadline plus the grace period told the student a time limit that doesn't exist.
  Scenario: An overdue assignment without a cut-off has no countdown
    Given a summative "assign" "A1" in "M1" was due "3 hours ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Overdue" section
    And "A1" should not show "You can still submit"

  @javascript
  Scenario Outline: Upcoming deadlines within a week show how soon
    Given a summative "assign" "A1" in "M1" is due "<due>"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "<text>"

    Examples:
      | due           | text          |
      | in 40 minutes | Due in 39 min |
      | in 5 hours    | Due in 4 h    |
      | in 3 days     | Due in 3 days |

  # G14: the pill already says "Feedback released". Pierre, 2026-10-06: the date is labelled
  # "Feedback received", so a first-time student knows what it is.
  Scenario: A released row labels its feedback date without repeating "released"
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
    And "A1" should show "Feedback received" with the date "today"
