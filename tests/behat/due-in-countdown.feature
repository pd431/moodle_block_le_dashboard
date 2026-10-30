@block @block_le_dashboard @javascript
Feature: Every upcoming deadline counts down, however far away
  In order to see how long I have for everything ahead
  As a student
  I need "Due in N days" on every Upcoming row I can still hand in

  # Pierre, 2026-10-29: "adding a computed 'in x days' for when something is due, in upcoming for students" and
  # "Render no matter the length." Scope: /mnt/project-files/remake/scopes/student-year-and-countdown.feature
  # (beta33). The note is student::notes(); the due-soon mark keeps its 7 days (student::DUESOON_DAYS).
  # Adjusted while building: the clock is set and the deadlines written as dates, so the counts stay exact; the
  # due-soon mark is checked with the existing modifier step.

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | startdate      | enddate        |
      | Module 26/27 | M2627     | ##2026-09-14## | ##2027-06-30## |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M2627  | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the academic year "2026/27" starts on "2026-09-01"
    And it is now "Thu 29 October 2026 10:00" in "Europe/London"
    And a summative "assign" "Essay due soon" in "M2627" is due "2026-10-31 12:00"
    And a summative "assign" "Dissertation" in "M2627" is due "2026-12-08 12:00"
    And the My assessments index is up to date

  Scenario: Every Upcoming deadline counts down, however far away
    When I log in as "student1" and view my dashboard
    Then "Essay due soon" should show "Due in 2 days"
    And "Dissertation" should show "Due in 40 days"

  Scenario: The due-soon mark keeps its 7-day window
    When I log in as "student1" and view my dashboard
    Then the activity icon of "Essay due soon" should have the "duesoon" modifier
    And the activity icon of "Dissertation" should not have the "duesoon" modifier

  Scenario: Submitted work shows no countdown, as before
    Given "student1" has submitted "Dissertation"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Dissertation" should not show "Due in"
