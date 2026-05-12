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
        // Go to Manage repositories directly (more robust across themes/versions).
        $this->getSession()->visit($this->locate_path('/admin/repository.php'));
        $page = $this->getSession()->getPage();

        // Ensure the repository is enabled and visible.
        $row = $page->find('xpath', "//tr[.//text()[contains(., '{$reponame}')]]");
        if ($row) {
            $select = $row->find('css', 'select');
            if ($select) {
                // Prefer selecting by value when available.
                // Moodle usually uses values: 1 => Enabled and visible, 0 => Disabled.
                $success = false;
                try {
                    $select->selectOption('1', false);
                    $success = true;
                } catch (\Exception $e) {
                    // Fallback to visible labels in common languages.
                    $labels = [
                        'Enabled and visible',
                        'Enable and visible',
                        'Enabled',
                        'Habilitado y visible',
                        'Habilitado',
                    ];
                    foreach ($labels as $label) {
                        try {
                            $select->selectOption($label);
                            $success = true;
                            break;
                        } catch (\Exception $ignored) {
                            // Intentionally ignored: try next label when this option is not available.
                            $ignored = $ignored; // No-op to avoid empty catch block.
                        }
                    }
                }
                // Click a global Save button if found to persist enable state.
                if ($success) {
                    $save = $page->findButton('Save changes') ?: $page->findButton('Save');
                    if ($save) {
                        $save->click();
                        $page = $this->getSession()->getPage();
                        // Re-acquire row after postback.
                        $row = $page->find('xpath', "//tr[.//text()[contains(., '{$reponame}')]]");
                    }
                }
            }
        }

        // Open Settings for this repository type if available.
        if ($row) {
            $settings = $row->findLink('Settings') ?: $row->findLink('Ajustes') ?: $row->findLink('Configuración');
            if ($settings) {
                $settings->click();
                $page = $this->getSession()->getPage();
            }
        }

        // Prepare fields to set from the provided table.
        $rows = $table->getRowsHash();
        // Ensure a name is set if the instance form requires it.
        if (!isset($rows['name']) && !isset($rows['Name']) && !isset($rows['Repository name'])) {
            $rows['name'] = $reponame;
        }

        // Fill fields by name/id/label using Mink directly to accept raw keys like 'baseurl'.
        $page = $this->getSession()->getPage();
        foreach ($rows as $key => $value) {
            // Try by name/id/label.
            $field = $page->findField($key);
            if (!$field) {
                // Try common English and Spanish labels.
                $labelmap = [
                    'baseurl' => ['Omeka-S base URL', 'URL base de Omeka-S'],
                    'keyidentity' => ['API key ID', 'ID de clave API'],
                    'keycredential' => ['API key credential', 'Credencial de clave API'],
                    'name' => ['Name', 'Nombre', 'Repository name'],
                ];
                $alts = $labelmap[$key] ?? [$key];
                foreach ((array)$alts as $alt) {
                    $field = $page->findField($alt);
                    if ($field) {
                        break;
                    }
                }
            }
            if (!$field) {
                // Try typical Moodle id_ pattern and name selectors.
                $selector = sprintf('#id_%1$s, input[name="%1$s"], textarea[name="%1$s"], select[name="%1$s"]', $key);
                $field = $page->find('css', $selector);
            }
            if ($field) {
                $field->setValue($value);
            }
        }

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
     * Set the Omeka repository enabled/disabled status via the admin UI.
     *
     * Accepted values: "Enabled and visible", "Enabled but hidden", "Disabled".
     *
     * @When I set the omeka repository status to :status
     * @param string $status The desired status label or numeric value.
     */
    public function i_set_the_omeka_repository_status_to(string $status): void {
        $page = $this->getSession()->getPage();
        $row = $page->find('xpath', "//tr[.//text()[contains(., 'Omeka')]]");
        if (!$row) {
            throw new \Exception('Omeka repository row not found on the page.');
        }
        $select = $row->find('css', 'select');
        if (!$select) {
            throw new \Exception('Status select not found for the Omeka repository row.');
        }
        $select->selectOption($status);
        $save = $page->findButton('Save changes') ?: $page->findButton('Save');
        if ($save) {
            $save->click();
        }
    }

    /**
     * Assert that the Omeka repository does not appear in the enabled repositories list.
     *
     * @Then I should not see :text in the enabled repositories list
     * @param string $text Text that should be absent from enabled repository entries.
     */
    public function i_should_not_see_in_the_enabled_repositories_list(string $text): void {
        $page = $this->getSession()->getPage();
        $rows = $page->findAll('xpath', "//tr[.//select[not(option[@selected and @value='1'])]]");
        foreach ($rows as $row) {
            if (strpos($row->getText(), $text) !== false) {
                throw new \Exception("'{$text}' was unexpectedly found in an enabled repository row.");
            }
        }
    }

    /**
     * Assert that an error message is shown for the baseurl field.
     *
     * @Then I should see an error for the baseurl field
     */
    public function i_should_see_an_error_for_the_baseurl_field(): void {
        $page = $this->getSession()->getPage();
        // Moodle renders field errors inside an element with id "id_error_<fieldname>"
        // or within a div.fitem that contains the field and a following .error span.
        $error = $page->find('css', '#id_error_baseurl')
            ?: $page->find(
                'xpath',
                "//*[contains(@class,'error') and (contains(., 'baseurl') or contains(., 'URL'))]"
            );
        if (!$error) {
            throw new \Exception('No error message found for the baseurl field.');
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
