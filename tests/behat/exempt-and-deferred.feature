@block @block_le_dashboard
Feature: An exempt or deferred student owes nothing and leaves the counts
  In order that marking counts and a student's dashboard leave out work that is not expected of them
  As a marker, module convenor or student
  I need coursework's permanent and temporary mitigations read as exempt and deferred

  # Pierre, 2026-10-28 14:26: "temporary and permanent can be treated as complete". 14:46: "For students, exempt
  # and deferred assessments can have a new 'exempt' status. keep the date rules. remove all buttons." 15:20: "for
  # staff view, I think permanent and temp mitigations can drop off the denominator altogether."
  # (coursework-flags-and-mitigations.feature; DECISIONS CS4.)
  # - Staff: the student leaves the Submitted counts (stu.expected = 0) and every marking count (counted = 0),
  #   with or without a submission, and is in no hint.
  # - Student: "Exempt" by the date alone (Upcoming, then Past, never Overdue), one icon for both types whose
  #   tooltip says a mitigation applies, and no actions at all.
  # - An exemption or deferral wins over an extension.
  # These run only where mod_coursework is installed (ele-test); views_test and classifier_test cover the same rules
  # on every target.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
      | student3 | Sid       | Student  |
      | marker1  | Mo        | Marker   |
      | convenor | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | M1     | student        |
      | student2 | M1     | student        |
      | student3 | M1     | student        |
      | marker1  | M1     | editingteacher |
      | convenor | M1     | moduleconvenor |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And a summative "coursework" "C1" in "M1" was due "2 days ago"
    And "student1" has submitted "C1"
    And "student2" has submitted "C1"

  @mod_coursework @javascript
  Scenario: The exemption's icon says why
    Given "student3" has a "permanent" mitigation on "C1"
    When I log in as "student3" and view my dashboard
    And I click on the activity icon of "C1"
    Then the activity icon tooltip of "C1" should say "Coursework. You don't need to complete this assessment: a mitigation applies. Contact your Info Point if that's unexpected."
