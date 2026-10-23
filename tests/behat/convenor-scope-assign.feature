@block @block_le_dashboard @block_le_dashboard_mirror
Feature: Convenors oversee all marking in their modules, with bars only where the activity supports them (assign mirror)
  In order to see where marking is stuck
  As a module convenor
  I need every assessment in my modules with progress for each marking stage the activity actually has

  # Bars come from what each activity type supports.
  # Each "view my dashboard" step first runs pending My assessments index tasks.
  # Freshness and refresh are covered in freshness.feature.

  # Mirror of convenor-scope.feature with assign in place of coursework, so the same rules run on sites
  # without mod_coursework. Scenarios that need coursework-only stages are left out.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | startdate      |
      | Module 1 | M1        | ##2026-09-28## |
      | Module 2 | M2        | ##2025-09-29## |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | student3 | Sid       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | student3 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
      | convenor | M2     | moduleconvenor |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | M1     | GA       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student2 | GA    |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the academic year "2026/27" starts on "2026-09-01"

  @javascript
  Scenario: Only modules in the selected academic year are shown
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And a summative "assign" "A2" in "M2" was due "3 days ago"
    When I log in as "convenor" and view the "Convenor view" for academic year "2026/27"
    Then I should see "A1" in the "My assessments" block
    And I should not see "A2" in the "My assessments" block

  # R4: computed when indexed; freshness.feature covers refresh.

  # D4: released means the agreed or final grade has been published to the student.

  # beta1 review M7: no "Marked 0 of 0" before anything is submitted.
  # A1 stays In progress for an extension: with nothing to come it would go to Past at once (beta28).

  # beta6 (Pierre, 2026-10-03): marking doesn't start before the deadline.

  # G1, G2 (workflow gaps, 2026-10-03).

  # ---------- Workflow gaps, beta7 (coordinator-approved scope, 2026-10-03) ----------

  # G6

  # G10

  # G11

  # G9: extensions still to come, for every student on the assessment.
