@block @block_le_dashboard @mod_coursework
Feature: Students see coursework's own feedback release date, not the marking deadline
  In order to know when my feedback will actually be released
  As a student
  I need the Feedback expected date to come from coursework's release dates, with the marking deadline only as a fallback

  # Pierre, 2026-11-24: students saw the initial marking deadline instead of the expected release date.
  # "1, failing that, 2. For students. Change nothing for markers." (CS7)

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And the following config values are set as admin:
      | config           | value | plugin             |
      | feedbackdatemode | asset | block_le_dashboard |

  Scenario: A personal feedback date comes first
    Given a summative "coursework" "C1" in "M1" with a marking deadline "in 5 days" and an individual feedback date "in 15 days" and personal feedback dates was due "2 days ago"
    And "student1" has submitted "C1"
    And "student1" has a personal feedback date "in 20 days" on "C1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "C1" should show "Feedback expected" with the date "in 20 days"
