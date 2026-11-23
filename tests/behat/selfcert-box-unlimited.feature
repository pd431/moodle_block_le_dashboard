@block @block_le_dashboard @block_rhb
Feature: The self-certified extensions box says how many an unlimited student has used
  In order to read the box without guessing what the number means
  As a student with no self-certification limit
  I need the box to say "2 used" and "Unlimited extensions" instead of a bare "2" and "No limit"

  # Approved scope selfcert-unlimited-box.feature (Pierre, 2026-11-23). The limited box still counts down
  # ("2 of 3 left"), as the Mitigate menu does: mitigate-menu-explains.feature.

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
    And a summative "coursework" "C1" in "M1" is due "in 4 days"

  @mod_coursework
  Scenario Outline: An unlimited student's box says how many they have used
    Given "student1" has no self-certification limit
    And "student1" has used <used> self-certified extensions
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "Self-certified extensions" summary box should show "<text>"
    And the "Self-certified extensions" summary box note should say "Unlimited extensions"
    And I should not see "No limit"

    Examples:
      | used | text   |
      | 0    | 0 used |
      | 2    | 2 used |
      | 5    | 5 used |

  @mod_coursework
  Scenario: A limited student's box still counts down
    Given "student1" has used 1 self-certified extension
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "Self-certified extensions" summary box should show "2 of 3 left"
    And I should not see "Unlimited extensions"
