@block @block_le_dashboard
Feature: Convenors are nudged to attach a brief or description
  In order to give students instructions in a consistent place
  As a module convenor
  I need a gentle hint on assessments that have neither a brief nor a description

  # Approved by the coordinator 2026-10-03 on Pierre's wording (replaces rc6's
  # has-brief/has-description pills, which showed the description text itself).
  # "Description" means the activity's intro text, ignoring whitespace and empty markup.
  # "Brief" means a file in the activity's intro area (or its own brief area, such as
  # an assignment's additional files).
  # The hint is an info-toned "No brief" icon button on the row. It opens like Moodle's help
  # icons (click or Tab, closes on Escape or click-away) and never shows the description.
  # The guidance link is an admin setting, empty by default; when empty, the link sentence
  # is left out. Only the Convenor view shows it: My marking covers only the person's own
  # marking (Pierre, 2026-10-03), and students never see it.

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
    And the My assessments block keeps its hints and guidance from before beta27

  @javascript
  Scenario: An assessment with no brief or description shows the hint to its convenor
    Given a summative "assign" "A1" in "M1" with no description is due "tomorrow"
    When I log in as "convenor" and view the "Convenor view"
    And I click on the brief hint of "A1"
    Then I should see "This assessment has no brief or description attached." in the "My assessments" block
    And I should see "Including the assessment brief within the activity will help students find instructions in a consistent place, but isn't a requirement." in the "My assessments" block

  @javascript
  Scenario: The hint links to the guidance when a link is set
    Given the My assessments block guidance link is "https://example.com/briefs"
    And a summative "assign" "A1" in "M1" with no description is due "tomorrow"
    When I log in as "convenor" and view the "Convenor view"
    And I click on the brief hint of "A1"
    Then "guidance" "link" should exist in the "My assessments" "block"

  @javascript
  Scenario: Without a guidance link the hint has no link sentence
    Given a summative "assign" "A1" in "M1" with no description is due "tomorrow"
    When I log in as "convenor" and view the "Convenor view"
    And I click on the brief hint of "A1"
    Then I should not see "See our guidance" in the "My assessments" block

  Scenario Outline: An assessment with a <what> shows no hint
    Given a summative "assign" "A1" in "M1" <with> is due "tomorrow"
    When I log in as "convenor" and view the "Convenor view"
    Then "A1" should not have a brief hint

    Examples:
      | what        | with                                   |
      | description | with the description "Write 2000 words" |
      | brief file  | with no description and a brief file   |

  Scenario: My marking never shows the hint
    Given a summative "assign" "A1" in "M1" with no description was due "yesterday"
    And "student1" has submitted "A1"
    When I log in as "marker1" and view my dashboard
    Then "A1" should not have a brief hint

  Scenario: Students never see the hint
    Given a summative "assign" "A1" in "M1" with no description is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then "A1" should not have a brief hint
