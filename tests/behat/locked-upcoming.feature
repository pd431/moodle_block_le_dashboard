@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Work the student can no longer change stays in Upcoming, with the reason in place of Submit
  In order to see work I've handed in early until its deadline, and know why I can't change it
  As a student
  I need final work kept in Upcoming as "Submitted", with a "Can't resubmit" control that says why

  # Pierre, 2026-10-09 (scope remake-scale/scopes/locked-upcoming.feature, approved). Reverses the 2026-10-06
  # rule that moved locked work to Past at once. Work that is final before its deadline stays in Upcoming until
  # the deadline, like any other submitted work. Its Submit button becomes a "Can't resubmit" control whose
  # popover says why, then the guidance from the settings (guidance-settings.feature):
  # | lock      | when                                                                 | guidance box       |
  # | finalised | coursework finalised, or an assignment submitted under required Submit | guidance_finalised |
  # | teacher   | an assignment or peer work locked by a teacher                       | guidance_finalised |
  # | attempts  | a quiz with every allowed attempt used                               | guidance_attempts  |
  # The reason is worked out at index time into the new stu column lockreason, so the view adds no queries.
  # Submitted work the student can still change keeps Submit, which takes them back to it.
  # Work that can't be submitted for any other reason (past its cut-off, quiz closed) still goes to Past at once.
  # The "Resubmission contact" setting the scope proposed is replaced by the guidance_finalised box (coordinator,
  # 2026-10-09). Mitigate's new rule is in mitigate-rc6.feature.

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

  Scenario: An assignment submitted under required Submit stays in Upcoming until its deadline
    Given a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show the status "Submitted"
    And "A1" should have a "Can't resubmit" button
    And "A1" should not have a "Submit" button
    And the "Can't resubmit" popover of "A1" should say "Your submission is finalised, so you can't change it."
    And the "Can't resubmit" popover of "A1" should say "If you need to resubmit, contact your Info Point to unfinalise it."

  Scenario: The guidance under the reason follows the setting
    Given the "guidance_finalised" setting is "<p>If you need to resubmit, <a href=\"https://example.ac.uk/hub\">email the Hub</a>.</p>"
    And a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "Can't resubmit" popover of "A1" should say "If you need to resubmit, email the Hub."
    And "email the Hub" in the "Can't resubmit" popover of "A1" should link to "https://example.ac.uk/hub"

  Scenario: Work a teacher locked
    Given a summative "assign" "A1" in "M1" is due "in 3 days"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is locked by a teacher
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And the "Can't resubmit" popover of "A1" should say "Your teacher has locked your submission, so you can't change it."
    And the "Can't resubmit" popover of "A1" should say "contact your Info Point"

  Scenario: A quiz with every attempt used
    Given a summative "quiz" "Q1" in "M1" with 1 attempts allowed is due "in 50 days"
    And "student1" has finished 1 attempts at "Q1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "Q1" should appear in the "Upcoming" section
    And "Q1" should show the status "Submitted"
    And "Q1" should have a "Can't resubmit" button
    And the "Can't resubmit" popover of "Q1" should say "You've used all your attempts."
    And the "Can't resubmit" popover of "Q1" should not say "unfinalise"

  Scenario: At the deadline the row moves to Past as usual
    Given a summative "assign" "A1" in "M1" with required Submit was due "1 hour ago"
    And "student1" submitted "A1" "2 hours ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Past" section
    And "A1" should show the status "In marking"
    And "A1" should not have a "Can't resubmit" button

  Scenario: Reverted to draft, the row is ordinary again
    Given a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is reverted to draft
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should have a "Submit" button
    And "A1" should not have a "Can't resubmit" button

  Scenario: A draft under required Submit keeps Submit
    Given a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has saved a draft of "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should have a "Submit" button

  Scenario: Work handed in that can still be changed keeps Submit
    Given a summative "assign" "A1" in "M1" is due "in 3 days"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should show the status "Submitted"
    And "A1" should have a "Submit" button
    And "A1" should not have a "Can't resubmit" button

  Scenario: A reopened attempt after a final submission is upcoming work
    Given a summative "assign" "A1" in "M1" with required Submit and reopened attempts was due "3 days ago"
    And "student1" submitted "A1" "4 days ago"
    And "teacher1" grades "student1" on "A1" with "40"
    And the submission of "student1" for "A1" is reopened
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section
    And "A1" should have no status pill
    And "A1" should have a "Submit" button

  @javascript
  Scenario: The control opens its explanation, and screen readers hear it
    Given a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "Can't resubmit" control of "A1" should be a button described by its explanation
    When I open the "Can't resubmit" control of "A1"
    Then the "locked" popover of "A1" should be visible
    And the "Can't resubmit" popover of "A1" should say "Your submission is finalised, so you can't change it."

  @mod_coursework
  Scenario: Finalised coursework stays in Upcoming until its deadline
    Given a summative "coursework" "C1" in "M1" is due "in 2 days"
    And "student1" has submitted "C1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "C1" should appear in the "Upcoming" section
    And "C1" should show the status "Submitted"
    And "C1" should have a "Can't resubmit" button

  @mod_coursework
  Scenario: A coursework draft that isn't final keeps Submit
    Given a summative "coursework" "C1" in "M1" is due "in 2 days"
    And "student1" has saved a draft of "C1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "C1" should appear in the "Upcoming" section
    And "C1" should have a "Submit" button

  # beta22: the popover opened at the far left of the list, because nothing between it and the block was
  # positioned. It now opens under its button, right-aligned with the row's actions, and takes the row's
  # width on a narrow block.
  @javascript
  Scenario: The Can't resubmit popover opens next to its button
    Given a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I open the "Can't resubmit" control of "A1"
    Then the "Can't resubmit" popover of "A1" should open under its button

  @javascript
  Scenario: The Can't resubmit popover stays on screen on a phone
    Given I change viewport size to "360x800"
    And a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I open the "Can't resubmit" control of "A1"
    Then the "Can't resubmit" popover of "A1" should open under its button
    And the "Can't resubmit" popover of "A1" should be fully on screen
