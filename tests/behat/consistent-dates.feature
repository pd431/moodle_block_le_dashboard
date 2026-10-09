@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Day counts and dates agree, in the viewer's own time zone
  In order not to do time zone arithmetic in my head
  As a student studying away from the university's time zone
  I need "Due in", "You can still submit" and every date the block writes to read as if the server were where I am

  # Pierre, 2026-10-09: "Do you compute the "in X days" by taking timezone into account? That's important that
  # they're consistent." and "to the user in china, appart from the UTC tooltip, all times, calculations, in N days,
  # all of it should look like the server was in china. a user shouldn't be concerned about where they are and
  # having to do mental arithmetics as a result."
  #
  # Approved by Pierre, 2026-10-09 (beta23 scope, item 8):
  # - Under 24 hours, time left stays in hours and minutes ("Due in 16 h"). That's the same in every zone.
  # - From 24 hours, the count is calendar days between today and the deadline's date, in the zone the date is
  #   shown in: "Due tomorrow", "Due in 2 days". The server counts in the Moodle zone; the page recounts in the
  #   browser's zone when it shows local dates. "You can still submit" follows the same rule.
  # - Every date the block writes, inside text too (a Mitigate reason, the staff extensions hint, "Updated ..."),
  #   is shown in the same zone as the row dates. Only the globe note keeps the Moodle time.
  # - "It is now" sets the time the dashboard is drawn at, so the calendar is fixed (12 October 2026 is a Monday).

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname | timezone      |
      | student1 | Sam       | Student  | Europe/London |
      | teacher1 | Tia       | Teacher  | Europe/London |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | teacher1 | M1     | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario Outline: "Due in" counts the calendar days to the date shown
    Given a summative "assign" "A1" in "M1" is due "<due>" in "Europe/London"
    And the My assessments index is up to date
    And it is now "<now>" in "Europe/London"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "<note>"

    Examples:
      | now                        | due                         | note          |
      | Mon 12 October 2026 20:00  | Wed 14 October 2026 12:00   | Due in 2 days |
      | Mon 12 October 2026 08:00  | Tue 13 October 2026 12:00   | Due tomorrow  |
      | Mon 12 October 2026 20:00  | Tue 13 October 2026 12:00   | Due in 16 h   |
      | Mon 12 October 2026 11:15  | Mon 12 October 2026 12:00   | Due in 45 min |

  @javascript
  Scenario: The count follows the browser's zone when the dates do
    Given a summative "assign" "A1" in "M1" is due "Thu 15 October 2026 12:00" in "Europe/London"
    And the My assessments index is up to date
    And it is now "Tue 13 October 2026 20:00" in "Europe/London"
    And my browser's time zone is "Asia/Tokyo"
    When I log in as "student1" and view my dashboard
    # 15 Oct 20:00 in Tokyo, and it's already 14 Oct 04:00 there.
    Then the "Due" date of "A1" should read "15 Oct 2026, 20:00"
    And "A1" should show "Due tomorrow"

  @javascript
  Scenario: "You can still submit" counts days in the browser's zone too
    Given a summative "assign" "A1" in "M1" with a cut-off "Wed 14 October 2026 01:00 Europe/London" was due "Mon 12 October 2026 10:00 Europe/London"
    And the My assessments index is up to date
    And it is now "Mon 12 October 2026 20:00" in "Europe/London"
    And my browser's time zone is "America/New_York"
    When I log in as "student1" and view my dashboard
    # 13 Oct 20:00 in New York, where it's 12 Oct 15:00: tomorrow. In London it's two calendar days away.
    Then "A1" should show "You can still submit until tomorrow"

  Scenario: Without the browser's help the count is in the Moodle zone
    Given a summative "assign" "A1" in "M1" with a cut-off "Wed 14 October 2026 01:00 Europe/London" was due "Mon 12 October 2026 10:00 Europe/London"
    And the My assessments index is up to date
    And it is now "Mon 12 October 2026 20:00" in "Europe/London"
    When I log in as "student1" and view my dashboard
    Then "A1" should show "You can still submit for 2 days"

  @javascript
  Scenario: Dates inside a hint are in the same zone as the row dates
    Given a summative "assign" "A1" in "M1" is due "Mon 12 October 2026 12:00" in "Europe/London"
    And "student1" has an extension on "A1" to "Wed 21 October 2026 12:00 Europe/London"
    And the My assessments index is up to date
    And it is now "Tue 13 October 2026 09:00" in "Europe/London"
    And my browser's time zone is "America/New_York"
    When I log in as "teacher1" and view my dashboard
    And I click on the extension hint of "A1"
    Then the "extensions" popover of "A1" should say "1 student has an extension" then "The latest extended deadline is 21 Oct 2026, 07:00."

  @javascript
  Scenario: The Updated line is in the same zone as the row dates
    Given a summative "assign" "A1" in "M1" is due "Wed 14 October 2026 12:00" in "Europe/London"
    And the My assessments index is up to date
    And it is now "Fri 16 October 2026 09:00" in "Europe/London"
    And the My assessments index was last updated "Tue 13 October 2026 23:30 Europe/London"
    And my browser's time zone is "Asia/Tokyo"
    When I log in as "student1" and view my dashboard
    Then I should see "Updated 14 Oct 2026, 07:30" in the "My assessments" block
