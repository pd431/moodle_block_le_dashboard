@block @block_le_dashboard
Feature: Every row reads in the same order, in every view
  In order to find a date or a flag without re-reading each row
  As a student, marker or convenor
  I need the facts in a row, and the rows in a section, to follow one order across the whole dashboard

  # beta28, scope order-of-information.feature (Pierre, 2026-10-20 and 2026-10-21).

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Stu       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | marker1  | M1     | teacher        |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  # ---------- Student dates in the order things happen ----------

  # ---------- Student date labels line up ----------

  @javascript
  Scenario: A row with one date keeps its label beside it
    Given a summative "assign" "A1" in "M1" is due "in 10 days"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the date labels of "A1" should not be on their own lines

  @javascript
  Scenario: A row with two dates puts each label on its own line
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the date labels of "A1" should be on their own lines

  # ---------- Type before activity in every view ----------

  # ---------- One order of flags; the default marking hint beside its date ----------

  Scenario Outline: The default marking deadline hint sits beside the date it explains
    Given a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" has finished "Q1"
    And the My assessments index is up to date
    When I log in as "<user>" and view the "<view>"
    Then the default marking deadline hint of "Q1" should sit beside its "Marking deadline" date

    Examples:
      | user     | view          |
      | marker1  | My marking    |
      | convenor | Convenor view |

  # ---------- Rows by relevance: soonest first while there's work, latest first once done ----------
