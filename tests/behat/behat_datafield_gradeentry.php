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

/**
 * Behat step definitions for datafield_gradeentry.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use Behat\Step\Given;

/**
 * Custom Behat steps for the datafield_gradeentry plugin.
 */
class behat_datafield_gradeentry extends behat_base {
    /**
     * Add a Grade entry field with the given min/max bounds to a Database activity.
     *
     * @param string $dataname   Name of the existing Database activity (mdl_data.name).
     * @param string $type       Field type label (ignored; provided for readability).
     * @param string $fieldname  Name of the new field.
     * @param string $min        param1 - minimum grade.
     * @param string $max        param2 - maximum grade.
     */
    #[Given('the database :dataname has a :type field named :fieldname with min :min and max :max')]
    public function the_database_has_a_field_with_min_and_max(
        string $dataname,
        string $type,
        string $fieldname,
        string $min,
        string $max
    ): void {
        $this->create_gradeentry_field($dataname, $fieldname, $min, $max, 2, 0);
    }

    /**
     * Add a Grade entry field with percentage display enabled.
     *
     * @param string $dataname   Name of the existing Database activity.
     * @param string $type       Field type label (ignored).
     * @param string $fieldname  Name of the new field.
     * @param string $min        param1 - minimum grade.
     * @param string $max        param2 - maximum grade.
     */
    #[Given('the database :dataname has a :type field named :fieldname with min :min, max :max, and percentage display enabled')]
    public function the_database_has_a_field_with_percentage(
        string $dataname,
        string $type,
        string $fieldname,
        string $min,
        string $max
    ): void {
        $this->create_gradeentry_field($dataname, $fieldname, $min, $max, 2, 1);
    }

    /**
     * Create a datafield_gradeentry field on the named Database activity.
     *
     * @param string $dataname    Name of the existing Database activity (mdl_data.name).
     * @param string $fieldname   Name of the new field.
     * @param string $min         param1 - minimum grade.
     * @param string $max         param2 - maximum grade.
     * @param int    $decimals    param3 - number of decimal places to display.
     * @param int    $percentage  param4 - 1 to enable percentage display, 0 otherwise.
     */
    private function create_gradeentry_field(
        string $dataname,
        string $fieldname,
        string $min,
        string $max,
        int $decimals,
        int $percentage
    ): void {
        global $DB;

        $data = $DB->get_record('data', ['name' => $dataname], '*', MUST_EXIST);

        $generator     = \behat_util::get_data_generator();
        $datagenerator = $generator->get_plugin_generator('mod_data');

        $datagenerator->create_field((object) [
            'name'   => $fieldname,
            'type'   => 'gradeentry',
            'param1' => $min,
            'param2' => $max,
            'param3' => (string) $decimals,
            'param4' => (string) $percentage,
        ], $data);
    }
}
