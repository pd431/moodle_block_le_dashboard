@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: An admin can purge and rebuild the dashboard's index
  In order to test changes back and forth without stale data
  As a site administrator
  I need one place to empty the index and fill it again from scratch

  # Pierre, 2026-10-06: "a way to purge and rebuild the cache table, either as a scheduled task or a
  # setting in admin". Proposed: an admin page (Plugins > Blocks > Le Dashboard > Index) with the row
  # counts and a Rebuild button, plus a CLI script for doing it straight away. The rebuild runs as the
  # existing backfill ad hoc task, so a large site isn't held up by one web request.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" is due "tomorrow"
    And the My assessments index is up to date

  Scenario: The index page shows what the index holds
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Index" in site administration
    Then I should see "Activities: 1"
    And I should see "Student rows: 1"
    And I should see "Rebuild index"

  Scenario: Rebuilding asks for confirmation first
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Index" in site administration
    And I press "Rebuild index"
    Then I should see "This empties the index and fills it again"
    When I press "Cancel"
    Then I should see "Activities: 1"

  Scenario: A rebuild drops rows the index should no longer hold and fills it again
    Given the index holds a row for an activity that no longer exists
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Index" in site administration
    And I press "Rebuild index"
    And I press "Continue"
    Then I should see "The index has been emptied. It fills again when the rebuild task runs."
    And I should see "Activities: 0"
    When the rebuild task runs
    And I navigate to "Plugins > Blocks > Le Dashboard > Index" in site administration
    Then I should see "Activities: 1"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Upcoming" section

  Scenario: Students see the Preparing note while the rebuild is pending
    Given an admin has rebuilt the index and the rebuild task has not run yet
    When I log in as "student1" and view my dashboard
    Then I should see "Preparing your assessments"

  # Only site admins can open the page or rebuild: it is gated on moodle/site:config, and the rebuild
  # needs a POST with the sesskey. Behat can't open a denied page without failing on the error, so
  # PHPUnit checks this (indexer_test::test_rebuild_page_needs_site_config).

  # Not Behat: cli/rebuild.php empties the index and rebuilds it in the same run (PHPUnit covers the
  # purge and refill). Usage: php blocks/le_dashboard/cli/rebuild.php [--now].
