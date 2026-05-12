@repository @repository_omeka
Feature: Omeka repository
  In order to use Omeka resources in Moodle
  As a user
  I need to be able to browse and select items from an Omeka-S instance

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: Plugin is configurable
    Given I log in as "admin"
    When I navigate to "Plugins > Repositories > Manage repositories" in site administration
    Then I should see "Omeka"

  Scenario: An administrator can disable and re-enable the repository
    Given I log in as "admin"
    And I navigate to "Plugins > Repositories > Manage repositories" in site administration
    When I set the omeka repository status to "Disabled"
    Then I should not see "Omeka" in the enabled repositories list
    When I set the omeka repository status to "Enabled and visible"
    Then I should see "Omeka"

  @javascript
  Scenario: Search for an item in the Omeka repository
    Given I log in as "admin"
    And I navigate to "Plugins > Repositories > Manage repositories" in site administration
    And I configure the "Omeka" repository with:
      | baseurl       | http://omeka:8080 |
      | keyidentity   | testkey           |
      | keycredential | testcredential    |
    And I log out
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I turn editing mode on
    And I add a "File" to section "1" using the activity chooser
    And I click on "Add..." "button"
    And I click on "Omeka" "text"
    And I wait "2" seconds
    Then I should see "Omeka"

  @javascript @_file_upload
  Scenario: Repository appears in the file picker
    Given I log in as "admin"
    And I navigate to "Plugins > Repositories > Manage repositories" in site administration
    And I configure the "Omeka" repository with:
      | baseurl | http://omeka:8080 |
    And I log out
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I turn editing mode on
    And I add a "File" to section "1" using the activity chooser
    And I click on "Add..." "button"
    Then I should see "Omeka-S"
