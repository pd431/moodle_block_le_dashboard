@block @block_le_dashboard @block_rhb @javascript
Feature: The Mitigate menu explains self-certification
  In order to know whether I can self-certify an extension, and what to do if I can't
  As a student
  I need the Mitigate menu to always list self-certification, with how many I have left or why I can't

  # Pierre, 2026-10-09: "it seems like we were pulling the mitigation button just fine before we reverted to the
  # rc6 implementation. implement the mitigation options as drafted in the other thread, option 1".
  # Option 1 is A on the Mitigate options page (Flags, pills and notices library thread, 2026-10-07).
  #
  # What changes from beta21 (rc6 behaviour):
  # - The menu items use our labels again, "Self-certify an extension" and "Request formal mitigation", with only the
  #   address taken from block_rhb (as beta17 did). If the address can't be read, block_rhb's HTML shows as it is.
  # - Coursework without a self-certification link still offers Mitigate when the request form has a link.
  #   Self-certification is then listed greyed, with the reason.
  # - The "No self-certified extension is allowed" note goes. A reason under the greyed item replaces it.
  #
  # What stays: Mitigate shows on summative rows only, while the window is open (until 24 hours after the
  # student's own deadline, whatever was handed in or released). "Delete mitigation" behaves as now.
  # Mitigate still needs at least one live link: with no request link and no self-cert link there is no button.
  #
  # Approved by Pierre, 2026-10-09 ("yes"), with his changes below. Coursework scenarios need mod_coursework
  # (@mod_coursework); mitigation_test covers every menu rule without it.
  # Defaults in the approved scope:
  # - Pierre, 2026-10-09: only coursework takes self-certified extensions, so quizzes and assignments don't list
  #   it at all; their menu holds "Request formal mitigation" alone. The other path is called "Formal mitigation"
  #   everywhere, to reduce confusion.
  # - block_rhb decides. When it returns a self-cert link, the item is live even if the block's own count says
  #   none are left (the count is the block's copy of rhb's rule and can drift). The count then isn't shown.
  # - When rhb returns no link and the block can't tell why (not set up, too late, already submitted), the
  #   reason says so generally: "Self-certification isn't available for this coursework."
  # - Greyed means aria-disabled, not disabled, so keyboard users reach it and hear the reason. Nothing is red.
  # - Pierre, 2026-10-09 14:56-14:57: "Delete mitigation" is its own item, red with a bin icon (Moodle's look
  #   for a destructive menu action; the red marks the action, not the student). It asks for confirmation in the
  #   same item: the first click turns it into "Click again to delete" and keeps the menu open; the second click
  #   deletes. Closing the menu puts it back. It is still found by "delete" in block_rhb's link text or address.
  # - The summary box counts down to match: "2 of 3 left", and "All 3 used" with "You can still request
  #   mitigation." Unlimited students still see "No limit".

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
    And block_rhb allows 3 self-certified extensions

  @mod_coursework
  Scenario: A self-cert left shows the count, with both links under our labels
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "a link" for self-certification
    And "student1" has used 1 self-certified extension
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    Then the Mitigate menu of "C1" should list:
      | item                       | state | note        |
      | Self-certify an extension  | live  | 2 of 3 left |
      | Request formal mitigation         | live  |             |
    And "Self-certify an extension" in the Mitigate menu of "C1" should go to block_rhb's self-certification address

  @mod_coursework
  Scenario: All self-certs used: greyed, with the reason and what's still possible
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "nothing" for self-certification
    And "student1" has used 3 self-certified extensions
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    Then the Mitigate menu of "C1" should list:
      | item                       | state   | note                                                                                       |
      | Self-certify an extension  | greyed  | You have used all 3 of your self-certified extensions. You can still request formal mitigation. |
      | Request formal mitigation         | live    |                                                                                            |

  @mod_coursework
  Scenario: Already self-certified here: greyed, with the new deadline, and Delete mitigation stays
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And "student1" self-certified "C1" to "in 11 days"
    And block_rhb offers "a delete link" for self-certification
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    Then the Mitigate menu of "C1" should list:
      | item                       | state  | note                                                                              |
      | Self-certify an extension  | greyed | You already self-certified an extension for this assessment. Your deadline is now |
      | Delete mitigation          | live   |                                                                                   |
      | Request formal mitigation         | live   |                                                                                   |

  Scenario: A quiz offers formal mitigation only
    Given a summative "quiz" "Q1" in "M1" is due "in 4 days"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "Q1" row
    Then the Mitigate menu of "Q1" should list:
      | item                       | state | note |
      | Request formal mitigation  | live  |      |

  @mod_coursework
  Scenario: Coursework rhb gives no self-cert link for still offers Mitigate
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "nothing" for self-certification
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    Then the Mitigate menu of "C1" should list:
      | item                       | state  | note                                                                                  |
      | Self-certify an extension  | greyed | Self-certification isn't available for this coursework. You can still request formal mitigation. |
      | Request formal mitigation         | live   |                                                                                       |

  @mod_coursework
  Scenario: rhb's answer wins over the block's own count
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "a link" for self-certification
    And "student1" has used 3 self-certified extensions
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    Then the Mitigate menu of "C1" should list:
      | item                       | state | note |
      | Self-certify an extension  | live  |      |
      | Request formal mitigation         | live  |      |

  @mod_coursework
  Scenario: No live link at all means no Mitigate
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "nothing" for self-certification
    And block_rhb offers "no link" for the request form
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "C1" should not show "Mitigate"

  @mod_coursework
  Scenario: An address block_rhb wraps oddly falls back to its own HTML
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "an unreadable link" for self-certification
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    Then the Mitigate menu of "C1" should show block_rhb's self-certification HTML

  @mod_coursework
  Scenario: The greyed item is reachable from the keyboard and reads its reason
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "nothing" for self-certification
    And "student1" has used 3 self-certified extensions
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    And I press the down key
    Then the focused element is "Self-certify an extension" "button"
    And I press the enter key
    And I should see "My assessments"

  @mod_coursework
  Scenario Outline: The summary box counts down
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And "student1" has used <used> self-certified extensions
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "Self-certified extensions" summary box should show "<text>"

    Examples:
      | used | text                                         |
      | 0    | 3 of 3 left                                  |
      | 1    | 2 of 3 left                                  |
      | 3    | All 3 used                                   |

  @mod_coursework
  Scenario: Formative work still never offers Mitigate
    Given a formative "coursework" "F1" in "M1" is due "in 4 days"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "F1" should not show "Mitigate"

  @mod_coursework
  Scenario: Delete mitigation is red with a bin, and asks for a second click in the same place
    Given a summative "coursework" "C1" in "M1" is due "in 4 days"
    And block_rhb offers "a delete link" for self-certification
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "C1" row
    Then "Delete mitigation" in the Mitigate menu of "C1" should be a destructive item with a bin icon
    When I click on "Delete mitigation" in the Mitigate menu of "C1"
    Then the Mitigate menu of "C1" should still be open
    And "Click again to delete" should be in the Mitigate menu of "C1"
    And block_rhb's delete link should not have been followed
    When I press the escape key
    And I press "Mitigate" in the "C1" row
    Then "Delete mitigation" should be in the Mitigate menu of "C1"
    When I click on "Delete mitigation" in the Mitigate menu of "C1"
    And I click on "Click again to delete" in the Mitigate menu of "C1"
    Then block_rhb's delete link should have been followed

  # The same menu on sites without mod_coursework: the block_rhb stand-in treats an assignment as a coursework,
  # so CI checks the menu and Delete mitigation too. The count needs mod_coursework, so none shows here.
  Scenario: Without mod_coursework: self-cert and formal mitigation under our labels
    Given a summative "assign" "A1" in "M1" is due "in 4 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a link" for self-certification
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should list:
      | item                      | state | note |
      | Self-certify an extension | live  |      |
      | Request formal mitigation | live  |      |
    And "Self-certify an extension" in the Mitigate menu of "A1" should go to block_rhb's self-certification address

  Scenario: Without mod_coursework: no self-cert link greys it, and the keyboard reaches it
    Given a summative "assign" "A1" in "M1" is due "in 4 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a message only" for self-certification
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should list:
      | item                      | state  | note                                                                                       |
      | Self-certify an extension | greyed | Self-certification isn't available for this coursework. You can still request formal mitigation. |
      | Request formal mitigation | live   |                                                                                            |
    And I press the down key
    And the focused element is "Self-certify an extension" "button"
    And I press the enter key
    And I should see "My assessments"

  Scenario: Without mod_coursework: an unreadable self-cert link shows block_rhb's own HTML
    Given a summative "assign" "A1" in "M1" is due "in 4 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "an unreadable link" for self-certification
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should show block_rhb's self-certification HTML

  Scenario: Without mod_coursework: Delete mitigation is red with a bin, and asks for a second click
    Given a summative "assign" "A1" in "M1" is due "in 4 days"
    And the block_rhb stand-in treats "A1" as a coursework
    And block_rhb offers "a delete link" for self-certification
    When I log in as "student1" and view my dashboard
    And I press "Mitigate" in the "A1" row
    Then the Mitigate menu of "A1" should list:
      | item                      | state  | note                                                          |
      | Self-certify an extension | greyed | You already self-certified an extension for this assessment. |
      | Delete mitigation         | live   |                                                               |
      | Request formal mitigation | live   |                                                               |
    And "Delete mitigation" in the Mitigate menu of "A1" should be a destructive item with a bin icon
    When I click on "Delete mitigation" in the Mitigate menu of "A1"
    Then the Mitigate menu of "A1" should still be open
    And "Click again to delete" should be in the Mitigate menu of "A1"
    And block_rhb's delete link should not have been followed
    When I press the escape key
    And I press "Mitigate" in the "A1" row
    Then "Delete mitigation" should be in the Mitigate menu of "A1"
    When I click on "Delete mitigation" in the Mitigate menu of "A1"
    And I click on "Click again to delete" in the Mitigate menu of "A1"
    Then block_rhb's delete link should have been followed
