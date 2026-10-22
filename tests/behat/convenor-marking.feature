@block @block_le_dashboard
Feature: Module convenors see unallocated marking in My marking
  In order to see marking nobody is allocated to in the courses I convene
  As a module convenor who also teaches elsewhere
  I need My marking to count it, as it does for teachers (UAT, beta27)

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
    # Convenors who hold no staff role anywhere don't get My marking at all (unchanged), so convenor1 also
    # teaches Module 2.
    And the following "courses" exist:
      | fullname | shortname |
      | Module 2 | M2        |
    And the following "users" exist:
      | username  | firstname | lastname |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | convenor1 | M1     | moduleconvenor |
      | convenor1 | M2     | teacher        |
    And the My assessments block treats "moduleconvenor" as a module convenor role

  Scenario: A convenor sees quiz marking in progress in My marking
    Given a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"
    And "student1" has finished "Q1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    Then "Q1" should appear in the "Marking in progress" section
    And the "To mark" summary box should show "1"

  Scenario: A convenor sees unallocated assignment marking in My marking
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section

  Scenario: Quiz marking still waits for the quiz to close
    Given a summative "quiz" "Q1" in "M1" with an essay question is due "in 3 days"
    And "student1" has finished "Q1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    Then "Q1" should not appear in the "Marking in progress" section
    And "Q1" should not appear in the "Completed marking" section
