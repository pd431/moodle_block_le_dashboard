@block @block_le_dashboard
Feature: Hints and flags are single icons that explain themselves on demand
  In order to keep rows short without hiding what a flag means
  As a student, marker or module convenor
  I need each hint to be one icon that shows its title and description when I ask for it

  # Pierre, 2026-10-06 (flags thread, then timeline 21:58): "Individual fa icons, when clicked or hovered for
  # half a second or a second ... show title and description (1st option in the draft)". The first option is
  # "Icon only" in the Flag icons mockup (https://claude.ai/artifact/VheqPM12tRcEgrLxKZbgjd).
  #
  # Decisions (coordinator confirmed 2026-10-06, with a scenario for the late policy link):
  # - One component for every hint: staff and convenor flags, the student clock and question-circle, and
  #   the activity icon tooltip. It opens on a click, Enter or Space, keyboard focus, or a 500 ms hover.
  #   A hover-opened popover closes when the pointer leaves both the icon and the popover. A popover
  #   opened any other way stays open until Escape, a second click or a click elsewhere. Only one is
  #   open at a time. This replaces the beta12 rule that the icon tooltip opens on click and focus only.
  # - The popover starts with the flag's title in bold, then today's hint text, with its links.
  # - Staff and convenor flags keep the mockup's tinted outline, with their number beside the icon.
  #   Icons and tones are as in the mockup:
  #   * Hidden from students: eye-slash, grey.
  #   * Unallocated: user-slash plus count, attention.
  #   * New work: file-circle-plus, info.
  #   * Extensions: calendar-plus plus count, info.
  #   * Flagged: flag plus count, attention.
  #   * No brief: file-circle-exclamation, info.
  #   * Check settings: screwdriver-wrench, attention (it replaced No deadline on 2026-10-07, setup-flags.feature).
  #   No brief and No deadline weren't in the mockup.
  # - Student icons are bare glyphs, still a button with a 24px hit area and a focus ring:
  #   * The late clock sits beside the grade once released, or beside the In marking pill before
  #     that.
  #   * The question-circle sits beside the "Feedback expected" date.
  #   * The status pills stay as text. Mitigate stays a labelled button.
  # - Removed from student rows: the "Submitted N late" line under the name, the "Your own deadline"
  #   note, the "Late penalties" button and the "No deadline" hint on Undated work. The clock's title
  #   is "Submitted N late." with "Late penalties may apply." added when a late penalties note is set.
  #   That note, with its link, is the clock's description.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username  | firstname | lastname |
      | student1  | Sam       | Student  |
      | student2  | Sue       | Student  |
      | teacher1  | Tim       | Teacher  |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | student2  | M1     | student        |
      | teacher1  | M1     | editingteacher |
      | convenor1 | M1     | moduleconvenor |
      | convenor1 | M1     | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block keeps its hints and guidance from before beta27
    And the My assessments block treats "moduleconvenor" as a module convenor role

  @javascript
  Scenario: A marker's flags are icons with their numbers, and the title and description open on hover
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student1" for "A1" is allocated to "teacher1"
    And the submission of "student2" for "A1" is not allocated
    And "A1" is hidden from students
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    Then "A1" should have an "unallocated" flag showing "1"
    And "A1" should have a "hidden" flag showing no number
    And "A1" should not show "Unallocated"
    When I hover over the "unallocated" flag of "A1" for "600" milliseconds
    Then the "unallocated" popover of "A1" should say "1 unallocated" then "Includes 1 submission not yet allocated to a marker."
    When I move the pointer away from "A1"
    Then the "unallocated" popover of "A1" should not be visible

  @javascript
  Scenario: A brief pass over a flag opens nothing
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    And "A1" is hidden from students
    And the My assessments index is up to date
    When I log in as "teacher1" and view the "My marking"
    And I pass the pointer over the "hidden" flag of "A1" for "200" milliseconds
    Then no tooltip should have opened

  @javascript
  Scenario: Clicking a flag keeps it open until Escape, and opens only one at a time
    Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student2" for "A1" is not allocated
    And "A1" is hidden from students
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    And I click on the "unallocated" flag of "A1"
    And I move the pointer away from "A1"
    Then the "unallocated" popover of "A1" should be visible
    When I click on the "hidden" flag of "A1"
    Then the "hidden" popover of "A1" should be visible
    And the "unallocated" popover of "A1" should not be visible
    When I press the escape key
    Then the "hidden" popover of "A1" should not be visible

  @javascript
  Scenario: A flag opens from the keyboard
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    And "A1" is hidden from students
    And the My assessments index is up to date
    When I log in as "teacher1" and view the "My marking"
    And I press the tab key until the "hidden" flag of "A1" is focused
    Then the "hidden" popover of "A1" should say "Hidden from students" then "Students can't see this assessment yet."

  @javascript
  Scenario: A student's late work has one bare clock beside the grade
    Given the My assessments late penalty link is "https://example.com/late"
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "47 hours ago"
    And "teacher1" grades "student1" on "A1" with "65"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Show grades" in the "My assessments" block
    Then the "late" icon of "A1" should sit next to its grade
    And the "late" icon of "A1" should have no circle
    And "A1" should not show "Late penalties"
    And "A1" should not show "Submitted 1 day late"
    When I hover over the "late" icon of "A1" for "600" milliseconds
    Then the "late" popover of "A1" should say "Submitted 1 day late. Late penalties may apply." then "Work up to 10 minutes late"
    And I should see "late penalties" linking to "https://example.com/late"

  @javascript
  Scenario: Before a grade, the clock sits beside In marking and the question-circle beside the feedback date
    Given the My assessments feedback contact is "your module convenor" linking to ""
    And a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "30 days ago"
    And "student1" submitted "A1" "29 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should show the status "In marking"
    And the "late" icon of "A1" should sit next to its "In marking" pill
    And the "feedback" icon of "A1" should sit next to its "Feedback expected" date
    And "A1" should not show "Waiting for feedback?"
    When I click on the "feedback" icon of "A1"
    Then the "feedback" popover of "A1" should say "Waiting for feedback?" then "If it doesn't, contact your module convenor."

  @javascript
  Scenario: The activity icon tooltip also opens after a hover
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I hover over the activity icon of "A1" for "600" milliseconds
    Then the activity icon tooltip of "A1" should say "Assignment. Overdue."

  @javascript
  Scenario: The late policy link in the clock popover can be reached and followed
    Given the My assessments late penalty link is "https://example.com/late"
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I click on the "late" icon of "A1"
    Then I should see "late penalties" linking to "https://example.com/late"
    And the late policy link in the "late" popover of "A1" can be reached with the tab key
