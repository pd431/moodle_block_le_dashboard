@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Final submissions leave Upcoming at once
  In order that Upcoming only holds work I can still act on
  As a student
  I need work I can no longer change shown in Past as soon as I hand it in

  # Pierre, 2026-10-06 22:06: "Anything the student can't action anymore must move to past." Coordinator,
  # 2026-10-07 02:43: a final, locked submission goes to Past as "Submitted" straight away; work the student
  # can still edit or resubmit stays in Upcoming.
  #
  # As built: each adapter sets one shared flag, student_row::$locked. Assign sets it on a submitted (not
  # draft) submission where students must click Submit, or when a teacher locked it. With no required Submit,
  # a submission stays editable until the cut-off; "untilpass" reopening reads as reopened. Coursework sets it once a submission is finalised, where
  # the version records that. Peerwork sets it when the submission is locked (lock editing). A quiz with every
  # attempt used is closed the same way. The indexer folds the flag into the student's own cut-off, the time
  # they submitted, so the one submittability rule in the classifier decides. Once the deadline passes the
  # row reads "In marking" as before.

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

  Scenario: A final submission handed in early goes to Past as Submitted
    Given a summative "assign" "A1" in "M1" with required Submit is due "next week"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Past" section
    And "A1" should show the status "Submitted"
    And "A1" should show "View"

  Scenario: A draft under required Submit stays in Upcoming
    Given a summative "assign" "A1" in "M1" with required Submit is due "next week"
    And "student1" has saved a draft of "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show "Submit"

  Scenario: A submission the student can still edit stays in Upcoming
    Given a summative "assign" "A1" in "M1" is due "next week"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show the status "Submitted"

  Scenario: A final submission reverted to draft is upcoming work again
    Given a summative "assign" "A1" in "M1" with required Submit is due "next week"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is reverted to draft
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show "Submit"

  Scenario: A reopened attempt after a final submission is upcoming work
    Given a summative "assign" "A1" in "M1" with required Submit and reopened attempts was due "3 days ago"
    And "student1" submitted "A1" "4 days ago"
    And "teacher1" grades "student1" on "A1" with "40"
    And the submission of "student1" for "A1" is reopened
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should have no status pill
