@block @block_le_dashboard
Feature: Admins can turn each optional hint off, on a Hints and guidance page with neutral defaults
  In order to show only the hints and policy text that fit my institution
  As a site administrator
  I need a switch for each optional hint, guidance beside it, and defaults that assume nothing (beta27 hints audit)

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username  | firstname | lastname | maildisplay |
      | student1  | Sam       | Student  | 1           |
      | student2  | Sue       | Student  | 1           |
      | marker1   | Mo        | Marker   | 1           |
      | marker2   | Max       | Hidden   | 0           |
      | convenor1 | Cat       | Convenor | 1           |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | student2  | M1     | student        |
      | marker1   | M1     | teacher        |
      | marker2   | M1     | teacher        |
      | convenor1 | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role

  # 1. The page

  Scenario: Hints and guidance is its own settings page
    Given I log in as "admin"
    When I navigate to "Plugins > Blocks > Le Dashboard > Hints and guidance" in site administration
    Then I should see "Late clock"
    And I should see "Academic conduct flags"
    And I should see "Generic assessment name"
    And I should see "Untagged activities"
    And I should see "Late penalties guidance"
    And I should see "Show marking deadlines to students"
    And I should not see "Status pills"

  Scenario: A new site starts with conduct flags, No brief and generic names off, and marking deadlines not shown
    Given I log in as "admin"
    When I navigate to "Plugins > Blocks > Le Dashboard > Hints and guidance" in site administration
    Then the field "Academic conduct flags" matches value "0"
    And the field "No brief" matches value "0"
    And the field "Generic assessment name" matches value "0"
    And the field "Late clock" matches value "1"
    And the field "Show marking deadlines to students" matches value "Not shown"
    And the field "Delay before feedback is expected" matches value "2"

  # 2. Each switch removes its hint, and only its hint

  Scenario Outline: Turning a staff hint off removes it, and only it
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "A1" is hidden from students
    And "student1" has submitted "A1"
    And "student2" has an extension on "A1" to "in 2 days"
    And the following config values are set as admin:
      | <key> | 0 | block_le_dashboard |
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should not have a "<hint>" hint
    And "A1" should have a "<other>" hint

    Examples:
      | key             | hint       | other      |
      | hint_hidden     | hidden     | extensions |
      | hint_extensions | extensions | hidden     |

  Scenario: Turning the unallocated hint off removes it from My marking
    Given the following "course enrolments" exist:
      | user      | course | role    |
      | convenor1 | M1     | teacher |
    And a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"
    And "student1" has submitted "A1"
    And the submission of "student1" for "A1" is not allocated
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    Then "A1" should have a "unallocated" hint
    And the following config values are set as admin:
      | hint_unallocated | 0 | block_le_dashboard |
    And I reload the page
    And "A1" should not have a "unallocated" hint

  Scenario: Turning the Check settings flag off leaves the convenor's checklist line
    Given a summative "assign" "A1" in "M1" with no due date exists
    And the following config values are set as admin:
      | hint_setup | 0 | block_le_dashboard |
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should not have a "setup" hint
    And I should see "1 tagged activity needs its settings checked" in the "My assessments" block

  @mod_coursework
  Scenario: Academic conduct flags show only once switched on
    Given a summative "coursework" "C1" in "M1" was due "3 days ago"
    And "student1" has submitted "C1"
    And the submission of "student1" for "C1" is flagged for plagiarism
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "C1" should not have a "plagiarism" hint
    And the following config values are set as admin:
      | hint_plagiarism | 1 | block_le_dashboard |
    And I reload the page
    And "C1" should have a "plagiarism" hint

  Scenario: No brief shows only once switched on
    Given a summative "assign" "B1" in "M1" with no description is due "tomorrow"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "B1" should not have a "brief" hint
    And the following config values are set as admin:
      | hint_brief | 1 | block_le_dashboard |
    And I reload the page
    And "B1" should have a "brief" hint

  Scenario: Turning the late clock off keeps the late mark on the activity icon
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    And the following config values are set as admin:
      | hint_late | 0 | block_le_dashboard |
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should not have a "late" hint
    And the activity icon of "A1" should have the "late" modifier

  Scenario: Turning the no online submission hint off removes it for students and staff
    Given a summative "assign" "P1" in "M1" with no online submission is due "tomorrow"
    And the following config values are set as admin:
      | hint_nosubmission | 0 | block_le_dashboard |
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "P1" should not have a "nosubmission" hint
    # In the Convenor view: My marking has no Upcoming section since beta28.
    And I log in as "convenor1" and view the "Convenor view"
    And "P1" should not have a "nosubmission" hint

  Scenario: Turning the unmarked after the marking deadline line off
    Given a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then "A1" should show "unmarked after the marking deadline"
    And the following config values are set as admin:
      | hint_unmarkedlate | 0 | block_le_dashboard |
    And I reload the page
    And "A1" should not show "unmarked after the marking deadline"

  @javascript
  Scenario: With hover popovers off, a hint opens only on click or keyboard focus
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "A1" is hidden from students
    And the following config values are set as admin:
      | hoverpopovers | 0 | block_le_dashboard |
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    And I hover over the "hidden" flag of "A1" for "1000" milliseconds
    Then the "hidden" popover of "A1" should not be visible
    And I click on the hidden hint of "A1"
    And the "hidden" popover of "A1" should be visible

  # 3. Feedback expected date (Pierre, 2026-10-19: as set, with a delay, or not at all)

  Scenario: A new site doesn't show marking deadlines to students
    Given a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should not show "Feedback expected"
    And "A1" should not have a "feedbackcontact" hint

  Scenario: "As set" shows the marking deadline, and the question once it has passed
    Given the following config values are set as admin:
      | feedbackdatemode | asset | block_le_dashboard |
    And a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected" with the date "2 days ago"
    And "A1" should have a "feedbackcontact" hint

  Scenario: "With a delay" adds the delay, and the question waits for the delayed date
    Given the following config values are set as admin:
      | feedbackdatemode  | delayed | block_le_dashboard |
      | feedbackdatedelay | 7       | block_le_dashboard |
    And a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected" with the date "in 5 days"
    And "A1" should not have a "feedbackcontact" hint

  @mod_coursework
  Scenario: The setting covers coursework's marking deadline too
    Given the following config values are set as admin:
      | feedbackdatemode | none | block_le_dashboard |
    And a summative "coursework" "C1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"
    And "student1" has submitted "C1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "C1" should not show "Feedback expected"
    And "C1" should not have a "feedbackcontact" hint

  Scenario: Staff see the internal marking deadline whatever students are shown
    Given the following config values are set as admin:
      | feedbackdatemode | none | block_le_dashboard |
    And a summative "assign" "A1" in "M1" with a marking deadline "in 2 days" was due "10 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "marker1" and view the "My marking"
    Then "A1" should show "Marking deadline"

  Scenario: The default marking period gives students a date where none was set
    Given the following config values are set as admin:
      | feedbackdatemode   | delayed | block_le_dashboard |
      | feedbackdatedelay  | 7       | block_le_dashboard |
      | defaultmarkingdays | 12      | block_le_dashboard |
    And a summative "assign" "A1" in "M1" was due "10 days ago"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should show "Feedback expected" with the date "in 9 days"

  # 4. Neutral guidance defaults

  Scenario: On a new site the late clock says how late, with no penalty rule
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "late" hint of "A1" should be titled "Submitted 1 day late."
    And I should not see "Late penalties may apply" in the "My assessments" block
    And I should not see "10%" in the "My assessments" block

  Scenario: A site that sets its own late penalties text gets the penalty line back
    Given the following config values are set as admin:
      | guidance_latepenalty | <p>Our late penalty policy.</p> | block_le_dashboard |
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "late" hint of "A1" should be titled "Submitted 1 day late. Late penalties may apply."

  Scenario: An upgraded site that never saved its settings keeps today's text and hints
    Given the My assessments guidance settings were never saved before the beta27 upgrade
    When the site was upgraded from before the hint switches existed
    Then the "guidance_latepenalty" setting should contain "Work up to 10 minutes late may have 10% taken off its mark."
    And the "guidance_finalised" setting should contain "contact your Info Point"
    And the "feedbackdatemode" setting should be "asset"
    And the "hint_plagiarism" setting should be "1"
    And the "genericnames" setting should be "1"

  # 5. Marker email links

  # Moodle shows a hidden email to those with moodle/course:useremail, or with moodle/site:viewuseridentity where email
  # is an identity field (the default). Editing teachers have both, so this convenor role is denied them here.
  Scenario: A marker who hides their email is shown as a plain name
    Given the following "permission overrides" exist:
      | capability                   | permission | role           | contextlevel | reference |
      | moodle/course:useremail      | Prevent    | moduleconvenor | Course       | M1        |
      | moodle/site:viewuseridentity | Prevent    | moduleconvenor | Course       | M1        |
    And a summative "assign" "A1" in "M1" was due "10 days ago"
    And "student1" and "student2" have submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And "marker2" marks the submission of "student2" for "A1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then the marker "Mo Marker" of "A1" should link to their email
    And the marker "Max Hidden" of "A1" should not link to their email

  Scenario: A convenor allowed to see hidden emails gets every marker's link, as on their profile
    Given a summative "assign" "A1" in "M1" was due "10 days ago"
    And "student1" and "student2" have submitted "A1"
    And "marker2" marks the submission of "student2" for "A1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then the marker "Max Hidden" of "A1" should link to their email

  Scenario: Turning marker email links off shows every marker as a plain name
    Given the following config values are set as admin:
      | hint_markeremail | 0 | block_le_dashboard |
    And a summative "assign" "A1" in "M1" was due "10 days ago"
    And "student1" has submitted "A1"
    And "marker1" marks the submission of "student1" for "A1"
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "Module convenor view"
    Then the marker "Mo Marker" of "A1" should not link to their email

  # 6. Late tolerance

  Scenario Outline: Work within the late tolerance is not late
    Given the following config values are set as admin:
      | latetolerance | 5 | block_le_dashboard |
    And a summative "assign" "A1" in "M1" was due "20 minutes ago"
    And "student1" submitted "A1" "<when>"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then "A1" should <have> a "late" hint
    And the activity icon of "A1" should <have> the "late" modifier

    Examples:
      | when           | have     |
      | 17 minutes ago | not have |
      | 10 minutes ago | have     |
