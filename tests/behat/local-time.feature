@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Dates show in the viewer's own time zone
  In order to know when work is really due where I am
  As someone using the dashboard away from the university's time zone
  I need dates in my local time, with a note giving the time the university set

  # Pierre, 2026-10-09: "If the client timezone is different to that of the server, display the local time
  # for deadlines and such, with a tooltip (fa-globe, or as an easter egg earth-americas, earth-oceania,
  # earth-europe or earth-africa)".
  #
  # What "server timezone" means here: Moodle draws dates in the user's own Moodle time zone, which is the
  # server's unless they changed it in their profile (or the admin forced one). We compare the browser's time
  # zone with that one, so a student who set their profile to their home zone sees no icons.
  #
  # Approved by Pierre, 2026-10-09. Defaults in the approved scope:
  # - Every date on a row changes, in every view (due, extended deadline, feedback expected, feedback received,
  #   marking deadline), not just due dates. The note's first word follows the date: "Deadline", "Date".
  # - The note gives the full date as well as the time, because converting can cross midnight
  #   ("23:30 on 14 Oct" in London is "00:30 on 15 Oct" in Paris).
  # - The icon follows the browser's zone: America/* earth-americas, Europe/* earth-europe, Africa/* earth-africa,
  #   Asia/* earth-asia (added: Font Awesome has it, and it covers most international students),
  #   Australia/* and Pacific/* earth-oceania, anything else (Etc/UTC, Atlantic/*, Indian/*) fa-globe.
  # - Only dates whose clock time actually differs change: Europe/Dublin against Europe/London shows nothing.
  # - The icon is neutral (no warning colour) and opens like the other hints: click, keyboard or hover.
  # - Without JavaScript the dates stay as Moodle drew them.
  # - Superseded by consistent-dates.feature (Pierre, 2026-10-09): the "Updated" line and dates inside text now
  #   follow the row dates.
  # - The browser's zone is set through Chrome DevTools (Emulation.setTimezoneOverride).
  # - The dates are fixed (14 October 2026) so the expected clock times hold; British Summer Time applies.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname | timezone      |
      | student1 | Sam       | Student  | Europe/London |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" is due "14 October 2026 13:30" in "Europe/London"
    And the My assessments index is up to date

  @javascript
  Scenario: The same time zone changes nothing
    Given my browser's time zone is "Europe/London"
    When I log in as "student1" and view my dashboard
    Then the "Due" date of "A1" should read "14 Oct 2026, 13:30"
    And "A1" should not have a time zone icon

  @javascript
  Scenario: Another time zone shows local time, with the university's time in the note
    Given my browser's time zone is "America/New_York"
    When I log in as "student1" and view my dashboard
    Then the "Due" date of "A1" should read "14 Oct 2026, 08:30"
    And the time zone icon of "A1" should be "earth-americas"
    When I click on the time zone icon of "A1"
    Then the time zone note of "A1" should say "Deadline is shown in your current time zone (America/New_York)."
    And the time zone note of "A1" should say "Europe/London deadline: 14 Oct 2026, 13:30"

  @javascript
  Scenario: Converting across midnight changes the date too
    Given a summative "assign" "A2" in "M1" is due "14 October 2026 23:30" in "Europe/London"
    And the My assessments index is up to date
    And my browser's time zone is "Asia/Tokyo"
    When I log in as "student1" and view my dashboard
    Then the "Due" date of "A2" should read "15 Oct 2026, 07:30"
    And the time zone icon of "A2" should be "earth-asia"

  @javascript
  Scenario Outline: The icon shows the viewer's part of the world
    Given my browser's time zone is "<zone>"
    When I log in as "student1" and view my dashboard
    Then the time zone icon of "A1" should be "<icon>"

    Examples:
      | zone             | icon           |
      | Europe/Paris     | earth-europe   |
      | Africa/Nairobi   | earth-africa   |
      | Australia/Perth  | earth-oceania  |
      | Pacific/Auckland | earth-oceania  |
      | Etc/UTC          | globe          |
      | Atlantic/Azores  | globe          |

  @javascript
  Scenario: A profile set to the viewer's own zone shows no icons
    Given the user "student1" has the time zone "America/New_York"
    And my browser's time zone is "America/New_York"
    When I log in as "student1" and view my dashboard
    Then the "Due" date of "A1" should read "14 Oct 2026, 08:30"
    And "A1" should not have a time zone icon

  @javascript
  Scenario: Feedback dates say "Date", not "Deadline"
    Given the following "users" exist:
      | username | firstname | lastname | timezone      |
      | marker1  | Mo        | Marker   | Europe/London |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | marker1 | M1     | editingteacher |
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    And my browser's time zone is "America/New_York"
    When I log in as "student1" and view my dashboard
    And I click on the time zone icon of the "Feedback received" date of "A1"
    Then the time zone note of "A1" should say "Date is shown in your current time zone (America/New_York)."

  @javascript
  Scenario: Staff views convert too
    Given the following "users" exist:
      | username | firstname | lastname | timezone      |
      | teacher1 | Tia       | Teacher  | Europe/London |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | M1     | editingteacher |
      | teacher1 | M1     | moduleconvenor |
    # A1's date is fixed, so draw the dashboard before it: the Convenor view lists it in Upcoming (My marking has no
    # Upcoming section since beta28).
    And it is now "Mon 12 October 2026 09:00" in "Europe/London"
    And my browser's time zone is "America/New_York"
    When I log in as "teacher1" and view the "Convenor view"
    Then the "Submission deadline" date of "A1" should read "14 Oct 2026, 08:30"
    And the time zone icon of "A1" should be "earth-americas"

  @javascript
  Scenario: Dates stay converted after Refresh
    Given my browser's time zone is "America/New_York"
    When I log in as "student1" and view my dashboard
    And I press "Refresh" in the "My assessments" block
    Then the "Due" date of "A1" should read "14 Oct 2026, 08:30"
    And the time zone icon of "A1" should be "earth-americas"
