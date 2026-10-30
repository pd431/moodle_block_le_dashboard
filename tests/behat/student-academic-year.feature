@block @block_le_dashboard @javascript
Feature: Students choose an academic year, by their own deadline or the course
  In order to look back at one year without this year's deadlines
  As a student
  I need the year picker staff have, with the same rule, applied to my own deadlines

  # Pierre, 2026-10-29: "match staff behaviour, only show that apply to the selected year period. Someone looking
  # at their performance last year doesn't need to be harassed by a deadline they missed yesterday. Default is good,
  # like staff." Scope: /mnt/project-files/remake/scopes/student-year-and-countdown.feature (beta33). The rule is
  # scope::in_year_sql() with the student's own deadline (EC3d).
  # Adjusted while building:
  # - The clock is set and the deadlines written as dates, so the scenarios keep their year after 2026/27 ends.
  # - The SQL and PHP agreement is scope_test's (test_in_year_student_forms_agree).

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | startdate      | enddate        |
      | Module 25/26 | M2526     | ##2025-09-15## | ##2026-06-30## |
      | Module 26/27 | M2627     | ##2026-09-14## | ##2027-06-30## |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M2526  | student |
      | student1 | M2627  | student |
      | student2 | M2526  | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the academic year "2025/26" starts on "2025-09-01"
    And the academic year "2026/27" starts on "2026-09-01"
    And it is now "Thu 29 October 2026 10:00" in "Europe/London"
    And a summative "assign" "Resit on old module" in "M2526" is due "2026-11-02 12:00"
    And a summative "assign" "Last year's exam" in "M2526" was due "2026-05-22 12:00"
    And a summative "assign" "Essay due soon" in "M2627" is due "2026-10-31 12:00"
    And a summative "assign" "Dissertation" in "M2627" is due "2026-12-08 12:00"
    And a summative "assign" "Missed yesterday" in "M2627" was due "2026-10-28 12:00"
    And the My assessments index is up to date

  Scenario: Students get the year picker, on the current year by default
    When I log in as "student1" and view my dashboard
    Then the control bar should hold "Academic year, Type filter, Search"
    And the field "Academic year" matches value "2026/27"

  Scenario: This year shows this year's deadlines and this year's modules only
    When I log in as "student1" and view my dashboard
    Then "Resit on old module" should appear in the "Upcoming" section
    And "Essay due soon" should appear in the "Upcoming" section
    And "Missed yesterday" should appear in the "Overdue" section
    And I should not see "Last year's exam" in the "My assessments" block
    And the "Overdue" summary box should show "1"

  Scenario: Last year hides this year's work, overdue included
    When I log in as "student1" and view the "Student view" for academic year "2025/26"
    Then "Last year's exam" should appear in the "Past" section
    And "Resit on old module" should appear in the "Upcoming" section
    And I should not see "Missed yesterday" in the "My assessments" block
    And I should not see "Dissertation" in the "My assessments" block
    And the "Overdue" summary box should show "0"

  Scenario: All years shows everything, as before
    When I log in as "student1" and view the "Student view" for academic year "All years"
    Then I should see "Last year's exam" in the "My assessments" block
    And I should see "Missed yesterday" in the "My assessments" block
    And I should see "Dissertation" in the "My assessments" block

  Scenario: The choice is remembered
    When I log in as "student1" and view the "Student view" for academic year "2025/26"
    And I view my dashboard again
    Then the field "Academic year" matches value "2025/26"

  Scenario: A student's own extension moves the assessment into the year of the new deadline
    Given "student2" has an extension on "Last year's exam" to "2026-11-08 12:00"
    And the My assessments index is up to date
    When I log in as "student2" and view the "Student view" for academic year "2026/27"
    Then "Last year's exam" should appear in the "Upcoming" section
    When I log in as "student1" and view the "Student view" for academic year "2026/27"
    Then I should not see "Last year's exam" in the "My assessments" block
