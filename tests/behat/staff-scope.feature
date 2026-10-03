@block @block_my_assessments @javascript
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

  Scenario Outline: A <activity> hidden from students still shows marking to do, flagged as hidden
    Given a summative "<activity>" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And "A1" is hidden from students
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And "A1" should show "Hidden from students"

    Examples:
      | activity   |
      | coursework |
      | assign     |

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

  Scenario: An activity type without allocation shows all its marking to every marker in the module
    Given a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" and "student2" have finished "Q1"
    When I log in as "marker1" and view my dashboard
    Then "Q1" should appear in the "Marking in progress" section with "2" to mark

  Scenario: A group-submitted peerwork shows one item to mark per group
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | M1     | GA       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student2 | GA    |
    And a summative "peerwork" "P1" in "M1" was due "3 days ago"
    And "student1" has submitted "P1" for "Group A"
    When I log in as "marker1" and view my dashboard
    Then "P1" should appear in the "Marking in progress" section with "1" to mark

  Scenario: Only the attempt that counts for the grade needs marking
    Given a summative "quiz" "Q1" in "M1" with an essay question and grading method "Last attempt" was due "3 days ago"
    And "student1" has finished 2 attempts at "Q1"
    And the first attempt of "student1" at "Q1" still needs grading
    And the last attempt of "student1" at "Q1" has been graded
    When I log in as "marker1" and view my dashboard
    Then "Q1" should appear in the "Completed marking" section

  # D6: unallocated work is shown to convenors only.
  Scenario: Unallocated submissions are not shown to markers who are not convenors
    Given a summative "coursework" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student2" for "A1" is not allocated
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  Scenario: Marking deadline is shown only for activity types that have one
    Given a summative "coursework" "A1" in "M1" with marker allocation and a marking deadline "in 10 days" was due "3 days ago"
    And a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    And "student1" has finished "Q1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should show "Marking deadline" with the date "in 10 days"
    And "Q1" should not show "Marking deadline"

  Scenario: A marker in a hidden course still sees their marking
    Given the course "M1" is hidden from students
    And a summative "coursework" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  # beta6 (Pierre, 2026-10-03): one bar per kind of work, its count once beside it.
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
  @mod_coursework
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
  Scenario: A resubmission under marking workflow comes back to the marker
    Given a summative "assign" "A1" in "M1" with marking workflow and reopened attempts was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the submission of "student1" for "A1" is reopened
    And "student1" has submitted "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  # ---------- Workflow gaps, beta7 (coordinator-approved scope, 2026-10-03) ----------

  # G3: unallocated work falls to the module convenor's own marking; D6 still holds for other markers.
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

  Scenario: A convenor whose marking is all allocated sees no unallocated hint
    Given the following "users" exist:
      | username | firstname | lastname |
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | convenor | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "convenor"
    When I log in as "convenor" and view the "My marking"
    Then "A1" should not have an unallocated hint

  # G6: work that arrives after I finished marking comes back, and says why.
  Scenario: Work that arrives after I finished marking comes back with a hint
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student2" has an extension on "A1" to "in 3 days"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And "student2" has submitted "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And "A1" should have a returned work hint

  Scenario: Marking that has simply not started has no returned work hint
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And "A1" should not have a returned work hint

  # G7, G8: Moodle 5.0+ only (the "2 markers" option skips the scenario on 4.5).
  Scenario: Without allocation, a marker who has given their mark is done with that submission
    Given a summative "assign" "A1" in "M1" with 2 markers was due "3 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Completed marking" section
    When I log in as "marker2" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark

  Scenario: With allocation, agreement belongs to the first allocated marker
    Given a summative "assign" "A1" in "M1" with 2 markers, manual agreement and marker allocation was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is allocated to "marker1" and "marker2"
    And both markers have given feedback on the submission of "student1" for "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And "A1" should show an "Agreed" progress bar with "0" of "1"
    When I log in as "marker2" and view my dashboard
    Then "A1" should appear in the "Completed marking" section
