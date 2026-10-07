@block @block_le_dashboard
Feature: Reopened and extended work stays in Upcoming without a flag
  In order not to flag something the student doesn't need to act on differently
  As a student
  I need a reopened attempt or an extended deadline to look like any other upcoming work

  # Pierre, 2026-10-06 21:58: "remove the case where student gets a highlight of something being re-opened.
  # If resubmission is allowed or deadline is extended, leave in upcoming, without additional flagging."
  # The G13 routing stays: a reopened attempt is in Upcoming with Submit, whatever its deadline. Only the
  # "Resubmission open" pill goes. An extension already lands in Upcoming. Its "Your own deadline" note goes
  # (flag-icons.feature).
  # Default, coordinator to confirm: the extension and self-certification modifiers on the activity
  # icon stay. They are part of the agreed icon tooltip ("Coursework. Self cert applied") and add no
  # height to the row.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tim       | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | teacher1 | M1     | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: A reopened attempt is ordinary upcoming work
    Given a summative "assign" "A1" in "M1" with reopened attempts was due "30 days ago"
    And "student1" submitted "A1" "31 days ago"
    And "teacher1" grades "student1" on "A1" with "40"
    And the submission of "student1" for "A1" is reopened
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should have no status pill
    And "A1" should not show "Resubmission open"
    And the activity icon of "A1" should have no modifiers

  Scenario: Work with an extension is ordinary upcoming work
    Given a summative "assign" "A1" in "M1" was due "yesterday"
    And "student1" has an extension on "A1" to "next week"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should have no status pill
    And "A1" should not show "Your own deadline"
