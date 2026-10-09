@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Names in the block link to what they name
  In order to get from the dashboard to a course, an activity or a colleague in one click
  As a student, marker or module convenor
  I need course names, activity names and people's names to be links

  # Pierre, 2026-10-09 11:36: "can you ensure all instances of hyperlinkable things are. course name,
  # coursework and activities, people's names to their email are".
  # - Activity names already link to the activity (every view, and the untagged activities list).
  # - Course names on rows link to the course page. In the untagged activities list, the course name in each
  #   course's heading links to the course; the rest of the heading still opens and closes the list.
  # - Markers' names in a convenor's marking detail link to their email (mailto:). A marker with no email
  #   address, a deleted account, or the convenor's own "All marking" line stays plain text.
  # - Nothing else in the block names a person or a course.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | student1 | Sam       | Student  | sam@example.com   |
      | marker1  | Mo        | Marker   | mo@example.com    |
      | convenor | Cat       | Convenor | cat@example.com   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: A student's row links its activity and its course
    Given a summative "assign" "A1" in "M1" is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then the name of "A1" should link to its activity
    And the course of "A1" should link to the course page of "M1"

  Scenario: A marker's row links its course
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    When I log in as "marker1" and view my dashboard
    Then the course of "A1" should link to the course page of "M1"

  Scenario: A convenor's marking detail links each marker to their email
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    When I log in as "convenor" and view my dashboard
    Then the course of "A1" should link to the course page of "M1"
    And the marker "Mo Marker" of "A1" should link to "mailto:mo@example.com"

  Scenario: The untagged activities list links each course
    Given the following "activities" exist:
      | activity | name     | course |
      | assign   | Untagged | M1     |
    When I log in as "convenor" and view my dashboard
    Then the course "Module 1" in the untagged activities list should link to the course page of "M1"
