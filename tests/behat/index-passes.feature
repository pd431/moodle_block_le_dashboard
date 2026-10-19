@block @block_le_dashboard
Feature: The index stops at a time limit, carries on where it stopped, and shows its progress
  In order that indexing a very large site finishes, even where ad hoc tasks don't run
  As an admin
  I need each index run to stop after a while, the next one to carry on, and the progress on the Index page

  # Pierre, 2026-10-19: "have the overnight task gracefully fail after a while, and pickup where it left off, and
  # describe progress in the settings page", on a site where "ad-hoc tasks don't always run". Approved scope
  # beta27-index.feature:
  # - The scheduled task runs hourly. It carries on an unfinished first fill at any hour, and otherwise does the
  #   nightly check from 02:00 to 06:00, server time; an unfinished check waits for the next night.
  # - "Time limit for each index run", 45 minutes by default: a run finishes the course it is on and stops. Here a
  #   limit of a number of courses stands in for the minutes.
  # - Progress, after each course, so a run that dies carries on from the last course saved.
  # - cli/rebuild.php takes the queued fill's place.
  # "Preparing" shows only while the student has nothing indexed yet (as since beta14), so the student is in M3,
  # the last course the fill reaches.
  # Wording changed from the scope: "should have done ... only" became "the last index run should have done" (one
  # run) and "the nightly check should have done ... so far" (the scenario's runs together).

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
      | Module 2 | M2        |
      | Module 3 | M3        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M3     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" is due "tomorrow"
    And a summative "assign" "A2" in "M2" is due "tomorrow"
    And a summative "assign" "A3" in "M3" is due "tomorrow"
    And the My assessments index is up to date

  Scenario: A run that reaches its time limit stops after the course it is on, and the next run carries on
    Given every My assessments index run has a time limit of 1 course
    When the nightly check runs
    Then the last index run should have done "M1" only
    When the nightly check runs
    Then the last index run should have done "M2" only
    When the nightly check runs
    Then the last index run should have done "M3" only

  Scenario: A run that died part-way carries on from the last course it saved
    Given the nightly check died after "M1"
    When the nightly check runs
    Then the last index run should have done "M2" and "M3" only

  Scenario: The Index page shows a pass that is part-way through
    Given every My assessments index run has a time limit of 1 course
    And the nightly check runs
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Index" in site administration
    Then I should see "Nightly check: 1 of 3 courses done."
    And I should see "The last run stopped at its time limit and carries on at the next run."

  Scenario: The Index page shows when the last full pass finished
    Given the nightly check runs
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Index" in site administration
    Then I should see "Nightly check: the last full pass finished"

  Scenario: With ad hoc tasks not running, the hourly task finishes the first fill and keeps Preparing until then
    Given every My assessments index run has a time limit of 1 course
    And the My assessments index is rebuilt
    And ad hoc tasks don't run
    When the hourly index task runs at "10:00"
    Then the Index page should say "Preparing: 1 of 3 courses done."
    When I log in as "student1" and view my dashboard
    Then I should see "Preparing your assessments"
    When the hourly index task runs at "11:00"
    And the hourly index task runs at "12:00"
    And I log in as "student1" and view my dashboard
    Then I should not see "Preparing your assessments"
    And I should see "A3"

  Scenario: The nightly check only works between 02:00 and 06:00
    Given every My assessments index run has a time limit of 1 course
    When the hourly index task runs at "14:00"
    Then the nightly check should have done no courses so far
    When the hourly index task runs at "02:00"
    And the hourly index task runs at "03:00"
    Then the nightly check should have done "M1" and "M2" so far
    When the hourly index task runs at "07:00"
    Then the nightly check should have done "M1" and "M2" so far
    When the hourly index task runs at "02:00"
    Then the nightly check should have done "M1", "M2" and "M3" so far

  Scenario: The command-line rebuild works with a first fill stuck in the queue
    Given the My assessments index is rebuilt
    And ad hoc tasks don't run
    When I run the command-line rebuild
    Then it should finish with every course done
    And no first fill should be queued

  Scenario: Rebuild starts the first fill again from the first course
    Given every My assessments index run has a time limit of 1 course
    And ad hoc tasks don't run
    And the My assessments index is rebuilt
    And the hourly index task runs at "10:00"
    When the My assessments index is rebuilt
    And the hourly index task runs at "11:00"
    Then the first fill should have done "M1" only
