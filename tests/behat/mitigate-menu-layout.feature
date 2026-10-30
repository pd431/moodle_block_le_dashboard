@block @block_le_dashboard @block_rhb
Feature: The Mitigate menu lists only what I can do, with self-certification set apart
  In order to see at once what I can do about a deadline
  As a student
  I need no greyed Self-certify item, its reason as a plain line, and a divider before formal mitigation

  # Pierre, 2026-10-22: "Can the mitigation hide the disabled "add a slef cert button" altogether, and add a
  # separator between the self cert bits (button, info, delete) and the formal mitigation button".
  # Approved scope beta28-feedback.feature, item 3. Replaces greyed-selfcert.feature (beta27): there is no greyed
  # item left to keep greyed. The reason line isn't a menu item, so the keyboard skips it; the next link reads it
  # out. Built on the block_rhb stand-in, so it runs without mod_coursework and block_rhb.

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

  @javascript
  Scenario: Self-certification already used here shows its line and Delete, not a greyed button
    Given a summative "assign" "A1" in "M1" is due "in 5 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a delete link" for self-certification
    And block_rhb offers "a link" for the request form
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should list:
      | item                                                         | state   | note |
      | You already self-certified an extension for this assessment. | info    |      |
      | Delete mitigation                                            | live    |      |
      | ---                                                          | divider |      |
      | Request formal mitigation                                    | live    |      |

  # "All 3 used" needs mod_coursework's count: mitigate-menu-explains.feature (@mod_coursework) and
  # mitigation_test cover it. Here block_rhb gives no link and the block can't tell why.
  @javascript
  Scenario: With no self-certification link, the menu says why above formal mitigation
    Given a summative "assign" "A1" in "M1" is due "in 5 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a message only" for self-certification
    And block_rhb offers "a link" for the request form
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should list:
      | item                                                                                             | state   | note |
      | Self-certification isn't available for this coursework. You can still request formal mitigation. | info    |      |
      | ---                                                                                              | divider |      |
      | Request formal mitigation                                                                        | live    |      |
    And I should not see "Self-certify an extension"
    When I click on "Self-certification isn't available for this coursework. You can still request formal mitigation." in the Mitigate menu of "A1"
    Then the Mitigate menu of "A1" should still be open

  @javascript
  Scenario: A live self-certification link and formal mitigation are split by a divider
    Given a summative "assign" "A1" in "M1" is due "in 5 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a link" for self-certification
    And block_rhb offers "a link" for the request form
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should list:
      | item                      | state   | note |
      | Self-certify an extension | live    |      |
      | ---                       | divider |      |
      | Request formal mitigation | live    |      |

  @javascript
  Scenario: An assignment's menu has formal mitigation only, with no divider
    Given a summative "assign" "A1" in "M1" is due "in 5 days"
    And block_rhb offers "a link" for the request form
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should list:
      | item                      | state | note |
      | Request formal mitigation | live  |      |
