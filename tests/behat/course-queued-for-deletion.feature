@block @block_le_dashboard
Feature: A course queued for deletion leaves the staff views at once
  In order not to open marking in a course that is going away
  As a marker or convenor
  I need a course queued for deletion to leave my dashboard before cron deletes it

  Scenario: A course queued for asynchronous deletion leaves My marking and the Convenor view
    Given the site runs Moodle 5.3 or later
    And the following config values are set as admin:
      | enablecourseasyncdeletion | 1 | moodlecourse |
    And the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
      | Module 2 | M2        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
      | student1 | M2     | student |
      | marker1  | M1     | teacher |
      | marker1  | M2     | teacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And a summative "assign" "A2" in "M2" was due "3 days ago"
    And "student1" has submitted "A1"
    And "student1" has submitted "A2"
    And the My assessments index is up to date
    When "M1" is queued for deletion
    And I log in as "marker1" and view the "My marking"
    Then "A1" should not appear in the "Marking in progress" section
    And "A2" should appear in the "Marking in progress" section with "1" to mark
