@block @block_le_dashboard
Feature: Optional assignment markers count as Moodle counts them
  In order to see the marking that is mine, and agree a mark only once every expected marker has marked
  As a marker or convenor
  I need required markers in their own stages, optional markers after them, and switched-off ones ignored

  Background:
    Given the site runs Moodle 5.3 or later
    And the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
      | marker2  | Max       | Marker   |
      | marker3  | Meg       | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | marker1  | M1     | teacher        |
      | marker2  | M1     | teacher        |
      | marker3  | M1     | teacher        |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And a summative "assign" "A1" in "M1" with marker allocation, 2 markers, 1 optional marker and manual agreement was due "3 days ago"
    And "student1" has submitted "A1"
    # Allocated in this order on purpose: core still places marker2 second, as a required marker.
    And the submission of "student1" for "A1" is allocated to "marker1"
    And the submission of "student1" for "A1" has "marker3" as its optional marker
    And the submission of "student1" for "A1" is allocated to "marker1" and "marker2"

  Scenario: A required marker keeps their stage whatever order the markers were allocated in
    Given the My assessments index is up to date
    When I log in as "marker2" and view the "My marking"
    Then "A1" should appear in the "Marking in progress" section with "1" to mark
    And I log in as "convenor" and view the "Convenor view"
    And "A1" should show progress bars "Submitted, Unallocated, Marker 1, Marker 2, Marker 3, Released"
