@local_datagrading @mod_data
Feature: Teacher grades database activity entries inline
  As a teacher
  I want to grade student entries directly in the Database activity browse view
  So that I do not need to leave the activity to assign grades

  Background:
    Given the following "courses" exist:
      | fullname    | shortname |
      | Test Course | TC101     |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
      | student2 | Student   | Two      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC101  | editingteacher |
      | student1 | TC101  | student        |
      | student2 | TC101  | student        |
    And the following "activities" exist:
      | activity | name         | course |
      | data     | Case Studies | TC101  |
    And the database "Case Studies" has a "Grade entry" field named "Mark" with min "0" and max "100"

  @javascript
  Scenario: Teacher sees grade inputs in browse view
    Given I log in as "teacher1"
    And I am on "Test Course" course homepage
    And I follow "Case Studies"
    When I am on the database browse page
    Then I should see a grade input with "data-gradeentry-field" attribute
    And I should see a grading progress indicator

  @javascript
  Scenario: Teacher can grade an entry and progress updates
    Given student1 has submitted a case study entry in "Case Studies"
    And I log in as "teacher1"
    And I am on "Test Course" course homepage
    And I follow "Case Studies"
    When I set the grade input for student1's entry to "78"
    And I wait for the AJAX save to complete
    Then the grading progress should show "Graded: 1 / 1"
    And the gradebook should contain a grade of "78" for "student1" in "Case Studies"

  @javascript
  Scenario: Student does not see grade before release
    Given student1 has a graded entry with grade "85" in "Case Studies"
    And the grade has not been released
    And I log in as "student1"
    And I am on "Test Course" course homepage
    And I follow "Case Studies"
    Then I should see "Grade pending"
    And I should not see "85"

  @javascript
  Scenario: Student sees grade after teacher releases it
    Given student1 has a graded entry with grade "85" in "Case Studies"
    And I log in as "teacher1"
    And I am on "Test Course" course homepage
    And I follow "Case Studies"
    When I click "Release all grades"
    And I log out
    And I log in as "student1"
    And I am on "Test Course" course homepage
    And I follow "Case Studies"
    Then I should see "85.00 / 100"

  @javascript
  Scenario: Teacher can leave feedback visible to student after release
    Given student1 has a graded entry with grade "72" in "Case Studies"
    And I log in as "teacher1"
    And I am on "Test Course" course homepage
    And I follow "Case Studies"
    When I enter feedback "Good analysis, but referencing needs work." for student1's entry
    And I wait for the AJAX save to complete
    And I click "Release all grades"
    And I log out
    And I log in as "student1"
    And I am on "Test Course" course homepage
    And I follow "Case Studies"
    Then I should see "Good analysis, but referencing needs work."
