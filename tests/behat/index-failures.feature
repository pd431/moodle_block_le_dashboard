@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: An assessment that can't be indexed still appears for staff, and says so
  In order that a convenor never loses an assessment because its data couldn't be read
  As a module convenor or marker
  I need the assessment listed with an honest note instead of missing or showing 0 of 0

  # Pierre, 2026-10-08: a large coursework "didn't show up for modcons". An assessment is missing from every view
  # when its first index never writes its row: the adapter fails before the row is built, or the process dies
  # (memory, time limit) before the write. Coordinator, 2026-10-08: write the row even when reading students
  # fails, record the error on the Activity types page, and make the row read honestly.
  # - The first index writes the assessment's row before reading any student, marked as not loaded. A process
  #   that dies part way leaves that row, not nothing.
  # - Any failure (the whole activity, every student, or the write) marks the row. A failed write is covered
  #   by PHPUnit (indexer_test), since the task that hits it fails and Behat stops on a failed task. A later successful index
  #   clears the mark.
  # - Staff rows with the mark carry a "Couldn't load student data" flag (attention tone, staff only). With no
  #   student data at all, the row says "Couldn't load student data" in place of the bars that would read 0 of 0;
  #   bars with real counts (marking read fine) stay.
  # - Students see nothing new: rows they would get come only from student data.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: An activity type that fails entirely still lists its assessments for the convenor
    Given indexing of "assign" activities fails with an error
    And a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "convenor" and view my dashboard
    Then "A1" should show "Couldn't load student data"
    And "A1" should have a "load error" hint
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Activity types" in site administration
    Then the "Assignment" activity type should show its last indexing error

  Scenario: Students see no error
    Given indexing of "assign" activities fails with an error
    And a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    Then I should not see "Couldn't load student data"
    And I should not see any error
