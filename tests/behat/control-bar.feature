@block @block_my_assessments @javascript
Feature: The dashboard's controls sit together in one control bar
  In order to find and use the dashboard's controls in one place
  As a student, marker or module convenor
  I need the academic year, assessment type and show grades controls grouped in one bar above the summary

  # Pierre, 2026-10-04: "grouping controls together so the interface feels more cohesive. Academic year,
  # summative/formative, show/hide marks". Scope approved by the coordinator for beta12, on two conditions:
  # Show grades uses aria-pressed with a pressed state that isn't colour alone (Pierre: an eye icon, crossed
  # out while grades are hidden), and it is set apart
  # from the type buttons (a rule and a gap).
  # Today the year picker and Refresh share a top row, the type filter and search sit in a second row under the
  # summary boxes, and Show grades sits alone under the sections.
  # Proposed: one bar directly under the view tabs, above the summary boxes. In order:
  #   academic year (marking and convenor views) | type filter | Show grades (student view, when a grade is
  #   released) | search
  # The freshness line ("Updated 3 min ago · Refresh") is status rather than a control, so it moves to its own line under
  # the bar, right-aligned. The type filter switches the summary counts, so it sits above them.
  # Show grades becomes a toggle button in the same style as the type filter buttons, with an eye icon. Its label
  # stays "Show grades".
  # The bar wraps on narrow screens. Search takes the full width when wrapped. No new colours: the existing control styles are merged
  # into one set of rules for the bar.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | marker1  | M1     | teacher        |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the academic year "2026/27" starts on "2026-09-01"

  Scenario: Students see the type filter, Show grades and search together above the summary
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    When I log in as "student1" and view my dashboard
    Then the control bar should hold "Type filter, Show grades, Search"
    And the control bar should come before the summary boxes
    And the freshness line should come after the control bar

  Scenario: Show grades only appears once a grade has been released
    Given a summative "assign" "A1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then the control bar should hold "Type filter, Search"

  Scenario: Markers see the academic year first in the control bar
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    When I log in as "marker1" and view the "My marking"
    Then the control bar should hold "Academic year, Type filter, Search"

  Scenario: Convenors see the academic year first in the control bar
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    When I log in as "convenor" and view the "Convenor view"
    Then the control bar should hold "Academic year, Type filter, Search"

  Scenario: Show grades in the control bar still shows and hides grades, and says whether it is on
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    When I log in as "student1" and view my dashboard
    And I press "Show grades"
    Then "Show grades" should be pressed
    And the grade of "A1" should be visible
    When I press "Show grades"
    Then "Show grades" should not be pressed

  Scenario: The type filter in the control bar still switches the summary counts
    Given a summative "assign" "A1" in "M1" is due "in 3 days"
    And a formative "assign" "F1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    And I press "Summative"
    Then I should see "A1" in the "My assessments" block
    And I should not see "F1" in the "My assessments" block
    And the "Upcoming" summary box should show "1"

  Scenario: The control bar wraps on a phone without scrolling sideways
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And I change viewport size to "360x800"
    When I log in as "convenor" and view the "Convenor view"
    Then the My assessments block should not scroll sideways
    And the search box should take the full width of the control bar
