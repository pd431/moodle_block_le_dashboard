@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Staff are told when a tagged assessment is missing a setting
  In order that every tagged assessment reaches students properly
  As a module convenor or marker
  I need tagged activities with a missing or contradictory setting flagged, with what to fix

  # Pierre, 2026-10-07 05:03: "And flag missing fields on tagged activities. Missing due date or something".
  #
  # As built (defaults, Pierre may change them):
  # - One staff flag, "Check settings", in the attention tone (staff only; never shown to students).
  #   It uses fa-screwdriver-wrench and its popover lists each problem in one line, with a link to the
  #   activity's settings. It replaces today's separate "No deadline" flag.
  # - The checks:
  #   1. No due date. The activity sits in Undated, and students can't see when it is due.
  #   2. No grade. The grade type is None, or the maximum grade is 0, so nothing will reach the
  #      gradebook.
  #   3. Marks never shown. A quiz whose review options never show marks, so students never see a grade
  #      here (F10).
  #   Checks 2 and 3 apply to summative activities only, because formative quizzes often hide marks
  #   on purpose (coordinator, 2026-10-07).
  #   4. Tagged summative and formative at once. It is shown as summative.
  #   5. Hidden from students while its due date is ahead. This is already a flag of its own, so it is
  #      not repeated here.
  # - Shown on module convenor and marker rows, in every section.
  # - The convenor view also gets a second collapsed line under the untagged one: "N tagged activities
  #   need their settings checked across your <year> courses." It follows the year picker and lists each
  #   activity with its problems, linked to its settings, with the same caching as the untagged list.
  #   It is hidden when N is 0.
  # - Also listed there, because they have no row: activities tagged summative or formative whose type is
  #   not ticked under Activity types. They are "Not shown on dashboards: <type> is not enabled in
  #   Le Dashboard", with no link, since only an admin can change it.
  # - Worked out at index time and stored on the index row (one new column), so views add no queries.
  #   The type-not-enabled check runs in the same query as the untagged list.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username  | firstname | lastname |
      | student1  | Sam       | Student  |
      | teacher1  | Tim       | Teacher  |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | teacher1  | M1     | editingteacher |
      | convenor1 | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role

  Scenario: A tagged assignment with no due date is flagged for its settings
    Given a summative "assign" "A1" in "M1" with no due date exists
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should have a "setup" hint
    And the "setup" flag of "A1" should be labelled "Check settings"
    And "A1" should not have a "nodeadline" hint

  @javascript
  Scenario: The flag lists every problem and links to the settings
    Given a summative "assign" "A1" in "M1" with no due date and no grade exists
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    And I click on the "setup" flag of "A1"
    Then the "setup" popover of "A1" should list "No due date" and "No grade"
    And the "setup" popover of "A1" should link to the settings of "A1"

  Scenario: A quiz whose marks are never shown is flagged
    Given a summative "quiz" "Q1" in "M1" with marks never shown is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "teacher1" and view the "My marking"
    Then "Q1" should have a "setup" hint

  Scenario: A formative quiz whose marks are never shown is not flagged
    Given a formative "quiz" "F1" in "M1" with marks never shown is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "teacher1" and view the "My marking"
    Then "F1" should not have a "setup" hint

  Scenario: An activity tagged both summative and formative is flagged
    Given a summative "assign" "A1" in "M1" tagged "formative" is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should have a "setup" hint

  Scenario: A complete activity has no flag
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should not have a "setup" hint

  Scenario: Students never see the flag
    Given a summative "assign" "A1" in "M1" with no due date exists
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should not have a "setup" hint

  @javascript
  Scenario: The convenor sees a count of activities to check, including tagged types that aren't enabled
    Given a summative "assign" "A1" in "M1" with no due date exists
    And a summative "workshop" "W1" in "M1" is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view" for academic year "All years"
    Then I should see "2 tagged activities need their settings checked across your courses." in the "My assessments" block
    And the settings check list should be collapsed
    When I click on "2 tagged activities need their settings checked across your courses." "text" in the "My assessments" "block"
    And I click on "Module 1 (2)" "text" in the "My assessments" "block"
    Then I should see "No due date" in the settings check list
    And I should see "Not shown on dashboards: Workshop is not enabled in Le Dashboard" in the settings check list

  @javascript
  Scenario: With nothing to check, the line is hidden
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view" for academic year "All years"
    Then I should not see "need their settings checked" in the "My assessments" block
