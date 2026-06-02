@datafield_gradeentry @mod_data
Feature: Grade entry field in database activity
  As a teacher
  I want to add a grade entry field to a database activity
  So that students can record numeric grades

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Test Course | TC101 |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher | One |
      | student1 | Student | One |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC101  | editingteacher |
      | student1 | TC101  | student        |
    And the following "activities" exist:
      | activity | name         | course |
      | data     | Grade Record | TC101  |
    And I log in as "teacher1"
    And I am on "Test Course" course homepage
    And I follow "Grade Record"

  @javascript
  Scenario: Teacher can add a grade entry field
    Given I navigate to "Fields" in current page administration
    When I set the field "Field type" to "Grade entry"
    And I set the field "Field name" to "Assignment grade"
    And I set the field "param1" to "0"
    And I set the field "param2" to "100"
    And I set the field "param3" to "2"
    And I press "Save changes"
    Then I should see "Assignment grade"

  @javascript
  Scenario: Student can enter a valid grade value
    Given the database "Grade Record" has a "Grade entry" field named "Assignment grade" with min "0" and max "100"
    And I log out
    And I log in as "student1"
    And I am on "Test Course" course homepage
    And I follow "Grade Record"
    When I press "Add entry"
    And I set the field "Assignment grade" to "85"
    And I press "Save and view"
    Then I should see "85.00"

  @javascript
  Scenario: Student cannot enter a value above maximum
    Given the database "Grade Record" has a "Grade entry" field named "Assignment grade" with min "0" and max "100"
    And I log out
    And I log in as "student1"
    And I am on "Test Course" course homepage
    And I follow "Grade Record"
    When I press "Add entry"
    And I set the field "Assignment grade" to "150"
    And I press "Save and view"
    Then I should see "value must be between"

  @javascript
  Scenario: Grade entry field displays percentage when configured
    Given the database "Grade Record" has a "Grade entry" field named "Assignment grade" with min "0", max "100", and percentage display enabled
    And I log out
    And I log in as "student1"
    And I am on "Test Course" course homepage
    And I follow "Grade Record"
    When I press "Add entry"
    And I set the field "Assignment grade" to "75"
    And I press "Save and view"
    Then I should see "75.00 (75.0%)"
