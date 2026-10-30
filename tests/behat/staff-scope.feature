@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Markers see the marking that is theirs, whatever the activity plugin
  In order to plan my marking
  As a marker
  I need to see exactly the submissions I am responsible for, including on hidden assessments

  # Staff rules deliberately ignore student visibility (R3).
  # Each "view my dashboard" step first runs pending My assessments index tasks.
  # Freshness and refresh are covered in freshness.feature.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | marker1  | Mo        | Marker   |
      | marker2  | Max       | Marker   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | marker2  | M1     | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  @javascript
  Scenario Outline: A <activity> hidden from students still shows marking to do, flagged as hidden
    Given a summative "<activity>" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And "A1" is hidden from students
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And "A1" should have a "hidden" hint

    Examples:
      | activity   |
      | coursework |
      | assign     |

  @javascript
  Scenario Outline: With allocation, a <activity> shows only submissions allocated to me
    Given a summative "<activity>" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is allocated to "marker2"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

    Examples:
      | activity   |
      | coursework |
      | assign     |

  # D6: unallocated work is shown to convenors only.
  @javascript
  Scenario: Unallocated submissions are not shown to markers who are not convenors
    Given a summative "coursework" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is not allocated
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  @javascript
  Scenario: Marking deadline is shown only for activity types that have one
    Given a summative "coursework" "A1" in "M1" with marker allocation and a marking deadline "in 10 days" was due "3 days ago"
    And a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And "student1" has finished "Q1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should show "Marking deadline" with the date "in 10 days"
    And "Q1" should not show "Marking deadline"

  @javascript
  Scenario: A marker in a hidden course still sees their marking
    Given the course "M1" is hidden from students
    And a summative "coursework" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  # beta6 (Pierre, 2026-10-03): one bar per kind of work, its count once beside it.
  @javascript
  Scenario: My marking shows the count once, beside its bar
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "2" to mark
    And "A1" should show progress bars "Marked"
    And "A1" should show a "Marked" progress bar with "0" of "2"
    And "A1" should not show "2 to mark"
    And "A1" should not show "My marking"

  # Coursework only: no core activity has a moderation stage.
  @mod_coursework @javascript
  Scenario: A moderator sees moderation progress alongside their own marking
    Given a summative "coursework" "A1" in "M1" with marker allocation and moderation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And "marker1" moderates "A1"
    And both markers have given feedback on the submission of "student2" for "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should show progress bars "Marked, Moderated"
    And "A1" should show a "Marked" progress bar with "0" of "1"
    And "A1" should show a "Moderated" progress bar with "0" of "1"

  # G1 (workflow gaps, 2026-10-03): a new attempt keeps the old workflow state, so only a grade
  # on the same attempt counts as marked.

  # ---------- Workflow gaps, beta7 (coordinator-approved scope, 2026-10-03) ----------

  # G3: unallocated work falls to the module convenor's own marking; D6 still holds for other markers.
  @javascript
  Scenario: Unallocated submissions count in the convenor's own marking, with a hint
    Given the following "users" exist:
      | username | firstname | lastname |
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | convenor | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "convenor"
    And the submission of "student2" for "A1" is not allocated
    When I log in as "convenor" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section with "2" to mark
    And "A1" should have an unallocated hint
    When I click on the unallocated hint of "A1"
    Then I should see "not yet allocated to a marker" in the "My assessments" block
    When I log in as "marker1" and view my dashboard
    Then "A1" should not appear in the "Marking in progress" section

  # G6: work that arrives after I finished marking comes back, and says why. Since G9 the
  # extension's deadline has to pass first.

  # G7, G8: Moodle 5.0+ only (the "2 markers" option skips the scenario on 4.5).

  # ---------- G9 (Pierre, 2026-10-03, Q3): marking arrives at each student's deadline ----------

  # My marking has no Upcoming section (Pierre, 2026-10-21): work before its deadline isn't marking yet.

  @javascript
  Scenario: An extension student's work counts once their own deadline passes, and their extension is shown until then
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student2" has an extension on "A1" to "tomorrow"
    And "student1" and "student2" have submitted "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And the "extension" flag of "A1" should be labelled "1 student has an extension"
    When I click on the extension hint of "A1"
    Then I should see "Their work may still arrive" in the "My assessments" block

  # Whose extensions: allocated to me, or unallocated when I convene.
