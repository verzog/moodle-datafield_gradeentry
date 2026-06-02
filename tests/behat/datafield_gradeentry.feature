@datafield @datafield_gradeentry @mod_data
Feature: Grade entry field in database activity
  As a teacher
  I want to add a grade entry field to a Database activity
  So that I can record numeric grades against student entries

  Scenario: A grade entry field can be created on a Database activity
    Given the following "courses" exist:
      | fullname    | shortname |
      | Test Course | TC101     |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC101  | editingteacher |
    And the following "activities" exist:
      | activity | name         | course |
      | data     | Grade Record | TC101  |
    And the database "Grade Record" has a "Grade entry" field named "Assignment grade" with min "0" and max "100"
    When I log in as "teacher1"
    And I am on "Test Course" course homepage
    And I follow "Grade Record"
    Then I should see "Grade Record"
