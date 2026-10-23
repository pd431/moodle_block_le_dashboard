@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Assessments with no online submission
  In order not to be told to submit something that is done in person
  As a student, marker or module convenor
  I need attendance, in-person and mark-only assessments shown as such, never as overdue

  # Pierre, 2026-10-06 22:10: "We also need a paradigm for activities like coursework with submission type
  # attendance, and mod_attendance, and other things where student is not expected to submit ... Suggest
  # something and go with it, I may change my mind tomorrow". Coordinator default, 22:10.
  #
  # As built:
  # - One shared flag, cm_row::$submissions = false, stored as nosubmission on the index. It covers an
  #   assignment with no submission types (EC5), coursework whose submission type is attendance (the
  #   field name is unverified without the plugin), mod_attendance, and any activity tagged with an
  #   In-person tag. That is a new setting under Tags, default "inperson". The tag goes on as well as a
  #   summative or formative tag.
  # - Student: Upcoming until the date with View, never Submit. One bare fa-person-chalkboard icon,
  #   whose popover reads "No online submission" then "Attend or complete this in person; your grade
  #   will appear here." After the date it goes to Past as "Awaiting feedback", then "Feedback
  #   released" with the grade. Never Overdue, never "Not submitted", no late clock, no Not submitted
  #   icon. Mitigate follows the activity: it shows only where block_rhb offers it for that activity
  #   type.
  # - Staff and convenor: every student counts as ready to mark at their deadline, marked once the
  #   gradebook holds their grade, released once they can see it. The same icon shows as an outlined
  #   grey flag, with "Students hand nothing in online. Every active student counts as ready to mark
  #   from the deadline." There is no Submitted bar.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username  | firstname | lastname |
      | student1  | Sam       | Student  |
      | student2  | Sue       | Student  |
      | teacher1  | Tim       | Teacher  |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | student2  | M1     | student        |
      | teacher1  | M1     | editingteacher |
      | convenor1 | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block keeps its hints and guidance from before beta27
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And the "workshop" activity type is enabled in My assessments

  @javascript
  Scenario: Before the date, an in-person assessment is upcoming with no Submit and says why
    Given a summative "workshop" "Viva" in "M1" tagged "inperson" is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Viva" should appear in the "Upcoming" section
    And "Viva" should not show "Submit"
    And "Viva" should show "View"
    And "Viva" should have no status pill
    When I click on the "nosubmission" icon of "Viva"
    Then the "nosubmission" popover of "Viva" should say "No online submission" then "Attend or complete this in person; your grade will appear here."

  @javascript
  Scenario: Once the grade is out, the student sees it
    Given a summative "workshop" "Viva" in "M1" tagged "inperson" was due "2 days ago"
    And the gradebook grade of "student1" for "Viva" is set to "64"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I press "Show grades" in the "My assessments" block
    Then "Viva" should appear in the "Past" section
    And "Viva" should show the status "Feedback released"
    And the grade of "Viva" should be visible

  Scenario: The in-person tag can be changed in the settings
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Settings" in site administration
    Then the field "In-person tags" matches value "inperson"
