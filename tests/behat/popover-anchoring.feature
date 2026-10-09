@block @block_le_dashboard @block_le_dashboard_crossdb @javascript
Feature: Every popover, tip and menu opens next to its control at every width
  In order to see what a hint, flag or menu says without hunting for it
  As a student, marker or module convenor, on a phone or a wide screen
  I need each popover to open beside the control I used, and inside the window

  # Pierre, 2026-10-09 20:16: "can you check anchoring of all popover, tooltips and other things, for all
  # breakpoints". The check opens each one in turn (row icon tips, hints and flags beside dates, pills and
  # names, the time zone globes, Can't resubmit, the Mitigate menu) and measures it against its control.
  # The widths cross every layout change of the block (its container breakpoints at 62, 44, 40 and 34 rem)
  # and Moodle's own (drawers, phone).

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
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And the user "student1" has the time zone "Europe/London"
    And my browser's time zone is "America/New_York"
    And block_rhb offers "a link" for the request form
    And a summative "assign" "Locked essay" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "Locked essay"
    And a summative "assign" "Extended essay" in "M1" is due "tomorrow"
    And "student1" has an extension on "Extended essay" to "in 5 days"
    And a summative "assign" "Marked essay" in "M1" was due "6 days ago"
    And "student1" submitted "Marked essay" "7 days ago"
    And "teacher1" marks the submission of "student1" for "Marked essay"
    And a summative "assign" "Allocated essay" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "Allocated essay"
    And the submission of "student2" for "Allocated essay" is not allocated
    And "Allocated essay" is hidden from students
    And the My assessments index is up to date

  Scenario Outline: Student view at <width> px
    Given I change viewport size to "<width>x900"
    When I log in as "student1" and view my dashboard
    Then every popover in the dashboard should open next to its control and on screen

    Examples:
      | width |
      | 1400  |
      | 1200  |
      | 1024  |
      | 900   |
      | 768   |
      | 640   |
      | 480   |
      | 390   |
      | 360   |

  Scenario Outline: Marker view at <width> px
    Given I change viewport size to "<width>x900"
    When I log in as "convenor1" and view the "My marking"
    Then every popover in the dashboard should open next to its control and on screen

    Examples:
      | width |
      | 1400  |
      | 1024  |
      | 768   |
      | 480   |
      | 360   |

  Scenario Outline: Module convenor view at <width> px
    Given I change viewport size to "<width>x900"
    When I log in as "convenor1" and view the "Module convenor view" for academic year "All years"
    Then every popover in the dashboard should open next to its control and on screen

    Examples:
      | width |
      | 1400  |
      | 1024  |
      | 768   |
      | 480   |
      | 360   |
