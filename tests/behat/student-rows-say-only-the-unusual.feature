@block @block_le_dashboard
Feature: Finished work goes to Past, and student rows say only what is unusual
  In order to see at a glance what is different about my work
  As a student, marker or module convenor
  I need finished work in Past, no pills or icons for the ordinary case, and plain help with late penalties

  # From Pierre's message of 2026-10-04 16:31 (beta11 flag 3 and the student row feedback). Approved by the
  # coordinator for beta12, on conditions: the Mitigate orange is the darkest step that still reads as orange
  # and reaches 3:1 and Lc 45 on white (#bd7300: 3.7:1, Lc 64.5); the red clock is for the Overdue icon only.
  # Choices made where the message leaves room (marked CHOICE):
  # - Finished means: My marking, all my units marked; convenor, something submitted and everything marked and
  #   released; student, a grade released. An undated assessment with nothing submitted stays Undated.
  # - Ordinary-case pills removed: "Not submitted yet", generic "Due" and "Open". Kept: Submitted, Received,
  #   In marking, Feedback released, Not submitted and Closed (these say something happened). Beta17 drops
  #   Resubmission open (reopened-quiet.feature).
  # - Ordinary-case icons removed: the on-time "submitted" tick. Kept: the not-submitted cross in Past (it is the
  #   exception there, and EC10 relies on it).
  # - Late is a clock. That frees nothing for due soon, so due soon moves to fa-hourglass-half.
  # - "Same colour base" for Mitigate: the Mitigate button's tone (attention) for the self-certification and
  #   extension icons too, instead of info today.
  # - The late penalty popover: an info hint next to the grade once released; before release it sits after the
  #   "Submitted 2 hours late" line, so the line under the dates no longer repeats the note. The text and a link
  #   are settings, as the late note already is. Default text (Pierre's, tidied; edit in settings):
  #   "Work up to 10 minutes late may have 10% taken off its mark. Work up to a day late is capped at the pass mark."
  #   The link has no default address; with none set, there is no link.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cara      | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | marker1  | M1     | teacher        |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block keeps its hints and guidance from before beta27

  # ---------- Finished undated work goes to Past (Pierre: "finished work, no matter the view, can move to past") ----------

  @javascript
  Scenario: A student's undated work, once handed in, is Received until it has a grade
    Given a summative "assign" "A1" in "M1" with no due date exists
    And "student1" has submitted "A1"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Undated" section
    And "A1" should show "Received"
    And "A1" should not have a "received" hint

  # ---------- No pills for the ordinary case ----------

  # ---------- Modifiers: only the unusual, side by side ----------

  @javascript
  Scenario: Overdue work carries a red clock
    Given a summative "assign" "A1" in "M1" was due "2 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Overdue" section
    And the activity icon of "A1" should have the "overdue" modifier
    And the "overdue" modifier of "A1" should be a "fa-clock" icon in the "danger" tone

  @javascript
  Scenario: Work handed in late carries a grey clock in Past
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should appear in the "Past" section
    And the "late" modifier of "A1" should be a "fa-clock" icon in the "grey" tone

  @javascript
  Scenario: Two modifiers sit side by side
    Given a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has an extension on "A1" to "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    When I log in as "student1" and view my dashboard
    Then the activity icon of "A1" should have the "extension" modifier
    And the activity icon of "A1" should have the "late" modifier
    And the modifiers of "A1" should sit side by side

  # Mitigate needs block_rhb's links: without block_rhb, a stand-in gives one.
  @block_rhb @javascript
  Scenario: Every Mitigate icon shares the Mitigate colour
    Given a summative "assign" "A1" in "M1" is due "in 2 days"
    And a summative "assign" "A2" in "M1" was due "5 days ago"
    And "student1" has an extension on "A2" to "in 3 days"
    When I log in as "student1" and view my dashboard
    Then the Mitigate button of "A1" should show a "fa-hand-holding-heart" icon in the "attention" tone
    And the "extension" modifier of "A2" should be a "fa-calendar-plus" icon in the "attention" tone

  @javascript
  Scenario: Due soon keeps its own icon, distinct from late
    Given a summative "assign" "A1" in "M1" is due "in 2 days"
    When I log in as "student1" and view my dashboard
    Then the "duesoon" modifier of "A1" should be a "fa-hourglass-half" icon in the "attention" tone

  # ---------- Late penalties next to the mark ----------

  @javascript
  Scenario: A late mark has a popover explaining late penalties
    Given the My assessments late penalty link is "https://example.com/late"
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    And "marker1" marks the submission of "student1" for "A1"
    And the grade of "student1" for "A1" is released
    When I log in as "student1" and view my dashboard
    And I press "Show grades"
    Then the late penalty hint of "A1" should sit next to its grade
    When I click on the late penalty hint of "A1"
    Then I should see "Work up to 10 minutes late may have 10% taken off its mark." in the "My assessments" block
    And I should see "late penalties" linking to "https://example.com/late"

  Scenario: Before release the late clock sits beside the In marking pill
    Given a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    When I log in as "student1" and view my dashboard
    Then "A1" should not show "Submitted 1 day late"
    And the "late" icon of "A1" should sit next to its "In marking" pill
