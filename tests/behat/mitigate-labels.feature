@block @block_le_dashboard @block_rhb @javascript
Feature: The Mitigate menu uses the dashboard's own labels
  In order that the Mitigate menu reads the same as the rest of the dashboard
  As a student
  I need block_rhb's links shown with the dashboard's wording, keeping only where they go

  # Pierre, 2026-10-06 23:05: "Could we only keep the href and discard the link text?" block_rhb returns each
  # link as HTML (block_rhb_get_srm_link, block_rhb_get_self_certification_link). Only the address is kept;
  # the label comes from this plugin's strings by the kind of link: "Request mitigation" for the request
  # form, "Self-certify an extension" for self-certification. A link whose address can't be read keeps
  # block_rhb's own HTML, so the student never loses the route.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Module 1 | M1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | M1     | student |
    And the My assessments block is configured with summative tag "summative" and formative tag "formative"

  Scenario: The request form link has the dashboard's label and block_rhb's address
    Given a summative "assign" "A1" in "M1" is due "tomorrow"
    When I log in as "student1" and view my dashboard
    And I click on "Mitigate" "button" in the "My assessments" "block"
    Then I should see "Request mitigation" linking to the site home
    And I should not see "Request mitigating circumstances" in the "My assessments" block
