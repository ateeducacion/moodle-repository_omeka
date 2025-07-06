<?php
/**
 * Privacy Subsystem implementation for repository_omeka.
 *
 * PHP version 7.4 or later
 *
 * @category  Privacy
 * @package   Repository_Omeka
 * @author    Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link      https://github.com/educacioncanarias/moodle-repository_omeka
 */

namespace repository_omeka\privacy;

/**
 * Privacy plugin providers.
 *
 * @category  Privacy
 * @package   Repository_Omeka
 * @author    Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link      https://github.com/educacioncanarias/moodle-repository_omeka
 */
class provider implements \core_privacy\local\metadata\null_provider
{
    /**
     * Returns the reason why this plugin stores no data.
     *
     * @return string
     */
    public static function get_reason(): string
    {
        return get_string('privacy:metadata', 'repository_omeka');
    }
}
