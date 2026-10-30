@block @block_le_dashboard @javascript
Feature: A student's provisional grade never splits between the number and its percent sign
  In order to read my grade at a glance
  As a student
  I need "Provisional grade 100 %" on one line, or broken only between the label and the grade

  # Pierre, 2026-10-29 09:28: "We switched the site's default 'Grade display type' to percent, and rename grade to
  # 'provisional grade'."
  # Pierre, 09:31 (screenshot: "Provisional Grade 100" then "%" alone on the next line): "we're just slightly over
  # the column width that would allow for the thing to be on one line. Can you fix it, and add a test that a
  # Provisional grade 100% either fits on one line, or breaks at the mark: Provisional grade / 100 %".
  #
  # Cause:
  # - row.mustache:96 writes the label and the grade as "{{#str}}grade{{/str}} <strong>{{text}}</strong>" in a
  #   block that wraps like any text. The grade text comes from Moodle's grade_format_gradevalue(), which for
  #   percentages is "100.00 %" or "100 %" with an ordinary space, so the browser may break inside the grade.
  # - The exact grade text ("100.00 %" or "100 %") follows the course's overall decimal places; the scenarios
  #   use Moodle's default of 2, the worst case for width.
  # - The student view's status column is 8.5rem wide (styles.css:671). "Provisional grade 100 %" with the grade
  #   in bold is about 9.7rem, so it always wraps there, and today it wraps at the last space it can: before "%".
  #
  # Where it lives:
  # - The markup: templates/row.mustache, the grade line only. The label goes in <span class="ma-grade-label">
  #   and the grade keeps its <strong>, gaining class ma-grade-value. The hint partial stays after the grade.
  # - The rule: styles.css, next to the existing .ma-grade rules (line 454): .ma-grade-value { white-space: nowrap; }.
  #   One declaration. The label keeps normal wrapping, so a long customised label still fits a narrow column.
  # - The label: lang/en string 'grade' becomes 'Provisional grade' (sentence case, as Moodle strings are). A
  #   site's own language customisation still wins, so ele's "Provisional Grade" is unaffected.
  # - The test steps: behat_block_le_dashboard.php, two new @Then beside "the modifiers of ... should sit side by
  #   side" (line 1613), which measures rows the same way: "the grade line of X should read Y" (label and grade
  #   text) and "the grade line of X should be on one line or break only before the grade" (the label's text
  #   and the grade each sit on a single line box, by Range/getClientRects; the grade either shares the label's
  #   last line or starts the next). One fixture step for the display type, "the grade display type is X",
  #   setting grade_displaytype and re-indexing, since the display is stored at index time.
  #
  # Defaults (flag any you disagree with):
  # - No change to column widths. Widening the student status column by about 1.25rem would put it on one line at
  #   desktop width, at the cost of the assessment name column on every row, and would break again for a
  #   two-decimal "100.00 %" or a longer label. The nowrap guarantees the break Pierre allows whatever the width.
  # - "Provisional grade" applies to every released grade in the student view, formative included, since the
  #   string is shared. Staff views don't show grades.
  # - Commits: 1 grade line keeps the grade whole (markup, CSS, step, this feature); 2 label 'Provisional grade';
  #   3 version bump. Target release: 5.0.0-beta32.
  #
  # Scope: /mnt/project-files/remake/scopes/grade-line-wrap.feature (beta32).
  # Adjusted while building:
  # - On Boost in Behat the status column is about 184px wide, so "Provisional grade 100.00 %" fits at every size and
  #   the outline passes without the style. The break is reproduced by the "Real (percentage)" scenario: without the
  #   style its grade wraps inside "(100.00 %)".
  # - The rule lives in scss/styles.scss and styles.css is compiled from it.
  #
  # Tests: these scenarios run everywhere (assign only), and the "large" example must fail before commit 1's CSS
  # (proving the step catches the "%" on its own line). No PHPUnit change: nothing in PHP changes. The golden
  # master HTML changes (one span, one class) and is regenerated in commit 1.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | marker1  | Mo        | Marker   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | marker1  | M1     | editingteacher |
    And the grade display type is "Percentage"
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And a summative "assign" "A1" in "M1" was due "5 days ago"
    And "student1" has submitted "A1"
    And "marker1" grades "student1" on "A1" with "100"
    And the grade of "student1" for "A1" is released
    And the My assessments index is up to date

  Scenario Outline: The grade line is whole, or broken only between the label and the grade
    Given I change window size to "<size>"
    When I log in as "student1" and view my dashboard
    And I press "Show grades"
    Then the grade line of "A1" should read "Provisional grade 100.00 %"
    And the grade line of "A1" should be on one line or break only before the grade

    Examples:
      | size   |
      | large  |
      | medium |
      | mobile |

  Scenario: A two-decimal real grade with its percentage stays whole too
    Given the grade display type is "Real (percentage)"
    When I log in as "student1" and view my dashboard
    And I press "Show grades"
    Then the grade line of "A1" should read "Provisional grade 100.00 (100.00 %)"
    And the grade line of "A1" should be on one line or break only before the grade
