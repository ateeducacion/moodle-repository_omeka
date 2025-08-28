@repository @repository_omeka
Feature: Omeka repository
  In order to use Omeka resources in Moodle
  As a user
  I need to be able to browse and select items from an Omeka-S instance

  Scenario: Plugin is configurable
    Given I am on the "Plugins" "administration" page
    And I follow "Repositories"
    And I follow "Manage repositories"
    Then I should see "Omeka"

  Scenario: Search for an item in the Omeka repository
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And I log in as "admin"
    And I navigate to "Manage repositories" in site administration
    And I configure the "Omeka" repository with:
      | baseurl       | http://omeka:8080 |
      | keyidentity   | testkey           |
      | keycredential | testcredential    |
    And I log out
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I turn editing mode on
    And I add a "File" to section "1"
    And I click on "Add..." "button"
    And I click on "Omeka" "text"
    And I wait "2" seconds
    Then I should see "Omeka"
