@block @block_le_dashboard @block_rhb
Feature: A greyed Self-certify never looks available
  In order not to think I can self-certify when I can't
  As a student
  I need a greyed Self-certify item to stay greyed when focused, hovered or clicked

  # Pierre's UAT, 2026-10-19: "Self cert button is enabled even when text is saying activity doesn't allow it."
  # Approved scope beta27-uat.feature: it stays muted on hover, focus and when the menu opens on it, with a focus
  # outline only, never the selected-item fill. It still does nothing when clicked and is still reachable by
  # keyboard, so the reason under it is read out.
  # Built on the stand-in from mitigate-menu-explains.feature, so it runs without mod_coursework and block_rhb.

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
    And block_rhb allows 3 self-certified extensions

  @javascript
  Scenario: Greyed Self-certify keeps its greyed look when the menu opens on it
    Given a summative "assign" "A1" in "M1" is due "in 4 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a message only" for self-certification
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    And I press the down key
    Then the focused element is "Self-certify an extension" "button"
    And "Self-certify an extension" in the Mitigate menu of "A1" should look greyed
    And "Self-certify an extension" in the Mitigate menu of "A1" should have a visible focus outline

  @javascript
  Scenario: Greyed Self-certify keeps its greyed look on hover and does nothing when clicked
    Given a summative "assign" "A1" in "M1" is due "in 4 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a message only" for self-certification
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    And I pass the pointer over "Self-certify an extension" in the Mitigate menu of "A1" for "200" milliseconds
    Then "Self-certify an extension" in the Mitigate menu of "A1" should look greyed
    And I click on "Self-certify an extension" in the Mitigate menu of "A1"
    And I should see "My Assessments"
    And "Request formal mitigation" should be in the Mitigate menu of "A1"
