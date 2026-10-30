@block @block_le_dashboard @block_le_dashboard_crossdb
Feature: Guidance and support text in popovers is edited as rich text in the settings
  In order to point people to our own guidance and support, with links and formatting
  As a site administrator
  I need each popover's guidance in a rich-text box in a "Guidance and support" settings section

  # Pierre, 2026-10-09 (scope remake-scale/scopes/guidance-settings.feature, approved). Every popover that
  # should point to guidance or support takes its text from an HTML editor box. Each popover keeps its factual
  # first line, written by the block; the box's text shows under it as its own block, through format_text() in
  # the system context (filters apply, unsafe HTML is cleaned). An empty box adds nothing.
  # | box                   | who sees it           | popover                                   |
  # | guidance_latepenalty  | students              | late clock on summative work              |
  # | guidance_feedbacklate | students              | "Waiting for feedback?"                   |
  # | guidance_nosubmission | students              | "No online submission"                    |
  # | guidance_finalised    | students              | "Can't resubmit": finalised or locked     |
  # | guidance_attempts     | students              | "Can't resubmit": quiz attempts used      |
  # | guidance_brief        | convenors             | "No brief"                                |
  # | guidance_untagged     | convenors             | untagged activities list                  |
  # | guidance_setup        | convenors and markers | "Check settings", under the problems      |
  # | guidance_unallocated  | convenors and markers | "N unallocated"                           |
  # | guidance_plagiarism   | convenors and markers | "N flagged"                               |
  # | guidance_loaderror    | convenors and markers | "Couldn't load student data"              |
  # Defaults are today's wording. Sentences that used to continue the factual line ("If it doesn't, contact
  # ...") are whole paragraphs now. Editor leftovers (<p><br></p>, <p dir="ltr"></p>, <div>&nbsp;</div>) count
  # as empty. The guidance's first and last blocks have no outer margins.
  # The upgrade fills the boxes from latenote and latenoteurl, feedbackcontact and feedbackcontacturl,
  # briefguidanceurl and untaggedguidanceurl, then removes those settings (PHPUnit: guidance_test).
  # guidance_finalised replaces the "Resubmission contact" setting locked-upcoming proposed (coordinator).

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username  | firstname | lastname |
      | student1  | Sam       | Student  |
      | student2  | Sue       | Student  |
      | convenor1 | Cat       | Convenor |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | student1  | M1     | student        |
      | student2  | M1     | student        |
      | convenor1 | M1     | moduleconvenor |
      | convenor1 | M1     | editingteacher |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"
    And the My assessments block keeps its hints and guidance from before beta27
    And the My assessments block treats "moduleconvenor" as a module convenor role

  # Beta27: the boxes moved to the Hints and guidance page, each beside its hint's switch.
  Scenario: The Hints and guidance page has one rich-text box per popover
    When I log in as "admin"
    And I navigate to "Plugins > Blocks > Le Dashboard > Hints and guidance" in site administration
    Then I should see "Other guidance"
    And I should see an HTML editor for each guidance setting
    And I should not see "Late penalties link"
    And I should not see "Feedback contact link"
    And I should not see "Brief guidance link"
    And I should not see "Tagging guidance link"

  Scenario: Edited guidance shows under the factual line, with its link
    Given the "guidance_latepenalty" setting is "<p>See the <a href=\"https://example.ac.uk/late\">late work policy</a>.</p>"
    And a summative "assign" "A1" in "M1" was due "3 days ago"
    And "student1" submitted "A1" "2 days ago"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    Then the "late" popover of "A1" should show the guidance "See the late work policy."
    And the "late" popover of "A1" should link "late work policy" to "https://example.ac.uk/late"

  @javascript
  Scenario: An empty box shows the factual line only
    Given the "guidance_unallocated" setting is empty
    And a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student2" for "A1" is not allocated
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    Then "A1" should have an "unallocated" flag showing "2"
    And the "unallocated" popover of "A1" should contain only its factual line

  Scenario: Untouched defaults read as before
    Given a summative "assign" "A1" in "M1" with a marking deadline "yesterday" was due "10 days ago"
    And "student1" submitted "A1" "11 days ago"
    When I log in as "student1" and view my dashboard
    Then the "feedbackcontact" popover of "A1" should show the guidance "If it doesn't, contact your module convenor."

  @javascript
  Scenario: Unsafe HTML is cleaned
    Given the "guidance_brief" setting is "<p>Read this</p><script>alert(1)</script>"
    And a summative "assign" "A1" in "M1" with no description is due "in 3 days"
    When I log in as "convenor1" and view the "Convenor view"
    Then the "brief" popover of "A1" should show the guidance "Read this"
    And the "brief" popover of "A1" should hold no script

  Scenario: Filters apply, so multi-language text shows the viewer's language
    Given the "multilang" filter is "on"
    And the "guidance_nosubmission" setting is "<span lang=\"en\" class=\"multilang\">In person</span><span lang=\"fr\" class=\"multilang\">En personne</span>"
    And a summative "assign" "A1" in "M1" with no online submission is due "in 3 days"
    When I log in as "student1" and view my dashboard
    Then the "nosubmission" popover of "A1" should show the guidance "In person"
    And the "nosubmission" popover of "A1" should not show the guidance "En personne"

  @javascript
  Scenario Outline: Editor leftovers count as empty
    Given the "guidance_unallocated" setting is "<saved>"
    And a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student2" for "A1" is not allocated
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    Then the "unallocated" popover of "A1" should contain only its factual line

    Examples:
      | saved             |
      | <p><br></p>       |
      | <p dir=\"ltr\"></p> |
      | <div>&nbsp;</div> |
      | <p> </p><p></p>   |

  @javascript
  Scenario: Guidance with a link stays keyboard reachable
    Given the "guidance_unallocated" setting is "<p>See the <a href=\"https://example.ac.uk/allocate\">allocation guide</a>.</p>"
    And a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"
    And "student1" and "student2" have submitted "A1"
    And the submission of "student2" for "A1" is not allocated
    And the My assessments index is up to date
    When I log in as "convenor1" and view the "My marking"
    And I click on the "unallocated" flag of "A1"
    Then the guidance link in the "unallocated" popover of "A1" can be reached with the tab key
    When I press the escape key
    Then the "unallocated" popover of "A1" should not be visible

  @javascript
  Scenario: A wrapped paragraph adds no extra gap
    Given the "guidance_finalised" setting is "<p>Contact your Info Point.</p>"
    And a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"
    And "student1" has submitted "A1"
    And the My assessments index is up to date
    When I log in as "student1" and view my dashboard
    And I open the "Can't resubmit" control of "A1"
    Then the guidance in the "locked" popover of "A1" should be its own block with no outer margins
