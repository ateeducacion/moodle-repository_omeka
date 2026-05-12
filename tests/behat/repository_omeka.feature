@repository @repository_omeka
Feature: Omeka-S repository
  In order to integrate Omeka-S resources in Moodle
  As an administrator
  I need the Omeka-S repository plugin to register itself in Moodle's repository catalogue

  Scenario: The Omeka-S repository is registered in the admin catalogue
    Given I log in as "admin"
    When I navigate to "Plugins > Repositories > Manage repositories" in site administration
    Then I should see "Omeka"
