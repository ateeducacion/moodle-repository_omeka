<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');
use Behat\Gherkin\Node\TableNode;

/**
 * Behat step definitions for repository_omeka.
 *
 * @package    repository_omeka
 * @category   test
 * @copyright  2025 Área de Tecnología Educativa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_repository_omeka extends behat_base {

    /**
     * Configure a repository instance for Omeka via the admin UI.
     * Tries to create a new instance (if none exists) and fills the provided fields.
     *
     * Expected table keys include: baseurl, keyidentity, keycredential.
     *
     * @Given I configure the :reponame repository with:
     * @param string $reponame Repository display name, e.g. "Omeka".
     * @param TableNode $table Key/value pairs to fill in the form.
     */
    public function i_configure_the_repository_with(string $reponame, TableNode $table): void {
        // Go to Manage repositories.
        $this->execute('I navigate to "Manage repositories" in site administration');

        $page = $this->getSession()->getPage();

        // Click "Create a repository instance" if present on the page (label may vary slightly).
        $candidates = [
            'Create a repository instance',
            'Create a repository instance…',
            'Create a repository instance...',
        ];
        $clicked = false;
        foreach ($candidates as $label) {
            $button = $page->findButton($label);
            if ($button) {
                $button->click();
                $clicked = true;
                break;
            }
            $link = $page->findLink($label);
            if ($link) {
                $link->click();
                $clicked = true;
                break;
            }
        }

        // If a type selection is shown, choose the requested type and continue.
        $typeselect = $page->find('named', [ 'field', 'type' ]);
        if (!$clicked && !$typeselect) {
            // Some themes may show an inline control per repository.
            // Try to click an action link in the row containing the repository name.
            $row = $page->find('xpath', "//tr[.//text()[contains(., '{$reponame}')]]");
            if ($row) {
                // Click any link that mentions 'Create' within this row.
                $action = $row->find('xpath', ".//a[contains(., 'Create')]");
                if ($action) {
                    $action->click();
                }
            }
        }

        $page = $this->getSession()->getPage();
        $typeselect = $page->find('named', [ 'field', 'type' ]);
        if ($typeselect) {
            $typeselect->selectOption($reponame);
            // Try common submit labels.
            $submitlabels = ['Create', 'Next', 'Continue'];
            foreach ($submitlabels as $label) {
                $btn = $page->findButton($label);
                if ($btn) {
                    $btn->click();
                    break;
                }
            }
        }

        // Prepare fields to set from the provided table.
        $rows = $table->getRowsHash();
        // Ensure a name is set if the instance form requires it.
        // Try both common labels.
        if (!isset($rows['Name'])) {
            $rows['Name'] = $reponame;
        }
        if (!isset($rows['Repository name'])) {
            $rows['Repository name'] = $reponame;
        }
        $this->execute('I set the following fields to these values:', new TableNode($this->hash_to_rows($rows)));

        // Save changes using common labels.
        $savecandidates = ['Save', 'Save changes', 'Create repository instance'];
        foreach ($savecandidates as $label) {
            $btn = $page->findButton($label);
            if ($btn) {
                $btn->click();
                return;
            }
        }
        // Fallback: submit first form.
        $form = $page->find('css', 'form');
        if ($form) {
            $form->submit();
        }
    }

    /**
     * Convert an associative array into Gherkin table rows.
     *
     * @param array $hash
     * @return array
     */
    private function hash_to_rows(array $hash): array {
        $rows = [];
        foreach ($hash as $k => $v) {
            $rows[] = [$k, $v];
        }
        return $rows;
    }
}
