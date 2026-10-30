@block @block_le_dashboard
Feature: An activity that hasn't opened yet isn't due soon
  In order not to be told to submit work I can't hand in yet
  As a student
  I need a not-yet-open activity to say when it opens, with View instead of Submit

  # Pierre's UAT, 2026-10-19: "Due soon can appear on a quiz the student can't submit to cuz open date hasn't
  # passed yet". Approved scope beta27-uat.feature: every type with an open date (quiz open, assignment "allow
  # submissions from", coursework start, peer work from). The row stays in Upcoming and shows "Opens <date>",
  # with View in place of Submit, no Due soon mark and no "Due in" line. Once it opens, it behaves as before.

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

  Scenario Outline: An activity that hasn't opened yet says when it opens, not that it's due soon
    Given a summative "<activity>" "A1" in "M1" opens "in 2 days" and is due "in 4 days"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show "Opens"
    And "A1" should show "View"
    And "A1" should not show "Submit"
    And "A1" should not show "Due in"
    And the activity icon of "A1" should not have the "duesoon" modifier

    Examples:
      | activity |
      | quiz     |
      | assign   |
