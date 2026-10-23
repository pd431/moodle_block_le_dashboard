@block @block_le_dashboard @mod_coursework
Feature: Coursework is read the way Exeter's mod_coursework actually stores it
  In order that coursework rows match what students and markers see in coursework itself
  As a student, marker or module convenor
  I need submissions, marks and releases read from the fields coursework really relies on

  # Pierre, 2026-10-07 16:01, the schema of Exeter's mod_coursework:
  # - coursework_submissions.finalised: "many submissions stay finalised = 0 and still get graded, so this is
  #   not a reliable 'has been submitted' filter". A submission row with a submission time, or with a mark,
  #   counts as handed in; finalised = 1 only means the student can't change it any more (A8 is reversed).
  # - firstpublished > 0 means feedback was released to the student (lastpublished kept as a fallback).
  # - A mark is a feedback row with finalised = 1 and a grade that isn't blank (grade is text).
  # - Groups: the setting is use_groups (beta17 read usegroups, so group courseworks were treated as individual).
  # - Moderator allocations and feedback may carry stage moderator_1: any moderator stage counts as moderation.
  # - Attendance: coursework has no submission type field; courseworktype is read instead. Its values are
  #   unconfirmed: "attendance" (any case) is assumed until Pierre confirms.
  # - Plagiarism flags are read only where plagiarismflagenabled is on.
  # These run only where mod_coursework is installed; PHPUnit covers the same rules with stand-in tables.

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
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: A draft that was never finalised is still marked and counts as handed in
    Given a summative "coursework" "C1" in "M1" was due "5 days ago"
    And "student1" has saved a draft of "C1"
    When I log in as "marker1" and view my dashboard
    Then "C1" should appear in the "Marking in progress" section with "1" to mark
    When I log in as "student1" and view my dashboard
    Then "C1" should appear in the "Past" section
    And "C1" should show the status "Awaiting feedback"

  # Pierre's export, 2026-10-08: 1 marker, moderation, allocation on, 276 of 277 marked and published, only
  # moderator allocation pairs. It read "Moderated 0 of 276" beside "Released to students", and "Unallocated
  # 277 of 277" (12 moderated through moderation agreements, 12 moderator pairs). Each stage now counts only
  # the work it still owes, and where moderators are allocated, moderation owes only that sample (12 of 12).
  # Allocation counts only once markers (not just moderators) are allocated.
