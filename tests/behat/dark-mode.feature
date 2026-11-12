@block @block_le_dashboard @block_rhb @javascript
Feature: The dashboard meets its contrast targets in light and dark colour modes
  In order to use My assessments with Moodle's dark mode turned on
  As a student, marker or module convenor
  I need every part of the dashboard to stay readable, in every state, whichever colour mode I use

  # Pierre, 2026-10-19: "build a prototype that is fully compliant with AA, and APCA targets", after the Moodle 5.3
  # dark mode assessment (dark-mode/findings.md). Targets: every text APCA Lc 75 and WCAG 4.5:1; icons, progress
  # bars, control outlines and focus indicators Lc 45 and 3:1 (WCAG 1.4.11). Before: 71 to 89 parts of each view
  # missed them in dark mode, and the progress bars missed them in light mode too (2.1:1).
  # Moodle 5.3 draws dark mode by setting data-bs-theme="dark" on the html tag, and switches it in place from the
  # navbar menu. Light scenarios also run on 4.5 and 5.2, which have no colour modes; dark ones skip there.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Ana       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | marker1  | M1     | teacher        |
      | convenor | M1     | moduleconvenor |
      | convenor | M1     | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And the academic year "2026/27" starts on "2026-09-01"
    And the site has colour modes turned on if it can
    # Every section, pill, modifier, flag and bar the views draw.
    And a summative "assign" "Overdue essay" in "M1" was due "2 days ago"
    And a summative "assign" "Lab report" in "M1" is due "in 2 days"
    And the block_rhb stand-in treats "Lab report" as a coursework
    And block_rhb offers "a delete link" for self-certification
    And a formative "assign" "Draft plan" in "M1" is due "in 6 days"
    And "student1" has submitted "Draft plan"
    And a summative "assign" "Portfolio" in "M1" with no due date exists
    And a summative "assign" "Marked essay" in "M1" with marker allocation was due "12 days ago"
    And "student1" has submitted "Marked essay"
    And the submission of "student1" for "Marked essay" is allocated to "marker1"
    And "marker1" marks the submission of "student1" for "Marked essay"
    And the grade of "student1" for "Marked essay" is released
    And a summative "assign" "Unmarked essay" in "M1" with marker allocation was due "3 days ago"
    And "student1" and "student2" have submitted "Unmarked essay"
    And the submission of "student1" for "Unmarked essay" is allocated to "marker1"
    And the submission of "student2" for "Unmarked essay" is not allocated
    And a summative "assign" "Missed quiz" in "M1" was due "20 days ago"
    And the My assessments index is up to date

  Scenario Outline: The student view meets its contrast targets at rest, hovered and focused in <mode> mode
    Given "student1" uses the <mode> colour mode
    When I log in as "student1" and view my dashboard
    And I press "Show more" in the "My assessments" block if there is one
    Then the page should be in <mode> colour mode
    And the My assessments block should meet its contrast targets
    And every kind of control in the My assessments block should meet its contrast targets when hovered
    And keyboard focus should be visible on every control in the My assessments block
    When I press "Show grades" in the "My assessments" block
    Then the My assessments block should meet its contrast targets

    Examples:
      | mode  |
      | light |
      | dark  |

  Scenario Outline: The <view> meets its contrast targets at rest, hovered and focused in <mode> mode
    Given "<user>" uses the <mode> colour mode
    When I log in as "<user>" and view the "<view>"
    Then the page should be in <mode> colour mode
    And the My assessments block should meet its contrast targets
    And every kind of control in the My assessments block should meet its contrast targets when hovered
    And keyboard focus should be visible on every control in the My assessments block

    Examples:
      | user     | view          | mode  |
      | marker1  | My marking    | light |
      | marker1  | My marking    | dark  |
      | convenor | Convenor view | light |
      | convenor | Convenor view | dark  |

  Scenario Outline: An open hint and the marker breakdown meet their targets in <mode> mode
    Given "convenor" uses the <mode> colour mode
    When I log in as "convenor" and view the "My marking"
    And I click on the "unallocated" flag of "Unmarked essay"
    Then the "unallocated" popover of "Unmarked essay" should be visible
    And the My assessments block should meet its contrast targets
    When I view the "Convenor view" again
    And I press "Show markers" in the "Unmarked essay" row
    Then the My assessments block should meet its contrast targets

    Examples:
      | mode  |
      | light |
      | dark  |

  Scenario Outline: The Mitigate menu, Delete mitigation included, meets its targets in <mode> mode
    Given "student1" uses the <mode> colour mode
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "Lab report" row
    Then "Delete mitigation" should be in the Mitigate menu of "Lab report"
    And the My assessments block should meet its contrast targets
    And every kind of control in the My assessments block should meet its contrast targets when hovered

    Examples:
      | mode  |
      | light |
      | dark  |

  Scenario: Switching to dark mode from the navbar recolours the dashboard without a reload
    Given the site has colour modes turned on
    And "student1" uses the light colour mode
    When I log in as "student1" and view my dashboard
    And I choose the dark colour mode from the navbar
    Then the page should be in dark colour mode
    And the My assessments block should meet its contrast targets
    When I choose the light colour mode from the navbar
    Then the page should be in light colour mode
    And the My assessments block should meet its contrast targets

  # Pierre, 2026-10-29 and 30: body text lifted to APCA Lc 90 so secondary text at Lc 75 reads as a level below it,
  # and dark mode's link underlines toned down (1px, 70% of the link colour) so they don't crowd the words. The
  # underline is the only mark on course and marker names, so it keeps the non-text floor, Lc 45 and 3:1.
  Scenario Outline: Body text reaches Lc 90 and stands clear of secondary text in dark mode in the <view>
    Given "<user>" uses the dark colour mode
    When I log in as "<user>" and view the "<view>"
    Then the body text in the My assessments block should reach APCA Lc 90 and 7:1
    And the secondary text in the My assessments block should reach APCA Lc 75 and 4.5:1
    And the body text in the My assessments block should be at least 14 Lc brighter than its secondary text

    Examples:
      | user     | view          |
      | marker1  | My marking    |
      | convenor | Convenor view |

  Scenario: Body text reaches Lc 90 and stands clear of secondary text in dark mode for a student
    Given "student1" uses the dark colour mode
    When I log in as "student1" and view my dashboard
    Then the body text in the My assessments block should reach APCA Lc 90 and 7:1
    And the secondary text in the My assessments block should reach APCA Lc 75 and 4.5:1
    And the body text in the My assessments block should be at least 14 Lc brighter than its secondary text

  Scenario Outline: Link underlines are thin and dimmed but still mark the link in dark mode for <user>
    Given "<user>" uses the dark colour mode
    When I log in as "<user>" and view the "<view>"
    Then every link underline in the My assessments block should be thin, dimmer than its link and meet its contrast target

    Examples:
      | user     | view          |
      | marker1  | My marking    |
      | convenor | Convenor view |

  Scenario: Hovering a link in dark mode brings its underline up to the link colour
    Given "student1" uses the dark colour mode
    When I log in as "student1" and view my dashboard
    Then every link underline in the My assessments block should be thin, dimmer than its link and meet its contrast target
    When I hover over the "course of Overdue essay" link in the My assessments block
    Then the underline of the "course of Overdue essay" link should be the same colour as the link

  # Pierre, 2026-10-30: an assessment title stands on its own line at the head of its row, which says it is a link,
  # so it has no underline in dark mode either. Course and marker names keep theirs: it is their only mark.
  Scenario: Assessment titles have no underline in dark mode, as in light, until hovered
    Given "student1" uses the dark colour mode
    When I log in as "student1" and view my dashboard
    Then the "Overdue essay" link in the My assessments block should have no underline
    And every link underline in the My assessments block should be thin, dimmer than its link and meet its contrast target
    When I hover over the "Overdue essay" link in the My assessments block
    Then the underline of the "Overdue essay" link should be the same colour as the link

  Scenario: Light mode keeps full-colour link underlines
    Given "student1" uses the light colour mode
    When I log in as "student1" and view my dashboard
    Then link underlines in the My assessments block should be the same colour as their links

  # Pierre, 2026-10-30: the section headers' fill sets them off from the rows, so the line under them goes.
  Scenario Outline: Section headers have no line under them in <mode> mode
    Given "student1" uses the <mode> colour mode
    When I log in as "student1" and view my dashboard
    Then the section headers in the My assessments block should have no line under them
    And the My assessments block should meet its contrast targets

    Examples:
      | mode  |
      | light |
      | dark  |
