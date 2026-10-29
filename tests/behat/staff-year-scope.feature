@block @block_le_dashboard
Feature: Staff and convenors see the assessments of the chosen academic year, by date or by course
  In order to see this year's marking without last year's, and nothing of this year hidden by its course's dates
  As a marker or module convenor
  I need an assessment shown when its deadline falls in the chosen year, or when its course does

  # Pierre, 2026-10-28: "for staff (marker and modcons) Only show assessments within the chosen academic year, or in
  # a course that falls within the chosen academic year.", "Say someone gets a long extension to a dissertation for
  # example, I'd want it to appear if either date or extended date is relevant." and "EC3 dies, no matter the
  # marking progress, discard assessment that is not in the relevant year filter."
  # Scope: /mnt/project-files/remake/scopes/staff-year-scope.feature (beta31). Adjusted while building:
  # - My marking lists only work to mark, completed and undated work, so student1 hands in the three assessments
  #   already past their deadline in the Background; the scope's counts move by one where it adds up.
  # - "Resit on old module" is on a 2025/26 course, so it shows under 2025/26 as well, by its course: the scope's
  #   "should not see" there contradicted its own rule. Under 2025/26 My marking then counts 3 to mark.
  # - The "(2025/26)" label check is a plain "should not see"; the SQL and PHP agreement is scope_test's.

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | startdate      | enddate        |
      | Module 25/26 | M2526     | ##2025-09-15## | ##2026-06-30## |
      | Module 26/27 | M2627     | ##2026-09-14## | ##2027-06-30## |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M2526  | student        |
      | student1 | M2627  | student        |
      | marker1  | M2526  | editingteacher |
      | marker1  | M2627  | editingteacher |
      | convenor | M2526  | moduleconvenor |
      | convenor | M2627  | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the academic year "2025/26" starts on "2025-09-01"
    And the academic year "2026/27" starts on "2026-09-01"
    And it is now "Wed 28 October 2026 10:00" in "Europe/London"
    And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"
    And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"
    And a summative "assign" "Last year's essay" in "M2526" was due "2025-10-20 12:00"
    And a summative "assign" "This year's essay" in "M2627" is due "2026-12-01 12:00"
    And a summative "assign" "Undated on old module" in "M2526" with no due date exists
    And "student1" has submitted "Resit on old module"
    And "student1" has submitted "Early quiz on new module"
    And "student1" has submitted "Last year's essay"
    And the My assessments index is up to date

  @javascript
  Scenario Outline: Under 2026/27 an assessment shows by its date or by its course
    When I log in as "<user>" and view the "<view>" for academic year "2026/27"
    Then I should see "Resit on old module" in the "My assessments" block
    And I should see "Early quiz on new module" in the "My assessments" block
    And I should not see "Last year's essay" in the "My assessments" block
    And I should not see "Undated on old module" in the "My assessments" block

    Examples:
      | user     | view          |
      | marker1  | My marking    |
      | convenor | Convenor view |
