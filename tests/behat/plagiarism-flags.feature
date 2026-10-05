@block @block_le_dashboard
Feature: Staff see how many submissions are flagged for plagiarism
  In order to follow up academic conduct cases while marking
  As a marker or module convenor
  I need a flag on an assessment's row saying how many of my submissions are flagged

  # Pierre, 2026-10-04: "Port plagiarism first ... Pick a relevant fa icon for the warning". Scope for beta12.
  # rc6 behaviour ported (flows/my-assessments-flows.md §12.5): a coursework row is flagged when
  # coursework_plagiarism_flags has rows for it with a submission and status 0-3; the count is distinct flagged
  # submissions. Quizzes and assignments never get one.
  # Where the coordinator's defaults don't fit the source (marked CHOICE):
  # - rc6's flags are mod_coursework's own table, not core's plagiarism API: core plagiarism plugins (Turnitin and
  #   others) keep their results in their own tables and expose only per-file HTML links, so there is no generic
  #   count to read. The coursework adapter reads the coursework table, guarded by table_exists, so the block runs
  #   without coursework. Other adapters report no flags. A future adapter can add its own.
  # - Indexed per marking unit (mark.flagged), so My marking counts the marker's own units and the convenor
  #   counts every unit. As of indexing (R4), like the other counts; Refresh and the nightly run pick up changes.
  # Fixes to rc6's known issues:
  # - B43: the flag shows in every section, not only In progress.
  # - B44: the wording says "submissions", which is what is counted (group coursework counts groups).
  # - B42 stays open: which statuses mean an open case is inferred from rc6 (0-3). It is one constant in the
  #   coursework adapter; Pierre should confirm against his coursework version.
  # The flag: a hint button (the existing component) with fa-flag, in the attention tone, not danger: it is
  # staff-only information, never shown to students. Label "1 flagged" / "N flagged"; the tip says
  # "1 submission is flagged for an academic conduct check in Coursework." / "N submissions are flagged ...".
  # Coursework can't be installed here, so these scenarios run only where it is (@mod_coursework, skipped here);
  # Pierre tests them later on his site. The scenario without coursework runs here.

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
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | marker1  | M1     | teacher        |
      | marker2  | M1     | teacher        |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  @mod_coursework @javascript
  Scenario: The convenor sees how many submissions are flagged, in every section
    Given a summative "coursework" "C1" in "M1" was due "2 days ago"
    And a summative "coursework" "C2" in "M1" is due "in 5 days"
    And "student1" and "student2" have submitted "C1"
    And "student1" has submitted "C2"
    And the submission of "student1" for "C1" is flagged for plagiarism
    And the submission of "student2" for "C1" is flagged for plagiarism
    And the submission of "student1" for "C2" is flagged for plagiarism
    When I log in as "convenor" and view the "Convenor view"
    Then "C1" should have a plagiarism hint
    And "C1" should show "2 flagged"
    And "C2" should show "1 flagged"
    When I click on the plagiarism hint of "C1"
    Then I should see "2 submissions are flagged for an academic conduct check in Coursework." in the "My assessments" block

  @mod_coursework @javascript
  Scenario: A marker sees only the flags on their own marking
    Given a summative "coursework" "C1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "C1"
    And the submission of "student1" for "C1" is allocated to "marker1"
    And the submission of "student2" for "C1" is allocated to "marker2"
    And the submission of "student2" for "C1" is flagged for plagiarism
    When I log in as "marker1" and view the "My marking"
    Then "C1" should not have a plagiarism hint
    When I log in as "marker2" and view the "My marking"
    Then "C1" should show "1 flagged"

  @mod_coursework @javascript
  Scenario: A cleared case no longer counts
    Given a summative "coursework" "C1" in "M1" was due "2 days ago"
    And "student1" has submitted "C1"
    And the submission of "student1" for "C1" is flagged for plagiarism with status "4"
    When I log in as "convenor" and view the "Convenor view"
    Then "C1" should not have a plagiarism hint

  @mod_coursework @javascript
  Scenario: Students never see the flag
    Given a summative "coursework" "C1" in "M1" was due "2 days ago"
    And "student1" has submitted "C1"
    And the submission of "student1" for "C1" is flagged for plagiarism
    When I log in as "student1" and view my dashboard
    Then "C1" should not have a plagiarism hint
    And I should not see "flagged" in the "My assessments" block

  @javascript
  Scenario: Without coursework, nothing is flagged and nothing breaks
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should appear in the "In progress" section
    And "A1" should not have a plagiarism hint
