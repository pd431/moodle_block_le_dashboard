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

  Scenario: Released feedback comes after the deadline it follows
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the dates of "A1" should be "Due, Feedback received" in that order

  Scenario: Expected feedback comes after the deadline, as before
    # New sites don't show students a feedback date (beta27), so this one does.
    Given the following config values are set as admin:
      | feedbackdatemode | asset | block_le_dashboard |
    And a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the dates of "A1" should be "Due, Feedback expected" in that order

  Scenario: An activity not open yet still reads Opens, then Due
    Given a summative "assign" "A1" in "M1" opens "in 3 days" and is due "in 10 days"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the dates of "A1" should be "Opens, Due" in that order

  Scenario: Undated work with released feedback shows only the feedback date
    Given a summative "assign" "A1" in "M1" with no due date exists
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the dates of "A1" should be "Feedback received" in that order

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
