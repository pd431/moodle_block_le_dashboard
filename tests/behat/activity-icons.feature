@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Activity icons say what the activity is and what applies to me
  In order to understand a row's icon without guessing
  As a student, marker or convenor
  I need the activity icon and its small modifier icons to explain themselves when I click or tab to them

  # Approved by the coordinator 2026-10-03, with late made grey (Pierre's ask: Font Awesome icons,
  # with a tooltip that names the activity and its modifiers).
  # Icons: one Font Awesome icon per activity type, with a fallback for any other type:
  #   Assignment fa-file-arrow-up, Quiz fa-clipboard-question, Coursework fa-file-pen,
  #   Peer work fa-people-group, any other fa-file-lines.
  # Modifier icons (student view), at most two per icon, side by side along its bottom edge, and only
  # for the unusual case: work handed in on time has none (Pierre, 2026-10-04):
  #   Overdue fa-clock (danger, Overdue section only), Submitted late fa-clock (grey), Due soon
  #   fa-hourglass-half (attention), Not submitted fa-circle-xmark (grey); Self-certification applied
  #   fa-hand-holding-heart and Extension granted fa-calendar-plus, both in the Mitigate tone (attention).
  # The tooltip opens on click, keyboard focus, or a hover of half a second (beta17, Pierre 2026-10-06),
  # and it closes on Escape, on a click elsewhere, when focus leaves, or when a hover ends. Its text is built
  # from lang strings: the activity type, then each part as its own sentence, e.g.
  # "Coursework. Self-certification applied. Submitted late." The icon's accessible
  # name is that same text, so screen readers hear it without opening the tooltip.
  # Due soon means due within 7 days, as rc6's "approaching" (the examples use
  # "in 10 days" where Due soon must not show).

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
      | marker1  | M1     | teacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  @javascript
  Scenario Outline: The icon's tooltip names the activity and what applies
    Given a summative "<activity>" "A1" in "M1" is due "<due>"
    And <event>
    When I log in as "student1" and view my dashboard
    And I click on the activity icon of "A1"
    Then the activity icon tooltip of "A1" should say "<text>"

    Examples:
      | activity | due        | event                                      | text                           |
      | assign   | yesterday  | nothing happens                            | Assignment. Overdue.           |
      | assign   | in 10 days | "student1" has submitted "A1"              | Assignment.                    |
      | assign   | 3 days ago | "student1" submitted "A1" "yesterday"      | Assignment. Submitted late.    |
      | assign   | tomorrow   | "student1" has an extension on "A1" to "in 10 days" | Assignment. Extension granted. |
      | assign   | tomorrow   | nothing happens                            | Assignment. Due soon.          |

  @mod_coursework @javascript
  Scenario: A self-certified coursework that was submitted late
    Given a summative "coursework" "A1" in "M1" was due "3 days ago"
    And "student1" self-certified "A1" to "yesterday"
    And "student1" submitted "A1" "today"
    When I log in as "student1" and view my dashboard
    And I click on the activity icon of "A1"
    Then the activity icon tooltip of "A1" should say "Coursework. Self-certification applied. Submitted late."

  @javascript
  Scenario: The tooltip opens from the keyboard and closes with Escape
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    And I press the tab key until the activity icon of "A1" is focused
    Then the activity icon tooltip of "A1" should say "Assignment. Overdue."
    When I press the escape key
    Then the activity icon tooltip of "A1" should not be visible

  @javascript
  Scenario: A brief pass over the icon does not open the tooltip
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    And I pass the pointer over the activity icon of "A1" for "200" milliseconds
    Then no tooltip should have opened

  @javascript
  Scenario: Clicking elsewhere closes the tooltip
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    When I log in as "student1" and view my dashboard
    And I click on the activity icon of "A1"
    And I click on "My assessments" "block"
    Then the activity icon tooltip of "A1" should not be visible

  @javascript
  Scenario: Markers get the activity type, and the hidden flag when it applies
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And "A1" is hidden from students
    And "student1" has submitted "A1"
    When I log in as "marker1" and view my dashboard
    And I click on the activity icon of "A1"
    Then the activity icon tooltip of "A1" should say "Assignment. Hidden from students."

  Scenario: An activity type without its own icon gets the fallback icon
    Given the "forum" activity type is enabled in My assessments
    And a summative "forum" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then the activity icon of "A1" should be the "fa-file-lines" icon
