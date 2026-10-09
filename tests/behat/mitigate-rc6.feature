@block @block_le_dashboard @block_rhb @javascript
Feature: Mitigate works as it did in rc6
  In order that students reach block_rhb's mitigation routes exactly as they did before the remake
  As a student
  I need the Mitigate menu to show block_rhb's own links on summative work until a day after my deadline

  # Pierre, 2026-10-07 16:01: "seems like beta17's buttons don't show up on our test site. Maybe go simpler
  # and pull back the behaviour from rc6." rc6 (coursework_handler, quiz_handler, content.mustache):
  # - Coursework: Mitigate only when block_rhb's self-certification HTML holds a real link (an <a> with href).
  #   The menu shows that HTML as it is, then the request form HTML as it is.
  # - Everything else (rc6: quiz): self-certification is never offered. Mitigate only when the request form
  #   HTML holds a real link, and the menu adds "No self-certified extension is allowed".
  # - A link block_rhb offers for deleting a self-certification reads "Delete mitigation"; it is followed in
  #   the background and the page reloads.
  # When it shows (Pierre, 2026-10-09, locked-upcoming scope, replacing rc6's "not handed in, no feedback"):
  # on every summative row (D8) from the start until the hours set in "Mitigate stays open after the
  # deadline" (24 by default) after the student's own deadline, extensions included. Handed in or not, locked
  # or not, released or not, in Upcoming, Overdue or Past. Work with no deadline keeps it.
  # Without block_rhb, a Behat stand-in gives the request form and, for coursework, self-certification.

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

  Scenario: The menu shows block_rhb's request link as it is, with the note that self-certification isn't allowed
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    And I click on "Mitigate" "button" in the "My assessments" "block"
    Then I should see "Request mitigating circumstances" linking to the site home
    And I should see "No self-certified extension is allowed" in the "My assessments" block

  Scenario Outline: Mitigate shows until 24 hours after the student's own deadline, whatever was handed in
    Given a summative "assign" "A1" in "M1"<opts> <is> due "<due>"
    And <step>
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "<section>" section
    And "A1" should <show> "Mitigate"

    Examples:
      | opts                          | is  | due          | step                                     | section  | show     |
      |                               | is  | in 100 days  | nothing happens                          | Upcoming | show     |
      |                               | is  | in 2 days    | nothing happens                          | Upcoming | show     |
      | with required Submit          | is  | in 2 days    | "student1" has submitted "A1"            | Upcoming | show     |
      |                               | was | 23 hours ago | nothing happens                          | Overdue  | show     |
      |                               | was | 3 days ago   | nothing happens                          | Overdue  | not show |
      | with a cut-off "20 hours ago" | was | 23 hours ago | nothing happens                          | Past     | show     |
      | with required Submit          | was | 23 hours ago | "student1" submitted "A1" "2 days ago"   | Past     | show     |
      | with required Submit          | was | 25 hours ago | "student1" submitted "A1" "2 days ago"   | Past     | not show |
      | with a cut-off "25 hours ago" | was | 25 hours ago | nothing happens                          | Past     | not show |

  Scenario: An extension moves the Mitigate window with the student's deadline
    Given a summative "assign" "A1" in "M1" with required Submit was due "2 days ago"
    And "student1" has an extension on "A1" to "12 hours ago"
    And "student1" submitted "A1" "3 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Mitigate"

  Scenario: Released feedback doesn't end Mitigate
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tim       | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | M1     | editingteacher |
    And a summative "assign" "A1" in "M1" was due "12 hours ago"
    And "student1" submitted "A1" "2 days ago"
    And "teacher1" grades "student1" on "A1" with "60"
    And the grade of "student1" for "A1" is released
    When I log in as "student1" and view my dashboard
    Then "A1" should show the status "Feedback released"
    And "A1" should show "Mitigate"

  Scenario: A quiz offers the request form only
    Given a summative "quiz" "Q1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    And I click on "Mitigate" "button" in the "My assessments" "block"
    Then I should see "Request mitigating circumstances" in the "My assessments" block
    And I should see "No self-certified extension is allowed" in the "My assessments" block

  Scenario: Formative work never offers Mitigate
    Given a formative "assign" "F1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then "F1" should not show "Mitigate"

  Scenario: Without a request link from block_rhb there is no Mitigate
    Given block_rhb offers "no link" for the request form
    And a summative "assign" "A1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then "A1" should not show "Mitigate"

  @mod_coursework
  Scenario Outline: Coursework shows Mitigate only when self-certification is a real link
    Given block_rhb offers "<selfcert>" for self-certification
    And a summative "coursework" "C1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then "C1" should <show> "Mitigate"

    Examples:
      | selfcert       | show     |
      | a link         | show     |
      | a message only | not show |
      | nothing        | not show |

  @mod_coursework
  Scenario: Coursework shows block_rhb's self-certification and request links as they are
    Given block_rhb offers "a link" for self-certification
    And a summative "coursework" "C1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    And I click on "Mitigate" "button" in the "My assessments" "block"
    Then I should see "Self-certify" in the "My assessments" block
    And I should see "Request mitigating circumstances" in the "My assessments" block
    And I should not see "No self-certified extension is allowed" in the "My assessments" block

  @mod_coursework
  Scenario: A link for deleting a self-certification reads "Delete mitigation"
    Given block_rhb offers "a delete link" for self-certification
    And a summative "coursework" "C1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    And I click on "Mitigate" "button" in the "My assessments" "block"
    Then I should see "Delete mitigation" in the "My assessments" block
