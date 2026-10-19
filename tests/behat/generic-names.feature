@block @block_le_dashboard
Feature: Assessments with generic names get a hint for staff
  In order to give students names that say what an assessment is
  As a marker or convenor
  I need a hint on assessments named only with generic words, such as "Essay 1"

  # Pierre, 2026-10-17: "looks at the assessment title, has a list of generic terms ... in the admin settings, and
  # if the assessment name only contains those plus punctuation, then add a little tooltip". Approved scope
  # beta26.feature: every word must be a listed term, a number, a roman numeral or a word of the course's full or
  # short name. Staff and convenor rows only; on by default, with its own off switch.

  Background:
    Given the following "courses" exist:
      | fullname        | shortname |
      | Law and Society | LAW1001   |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tia       | Teacher  |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course  | role           |
      | student1 | LAW1001 | student        |
      | teacher1 | LAW1001 | editingteacher |
      | convenor | LAW1001 | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario Outline: A name made only of generic words gets a hint for staff
    Given a summative "assign" "<name>" in "LAW1001" is due "in 3 days"
    When I log in as "teacher1" and view my dashboard
    Then "<name>" should <have> a "generic name" hint

    Examples:
      | name                      | have     |
      | Essay 1                   | have     |
      | CW2 - Summative           | have     |
      | LAW1001 Assessment        | have     |
      | Law: Coursework (Part 2)  | have     |
      | Essay II                  | have     |
      | Online Learning Essay     | not have |
      | Society and the Law Essay | not have |

  # Pierre, 2026-10-17: a course's full name can carry its code and run, and its words still count.
  Scenario: Words of a long course name with a code in it count as generic
    Given the following "courses" exist:
      | fullname                                  | shortname    |
      | Computing in History - CSM1056_B_23_48353 | CSM1056_B_23 |
    And the following "course enrolments" exist:
      | user     | course       | role           |
      | teacher1 | CSM1056_B_23 | editingteacher |
    And a summative "assign" "Computing in History Essay" in "CSM1056_B_23" is due "in 3 days"
    And a summative "assign" "Computing the Census, 1841-1911" in "CSM1056_B_23" is due "in 4 days"
    When I log in as "teacher1" and view my dashboard
    Then "Computing in History Essay" should have a "generic name" hint
    And "Computing the Census, 1841-1911" should not have a "generic name" hint

  Scenario: Convenors see the hint too
    Given a summative "assign" "Essay 1" in "LAW1001" is due "in 3 days"
    When I log in as "convenor" and view the "Convenor view"
    Then "Essay 1" should have a "generic name" hint

  @javascript
  Scenario: The hint says why and gives an example
    Given a summative "assign" "Essay 1" in "LAW1001" is due "in 3 days"
    When I log in as "teacher1" and view my dashboard
    And I click on the generic name hint of "Essay 1"
    Then the "genericname" popover of "Essay 1" should say "Generic name" then "Students benefit from descriptive names for their assessments."

  Scenario: Students don't see the hint
    Given a summative "assign" "Essay 1" in "LAW1001" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then "Essay 1" should not have a "generic name" hint

  Scenario: An admin can turn the hint off
    Given the following config values are set as admin:
      | genericnames | 0 | block_le_dashboard |
    And a summative "assign" "Essay 1" in "LAW1001" is due "in 3 days"
    When I log in as "teacher1" and view my dashboard
    Then "Essay 1" should not have a "generic name" hint

  @javascript
  Scenario: An admin can change the terms and the text
    Given the following config values are set as admin:
      | genericterms    | essay resit                      | block_le_dashboard |
      | genericnametext | Please give this a proper title. | block_le_dashboard |
    And a summative "assign" "Resit Essay" in "LAW1001" is due "in 3 days"
    And a summative "assign" "Coursework 1" in "LAW1001" is due "in 4 days"
    When I log in as "teacher1" and view my dashboard
    Then "Coursework 1" should not have a "generic name" hint
    When I click on the generic name hint of "Resit Essay"
    Then the "genericname" popover of "Resit Essay" should say "Generic name" then "Please give this a proper title."
