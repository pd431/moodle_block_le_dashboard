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
