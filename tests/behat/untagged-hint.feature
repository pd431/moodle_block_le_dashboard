@block @block_le_dashboard
Feature: Module convenors see which compatible activities are not tagged
  In order to tag every assessment that should be on the dashboard
  As a module convenor
  I need a quiet line that tells me how many compatible activities are untagged, and where they are

  # Pierre, 2026-10-06 22:15: "add a hint somewhere visible but unobtrusive that expands to tell a modcon
  # 'you have 34 compatible but untagged activities across your <year filter> courses.' Guidance on tagging
  # activities link. List of modules with dropdown, default collapsed, and hyperlinked untagged activities
  # in each". Coordinator defaults, 22:15.
  #
  # As built:
  # - Module convenor view only. It is one collapsed line under the control bar, in muted text with a
  #   tag icon: "You have 34 compatible but untagged activities across your 2026/27 courses." With All
  #   years it reads "across your courses". When the count is 0 the line is hidden.
  # - Expanded, it shows a "Guidance on tagging activities" link, from the new setting "Tagging guidance
  #   link" under Tags. With the setting empty there is no link. Below the link is one collapsed line per
  #   course, with its full name and count. Each opens to its untagged activities, which link to the
  #   activity.
  # - Compatible means an activity type ticked in Activity types that has a grade item, is not tagged
  #   with a summative or formative tag, and is not being deleted. Hidden activities are listed with an
  #   eye-slash and "Hidden from students", as the hidden flag does.
  # - It follows the year picker, using the same rule as the view's courses. A new year reloads the view,
  #   so the line updates with it.
  # - Native details/summary: no script. It opens and closes by click, Enter or Space.
  # - The list is computed with two indexed queries over the convenor's courses (4 ms for 10 courses, 10 ms for 40
  #   and 240 ms for 1,000 on a 90,000-activity MariaDB site), and cached per user and year
  #   for 5 minutes. Tagging an activity therefore drops it from the list within 5 minutes, or at once
  #   on Refresh.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | startdate      |
      | Module 1 | M1        | ##1 Oct 2026## |
      | Module 2 | M2        | ##1 Oct 2026## |
      | Old 1    | OLD       | ##1 Oct 2024## |
    And the following "users" exist:
      | username  | firstname | lastname |
      | student1  | Sam       | Student  |
      | teacher1  | Tim       | Teacher  |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | teacher1  | M1     | editingteacher |
      | convenor1 | M1     | moduleconvenor |
      | convenor1 | M2     | moduleconvenor |
      | convenor1 | OLD    | moduleconvenor |
    And the academic year "2026/27" starts on "1 September 2026"
    And the academic year "2024/25" starts on "1 September 2024"
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block treats "moduleconvenor" as a module convenor role
    And the following "activities" exist:
      | activity | name       | course | grade |
      | assign   | Essay      | M1     | 100   |
      | quiz     | Practice   | M1     | 10    |
      | assign   | Report     | M2     | 100   |
      | assign   | Old essay  | OLD    | 100   |
      | page     | Reading    | M1     |       |
    And a summative "assign" "Tagged" in "M1" is due "tomorrow"

  @javascript
  Scenario: The convenor sees how many compatible activities are untagged in the selected year
    When I log in as "convenor1" and view the "Module convenor view" for academic year "2026/27"
    Then I should see "You have 3 compatible but untagged activities across your 2026/27 courses." in the "My assessments" block
    And I should not see "Tagged" in the untagged activities list
    And I should not see "Reading" in the untagged activities list

  @javascript
  Scenario: The count follows the year picker
    When I log in as "convenor1" and view the "Module convenor view" for academic year "2024/25"
    Then I should see "You have 1 compatible but untagged activities across your 2024/25 courses." in the "My assessments" block
    When I set the field "Academic year" to "All years"
    Then I should see "You have 4 compatible but untagged activities across your courses." in the "My assessments" block

  @javascript
  Scenario: The list is collapsed until opened, and each course opens to its activities as links
    Given the My assessments tagging guidance link is "https://example.com/tagging"
    When I log in as "convenor1" and view the "Module convenor view" for academic year "2026/27"
    Then the untagged activities list should be collapsed
    When I click on "You have 3 compatible but untagged activities across your 2026/27 courses." "text" in the "My Assessments" "block"
    Then I should see "Guidance on tagging activities" linking to "https://example.com/tagging"
    And I should see "Module 1 (2)" in the untagged activities list
    And I should see "Module 2 (1)" in the untagged activities list
    And the untagged activities of "Module 1" should be collapsed
    When I click on "Module 1 (2)" "text" in the "My Assessments" "block"
    Then I should see "Essay" linking to the page of "Essay"
    And I should see "Practice" linking to the page of "Practice"
    When I click on "You have 3 compatible but untagged activities across your 2026/27 courses." "text" in the "My Assessments" "block"
    Then the untagged activities list should be collapsed

  @javascript
  Scenario: Hidden activities are listed and flagged as hidden
    Given "Essay" is hidden from students
    When I log in as "convenor1" and view the "Module convenor view" for academic year "2026/27"
    Then "Essay" in the untagged activities list should be marked "Hidden from students"

  @javascript
  Scenario: With nothing untagged, the line is hidden
    Given every compatible activity in "M1", "M2" and "OLD" is tagged "formative"
    When I log in as "convenor1" and view the "Module convenor view" for academic year "All years"
    Then I should not see "compatible but untagged" in the "My assessments" block

  @javascript
  Scenario: Activity types not ticked in Activity types don't count
    Given the "quiz" activity type is not enabled in My assessments
    When I log in as "convenor1" and view the "Module convenor view" for academic year "2026/27"
    Then I should see "You have 2 compatible but untagged activities across your 2026/27 courses." in the "My assessments" block

  @javascript
  Scenario: Markers and students never see it
    When I log in as "teacher1" and view the "My marking"
    Then I should not see "compatible but untagged" in the "My assessments" block
    When I log in as "student1" and view my dashboard
    Then I should not see "compatible but untagged" in the "My assessments" block

  @javascript
  Scenario: With no guidance link set, the list has no guidance link
    When I log in as "convenor1" and view the "Module convenor view" for academic year "2026/27"
    Then I should not see "Guidance on tagging activities" in the "My assessments" block
