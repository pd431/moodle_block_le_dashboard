@block @block_le_dashboard @javascript
Feature: The search box and the bottom of each section are drawn cleanly
  In order that the block looks finished
  As anyone using My assessments
  I need the search box to be symmetrical and every section to keep its rounded bottom corners

  # Pierre, 2026-10-07 04:47: "Search box is asymmetrical I think, can you clean all of it up. Also when
  # no show more row, the missing right hand corners may be missing I think."
  #
  # As built (beta17):
  # - Search box: the icon and the input form one control. It has the same height as the other control
  #   bar buttons, the same border on all four sides, matching rounded outer corners, and no rounded inner
  #   corners where the icon meets the input. The icon cell and the input share a background, the icon is
  #   centred, and the left and right padding inside are equal. Keyboard focus rings the whole control,
  #   not only the input. The same on Moodle 4.5 (Bootstrap 4) and 5.2 (Bootstrap 5).
  # - Sections: when there is no Show more row (five rows or fewer), the last row takes the card's
  #   bottom corners. The hidden footer no longer counts as the card's last child. With a Show more row,
  #   the footer keeps them. The same applies to a collapsed section and to a section whose rows the
  #   search has filtered down.
  # - The SCSS is consolidated as these rules change, with no new duplicated rules. Contrast stays at
  #   APCA Lc 75 or above.
  # Checked by rendered screenshots on 4.5 and 5.2 as well as by these steps.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: The search box's outer corners match and it is as tall as the buttons beside it
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then the search box should have the same radius on all four outer corners
    And the search box should have square corners where the icon meets the input
    And the search box should be as tall as the "Summative" button

  Scenario: Focus rings the whole search box
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    And I click on "Search assessments" "field" in the "My Assessments" "block"
    Then the search box should show its focus ring around the icon and the input

  Scenario: With no Show more row, the last row has the section's rounded bottom corners
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then the "Upcoming" section should have no Show more row
    And the last row of the "Upcoming" section should have the card's bottom corners

  Scenario: With a Show more row, the footer has the bottom corners
    Given 6 summative "assign" activities in "M1" are due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then the "Upcoming" section should have a Show more row
    And the Show more row of the "Upcoming" section should have the card's bottom corners

  Scenario: When search hides rows, the last row still shows rounded bottom corners
    Given 6 summative "assign" activities in "M1" are due "tomorrow"
    And a summative "assign" "Essay" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    And I search the "My assessments" block for "Essay"
    Then the last row of the "Upcoming" section should have the card's bottom corners
